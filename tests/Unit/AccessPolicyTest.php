<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\AccessPolicy;
use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\Membership;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Every row of the permissions table from API.md. */
class AccessPolicyTest extends TestCase {
	private AccessPolicy $p;

	protected function setUp(): void {
		$this->p = new AccessPolicy();
	}

	private static function as(string $role, string $uid = 'mia'): Membership {
		return new Membership($uid, 1, $role);
	}

	/**
	 * [role, kind, key, accounts, may read]
	 *
	 * @return array<string,array{string,string,string,list<string>,bool}>
	 */
	public static function readMatrix(): array {
		$rows = [];
		foreach (['user', 'lead', 'admin'] as $role) {
			foreach (['setting', 'region', 'project'] as $kind) {
				$rows["$role reads $kind"] = [$role, $kind, 'x', [], true];
			}
			$rows["$role reads own person (key)"] = [$role, 'person', 'mia', [], true];
			$rows["$role reads own person (accounts)"] = [$role, 'person', 'mia.muster@example.org', ['mia'], true];
			$others = $role !== 'user';
			$rows["$role reads another person"] = [$role, 'person', 'noah', ['noah'], $others];
			$rows["$role reads customer"] = [$role, 'customer', 'K1', [], $others];
		}
		return $rows;
	}

	/** @param list<string> $accounts */
	#[DataProvider('readMatrix')]
	public function testRead(string $role, string $kind, string $key, array $accounts, bool $allowed): void {
		$this->assertSame($allowed, $this->p->canRead(self::as($role), $kind, $key, $accounts));
	}

	public function testOwnPersonNeedsExactAccount(): void {
		$m = self::as('user');
		$this->assertFalse($this->p->canRead($m, 'person', 'mia2', ['mia2', 'miа']));
		$this->assertFalse($this->p->canRead($m, 'person', 'Mia', []));
		$this->assertTrue(AccessPolicy::isOwnPerson('mia', 'x', ['a', 'mia']));
	}

	/** @return array<string,array{string,bool}> */
	public static function writeMatrix(): array {
		return [
			'user does not write' => ['user', false],
			'lead does not write' => ['lead', false],
			'Team Admin writes' => ['admin', true],
		];
	}

	#[DataProvider('writeMatrix')]
	public function testWrite(string $role, bool $allowed): void {
		$this->assertSame($allowed, $this->p->canWrite(self::as($role)));
		if (!$allowed) {
			try {
				$this->p->requireWrite(self::as($role));
				$this->fail('403 expected');
			} catch (ApiException $e) {
				$this->assertSame(403, $e->getStatus());
				$this->assertSame('forbidden', $e->getErrorCode());
			}
		} else {
			$this->p->requireWrite(self::as($role));
		}
	}

	/** @return array<string,array{string,string,string,list<string>,bool}> */
	public static function historyMatrix(): array {
		return [
			'user: own person' => ['user', 'person', 'mia', [], true],
			'user: own person via accounts' => ['user', 'person', 'm.m', ['mia'], true],
			'user: another person' => ['user', 'person', 'noah', [], false],
			'user: project' => ['user', 'project', 'P1', [], false],
			'lead: own person' => ['lead', 'person', 'mia', [], true],
			'lead: another person' => ['lead', 'person', 'noah', [], false],
			'lead: customer' => ['lead', 'customer', 'K1', [], false],
			'Team Admin: another person' => ['admin', 'person', 'noah', [], true],
			'Team Admin: project' => ['admin', 'project', 'P1', [], true],
			'Team Admin: customer' => ['admin', 'customer', 'K1', [], true],
		];
	}

	/** @param list<string> $accounts */
	#[DataProvider('historyMatrix')]
	public function testHistory(string $role, string $kind, string $key, array $accounts, bool $allowed): void {
		$this->assertSame($allowed, $this->p->canReadHistory(self::as($role), $kind, $key, $accounts));
	}

	public function testBackups(): void {
		foreach (['user', 'lead'] as $role) {
			$this->assertTrue($this->p->canSeeBackupsOf(self::as($role), 'mia'), "$role own");
			$this->assertFalse($this->p->canSeeBackupsOf(self::as($role), 'noah'), "$role other's");
		}
		foreach (['admin'] as $role) {
			$this->assertTrue($this->p->canSeeBackupsOf(self::as($role), 'mia'), "$role own");
			$this->assertTrue($this->p->canSeeBackupsOf(self::as($role), 'noah'), "$role other's");
		}
	}

	public function testTeamAndStatus(): void {
		$this->assertFalse($this->p->canReadTeam(self::as('user')));
		$this->assertFalse($this->p->canReadTeam(self::as('lead')));
		$this->assertTrue($this->p->canReadTeam(self::as('admin')));
		$this->expectException(ApiException::class);
		$this->p->requireTeamRead(self::as('lead'));
	}
}
