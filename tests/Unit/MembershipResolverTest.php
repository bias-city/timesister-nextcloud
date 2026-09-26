<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\MembershipResolver;
use OCA\TimeSister\Service\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MembershipResolverTest extends TestCase {
	/** Zwei Teams wie in docker-next/aufsetzen.sh. */
	private const ROWS = [
		['tenant_id' => 1, 'role' => 'user', 'gid' => 'pb-mitarbeitende'],
		['tenant_id' => 1, 'role' => 'lead', 'gid' => 'pb-leitung'],
		['tenant_id' => 1, 'role' => 'subadmin', 'gid' => 'pb-verwaltung'],
		['tenant_id' => 1, 'role' => 'admin', 'gid' => 'pb-admin'],
		['tenant_id' => 2, 'role' => 'user', 'gid' => 'at-mitarbeitende'],
		['tenant_id' => 2, 'role' => 'lead', 'gid' => 'at-leitung'],
		['tenant_id' => 2, 'role' => 'subadmin', 'gid' => 'at-verwaltung'],
		['tenant_id' => 2, 'role' => 'admin', 'gid' => 'at-admin'],
	];

	/** @return array<string,array{list<string>,int,string}> */
	public static function members(): array {
		return [
			'nur Mitarbeitende' => [['pb-mitarbeitende'], 1, 'user'],
			'Leitung' => [['pb-mitarbeitende', 'pb-leitung'], 1, 'lead'],
			'Verwaltung schlägt Leitung' => [['pb-leitung', 'pb-verwaltung'], 1, 'subadmin'],
			'Admin schlägt alles' => [['pb-verwaltung', 'pb-admin', 'pb-mitarbeitende', 'pb-leitung'], 1, 'admin'],
			'fremde Gruppen stören nicht' => [['admin', 'Familie', 'at-leitung'], 2, 'lead'],
			'nur Admin-Gruppe' => [['at-admin'], 2, 'admin'],
		];
	}

	/** @param list<string> $groups */
	#[DataProvider('members')]
	public function testStrongestRoleInOneTeam(array $groups, int $tenant, string $role): void {
		$r = MembershipResolver::resolve($groups, self::ROWS);
		$this->assertSame(['tenant_id' => $tenant, 'role' => $role], $r);
	}

	public function testNoTeam(): void {
		try {
			MembershipResolver::resolve(['admin', 'andere'], self::ROWS);
			$this->fail('no_team erwartet');
		} catch (ApiException $e) {
			$this->assertSame(403, $e->getStatus());
			$this->assertSame('no_team', $e->getErrorCode());
		}
	}

	public function testNoGroupsAtAll(): void {
		$this->expectException(ApiException::class);
		MembershipResolver::resolve([], self::ROWS);
	}

	public function testNoTeamsConfigured(): void {
		$this->expectException(ApiException::class);
		MembershipResolver::resolve(['pb-admin'], []);
	}

	public function testAmbiguousTeam(): void {
		try {
			MembershipResolver::resolve(['pb-mitarbeitende', 'at-mitarbeitende'], self::ROWS);
			$this->fail('ambiguous_team erwartet');
		} catch (ApiException $e) {
			$this->assertSame(409, $e->getStatus());
			$this->assertSame('ambiguous_team', $e->getErrorCode());
		}
	}

	public function testUnknownRoleIsIgnored(): void {
		$rows = [['tenant_id' => 3, 'role' => 'boss', 'gid' => 'x']];
		$this->expectException(ApiException::class);
		MembershipResolver::resolve(['x'], $rows);
	}

	public function testStrongestRolesListsEachAccountOnce(): void {
		$roles = MembershipResolver::strongestRoles([
			'user' => ['carol', 'alice', 'bob', 'dora'],
			'lead' => ['alice'],
			'subadmin' => ['bob'],
			'admin' => ['bob', 'erin'],
		]);
		$this->assertSame(['alice' => 'lead', 'bob' => 'admin', 'carol' => 'user', 'dora' => 'user', 'erin' => 'admin'], $roles);
		$this->assertSame(
			['user' => ['carol', 'dora'], 'lead' => ['alice'], 'subadmin' => [], 'admin' => ['bob', 'erin']],
			MembershipResolver::membersByRole($roles),
		);
	}

	public function testRoleOrder(): void {
		$this->assertSame(['user', 'lead', 'subadmin', 'admin'], Role::ALL);
		$this->assertSame('admin', Role::stronger('admin', 'subadmin'));
		$this->assertSame('lead', Role::stronger('user', 'lead'));
		$this->assertFalse(Role::manages('lead'));
		$this->assertTrue(Role::manages('subadmin'));
		$this->assertTrue(Role::readsAll('lead'));
		$this->assertFalse(Role::readsAll('user'));
	}
}
