<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Db\Record;
use OCA\TimeSister\Service\PrivacyDocument;
use OCA\TimeSister\Service\PrivacyFacts;
use OCA\TimeSister\Service\PrivacyFormat;
use OCA\TimeSister\Service\PrivacyRules;
use PHPUnit\Framework\TestCase;

/**
 * The privacy notice (0.10.2): the nine sections, placeholders for empty
 * fields, escaping in the HTML, Leads with their projects, the shares
 * matrix, the legal basis by selection and the modules.
 */
class PrivacyNoticeTest extends TestCase {
	/** @return array<string,mixed> facts as PrivacyFacts::collect() returns them */
	private static function facts(array $over = [], ?\stdClass $privacy = null): array {
		return array_replace([
			'team' => ['id' => 2, 'name' => 'Planungsbüro', 'slug' => 'pb', 'group' => 'pb-team'],
			'app_version' => '0.10.2',
			'date' => '2026-10-05',
			'settings' => PrivacyRules::normalize($privacy),
			'members' => [
				['uid' => 'pbadmin', 'name' => 'Petra Brunner', 'role' => 'admin', 'left_at' => null],
				['uid' => 'pblead', 'name' => 'Lea Planer', 'role' => 'lead', 'left_at' => null],
				['uid' => 'pbuser1', 'name' => 'Mia Muster', 'role' => 'user', 'left_at' => null],
			],
			'admins' => ['Petra Brunner'],
			'leads' => [['uid' => 'pblead', 'name' => 'Lea Planer', 'projects' => ['Jobprobe', 'Teamkapazität']]],
			'matrix' => [
				['uid' => 'pbadmin', 'name' => 'Petra Brunner', 'role' => 'admin', 'sees' => ['Lea Planer', 'Mia Muster'], 'edits' => ['Lea Planer', 'Mia Muster']],
				['uid' => 'pblead', 'name' => 'Lea Planer', 'role' => 'lead', 'sees' => ['Mia Muster'], 'edits' => []],
				['uid' => 'pbuser1', 'name' => 'Mia Muster', 'role' => 'user', 'sees' => [], 'edits' => []],
			],
			'backup_owner' => ['uid' => 'pbadmin', 'name' => 'Petra Brunner'],
			'consents' => ['yes' => 2, 'no' => 1],
			'modules' => ['jobs' => true, 'budgets' => false, 'customers' => true],
			'absence_kinds' => ['vacation', 'sickness'],
			'vacation_calendar' => true,
			'externals' => 1,
			'customers' => 3,
		], $over);
	}

	private static function doc(array $over = [], ?\stdClass $privacy = null): array {
		return (new PrivacyDocument(new FakeL10n()))->build(self::facts($over, $privacy));
	}

	public function testMarkdownHasTheNineSectionsInOrder(): void {
		$md = PrivacyFormat::markdown(self::doc());
		$this->assertStringStartsWith("# Privacy notice – Planungsbüro\n", $md);
		$titles = ['Controller and contact', 'Purpose', 'Which data, which categories', 'Where the data is stored', 'Who has access',
			'Backups', 'Retention and deletion', 'Rights of the persons', 'Note'];
		$pos = -1;
		foreach ($titles as $i => $title) {
			$p = strpos($md, '## ' . ($i + 1) . '. ' . $title . "\n");
			$this->assertNotFalse($p, $title);
			$this->assertGreaterThan($pos, $p, $title);
			$pos = $p;
		}
		$this->assertStringContainsString('not legal advice', $md);
		$this->assertStringContainsString('ArGV 1 Art. 73', $md);
		$this->assertStringContainsString('GDPR Art. 6(1)(b)', $md);
		$this->assertStringContainsString('Nextcloud app 0.10.2', $md);
		$this->assertStringContainsString('2026-10-05', $md);
	}

