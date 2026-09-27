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
		$this->assertSame(['left' => true], MemberRules::validate(['left' => true]));
		$this->assertSame(['role' => 'user', 'left' => false], MemberRules::validate(['role' => 'user', 'left' => false]));
		$this->assertSame('400 invalid', self::code(fn () => MemberRules::validate([])));
		foreach (['admin', 'boss', '', 1, null] as $bad) {
			$this->assertSame('422 invalid', self::code(fn () => MemberRules::validate(['role' => $bad])), var_export($bad, true));
		}
		$this->assertSame('422 invalid', self::code(fn () => MemberRules::validate(['left' => 'ja'])));
		$this->assertSame('422 invalid', self::code(fn () => MemberRules::validate(['left' => 1])));
	}

	/**
	 * [caller's role, account's current role, change, outcome]
	 *
	 * @return array<string,array{string,string,array{role?:string,left?:bool},string}>
	 */
	public static function matrix(): array {
		return [
			'user sets lead' => ['user', 'user', ['role' => 'lead'], '403 forbidden'],
			'user records a departure' => ['user', 'user', ['left' => true], '403 forbidden'],
			'lead sets lead' => ['lead', 'user', ['role' => 'lead'], '403 forbidden'],
			'subadmin sets lead' => ['subadmin', 'user', ['role' => 'lead'], 'ok'],
			'subadmin withdraws lead' => ['subadmin', 'lead', ['role' => 'user'], 'ok'],
			'subadmin records a user\'s departure' => ['subadmin', 'user', ['left' => true], 'ok'],
			'subadmin sets subadmin' => ['subadmin', 'user', ['role' => 'subadmin'], '403 forbidden'],
			'subadmin changes a subadmin' => ['subadmin', 'subadmin', ['role' => 'user'], '403 forbidden'],
			'subadmin records an admin\'s departure' => ['subadmin', 'admin', ['left' => true], '403 forbidden'],
			'admin sets subadmin' => ['admin', 'user', ['role' => 'subadmin'], 'ok'],
			'admin withdraws subadmin' => ['admin', 'subadmin', ['role' => 'lead'], 'ok'],
			'admin records an admin\'s departure' => ['admin', 'admin', ['left' => true], 'ok'],
		];
	}

	/** @param array{role?:string,left?:bool} $change */
	#[DataProvider('matrix')]
	public function testAuthorize(string $actor, string $target, array $change, string $want): void {
		$this->assertSame($want, self::code(fn () => MemberRules::authorize('ich', $actor, 'du', $target, $change)));
	}

	public function testOwnLeaveIsForbidden(): void {
		$this->assertSame('403 forbidden', self::code(fn () => MemberRules::authorize('ich', 'admin', 'ich', 'admin', ['left' => true])));
		$this->assertSame('ok', self::code(fn () => MemberRules::authorize('ich', 'admin', 'ich', 'admin', ['role' => 'lead'])));
	}

	public function testManagerFirst(): void {
		$this->assertSame('403 forbidden', self::code(fn () => MemberRules::requireManager('lead')));
		$this->assertSame('ok', self::code(fn () => MemberRules::requireManager('subadmin')));
	}
}
