<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\OfficeCostRules;
use OCA\TimeSister\Service\RecordValidator;
use PHPUnit\Framework\TestCase;

/** The record `setting/office_costs` (0.27.0, controlling C5): what is accepted and what is 422. */
class OfficeCostRulesTest extends TestCase {
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

	private const FULL = '{"schema":1,"reserve":150000,
		"costs":[{"id":"c-1","name":"Rent","amount":3000,"due_day":1,"rhythm":"monthly","from":"2026-01-01","to":null,"kind":"rent","note":"Office"},
		         {"id":"c-2","name":"Salaries","amount":40000.5,"due_day":25,"rhythm":"monthly","from":"2026-01-01","kind":"salaries","month13":12},
		         {"id":"c-3","name":"AHV","amount":0,"due_day":10,"rhythm":"monthly","from":"2026-01-01","kind":"social","percent":10.5},
		         {"id":"c-4","name":"VAT","amount":0,"due_day":30,"rhythm":"quarterly","from":"2026-02-28","kind":"vat","percent":8.1},
		         {"id":"c-5","name":"Rebuild","amount":12000,"due_day":1,"rhythm":"once","from":"2027-03-15","kind":"other"}],
		"balances":[{"date":"2026-10-20","amount":248000,"note":"Bank and cash"},{"date":"2026-09-30","amount":-100}]}';

	public function testOfficeCostsIsASettingKey(): void {
		$this->assertContains('office_costs', RecordValidator::SETTING_KEYS);
		$this->assertSame('ok', self::code(fn () => RecordValidator::validate('setting', 'office_costs', self::obj('{}'))));
		$this->assertSame('ok', self::code(fn () => RecordValidator::validate('setting', 'office_costs', self::obj(self::FULL))));
	}

	public function testFullRecordIsValid(): void {
		$this->assertSame('ok', self::code(fn () => OfficeCostRules::validate(self::obj(self::FULL))));
		$this->assertSame('ok', self::code(fn () => OfficeCostRules::validate(self::obj('{"costs":[],"balances":[],"reserve":null}'))));
	}

	/** @return list<array{string}> */
	public static function invalid(): array {
		return [
			['{"other":1}'],
			['{"schema":"1"}'],
			['{"reserve":-1}'],
			['{"reserve":"viel"}'],
			['{"costs":{}}'],
			['{"costs":[1]}'],
			['{"costs":[{"name":"","amount":1,"rhythm":"monthly","from":"2026-01-01","kind":"rent"}]}'],
			['{"costs":[{"name":"X","amount":-1,"rhythm":"monthly","from":"2026-01-01","kind":"rent"}]}'],
			['{"costs":[{"name":"X","amount":1,"due_day":0,"rhythm":"monthly","from":"2026-01-01","kind":"rent"}]}'],
			['{"costs":[{"name":"X","amount":1,"due_day":32,"rhythm":"monthly","from":"2026-01-01","kind":"rent"}]}'],
			['{"costs":[{"name":"X","amount":1,"rhythm":"weekly","from":"2026-01-01","kind":"rent"}]}'],
			['{"costs":[{"name":"X","amount":1,"rhythm":"monthly","from":"2026-01-01","kind":"coffee"}]}'],
			['{"costs":[{"name":"X","amount":1,"rhythm":"monthly","kind":"rent"}]}'],
			['{"costs":[{"name":"X","amount":1,"rhythm":"monthly","from":"2026-13-01","kind":"rent"}]}'],
			['{"costs":[{"name":"X","amount":1,"rhythm":"monthly","from":"2026-01-01","to":"2025-12-31","kind":"rent"}]}'],
			['{"costs":[{"name":"X","amount":1,"rhythm":"monthly","from":"2026-01-01","kind":"salaries","month13":13}]}'],
			['{"costs":[{"name":"X","amount":1,"rhythm":"monthly","from":"2026-01-01","kind":"social","percent":101}]}'],
			['{"costs":[{"name":"X","amount":1,"rhythm":"monthly","from":"2026-01-01","kind":"rent","extra":1}]}'],
			['{"balances":[{"amount":1}]}'],
			['{"balances":[{"date":"2026-10-20"}]}'],
			['{"balances":[{"date":"2026-10-20","amount":"1"}]}'],
			['{"balances":[{"date":"2026-10-20","amount":1,"x":1}]}'],
		];
	}

	/** @dataProvider invalid */
	public function testInvalidIs422(string $json): void {
		$this->assertSame('422 invalid', self::code(fn () => OfficeCostRules::validate(self::obj($json))), $json);
	}

	public function testListsAreBounded(): void {
		$costs = array_fill(0, OfficeCostRules::COSTS_MAX + 1, '{"name":"X","amount":1,"rhythm":"monthly","from":"2026-01-01","kind":"rent"}');
		$this->assertSame('422 invalid', self::code(fn () => OfficeCostRules::validate(self::obj('{"costs":[' . implode(',', $costs) . ']}'))));
		$balances = array_fill(0, OfficeCostRules::BALANCES_MAX + 1, '{"date":"2026-10-20","amount":1}');
		$this->assertSame('422 invalid', self::code(fn () => OfficeCostRules::validate(self::obj('{"balances":[' . implode(',', $balances) . ']}'))));
	}
}
