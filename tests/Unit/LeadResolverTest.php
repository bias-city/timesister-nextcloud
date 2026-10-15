<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Db\Record;
use OCA\TimeSister\Service\LeadResolver;
use OCA\TimeSister\Service\MembershipResolver;
use PHPUnit\Framework\TestCase;

/** 0.11.0: the role Lead follows from `project.leads` of the active projects. */
class LeadResolverTest extends TestCase {
	/** @param list<string> $accounts */
	private static function person(string $key, array $accounts): Record {
		$r = new Record();
		$r->setKind('person');
		$r->setRkey($key);
		$r->setData(json_encode(['login' => $key]));
		$r->setAccounts(json_encode($accounts));
		return $r;
	}

	/** @param array<string,mixed> $fields */
	private static function project(string $key, array $fields): Record {
		$r = new Record();
		$r->setKind('project');
		$r->setRkey($key);
		$r->setData(json_encode(['id' => $key] + $fields));
		return $r;
	}

	public function testLeadsByUidResolvesPersonsAndAccounts(): void {
		$persons = [
			self::person('carol@example.org', ['carol']),
			self::person('pblead', ['pblead']),
			self::person('ghost', []), // a person without an account leads nothing
			self::person('two', ['tina', 'tom']),
		];
		$projects = [
			self::project('intern', ['name' => 'Interne Arbeit', 'codes' => ['I100'], 'leads' => ['carol@example.org'], 'status' => 'running']),
			self::project('TK1', ['name' => 'Teamkapazität', 'codes' => ['TK1'], 'leads' => ['pblead', 'ghost', 'two']]),
			self::project('M1', ['leads' => ['pblead', 'pblead']]), // no name, no codes, lead named twice
			self::project('done', ['name' => 'Fertig', 'leads' => ['pblead'], 'status' => 'completed']),
			self::project('old', ['name' => 'Alt', 'leads' => ['carol@example.org'], 'status' => 'Archiviert']),
			self::project('direct', ['name' => 'Direkt', 'leads' => ['bob']]), // an account without a person record
			self::project('broken', ['name' => 'Kaputt', 'leads' => 'pblead']), // not a list
		];
		$leads = LeadResolver::leadsByUid($persons, $projects);
		$this->assertSame(['bob', 'carol', 'pblead', 'tina', 'tom'], array_keys($leads));
		$this->assertSame(
			[['kind' => 'project', 'key' => 'M1', 'code' => null, 'name' => 'M1'], ['kind' => 'project', 'key' => 'TK1', 'code' => 'TK1', 'name' => 'Teamkapazität']],
			$leads['pblead'],
			'several projects, sorted by name; the ended one is missing; named twice counts once',
		);
		$this->assertSame([['kind' => 'project', 'key' => 'intern', 'code' => 'I100', 'name' => 'Interne Arbeit']], $leads['carol'], 'archived does not count, case does not matter');
		$this->assertSame(['TK1'], array_column($leads['tina'], 'key'));
		$this->assertSame(['TK1'], array_column($leads['tom'], 'key'));
		$this->assertSame(['direct'], array_column($leads['bob'], 'key'));
		$this->assertArrayNotHasKey('ghost', $leads);
	}

	public function testTombstonesAndEmptyLeadsDoNotCount(): void {
		$gone = self::project('gone', ['name' => 'Weg', 'leads' => ['pblead']]);
		$gone->setDeleted(1);
		$projects = [$gone, self::project('none', ['name' => 'Niemand', 'leads' => []]), self::project('empty', ['name' => 'Leer', 'leads' => ['', 7]])];
		$this->assertSame([], LeadResolver::leadsByUid([], $projects));
	}

	public function testEndedStatuses(): void {
		foreach (['completed', 'archived', 'abgeschlossen', 'archiviert', ' Completed '] as $s) {
			$this->assertFalse(LeadResolver::isActive((object)['status' => $s]), $s);
		}
		// A quote (0.16.0: draft, quoted, declined) keeps its leads: they plan with it.
		foreach (['active', 'running', 'paused', 'draft', 'quoted', 'declined', 'aktiv', 'laufend', 'ruhend', '', null, 7] as $s) {
			$this->assertTrue(LeadResolver::isActive((object)['status' => $s]), var_export($s, true));
		}
		$this->assertTrue(LeadResolver::isActive(new \stdClass()));
	}

	/** admin > lead > user, with the leads from the projects. */
	public function testRoleFromGroupAdminAndProjects(): void {
		$projects = [self::project('P', ['name' => 'P', 'leads' => ['lea']])];
		$leads = array_keys(LeadResolver::leadsByUid([], $projects));
		$members = MembershipResolver::members(['lea', 'uwe', 'ada'], ['ada'], ['lea' => ['role' => null, 'left_at' => null]], $leads);
		$this->assertSame('admin', $members['ada']['role']);
		$this->assertSame('lead', $members['lea']['role']);
		$this->assertSame('user', $members['uwe']['role']);
		// A group admin who also leads a project stays admin.
		$this->assertSame('admin', MembershipResolver::members(['lea'], ['lea'], [], $leads)['lea']['role']);
		// Project ended: back to user.
		$ended = [self::project('P', ['name' => 'P', 'leads' => ['lea'], 'status' => 'completed'])];
		$this->assertSame('user', MembershipResolver::members(['lea'], [], [], array_keys(LeadResolver::leadsByUid([], $ended)))['lea']['role']);
	}
}
