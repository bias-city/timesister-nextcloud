<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\AccessRules;
use OCA\TimeSister\Service\ApiException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The shares matrix: defaults, overriding, effective, share targets, permissions. */
class AccessRulesTest extends TestCase {
	private const ROLES = ['petra' => 'admin', 'paul' => 'admin', 'lea' => 'lead', 'mia' => 'user', 'noah' => 'user'];

	private static function code(callable $fn): string {
		try {
			$fn();
		} catch (ApiException $e) {
			return $e->getStatus() . ' ' . $e->getErrorCode();
		}
		return 'ok';
	}

	/** @return array<string,array<string,array<string,array{level:string,at:int,by:string}>>> */
	private static function entries(array $admin = [], array $self = []): array {
		$out = ['admin' => [], 'self' => []];
		foreach (['admin' => $admin, 'self' => $self] as $source => $list) {
			foreach ($list as [$viewer, $owner, $level]) {
				$out[$source][$viewer][$owner] = ['level' => $level, 'at' => 100, 'by' => 'petra'];
			}
		}
		return $out;
	}

	public function testDefaultsTeamAdminsEditEverythingElseNone(): void {
		$e = self::entries();
		$this->assertSame('edit', AccessRules::field('petra', 'mia', self::ROLES, [], $e)['level']);
		$this->assertSame('edit', AccessRules::field('paul', 'petra', self::ROLES, [], $e)['level']);
		$this->assertSame('none', AccessRules::field('lea', 'mia', self::ROLES, [], $e)['level']);
		$this->assertSame('none', AccessRules::field('mia', 'noah', self::ROLES, [], $e)['level']);
		$this->assertNull(AccessRules::field('lea', 'mia', self::ROLES, [], $e)['changed_at']);
	}

	public function testTeamAdminEntryReplacesDefault(): void {
		$e = self::entries([['lea', 'mia', 'view'], ['petra', 'noah', 'none']]);
		$f = AccessRules::field('lea', 'mia', self::ROLES, [], $e);
		$this->assertSame(['view', 'view', null, null, false, 100, 'petra'], array_values($f));
		$this->assertSame('none', AccessRules::field('petra', 'noah', self::ROLES, [], $e)['level']);
	}

	public function testOwnChoiceOnlyWhileAllowed(): void {
		$e = self::entries([['lea', 'mia', 'view']], [['lea', 'mia', 'none'], ['noah', 'mia', 'edit']]);
		// Not allowed: the choice rests, the Team Admin's applies.
		$f = AccessRules::field('lea', 'mia', self::ROLES, ['mia' => false], $e);
		$this->assertSame('view', $f['level']);
		$this->assertSame('none', $f['self_level']);
		$this->assertTrue($f['resting']);
		$this->assertNull($f['overridden']);
		// Allowed: the own choice wins, restricting and granting.
		$f = AccessRules::field('lea', 'mia', self::ROLES, ['mia' => true], $e);
		$this->assertSame(['none', 'view', 'restricted', false], [$f['level'], $f['admin_level'], $f['overridden'], $f['resting']]);
		$g = AccessRules::field('noah', 'mia', self::ROLES, ['mia' => true], $e);
		$this->assertSame(['edit', 'granted'], [$g['level'], $g['overridden']]);
		// Even a Team Admin can be shut out, if allowed.
		$h = AccessRules::field('petra', 'mia', self::ROLES, ['mia' => true], self::entries([], [['petra', 'mia', 'none']]));
		$this->assertSame(['none', 'restricted'], [$h['level'], $h['overridden']]);
	}

	public function testShareTargets(): void {
		$e = self::entries([['lea', 'mia', 'view'], ['paul', 'mia', 'none']], [['noah', 'mia', 'edit']]);
		$this->assertSame([
			['uid' => 'lea', 'access' => 'read'],
			['uid' => 'petra', 'access' => 'write'],
		], AccessRules::shareTargets('mia', self::ROLES, ['mia' => false], $e));
		$this->assertSame([
			['uid' => 'lea', 'access' => 'read'],
			['uid' => 'noah', 'access' => 'write'],
			['uid' => 'petra', 'access' => 'write'],
		], AccessRules::shareTargets('mia', self::ROLES, ['mia' => true], $e));
		// Never to oneself; a Team Admin's calendar goes to the other Team Admin.
		$this->assertSame([['uid' => 'paul', 'access' => 'write']], AccessRules::shareTargets('petra', self::ROLES, [], self::entries()));
	}

