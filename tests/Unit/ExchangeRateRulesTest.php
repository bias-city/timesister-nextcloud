<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\ExchangeRateRules;
use OCA\TimeSister\Service\RecordValidator;
use PHPUnit\Framework\TestCase;

/**
 * Exchange rates of the ECB (0.30.0, PLAN-SPESEN 9 H): the switch, which
 * days a team needs, the request and the answer inverted into the record.
 */
class ExchangeRateRulesTest extends TestCase {
	private static function outcome(callable $fn): string {
		try {
			$fn();
			return 'ok';
		} catch (ApiException $e) {
			return $e->getStatus() . ' ' . $e->getMessage();
		}
	}

	private static function receipt(array $over = []): \stdClass {
		return (object)array_merge(['kind' => 'receipt', 'date' => '2026-10-20', 'currency' => 'EUR', 'gross' => 50.0, 'paid_by' => 'person', 'person' => 'alice',
			'reimburse' => true, 'status' => 'open', 'booked' => null, 'rate' => null, 'report' => null], $over);
	}

	public function testSwitchAndAddress(): void {
		$this->assertFalse(ExchangeRateRules::on(null));
		$this->assertFalse(ExchangeRateRules::on((object)['exchange_rates' => 'yes']));
		$this->assertTrue(ExchangeRateRules::on((object)['exchange_rates' => true]));
		$this->assertSame('ok', self::outcome(fn () => RecordValidator::validate('setting', 'settings', (object)['exchange_rates' => true])));
		$this->assertSame('ok', self::outcome(fn () => RecordValidator::validate('setting', 'settings', (object)['exchange_rates' => null])));
		$this->assertSame('422 “exchange_rates” must be true or false.', self::outcome(fn () => RecordValidator::validate('setting', 'settings', (object)['exchange_rates' => 1])));
		$this->assertSame('https://api.frankfurter.dev', ExchangeRateRules::baseUrl('https://api.frankfurter.dev/'));
		$this->assertSame('http://host.docker.internal:8792', ExchangeRateRules::baseUrl(' http://host.docker.internal:8792 '));
		$this->assertNull(ExchangeRateRules::baseUrl('ftp://example.org'));
		$this->assertNull(ExchangeRateRules::baseUrl(''));
		$this->assertSame('https://api.frankfurter.dev/v1/2026-10-20?base=CHF&symbols=EUR,USD', ExchangeRateRules::url('https://api.frankfurter.dev', '2026-10-20', 'CHF', ['EUR', 'USD']));
		$this->assertTrue(ExchangeRateRules::supported('USD', '2026-10-20'));
		$this->assertTrue(ExchangeRateRules::supported('BGN', '2025-12-31'));
		$this->assertFalse(ExchangeRateRules::supported('BGN', '2026-01-02'), 'Bulgaria uses the euro since 2026');
		$this->assertFalse(ExchangeRateRules::supported('RUB', '2026-10-20'));
	}

