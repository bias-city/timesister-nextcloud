<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\BookingRules;
use OCA\TimeSister\Service\Json;
use OCA\TimeSister\Service\RecordValidator;
use PHPUnit\Framework\TestCase;

/** Booking reports and billable marks (0.14.0): keys, content, rights. */
class BookingRulesTest extends TestCase {
	private static function code(callable $fn): string {
		try {
			$fn();
		} catch (ApiException $e) {
			return $e->getStatus() . ' ' . $e->getErrorCode();
		}
		return 'ok';
	}

	/** @return array<string,mixed> */
	private static function booking(array $over = []): array {
		return array_replace([
			'id' => '7f3a-1@example.test', 'date' => '2026-10-07', 'start' => '08:15', 'end' => '10:00', 'minutes' => 105,
			'code' => 'JB1', 'title' => 'Site visit', 'notes' => "First line\nSecond line", 'billable' => true, 'billed' => false,
		], $over);
	}

	/** @param list<array<string,mixed>> $bookings */
	private static function report(array $bookings, array $over = []): \stdClass {
		$d = array_replace(['person' => 'mia', 'project' => 'JB1', 'month' => '2026-10', 'bookings' => $bookings, 'at' => '2026-10-07T12:00:00Z'], $over);
		return Json::decode(json_encode($d, JSON_THROW_ON_ERROR));
	}

	private static function validReport(\stdClass $d, string $key = 'mia+JB1+2026-10'): string {
		return self::code(fn () => RecordValidator::validate(BookingRules::REPORT, $key, $d));
	}

	public function testKeys(): void {
		$this->assertSame('mia+JB1+2026-10', BookingRules::reportKey('mia', 'JB1', '2026-10'));
		$this->assertSame(['uid' => 'mia', 'project' => 'JB1', 'tail' => '2026-10'], BookingRules::parse(BookingRules::REPORT, 'mia+JB1+2026-10'));
		$this->assertSame(['uid' => 'mia', 'project' => 'A+B', 'tail' => '2026-10'], BookingRules::parse(BookingRules::REPORT, 'mia+A+B+2026-10'), 'the project lies between the first and the last +');
		foreach (['mia', 'mia+2026-10', '+JB1+2026-10', 'mia++2026-10', 'mia+JB1+2026-13', 'mia+JB1+2026-1', 'mia+JB1+'] as $bad) {
			$this->assertNull(BookingRules::parse(BookingRules::REPORT, $bad), $bad);
		}
		$mark = BookingRules::markKey('mia', 'JB1', '7f3a-1@example.test');
		$this->assertSame('mia+JB1+' . substr(hash('sha256', '7f3a-1@example.test'), 0, 32), $mark);
		$this->assertSame('JB1', BookingRules::parse(BookingRules::MARK, $mark)['project'] ?? null);
		$this->assertNull(BookingRules::parse(BookingRules::MARK, 'mia+JB1+2026-10'), 'a month is no hash');
		$this->assertNull(BookingRules::parse(BookingRules::REPORT, $mark), 'a hash is no month');
		$this->assertSame('422 invalid', self::code(fn () => BookingRules::requireKey(BookingRules::REPORT, 'mia+JB1')));
		$this->assertSame('422 invalid', self::code(fn () => BookingRules::requireKey(BookingRules::MARK, 'mia+JB1+abc')));
		$this->assertTrue(RecordValidator::isKind('booking-report'));
		$this->assertTrue(RecordValidator::isKind('billable'));
		$this->assertTrue(RecordValidator::isKey($mark), 'within the record key pattern');
	}

