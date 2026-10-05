<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\PrivacyRules;
use OCA\TimeSister\Service\RecordValidator;
use PHPUnit\Framework\TestCase;

/** The record `setting/privacy` (0.10.2): what is accepted, what is 422, and the defaults. */
class PrivacyRulesTest extends TestCase {
	private static function code(callable $fn): string {
		try {
			$fn();
			return 'ok';
		} catch (ApiException $e) {
			return $e->getStatus() . ' ' . $e->getErrorCode();
		}
	}

	private static function obj(string $json): mixed {
		return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
	}

	public function testPrivacyIsASettingKey(): void {
		$this->assertContains('privacy', RecordValidator::SETTING_KEYS);
		$this->assertSame('ok', self::code(fn () => RecordValidator::validate('setting', 'privacy', self::obj('{}'))));
		$this->assertSame('422 invalid', self::code(fn () => RecordValidator::validate('setting', 'other', self::obj('{}'))));
	}

	public function testFullRecordIsValid(): void {
		$full = '{"schema":1,"controller":{"name":"B/IAS GmbH","address":"Basel","contact":"info@example.test"},"privacy_contact":"Petra",
			"hosting":{"provider":"Hostpoint","location":"CH"},"retention":{"time_years":5,"billing_years":10,"note":"n"},"law":"both","authority":"EDÖB"}';
		$this->assertSame('ok', self::code(fn () => RecordValidator::validate('setting', 'privacy', self::obj($full))));
		$this->assertSame('ok', self::code(fn () => RecordValidator::validate('setting', 'privacy', self::obj('{"controller":{"name":null},"retention":{"time_years":0}}'))));
	}

	public function testInvalidValuesAre422(): void {
		$long = str_repeat('x', 501);
		$cases = [
			'{"law":"us"}',
			'{"law":null}',
			'{"controller":"B/IAS"}',
			'{"controller":{"phone":"1"}}',
			'{"unknown":1}',
			'{"privacy_contact":' . json_encode($long) . '}',
			'{"controller":{"name":' . json_encode($long) . '}}',
			'{"retention":{"time_years":31}}',
			'{"retention":{"time_years":-1}}',
			'{"retention":{"time_years":"5"}}',
			'{"retention":{"time_years":5.5}}',
			'{"retention":{"years":5}}',
			'{"schema":"1"}',
			'{"authority":"a\u0000b"}',
		];
		foreach ($cases as $json) {
			$this->assertSame('422 invalid', self::code(fn () => RecordValidator::validate('setting', 'privacy', self::obj($json))), $json);
		}
	}

	public function testNormalizeFillsDefaults(): void {
		$n = PrivacyRules::normalize(null);
		$this->assertSame('', $n['controller']['name']);
		$this->assertSame('', $n['privacy_contact']);
		$this->assertSame(5, $n['retention']['time_years']);
		$this->assertSame(10, $n['retention']['billing_years']);
		$this->assertSame('both', $n['law']);
		$this->assertSame('', $n['authority']);

		$n = PrivacyRules::normalize(self::obj('{"controller":{"name":"  B/IAS  "},"retention":{"time_years":7,"billing_years":99},"law":"eu","hosting":{"location":"CH"}}'));
		$this->assertSame('B/IAS', $n['controller']['name']);
		$this->assertSame(7, $n['retention']['time_years']);
		$this->assertSame(10, $n['retention']['billing_years']); // out of range: default
		$this->assertSame('eu', $n['law']);
		$this->assertSame('CH', $n['hosting']['location']);
		$this->assertSame('', $n['hosting']['provider']);
	}
}
