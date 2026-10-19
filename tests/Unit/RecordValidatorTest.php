<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\BillingRules;
use OCA\TimeSister\Service\Json;
use OCA\TimeSister\Service\RecordValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RecordValidatorTest extends TestCase {
	private static function obj(string $json): mixed {
		return Json::decode($json);
	}

	private static function code(callable $fn): string {
		try {
			$fn();
		} catch (ApiException $e) {
			return $e->getStatus() . ' ' . $e->getErrorCode();
		}
		return 'ok';
	}

	/** @return array<string,array{string,bool}> */
	public static function keys(): array {
		return [
			'simple' => ['alice', true],
			'mail' => ['alice.muster@example.org', true],
			'plus and minus' => ['a+b-c_d', true],
			'128 characters' => [str_repeat('a', 128), true],
			'one character' => ['x', true],
			'129 characters' => [str_repeat('a', 129), false],
			'empty' => ['', false],
			'space inside' => ['Anna Muster', true],
			'apostrophe' => ["o'neil", true],
			'space at the start' => [' ab', false],
			'space at the end' => ['ab ', false],
			'slash' => ['a/b', false],
			'dot path' => ['../x', false],
			'umlaut' => ['müller', false],
			'colon' => ['a:b', false],
		];
	}

	#[DataProvider('keys')]
	public function testKeys(string $key, bool $ok): void {
		$this->assertSame($ok, RecordValidator::isKey($key));
	}

	public function testAddress(): void {
		$this->assertSame('ok', self::code(fn () => RecordValidator::checkAddress('project', 'D200')));
		$this->assertSame('400 invalid', self::code(fn () => RecordValidator::checkAddress('budget', 'D200')));
		$this->assertSame('400 invalid', self::code(fn () => RecordValidator::checkAddress('project', 'a/b')));
	}

	public function testVersion(): void {
		$this->assertSame(0, RecordValidator::checkVersion(0));
		$this->assertSame(7, RecordValidator::checkVersion(7));
		$this->assertSame(7, RecordValidator::checkVersion('7'));
		$this->assertSame('400 invalid', self::code(fn () => RecordValidator::checkVersion(-1)));
		$this->assertSame('400 invalid', self::code(fn () => RecordValidator::checkVersion(1.5)));
		$this->assertSame('400 invalid', self::code(fn () => RecordValidator::checkVersion(null)));
		$this->assertSame('400 invalid', self::code(fn () => RecordValidator::checkVersion(true)));
		$this->assertSame('400 invalid', self::code(fn () => RecordValidator::checkVersion('x')));
	}

	public function testKeyFields(): void {
		$this->assertSame('ok', self::code(fn () => RecordValidator::validate('person', 'alice', self::obj('{"login":"alice"}'))));
		$this->assertSame('422 invalid', self::code(fn () => RecordValidator::validate('person', 'alice', self::obj('{"login":"bob"}'))));
		$this->assertSame('422 invalid', self::code(fn () => RecordValidator::validate('person', 'alice', self::obj('{"id":"alice"}'))));
		foreach (['region', 'project', 'customer'] as $kind) {
			$this->assertSame('ok', self::code(fn () => RecordValidator::validate($kind, 'K1', self::obj('{"id":"K1","codes":["K1"]}'))), $kind);
			$this->assertSame('422 invalid', self::code(fn () => RecordValidator::validate($kind, 'K1', self::obj('{"id":"K2"}'))), $kind);
			$this->assertSame('422 invalid', self::code(fn () => RecordValidator::validate($kind, '4711', self::obj('{"id":4711}'))), $kind);
			$this->assertSame('422 invalid', self::code(fn () => RecordValidator::validate($kind, 'K1', self::obj('{}'))), $kind);
		}
	}

	/** One project, one code (0.12.0). */
	public function testProjectOneCode(): void {
		$this->assertSame('ok', self::code(fn () => RecordValidator::validate('project', 'd200', self::obj('{"id":"d200","codes":["D200"]}'))));
		$this->assertSame('422 invalid', self::code(fn () => RecordValidator::validate('project', 'd200', self::obj('{"id":"d200","codes":[]}'))), 'no status: as active, needs a code (0.16.0)');
		$this->assertSame('422 invalid', self::code(fn () => RecordValidator::validate('project', 'd200', self::obj('{"id":"d200","status":"active"}'))));
		$this->assertSame('ok', self::code(fn () => RecordValidator::validate('project', 'd200', self::obj('{"id":"d200","status":"draft","codes":[]}'))), 'a quote has no code yet');
		$this->assertSame('ok', self::code(fn () => RecordValidator::validate('project', 'd200', self::obj('{"id":"d200","status":"quoted"}'))));
		$this->assertSame('422 invalid', self::code(fn () => RecordValidator::validate('project', 'd200', self::obj('{"id":"d200","status":"draft","codes":["D200","4711"]}'))), 'two codes: never');
		$this->assertSame('422 invalid', self::code(fn () => RecordValidator::validate('project', 'd200', self::obj('{"id":"d200","codes":["D200","4711"]}'))));
		$this->assertSame('ok', self::code(fn () => RecordValidator::validate('customer', 'k1', self::obj('{"id":"k1","codes":["A","B"]}'))));
	}

	/** Billing marks (0.7.6): person and uid, the derived key, the date, the optional texts. */
	public function testBilling(): void {
		$key = BillingRules::key('alice', 'abc-123@example.test');
		$this->assertMatchesRegularExpression('/^alice\+[0-9a-f]{32}$/', $key);
		$this->assertTrue(RecordValidator::isKey($key));
		$this->assertSame('alice', BillingRules::personOf($key));
		$this->assertNull(BillingRules::personOf('alice'));
		$this->assertNull(BillingRules::personOf('+' . str_repeat('a', 32)));
		$this->assertNull(BillingRules::personOf('alice+' . str_repeat('g', 32)));
		$this->assertSame('a+b', BillingRules::personOf('a+b+' . str_repeat('0', 32)), 'the last + separates');
		$ok = '{"person":"alice","uid":"abc-123@example.test","billed_on":"2026-09-30","by":"petra","checksum":"ff","source":"manual"}';
		$this->assertSame('ok', self::code(fn () => RecordValidator::validate('billing', $key, self::obj($ok))));
		$this->assertSame('422 invalid', self::code(fn () => RecordValidator::validate('billing', 'alice+' . str_repeat('0', 32), self::obj($ok))), 'key does not match');
		$this->assertSame('422 invalid', self::code(fn () => RecordValidator::validate('billing', $key, self::obj('{"person":"alice","uid":"abc-123@example.test"}'))), 'no date');
		$this->assertSame('422 invalid', self::code(fn () => RecordValidator::validate('billing', $key, self::obj('{"person":"alice","uid":"abc-123@example.test","billed_on":"2026-02-30"}'))), 'no such day');
		$this->assertSame('422 invalid', self::code(fn () => RecordValidator::validate('billing', $key, self::obj('{"person":"alice","uid":"","billed_on":"2026-09-30"}'))), 'empty uid');
		$this->assertSame('422 invalid', self::code(fn () => RecordValidator::validate('billing', $key, self::obj('{"uid":"abc-123@example.test","billed_on":"2026-09-30"}'))), 'no person');
		$long = '{"person":"alice","uid":"abc-123@example.test","billed_on":"2026-09-30","external_id":"' . str_repeat('x', 257) . '"}';
		$this->assertSame('422 invalid', self::code(fn () => RecordValidator::validate('billing', $key, self::obj($long))), 'text too long');
		$this->assertSame('422 invalid', self::code(fn () => RecordValidator::validate('billing', $key, self::obj('{"person":"alice","uid":"abc-123@example.test","billed_on":"2026-09-30","document":7}'))), 'text not text');
		$this->assertSame('422 invalid', self::code(fn () => BillingRules::requirePerson('alice')));
		$this->assertSame('alice', BillingRules::requirePerson($key));

		// 0.14.0: billed only by Team Admins; read by them and the person.
		$this->assertTrue(BillingRules::canWrite('admin'));
		$this->assertFalse(BillingRules::canWrite('lead'));
		$this->assertFalse(BillingRules::canWrite('user'));
		$this->assertTrue(BillingRules::canRead('user', true));
		$this->assertTrue(BillingRules::canRead('lead', true));
		$this->assertFalse(BillingRules::canRead('user', false));
		$this->assertFalse(BillingRules::canRead('lead', false));
		$this->assertTrue(BillingRules::canRead('admin', false));
	}

	public function testSettings(): void {
		$this->assertSame('ok', self::code(fn () => RecordValidator::validate('setting', 'targethours', self::obj('{"entries":[]}'))));
		$this->assertSame('ok', self::code(fn () => RecordValidator::validate('setting', 'settings', self::obj('{}'))));
		$this->assertSame('422 invalid', self::code(fn () => RecordValidator::validate('setting', 'other', self::obj('{}'))));
	}

	public function testDataMustBeObject(): void {
		$this->assertSame('422 invalid', self::code(fn () => RecordValidator::validate('setting', 'settings', self::obj('[]'))));
		$this->assertSame('422 invalid', self::code(fn () => RecordValidator::validate('setting', 'settings', self::obj('"x"'))));
		$this->assertSame('422 invalid', self::code(fn () => RecordValidator::validate('setting', 'settings', null)));
	}

	public function testAccounts(): void {
		$r = RecordValidator::validate('person', 'a.m', self::obj('{"login":"a.m","accounts":["alice","alice","al"]}'));
		$this->assertSame(['alice', 'al'], $r['accounts']);
		$this->assertSame([], RecordValidator::validate('person', 'a', self::obj('{"login":"a"}'))['accounts']);
		$this->assertSame([], RecordValidator::validate('person', 'a', self::obj('{"login":"a","accounts":null}'))['accounts']);
		$this->assertSame('422 invalid', self::code(fn () => RecordValidator::validate('person', 'a', self::obj('{"login":"a","accounts":"alice"}'))));
		$this->assertSame('422 invalid', self::code(fn () => RecordValidator::validate('person', 'a', self::obj('{"login":"a","accounts":[1]}'))));
		$this->assertSame('422 invalid', self::code(fn () => RecordValidator::validate('person', 'a', self::obj('{"login":"a","accounts":{"x":"alice"}}'))));
		// For other kinds, `accounts` is just content.
		$this->assertSame([], RecordValidator::validate('project', 'P', self::obj('{"id":"P","codes":["P"],"accounts":["x"]}'))['accounts']);
	}

	/** Cost rates on the person (0.16.0): a list of {from, rate}, valid from a day on, each day once. */
	public function testCostRates(): void {
		$ok = fn (string $rates) => RecordValidator::validate('person', 'a', self::obj('{"login":"a","cost_rates":' . $rates . '}'))['json'];
		$this->assertSame('{"login":"a","cost_rates":[{"from":"2026-01-01","rate":95},{"from":"2026-07-01","rate":110.5},{"from":"2025-01-01","rate":0}]}',
			$ok('[{"from":"2026-01-01","rate":95},{"from":"2026-07-01","rate":110.5},{"from":"2025-01-01","rate":0}]'), 'any order, kept as sent');
		$this->assertSame('{"login":"a","cost_rates":[]}', $ok('[]'));
		$this->assertSame('{"login":"a","cost_rates":null}', $ok('null'));
		$this->assertSame('{"login":"a","cost_rates":[{"from":"2026-01-01","rate":95,"note":"kept"}]}', $ok('[{"from":"2026-01-01","rate":95,"note":"kept"}]'), 'other fields pass');
		$bad = fn (string $rates) => self::message(fn () => RecordValidator::validate('person', 'a', self::obj('{"login":"a","cost_rates":' . $rates . '}')));
		foreach (['{}', '"95"', '95', '{"2026-01-01":95}'] as $v) {
			$this->assertSame('422 “cost_rates” must be a list of { from, rate }.', $bad($v), $v);
		}
		foreach (['[95]', '[null]', '[{"rate":95}]', '[{"from":"2026-01-01"}]', '[{"from":"2026-02-30","rate":95}]', '[{"from":"1.1.2026","rate":95}]',
			'[{"from":"2026-01-01","rate":-1}]', '[{"from":"2026-01-01","rate":"95"}]', '[{"from":"2026-01-01","rate":null}]', '[{"from":20260101,"rate":95}]'] as $v) {
			$this->assertSame('422 Each entry of “cost_rates” needs a day “from” (YYYY-MM-DD) and a “rate” ≥ 0.', $bad($v), $v);
		}
		$this->assertSame('422 “cost_rates” names the day 2026-01-01 twice.', $bad('[{"from":"2026-01-01","rate":95},{"from":"2026-03-01","rate":99},{"from":"2026-01-01","rate":100}]'));
		$this->assertSame('ok', self::code(fn () => RecordValidator::validate('customer', 'k', self::obj('{"id":"k","cost_rates":"x"}'))), 'only persons are checked');
	}

	private static function message(callable $fn): string {
		try {
			$fn();
		} catch (ApiException $e) {
			return $e->getStatus() . ' ' . $e->getMessage();
		}
		return 'ok';
	}

	public function testEmptyObjectsStayObjects(): void {
		$r = RecordValidator::validate('project', 'P', self::obj('{"id":"P","codes":["P"],"mapping":{},"list":[],"n":42.0,"s":"ü/ä"}'));
		$this->assertSame('{"id":"P","codes":["P"],"mapping":{},"list":[],"n":42.0,"s":"ü/ä"}', $r['json']);
		$this->assertEquals(self::obj($r['json']), self::obj('{"id":"P","codes":["P"],"mapping":{},"list":[],"n":42.0,"s":"ü/ä"}'));
	}

	public function testSizeLimit(): void {
		$pad = str_repeat('x', RecordValidator::MAX_DATA_BYTES - strlen('{"id":"P","codes":["P"],"pad":""}'));
		$this->assertSame('ok', self::code(fn () => RecordValidator::validate('project', 'P', self::obj('{"id":"P","codes":["P"],"pad":"' . $pad . '"}'))));
		$this->assertSame('413 too_large', self::code(fn () => RecordValidator::validate('project', 'P', self::obj('{"id":"P","codes":["P"],"pad":"' . $pad . 'y"}'))));
	}

	public function testBody(): void {
		$this->assertSame('ok', self::code(fn () => Json::body('{"version":1}', 100)));
		$this->assertSame('413 too_large', self::code(fn () => Json::body('{"version":1}', 5)));
		$this->assertSame('400 invalid', self::code(fn () => Json::body('', 100)));
		$this->assertSame('400 invalid', self::code(fn () => Json::body('{', 100)));
		$this->assertSame('400 invalid', self::code(fn () => Json::body('[1]', 100)));
	}
}