	public function testEmptyFieldsShowPlaceholders(): void {
		$md = PrivacyFormat::markdown(self::doc());
		$this->assertStringContainsString('| Controller | [to be completed] |', $md);
		$this->assertStringContainsString('| Privacy contact | [to be completed] |', $md);
		$this->assertStringContainsString('Nextcloud of the team: [to be completed]', $md);
		// Without an authority the Swiss one is named as a hint, the placeholder stays.
		$this->assertStringContainsString('supervisory authority: [to be completed] – Switzerland: Federal Data Protection', $md);
		// Defaults: 5 and 10 years, both legal systems.
		$this->assertStringContainsString('time records 5 years, billing records 10 years.', $md);
	}

	public function testFilledFieldsAppearAndReplaceThePlaceholder(): void {
		$p = json_decode('{"controller":{"name":"B/IAS GmbH","address":"Basel","contact":"info@example.test"},"privacy_contact":"Petra Brunner",
			"hosting":{"provider":"Hostpoint","location":"Switzerland"},"retention":{"time_years":7,"billing_years":12,"note":"Longer by contract."},"law":"ch","authority":"EDÖB"}');
		$md = PrivacyFormat::markdown(self::doc([], $p));
		$this->assertStringContainsString('| Controller | B/IAS GmbH |', $md);
		$this->assertStringContainsString('| Hosting of the Nextcloud | Hostpoint, Switzerland |', $md);
		$this->assertStringContainsString('time records 7 years, billing records 12 years. Longer by contract.', $md);
		$this->assertStringContainsString('supervisory authority: EDÖB', $md);
		$this->assertStringNotContainsString('[to be completed]', $md);
	}

	public function testLegalBasisFollowsTheSelection(): void {
		$ch = PrivacyFormat::markdown(self::doc([], json_decode('{"law":"ch"}')));
		$this->assertStringContainsString('ArG Art. 46', $ch);
		$this->assertStringNotContainsString('GDPR', $ch);
		$eu = PrivacyFormat::markdown(self::doc([], json_decode('{"law":"eu"}')));
		$this->assertStringContainsString('EU: GDPR Art. 6(1)(b) and (c)', $eu);
		$this->assertStringContainsString('Art. 17(3)(b)', $eu);
		$this->assertStringNotContainsString('ArG', $eu);
		$this->assertStringNotContainsString('FDPIC', $eu);
		$both = PrivacyFormat::markdown(self::doc());
		$this->assertStringContainsString('OR Art. 958f', $both);
		$this->assertStringContainsString('Art. 5(1)(e)', $both);
	}

	public function testHtmlEscapesEverythingForeign(): void {
		$evil = '<script>alert(1)</script> & "Müller"';
		$html = PrivacyFormat::html(self::doc([
			'team' => ['id' => 2, 'name' => $evil, 'slug' => 'pb', 'group' => 'pb-team'],
			'admins' => [$evil],
			'leads' => [['uid' => 'x', 'name' => $evil, 'projects' => ['<b>P</b>']]],
		]), 'de');
		$this->assertStringNotContainsString('<script>', $html);
		$this->assertStringNotContainsString('<b>P</b>', $html);
		$this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;Müller&quot;', $html);
		$this->assertStringContainsString('&lt;b&gt;P&lt;/b&gt;', $html);
		$this->assertStringStartsWith("<!DOCTYPE html>\n<html lang=\"de\">", $html);
		$this->assertStringContainsString('<style>', $html);
		// Nothing from outside: no src=, href= or @import.
		$this->assertDoesNotMatchRegularExpression('/\b(src|href)=|@import|<link|<script/', $html);
		$this->assertSame(9, substr_count($html, '<h2>'));
	}

	public function testMarkdownTableCellsEscapePipesAndLineBreaks(): void {
		$md = PrivacyFormat::markdown(self::doc(['matrix' => [
			['uid' => 'x', 'name' => "Max | Moritz\nZwei", 'role' => 'user', 'sees' => [], 'edits' => []],
		]]));
		$this->assertStringContainsString('| Max \\| Moritz Zwei | User | – | – |', $md);
	}

