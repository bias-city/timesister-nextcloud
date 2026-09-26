<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\ApiException;
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
			'einfach' => ['alice', true],
			'Mail' => ['alice.muster@example.org', true],
			'Plus und Minus' => ['a+b-c_d', true],
			'128 Zeichen' => [str_repeat('a', 128), true],
			'ein Zeichen' => ['x', true],
			'129 Zeichen' => [str_repeat('a', 129), false],
			'leer' => ['', false],
			'Leerzeichen' => ['a b', false],
			'Schrägstrich' => ['a/b', false],
			'Punkte-Pfad' => ['../x', false],
			'Umlaut' => ['müller', false],
			'Doppelpunkt' => ['a:b', false],
		];
	}

	#[DataProvider('keys')]
	public function testKeys(string $key, bool $ok): void {
		$this->assertSame($ok, RecordValidator::isKey($key));
	}

	public function testAddress(): void {
		$this->assertSame('ok', self::code(fn () => RecordValidator::checkAddress('project', 'D200')));
		$this->assertSame('400 invalid', self::code(fn () => RecordValidator::checkAddress('budget', 'D200')));
		$this->assertSame('400 invalid', self::code(fn () => RecordValidator::checkAddress('project', 'a b')));
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
			$this->assertSame('ok', self::code(fn () => RecordValidator::validate($kind, 'K1', self::obj('{"id":"K1"}'))), $kind);
			$this->assertSame('422 invalid', self::code(fn () => RecordValidator::validate($kind, 'K1', self::obj('{"id":"K2"}'))), $kind);
			$this->assertSame('422 invalid', self::code(fn () => RecordValidator::validate($kind, '4711', self::obj('{"id":4711}'))), $kind);
			$this->assertSame('422 invalid', self::code(fn () => RecordValidator::validate($kind, 'K1', self::obj('{}'))), $kind);
		}
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
		// Bei anderen Arten ist `accounts` nur Inhalt.
		$this->assertSame([], RecordValidator::validate('project', 'P', self::obj('{"id":"P","accounts":["x"]}'))['accounts']);
	}

	public function testEmptyObjectsStayObjects(): void {
		$r = RecordValidator::validate('project', 'P', self::obj('{"id":"P","mapping":{},"list":[],"n":42.0,"s":"ü/ä"}'));
		$this->assertSame('{"id":"P","mapping":{},"list":[],"n":42.0,"s":"ü/ä"}', $r['json']);
		$this->assertEquals(self::obj($r['json']), self::obj('{"id":"P","mapping":{},"list":[],"n":42.0,"s":"ü/ä"}'));
	}

	public function testSizeLimit(): void {
		$pad = str_repeat('x', RecordValidator::MAX_DATA_BYTES - strlen('{"id":"P","pad":""}'));
		$this->assertSame('ok', self::code(fn () => RecordValidator::validate('project', 'P', self::obj('{"id":"P","pad":"' . $pad . '"}'))));
		$this->assertSame('413 too_large', self::code(fn () => RecordValidator::validate('project', 'P', self::obj('{"id":"P","pad":"' . $pad . 'y"}'))));
	}

	public function testBody(): void {
		$this->assertSame('ok', self::code(fn () => Json::body('{"version":1}', 100)));
		$this->assertSame('413 too_large', self::code(fn () => Json::body('{"version":1}', 5)));
		$this->assertSame('400 invalid', self::code(fn () => Json::body('', 100)));
		$this->assertSame('400 invalid', self::code(fn () => Json::body('{', 100)));
		$this->assertSame('400 invalid', self::code(fn () => Json::body('[1]', 100)));
	}
}