	public function testReportContent(): void {
		$this->assertSame('ok', self::validReport(self::report([self::booking(), self::booking(['id' => 'b2', 'start' => null, 'end' => null, 'code' => null, 'title' => null, 'notes' => null, 'billable' => null, 'billed' => null])])));
		$this->assertSame('ok', self::validReport(self::report([])), 'an empty month clears the report');
		$this->assertSame('ok', self::validReport(self::report([self::booking(['end' => '24:00', 'minutes' => 0])])));
		$this->assertSame('ok', self::validReport(self::report([], ['schema' => 1])));

		$bad = [
			'person not the key' => [self::report([], ['person' => 'noah'])],
			'project not the key' => [self::report([], ['project' => 'TK1'])],
			'month not the key' => [self::report([], ['month' => '2026-09'])],
			'unknown field' => [self::report([], ['pensum' => 80])],
			'no time' => [self::report([], ['at' => 'yesterday'])],
			'bookings not a list' => [self::report([], ['bookings' => ['a' => self::booking()]])],
			'unknown booking field' => [self::report([self::booking(['calendar' => 'x'])])],
			'date in another month' => [self::report([self::booking(['date' => '2026-11-01'])])],
			'no real day' => [self::report([self::booking(['date' => '2026-10-32'])])],
			'time format' => [self::report([self::booking(['start' => '8:15'])])],
			'time 24:30' => [self::report([self::booking(['end' => '24:30'])])],
			'minutes as text' => [self::report([self::booking(['minutes' => '105'])])],
			'minutes negative' => [self::report([self::booking(['minutes' => -1])])],
			'minutes above a month' => [self::report([self::booking(['minutes' => BookingRules::MAX_MINUTES + 1])])],
			'id empty' => [self::report([self::booking(['id' => ''])])],
			'id with a line break' => [self::report([self::booking(['id' => "a\nb"])])],
			'id too long' => [self::report([self::booking(['id' => str_repeat('i', BookingRules::ID_MAX + 1)])])],
			'title with a line break' => [self::report([self::booking(['title' => "a\nb"])])],
			'title too long' => [self::report([self::booking(['title' => str_repeat('t', BookingRules::TITLE_MAX + 1)])])],
			'notes with a bell' => [self::report([self::booking(['notes' => "a\x07b"])])],
			'notes too long' => [self::report([self::booking(['notes' => str_repeat('n', BookingRules::NOTES_MAX + 1)])])],
			'code too long' => [self::report([self::booking(['code' => str_repeat('c', BookingRules::CODE_MAX + 1)])])],
			'billable as text' => [self::report([self::booking(['billable' => 'yes'])])],
			'billed as number' => [self::report([self::booking(['billed' => 1])])],
			'same id twice' => [self::report([self::booking(), self::booking()])],
			'too many' => [self::report(array_map(static fn (int $i) => ['id' => "b$i", 'date' => '2026-10-01', 'minutes' => 1], range(0, BookingRules::MAX_BOOKINGS)))],
		];
		foreach ($bad as $why => [$d]) {
			$this->assertSame('422 invalid', self::validReport($d), $why);
		}
		$this->assertSame('422 invalid', self::validReport(self::report([]), 'mia+JB1'), 'malformed key');
		// The error names the booking.
		try {
			RecordValidator::validate(BookingRules::REPORT, 'mia+JB1+2026-10', self::report([self::booking(), self::booking(['id' => 'b2', 'minutes' => -5])]));
			$this->fail('expected 422');
		} catch (ApiException $e) {
			$this->assertSame('Booking 2: “minutes” is invalid.', $e->getMessage());
		}
	}

	/** A month may hold more than 256 KB, up to 768 KB. */
	public function testReportSize(): void {
		$big = array_map(static fn (int $i) => ['id' => "b$i", 'date' => '2026-10-01', 'minutes' => 30, 'notes' => str_repeat('n', 400)], range(1, 1000));
		$this->assertSame('ok', self::validReport(self::report($big)));
		$huge = array_map(static fn (int $i) => ['id' => "b$i", 'date' => '2026-10-01', 'minutes' => 30, 'notes' => str_repeat('n', 1000)], range(1, 1000));
		$this->assertSame('413 too_large', self::validReport(self::report($huge)));
		$this->assertSame('413 too_large', self::code(fn () => RecordValidator::validate('customer', 'K1', Json::decode(json_encode(['id' => 'K1', 'note' => str_repeat('x', 300000)], JSON_THROW_ON_ERROR)))), 'other kinds keep 256 KB');
	}

