<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\MembershipResolver;
use OCA\TimeSister\Service\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Fassung 2: Teamgruppe, Gruppenadmins, App-Rollen und Austritt. */
class MembershipResolverTest extends TestCase {
	/** Zwei Teams wie in docker-next, dazu alte Zeilen aus Fassung 1, die nicht zählen. */
	private const ROWS = [
		['tenant_id' => 1, 'role' => 'team', 'gid' => 'pb-team'],
		['tenant_id' => 2, 'role' => 'team', 'gid' => 'at-team'],
		['tenant_id' => 1, 'role' => 'user', 'gid' => 'pb-mitarbeitende'],
		['tenant_id' => 1, 'role' => 'admin', 'gid' => 'pb-admin'],
		['tenant_id' => 1, 'role' => 'accounts', 'gid' => 'pb-konten'],
		['tenant_id' => 3, 'role' => 'zeit', 'gid' => 'xx-zeit'],
	];

	private static function code(callable $fn): string {
		try {
			$fn();
		} catch (ApiException $e) {
			return $e->getStatus() . ' ' . $e->getErrorCode();
		}
		return 'ok';
	}

	/** @return array<string,array{list<string>,list<string>,array<int,array{role:?string,left_at:?int}>,int,string}> */
	public static function members(): array {
		return [
			'in der Teamgruppe' => [['pb-team'], [], [], 1, 'user'],
			'App-Rolle lead' => [['pb-team'], [], [1 => ['role' => 'lead', 'left_at' => null]], 1, 'lead'],
			'App-Rolle subadmin' => [['pb-team'], [], [1 => ['role' => 'subadmin', 'left_at' => null]], 1, 'subadmin'],
			'Gruppenadmin ist admin' => [['pb-team'], ['pb-team'], [], 1, 'admin'],
			'Gruppenadmin schlägt App-Rolle' => [['pb-team'], ['pb-team'], [1 => ['role' => 'lead', 'left_at' => null]], 1, 'admin'],
			'Gruppenadmin, nicht in der Gruppe' => [[], ['at-team'], [], 2, 'admin'],
			'fremde Gruppen stören nicht' => [['admin', 'Familie', 'at-team'], ['Familie'], [], 2, 'user'],
			'App-Rolle eines anderen Teams zählt nicht' => [['at-team'], [], [1 => ['role' => 'subadmin', 'left_at' => null]], 2, 'user'],
			'unbekannte App-Rolle ist user' => [['pb-team'], [], [1 => ['role' => 'boss', 'left_at' => null]], 1, 'user'],
			'ausgetreten aus pb, in at: at' => [['pb-team', 'at-team'], [], [1 => ['role' => null, 'left_at' => 100]], 2, 'user'],
		];
	}

	/**
	 * @param list<string> $groups
	 * @param list<string> $managed
	 * @param array<int,array{role:?string,left_at:?int}> $entries
	 */
	#[DataProvider('members')]
	public function testRole(array $groups, array $managed, array $entries, int $tenant, string $role): void {
		$this->assertSame(['tenant_id' => $tenant, 'role' => $role], MembershipResolver::resolve($groups, $managed, self::ROWS, $entries));
	}

	public function testNoTeam(): void {
		$this->assertSame('403 no_team', self::code(fn () => MembershipResolver::resolve(['admin', 'andere'], [], self::ROWS, [])));
		$this->assertSame('403 no_team', self::code(fn () => MembershipResolver::resolve([], [], self::ROWS, [])));
		$this->assertSame('403 no_team', self::code(fn () => MembershipResolver::resolve(['pb-team'], [], [], [])));
	}

	public function testOldGroupsDoNotCount(): void {
		// Rollen- und Konten-Gruppen aus Fassung 1 und fremde Werte: kein Team.
		foreach (['pb-mitarbeitende', 'pb-admin', 'pb-konten', 'xx-zeit'] as $gid) {
			$this->assertSame('403 no_team', self::code(fn () => MembershipResolver::resolve([$gid], [$gid], self::ROWS, [])), $gid);
		}
	}

	public function testAmbiguousTeam(): void {
		$this->assertSame('409 ambiguous_team', self::code(fn () => MembershipResolver::resolve(['pb-team', 'at-team'], [], self::ROWS, [])));
		$this->assertSame('409 ambiguous_team', self::code(fn () => MembershipResolver::resolve(['pb-team'], ['at-team'], self::ROWS, [])));
	}

	public function testLeftIsNoTeam(): void {
		$left = [1 => ['role' => 'lead', 'left_at' => 1790000000]];
		$this->assertSame('403 no_team', self::code(fn () => MembershipResolver::resolve(['pb-team'], [], self::ROWS, $left)));
		// Auch ein Admin, dessen Austritt vermerkt ist.
		$this->assertSame('403 no_team', self::code(fn () => MembershipResolver::resolve(['pb-team'], ['pb-team'], self::ROWS, $left)));
		// Wieder aufgenommen: die Rolle ist wieder da.
		$back = [1 => ['role' => 'lead', 'left_at' => null]];
		$this->assertSame(['tenant_id' => 1, 'role' => 'lead'], MembershipResolver::resolve(['pb-team'], [], self::ROWS, $back));
	}

	public function testTeamGroups(): void {
		$this->assertSame([1 => 'pb-team', 2 => 'at-team'], MembershipResolver::teamGroups(self::ROWS));
	}

	public function testMembersListsGroupAndAdminsWithLeft(): void {
		$m = MembershipResolver::members(
			['carol', 'alice', 'bob', 'dora'],
			['erin', 'alice'],
			['bob' => ['role' => 'lead', 'left_at' => null], 'dora' => ['role' => 'subadmin', 'left_at' => 50], 'nur-eintrag' => ['role' => 'lead', 'left_at' => null]],
		);
		$this->assertSame([
			'alice' => ['role' => 'admin', 'left_at' => null],
			'bob' => ['role' => 'lead', 'left_at' => null],
			'carol' => ['role' => 'user', 'left_at' => null],
			'dora' => ['role' => 'subadmin', 'left_at' => 50],
			'erin' => ['role' => 'admin', 'left_at' => null],
		], $m, 'nach Kennung, Admins auch ausserhalb der Gruppe, Einträge ohne Gruppe fehlen');
		$this->assertSame(['alice' => 'admin', 'bob' => 'lead', 'carol' => 'user', 'erin' => 'admin'], MembershipResolver::activeRoles($m));
	}

	public function testRoleOrder(): void {
		$this->assertSame(['user', 'lead', 'subadmin', 'admin'], Role::ALL);
		$this->assertSame(['user', 'lead', 'subadmin'], Role::APP_ROLES);
		$this->assertSame('admin', Role::stronger('admin', 'subadmin'));
		$this->assertSame('lead', Role::stronger('user', 'lead'));
		$this->assertFalse(Role::manages('lead'));
		$this->assertTrue(Role::manages('subadmin'));
		$this->assertTrue(Role::readsAll('lead'));
		$this->assertFalse(Role::readsAll('user'));
	}
}