	public function testEffectiveAndPending(): void {
		$this->assertNull(AccessRules::effective('lea', 'view', null), 'never reported');
		$this->assertTrue(AccessRules::pending('view', null));
		$this->assertFalse(AccessRules::pending('none', null));
		$this->assertTrue(AccessRules::effective('lea', 'view', ['lea' => 'read']));
		$this->assertFalse(AccessRules::effective('lea', 'edit', ['lea' => 'read']));
		$this->assertTrue(AccessRules::effective('lea', 'none', []));
		$this->assertFalse(AccessRules::effective('lea', 'none', ['lea' => 'write']), 'withdrawal not yet set');
		$this->assertTrue(AccessRules::pending('none', false));
	}

	/** @return array<string,array{string,string,string,string,array<string,bool>,string}> */
	public static function permissions(): array {
		return [
			'Team Admin, any field' => ['petra', 'admin', 'lea', 'mia', [], 'admin'],
			'Team Admin, own column' => ['petra', 'admin', 'lea', 'petra', [], 'admin'],
			'owner, allowed' => ['mia', 'user', 'lea', 'mia', ['mia' => true], 'self'],
			'owner, not allowed' => ['mia', 'user', 'lea', 'mia', [], '403 forbidden'],
			'Lead, another column' => ['lea', 'lead', 'noah', 'mia', ['lea' => true], '403 forbidden'],
			'User, own row (whom I see)' => ['mia', 'user', 'mia', 'noah', ['mia' => true], '403 forbidden'],
			'diagonal' => ['petra', 'admin', 'mia', 'mia', [], '422 invalid'],
			'outside the team' => ['petra', 'admin', 'atuser1', 'mia', [], '404 not_found'],
		];
	}

	/** @param array<string,bool> $flags */
	#[DataProvider('permissions')]
	public function testAuthorize(string $actor, string $role, string $viewer, string $owner, array $flags, string $want): void {
		$got = self::code(function () use ($actor, $role, $viewer, $owner, $flags, &$source) {
			$source = AccessRules::authorize($actor, $role, $viewer, $owner, self::ROLES, $flags);
		});
		$this->assertSame($want, $got === 'ok' ? $source : $got);
	}

	public function testValidate(): void {
		foreach (['none', 'view', 'edit', 'default'] as $l) {
			$this->assertSame($l, AccessRules::validateLevel($l));
		}
		foreach (['read', 'write', '', null, 1] as $bad) {
			$this->assertSame('422 invalid', self::code(fn () => AccessRules::validateLevel($bad)));
		}
		$this->assertSame([['viewer' => 'lea', 'owner' => 'mia', 'level' => 'view']],
			AccessRules::validateChanges([['viewer' => 'lea', 'owner' => 'mia', 'level' => 'view']]));
		$this->assertSame('422 invalid', self::code(fn () => AccessRules::validateChanges([])));
		$this->assertSame('422 invalid', self::code(fn () => AccessRules::validateChanges([['viewer' => 'lea', 'level' => 'view']])));
		$this->assertSame('413 too_large', self::code(fn () => AccessRules::validateChanges(array_fill(0, AccessRules::MAX_CHANGES + 1, ['viewer' => 'a', 'owner' => 'b', 'level' => 'none']))));
	}

	public function testAccessWords(): void {
		$this->assertSame('read', AccessRules::access('view'));
		$this->assertSame('write', AccessRules::access('edit'));
		$this->assertNull(AccessRules::access('none'));
		$this->assertSame('edit', AccessRules::levelOf('write'));
		$this->assertSame('none', AccessRules::levelOf(null));
	}
}
