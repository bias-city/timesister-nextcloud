<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\MemberRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Permissions and body of PUT /team/members/{uid}. */
class MemberRulesTest extends TestCase {
	private static function code(callable $fn): string {
		try {
			$fn();
		} catch (ApiException $e) {
			return $e->getStatus() . ' ' . $e->getErrorCode();
		}
		return 'ok';
	}

	public function testValidate(): void {
		$this->assertSame(['role' => 'lead'], MemberRules::validate(['role' => 'lead']));
		$this->assertSame(['role' => 'admin'], MemberRules::validate(['role' => 'admin']));
		$this->assertSame(['left' => true], MemberRules::validate(['left' => true]));
		$this->assertSame(['may_override' => false], MemberRules::validate(['may_override' => false]));
		$this->assertSame(['role' => 'user', 'left' => false], MemberRules::validate(['role' => 'user', 'left' => false]));
		$this->assertSame('400 invalid', self::code(fn () => MemberRules::validate([])));
		foreach (['subadmin', 'boss', '', 1, null] as $bad) {
			$this->assertSame('422 invalid', self::code(fn () => MemberRules::validate(['role' => $bad])), var_export($bad, true));
		}
		$this->assertSame('422 invalid', self::code(fn () => MemberRules::validate(['left' => 'ja'])));
		$this->assertSame('422 invalid', self::code(fn () => MemberRules::validate(['may_override' => 1])));
	}

	/**
	 * [caller's role, account's current role, change, Team Admins in the team, outcome]
	 *
	 * @return array<string,array{string,string,array{role?:string,left?:bool,may_override?:bool},int,string}>
	 */
	public static function matrix(): array {
		return [
			'user sets lead' => ['user', 'user', ['role' => 'lead'], 1, '403 forbidden'],
			'user records a departure' => ['user', 'user', ['left' => true], 1, '403 forbidden'],
			'lead sets lead' => ['lead', 'user', ['role' => 'lead'], 1, '403 forbidden'],
			'lead allows overriding' => ['lead', 'user', ['may_override' => true], 1, '403 forbidden'],
			'former Manager sets lead' => ['subadmin', 'user', ['role' => 'lead'], 1, '403 forbidden'],
			'Team Admin sets lead' => ['admin', 'user', ['role' => 'lead'], 1, 'ok'],
			'Team Admin appoints a Team Admin' => ['admin', 'lead', ['role' => 'admin'], 1, 'ok'],
			'Team Admin allows overriding' => ['admin', 'user', ['may_override' => true], 1, 'ok'],
			'Team Admin demotes one of two Team Admins' => ['admin', 'admin', ['role' => 'lead'], 2, 'ok'],
			'Team Admin demotes the last Team Admin' => ['admin', 'admin', ['role' => 'user'], 1, '409 conflict'],
			'Team Admin records the departure of one of two' => ['admin', 'admin', ['left' => true], 2, 'ok'],
			'Team Admin records the departure of the last' => ['admin', 'admin', ['left' => true], 1, '409 conflict'],
			'last Team Admin stays Team Admin' => ['admin', 'admin', ['role' => 'admin', 'may_override' => true], 1, 'ok'],
		];
	}

	/** @param array{role?:string,left?:bool,may_override?:bool} $change */
	#[DataProvider('matrix')]
	public function testAuthorize(string $actor, string $target, array $change, int $admins, string $want): void {
		$this->assertSame($want, self::code(fn () => MemberRules::authorize('ich', $actor, 'du', $target, $change, $admins)));
	}

	public function testOwnRole(): void {
		$this->assertSame('403 forbidden', self::code(fn () => MemberRules::authorize('ich', 'admin', 'ich', 'admin', ['left' => true], 3)));
		$this->assertSame('ok', self::code(fn () => MemberRules::authorize('ich', 'admin', 'ich', 'admin', ['role' => 'lead'], 2)));
		// The last Team Admin cannot take the role from themselves.
		$this->assertSame('409 conflict', self::code(fn () => MemberRules::authorize('ich', 'admin', 'ich', 'admin', ['role' => 'lead'], 1)));
	}

	public function testTeamAdminFirst(): void {
		$this->assertSame('403 forbidden', self::code(fn () => MemberRules::requireTeamAdmin('lead')));
		$this->assertSame('403 forbidden', self::code(fn () => MemberRules::requireTeamAdmin('subadmin')));
		$this->assertSame('ok', self::code(fn () => MemberRules::requireTeamAdmin('admin')));
	}
}