	public function testDays(): void {
		$receipts = [
			self::receipt(),
			self::receipt(['currency' => 'USD']),
			self::receipt(['date' => '2026-10-21', 'currency' => 'usd']),
			self::receipt(['date' => '2026-10-19', 'currency' => 'CHF']),
			self::receipt(['date' => '2026-10-18', 'booked' => (object)['gross' => 47.0, 'currency' => 'CHF']]),
			self::receipt(['date' => '2026-10-17', 'rate' => (object)['value' => 0.94, 'date' => '2026-10-17', 'source' => 'manual']]),
			self::receipt(['date' => '2026-10-16', 'status' => 'rejected']),
			self::receipt(['date' => '2026-10-15', 'reimburse' => false]),
			self::receipt(['date' => '2026-10-14', 'report' => 'sb-1']),
			self::receipt(['date' => '2026-10-13', 'report' => 'sb-2']),
			self::receipt(['date' => '2026-10-12', 'currency' => 'XAU']),
			self::receipt(['date' => '2026-10-28']),
			self::receipt(['date' => '2025-09-22']),
			self::receipt(['date' => '2025-09-23']),
			null,
		];
		$reports = ['sb-1' => (object)['schema' => 2, 'status' => 'fixed'], 'sb-2' => (object)['schema' => 2, 'status' => 'reversed']];
		$due = ExchangeRateRules::due($receipts, $reports, 'CHF', [], '2026-10-28', 50);
		$this->assertSame(['2026-10-21' => ['USD'], '2026-10-20' => ['EUR', 'USD'], '2026-10-13' => ['EUR'], '2025-09-23' => ['EUR']], $due,
			'newest first; not today, not 400 days back, not booked, rated, rejected, company, settled or outside the ECB');
		$this->assertSame(['2026-10-21' => ['USD']], ExchangeRateRules::due($receipts, $reports, 'CHF', [], '2026-10-28', 1), 'at most the limit');
		$stored = ['CHF-2026-10-20' => (object)['base' => 'CHF', 'date' => '2026-10-20', 'ecb_date' => '2026-10-20', 'rates' => (object)['EUR' => 0.93]],
			'CHF-2026-10-21' => (object)['base' => 'CHF', 'date' => '2026-10-21', 'ecb_date' => '2026-10-21', 'rates' => (object)['GBP' => 1.06, 'USD' => 0.8]]];
		$this->assertSame(['2026-10-20' => ['EUR', 'USD'], '2026-10-13' => ['EUR'], '2025-09-23' => ['EUR']], ExchangeRateRules::due($receipts, $reports, 'CHF', $stored, '2026-10-28', 50),
			'a day with all its currencies is done; a missing one is fetched with the stored ones');
		$this->assertSame([], ExchangeRateRules::due([self::receipt(['currency' => 'EUR'])], [], 'EUR', [], '2026-10-28', 50), 'the team’s own currency');
	}

	public function testAnswer(): void {
		$answer = json_decode('{"amount":1.0,"base":"CHF","date":"2026-10-16","rates":{"EUR":1.0624,"USD":1.25,"GBP":0.9}}');
		$d = ExchangeRateRules::fromAnswer($answer, 'CHF', '2026-10-18', ['EUR', 'USD', 'JPY']);
		$this->assertSame('{"base":"CHF","date":"2026-10-18","ecb_date":"2026-10-16","rates":{"EUR":0.941265,"USD":0.8}}', json_encode($d, JSON_PRESERVE_ZERO_FRACTION),
			'inverted to the team’s currency per unit on six places; the ECB day of a weekend; what was not asked or is missing is left out');
		$this->assertSame('ok', self::outcome(fn () => RecordValidator::validate('exchange_rate', 'CHF-2026-10-18', $d)));
		foreach ([
			json_decode('{"base":"EUR","date":"2026-10-16","rates":{"USD":1.1}}'),
			json_decode('{"base":"CHF","date":"2026-10-19","rates":{"EUR":1.06}}'),
			json_decode('{"base":"CHF","date":"2026-10-16","rates":{"JPY":160}}'),
			json_decode('{"message":"not found"}'),
			'not json',
		] as $bad) {
			try {
				ExchangeRateRules::fromAnswer($bad, 'CHF', '2026-10-18', ['EUR']);
				$this->fail('accepted: ' . json_encode($bad));
			} catch (\UnexpectedValueException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testRecord(): void {
		$ok = (object)['base' => 'CHF', 'date' => '2026-10-18', 'ecb_date' => '2026-10-16', 'rates' => (object)['EUR' => 0.941265]];
		$this->assertSame('ok', self::outcome(fn () => ExchangeRateRules::validate('CHF-2026-10-18', $ok)));
		$bad = '422 An exchange rate is { “base”, “date”, “ecb_date”, “rates” } under the key “<base>-<date>”.';
		$this->assertSame($bad, self::outcome(fn () => ExchangeRateRules::validate('EUR-2026-10-18', $ok)));
		foreach ([['ecb_date' => '2026-10-19'], ['rates' => (object)['eur' => 0.9]], ['rates' => (object)['EUR' => 0]], ['rates' => []], ['base' => 'chf'], ['extra' => 1]] as $over) {
			$d = (object)array_merge((array)$ok, $over);
			$this->assertSame($bad, self::outcome(fn () => ExchangeRateRules::validate('CHF-2026-10-18', $d)), json_encode($over));
		}
		$this->assertTrue(RecordValidator::isKind('exchange_rate'));
	}
}