	public function testLeadsAndMatrixAreListed(): void {
		$md = PrivacyFormat::markdown(self::doc());
		$this->assertStringContainsString('| Lead | Projects |', $md);
		$this->assertStringContainsString('| Lea Planer | Jobprobe, Teamkapazität |', $md);
		$this->assertStringContainsString('| Person | Role | sees the time calendar of | edits the time calendar of |', $md);
		$this->assertStringContainsString('| Petra Brunner | Team Admin | Lea Planer, Mia Muster | Lea Planer, Mia Muster |', $md);
		$this->assertStringContainsString('| Lea Planer | Lead | Mia Muster | – |', $md);
		$this->assertStringContainsString('| Mia Muster | User | – | – |', $md);
		$this->assertStringContainsString('Currently: Petra Brunner', $md);
		$this->assertStringContainsString('Consents: 2 given, 1 not given', $md);
		$this->assertStringContainsString('Currently 1.', $md); // externals: count only
	}

	public function testOnlyEnabledModulesAreNamed(): void {
		$md = PrivacyFormat::markdown(self::doc());
		$this->assertStringContainsString('- Jobs: shares of a project', $md);
		$this->assertStringContainsString('- Customers: the customer book', $md);
		$this->assertStringNotContainsString('- Budgets:', $md);
		$none = PrivacyFormat::markdown(self::doc(['modules' => ['jobs' => false, 'budgets' => false, 'customers' => false], 'vacation_calendar' => false]));
		$this->assertStringNotContainsString('module switched on', $none);
		$this->assertStringContainsString('no shared vacation calendar is set up', $none);
	}

	public function testProjectsLedByFollowsPersonKeysAndAccounts(): void {
		$person = static function (string $key, array $accounts): Record {
			$r = new Record();
			$r->setKind('person');
			$r->setRkey($key);
			$r->setData(json_encode(['login' => $key]));
			$r->setAccounts(json_encode($accounts));
			return $r;
		};
		$project = static function (string $key, ?string $name, array $leads): Record {
			$r = new Record();
			$r->setKind('project');
			$r->setRkey($key);
			$r->setData(json_encode(array_filter(['id' => $key, 'name' => $name, 'leads' => $leads], static fn ($v) => $v !== null)));
			return $r;
		};
		$persons = [$person('carol@example.org', ['carol']), $person('pblead', ['pblead'])];
		$projects = [
			$project('intern', 'Interne Arbeit', ['carol@example.org']),
			$project('TK1', 'Teamkapazität', ['pblead']),
			$project('M1', null, ['pblead']),
			$project('other', 'Other', ['bob']),
		];
		$this->assertSame(['Interne Arbeit'], PrivacyFacts::projectsLedBy('carol', $persons, $projects));
		$this->assertSame(['M1', 'Teamkapazität'], PrivacyFacts::projectsLedBy('pblead', $persons, $projects));
		$this->assertSame([], PrivacyFacts::projectsLedBy('pbuser1', $persons, $projects));
	}

	public function testFormatNameAndMime(): void {
		$this->assertSame('html', PrivacyFormat::checkFormat(null));
		$this->assertSame('md', PrivacyFormat::checkFormat('md'));
		$this->assertSame('timesister-privacy-pb-2026-10-05.md', PrivacyFormat::fileName('pb', '2026-10-05', 'md'));
		$this->assertSame('text/markdown; charset=utf-8', PrivacyFormat::mime('md'));
		$this->assertSame('text/html; charset=utf-8', PrivacyFormat::mime('html'));
		try {
			PrivacyFormat::checkFormat('pdf');
			$this->fail('400 expected');
		} catch (\OCA\TimeSister\Service\ApiException $e) {
			$this->assertSame(400, $e->getStatus());
		}
	}
}
