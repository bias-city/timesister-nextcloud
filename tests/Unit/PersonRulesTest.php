<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\Json;
use OCA\TimeSister\Service\PersonAccess;
use OCA\TimeSister\Service\PersonRules;
use PHPUnit\Framework\TestCase;

/** Person record (0.27.0): level and company beside the cost rates. */
class PersonRulesTest extends TestCase {
	private static function obj(string $json): \stdClass {
		$o = Json::decode($json);
		self::assertInstanceOf(\stdClass::class, $o);
		return $o;
	}

	private static function outcome(callable $fn): string {
		try {
			$fn();
		} catch (ApiException $e) {
			return $e->getStatus() . ' ' . $e->getText()->text();
		}
		return '';
	}

	public function testLevelsAndCostRates(): void {
		self::assertSame('', self::outcome(fn () => PersonRules::validate(self::obj('{"login":"a","levels":[{"from":"2026-01-01","level":"lead"}]}'))));
		self::assertStringContainsString('levels', self::outcome(fn () => PersonRules::validate(self::obj('{"login":"a","levels":{"from":"x"}}'))));
		self::assertStringContainsString('twice', self::outcome(fn () => PersonRules::validate(self::obj('{"login":"a","levels":[{"from":"2026-01-01","level":"a"},{"from":"2026-01-01","level":"b"}]}'))));
		self::assertStringContainsString('cost_rates', self::outcome(fn () => PersonRules::validate(self::obj('{"login":"a","cost_rates":[{"from":"2026-01-01","rate":-1}]}'))));
		// 0.27.0 before the levels: `billing_rates` is no field any more – it passes unchecked like any unknown one.
		self::assertSame('', self::outcome(fn () => PersonRules::validate(self::obj('{"login":"a","billing_rates":{"from":"x"}}'))));
	}

	public function testCompany(): void {
		self::assertSame('', self::outcome(fn () => PersonRules::validate(self::obj('{"login":"a","company":"S2L"}'))));
		self::assertSame('', self::outcome(fn () => PersonRules::validate(self::obj('{"login":"a","company":null}'))));
		self::assertStringContainsString('company', self::outcome(fn () => PersonRules::validate(self::obj('{"login":"a","company":"' . str_repeat('x', 81) . '"}'))));
		self::assertStringContainsString('company', self::outcome(fn () => PersonRules::validate(self::obj('{"login":"a","company":5}'))));
	}

	public function testAdminOnlyAndCard(): void {
		$own = PersonAccess::own(self::obj('{"login":"a","company":"S2L","cost_rates":[],"levels":[]}'));
		self::assertObjectNotHasProperty('levels', $own);
		self::assertObjectNotHasProperty('cost_rates', $own);
		self::assertSame('S2L', $own->company);
		$card = PersonAccess::card(self::obj('{"login":"a","first_name":"Ann","company":"S2L","levels":[{"from":"2026-01-01","level":"lead"}]}'));
		self::assertSame('S2L', $card->company);
		self::assertObjectNotHasProperty('levels', $card);
	}
}