	public function testMarkContent(): void {
		$key = BookingRules::markKey('mia', 'JB1', 'b1');
		$mark = static fn (array $over = []): \stdClass => Json::decode(json_encode(array_replace(['person' => 'mia', 'project' => 'JB1', 'uid' => 'b1', 'billable' => false], $over), JSON_THROW_ON_ERROR));
		$check = static fn (\stdClass $d, ?string $k = null): string => self::code(fn () => RecordValidator::validate(BookingRules::MARK, $k ?? $key, $d));
		$this->assertSame('ok', $check($mark()));
		$this->assertSame('ok', $check($mark(['billable' => true, 'schema' => 1])));
		$this->assertSame('422 invalid', $check($mark(['uid' => 'b2'])), 'another booking');
		$this->assertSame('422 invalid', $check($mark(['project' => 'TK1'])), 'another project');
		$this->assertSame('422 invalid', $check($mark(['person' => 'noah'])), 'another person');
		$this->assertSame('422 invalid', $check($mark(['billable' => null])), 'billable is required');
		$this->assertSame('422 invalid', $check($mark(['note' => 'x'])), 'only the documented fields');
		$this->assertSame('422 invalid', $check($mark(['uid' => ''])));
		$this->assertSame('422 invalid', $check($mark(), 'mia+JB1+2026-10'), 'a report key');
	}

	public function testRights(): void {
		$report = 'mia+JB1+2026-10';
		$mark = BookingRules::markKey('mia', 'JB1', 'b1');
		$led = ['JB1' => true];
		foreach ([BookingRules::REPORT => $report, BookingRules::MARK => $mark] as $kind => $key) {
			$this->assertTrue(BookingRules::canRead('user', 'mia', $kind, $key, []), "$kind: the person");
			$this->assertTrue(BookingRules::canRead('admin', 'petra', $kind, $key, []), "$kind: Team Admins");
			$this->assertTrue(BookingRules::canRead('lead', 'lea', $kind, $key, $led), "$kind: the project's Lead");
			$this->assertFalse(BookingRules::canRead('lead', 'luca', $kind, $key, ['TK1' => true]), "$kind: the Lead of another project");
			$this->assertFalse(BookingRules::canRead('user', 'noah', $kind, $key, []), "$kind: another User");
		}
		$this->assertFalse(BookingRules::canRead('lead', 'lea', BookingRules::REPORT, 'mia+JB1', $led), 'malformed key');

		// A report only its account writes – not a Team Admin, not the Lead.
		$this->assertTrue(BookingRules::canWrite('user', 'mia', BookingRules::REPORT, $report, []));
		$this->assertFalse(BookingRules::canWrite('admin', 'petra', BookingRules::REPORT, $report, []));
		$this->assertFalse(BookingRules::canWrite('lead', 'lea', BookingRules::REPORT, $report, $led));
		// A mark: Team Admins and the project's Leads, not the person.
		$this->assertTrue(BookingRules::canWrite('admin', 'petra', BookingRules::MARK, $mark, []));
		$this->assertTrue(BookingRules::canWrite('lead', 'lea', BookingRules::MARK, $mark, $led));
		$this->assertFalse(BookingRules::canWrite('lead', 'luca', BookingRules::MARK, $mark, ['TK1' => true]));
		$this->assertFalse(BookingRules::canWrite('user', 'mia', BookingRules::MARK, $mark, []));

		$this->assertSame('403 forbidden', self::code(fn () => BookingRules::requireWrite('lead', 'lea', BookingRules::REPORT, $report, $led)));
		$this->assertSame('403 forbidden', self::code(fn () => BookingRules::requireWrite('user', 'mia', BookingRules::MARK, $mark, [])));
		$this->assertSame('422 invalid', self::code(fn () => BookingRules::requireWrite('user', 'mia', BookingRules::REPORT, 'mia+JB1', [])));
		$this->assertSame('ok', self::code(fn () => BookingRules::requireWrite('lead', 'lea', BookingRules::MARK, $mark, $led)));
	}
}
