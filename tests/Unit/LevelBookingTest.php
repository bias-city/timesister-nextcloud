<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Db\InvHour;
use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\CorrectionRules;
use OCA\TimeSister\Service\EntryRules;
use OCA\TimeSister\Service\FeeModelRules;
use OCA\TimeSister\Service\InvoiceRules;
use OCA\TimeSister\Service\IssueRules;
use OCA\TimeSister\Service\Json;
use OCA\TimeSister\Service\ReceiptLineRules;
use OCA\TimeSister\Service\RecordValidator;
use OCA\TimeSister\Service\Role;
use PHPUnit\Framework\TestCase;

/**
 * The level of a booking (0.33.0, one list of levels): the person's own
 * right on it in a budget by category (`correction_lead_only`), the budget
 * a booking falls into, `level` and `level_name` on `hours` lines and per
 * booking, and the issue checks (`no_levels`, `level_rate_missing`,
 * `amount_without_rate`). The categories of the drafts are gone.
 */
class LevelBookingTest extends TestCase {
	private const NOW = '20261029T120000Z';

	private static function obj(string $json): \stdClass {
		$o = Json::decode($json);
		self::assertInstanceOf(\stdClass::class, $o);
		return $o;
	}

	/** `status code field` of the error, '' when none. */
	private static function err(callable $fn): string {
		try {
			$fn();
		} catch (ApiException $e) {
			return trim($e->getStatus() . ' ' . ($e->getRuleCode() ?? '-') . ' ' . ($e->getField() ?? '-'));
		}
		return '';
	}

	/** The message of the error, '' when none. */
	private static function message(callable $fn): string {
		try {
			$fn();
		} catch (ApiException $e) {
			return $e->getText()->text();
		}
		return '';
	}

	private static function ics(string ...$lines): string {
		return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//EN\r\nBEGIN:VEVENT\r\nUID:e1\r\nDTSTAMP:20261001T080000Z\r\n"
			. "DTSTART:20261001T080000Z\r\nDTEND:20261001T100000Z\r\nSUMMARY:#N410.31 Vorprojekt\r\n" . implode("\r\n", $lines) . ($lines === [] ? '' : "\r\n")
			. "END:VEVENT\r\nEND:VCALENDAR\r\n";
	}

	public function testNoCategoryAnyMore(): void {
		// The book reads the level only; a leftover X-TIMESISTER-CATEGORY stays in the event, read by nobody.
		$p = EntryRules::parse(self::ics('X-TIMESISTER-CATEGORY:B', 'X-TIMESISTER-LEVEL:b'));
		$this->assertNotNull($p);
		$this->assertArrayNotHasKey('category', $p);
		$this->assertSame('b', $p['level']);
		$out = CorrectionRules::apply(self::ics('X-TIMESISTER-CATEGORY:B', 'SEQUENCE:1'), ['level' => 'd'], self::NOW);
		$this->assertStringContainsString('X-TIMESISTER-CATEGORY:B', $out, 'kept as received');
		$this->assertSame('d', EntryRules::parse($out)['level'] ?? null);
		// A correction with `category` is refused as an unknown field.
		$this->assertSame('“category” is not a field of a correction.', self::message(fn () => CorrectionRules::parseBody(self::obj('{"entries":[{"uid":"anna","event_uid":"e1"}],"category":"B"}'))));
		$this->assertSame('“category” is not a field of a correction.', self::message(fn () => RecordValidator::validate('correction', CorrectionRules::key('anna', 'e1', null), self::obj('{"person":"anna","event_uid":"e1","category":"C"}'))));
		$this->assertSame(['title', 'code', 'billable', 'level'], CorrectionRules::CHANGE_FIELDS);
		$this->assertSame(['title', 'code', 'billable'], CorrectionRules::LEAD_FIELDS);
		$this->assertTrue(CorrectionRules::levelOnly(['level' => 'b']));
		$this->assertTrue(CorrectionRules::levelOnly(['level' => null]));
		$this->assertFalse(CorrectionRules::levelOnly(['level' => 'b', 'title' => 'x']));
	}

	public function testThePersonsOwnRight(): void {
		$key = CorrectionRules::key('anna', 'e1', null);
		$lead = self::obj('{"person":"anna","event_uid":"e1","title":"Werkstatt","level":"lead","status":"applied","by":"lea"}');
		/** @return string '' + `true`/`false` (needs the budget check), or the error */
		$r = static function (string $role, ?\stdClass $before, ?\stdClass $after, array $keys = ['anna']) use ($key): string {
			try {
				return CorrectionRules::requirePersonal($role, $key, $before, $after, $keys) ? 'check' : 'pass';
			} catch (ApiException $e) {
				return trim($e->getStatus() . ' ' . ($e->getRuleCode() ?? '-') . ' ' . ($e->getField() ?? '-'));
			}
		};
		// Team Admins and Leads pass here (their rights are checked as the record is applied).
		$this->assertSame('pass', $r(Role::ADMIN, null, self::obj('{"person":"bob","event_uid":"e1","title":"x"}'), []));
		$this->assertSame('pass', $r(Role::LEAD, null, self::obj('{"person":"bob","event_uid":"e1","title":"x"}'), []));
		// A member: the level of their own booking – the budget check follows (by category).
		$this->assertSame('check', $r(Role::USER, null, self::obj('{"person":"anna","event_uid":"e1","level":"b"}')));
		$this->assertSame('check', $r(Role::USER, $lead, self::obj('{"person":"anna","event_uid":"e1","level":"b"}')), 'the Lead’s fields left out');
		$this->assertSame('check', $r(Role::USER, $lead, self::obj('{"person":"anna","event_uid":"e1","title":"Werkstatt","level":null}')), 'removing the level is a change');
		$this->assertSame('pass', $r(Role::USER, $lead, self::obj('{"person":"anna","event_uid":"e1","title":"Werkstatt","level":"lead"}')), 'nothing new: no budget check');
		$this->assertSame('403 correction_lead_only title', $r(Role::USER, $lead, self::obj('{"person":"anna","event_uid":"e1","title":"Andere","level":"b"}')));
		$this->assertSame('403 correction_lead_only billable', $r(Role::USER, null, self::obj('{"person":"anna","event_uid":"e1","billable":false}')));
		$this->assertSame('403 correction_lead_only code', $r(Role::USER, null, self::obj('{"person":"anna","event_uid":"e1","code":"N410.32","level":"b"}')));
		$this->assertSame('403 correction_lead_only person', $r(Role::USER, null, self::obj('{"person":"bob","event_uid":"e1","level":"b"}')), 'someone else’s booking');
		// A refused change of a Lead never passes on through the person's write.
		$refused = self::obj('{"person":"anna","event_uid":"e1","code":"X9","status":"forbidden","by":"lea"}');
		$this->assertSame('403 correction_lead_only code', $r(Role::USER, $refused, self::obj('{"person":"anna","event_uid":"e1","code":"X9","level":"b"}')));
		$this->assertSame('check', $r(Role::USER, $refused, self::obj('{"person":"anna","event_uid":"e1","level":"b"}')));
		$this->assertNull(CorrectionRules::appliedOnly($refused));
		$this->assertSame($lead, CorrectionRules::appliedOnly($lead));
		// Deleting: only a record without a field of the Lead; with a level the budget decides.
		$this->assertSame('check', $r(Role::USER, self::obj('{"person":"anna","event_uid":"e1","level":"b"}'), null));
		$this->assertSame('pass', $r(Role::USER, null, null));
		$this->assertSame('403 correction_lead_only title', $r(Role::USER, $lead, null));
		$this->assertSame('403 correction_lead_only person', $r(Role::USER, null, null, ['bob']));
		// The Lead's fields stay with the person's write.
		$kept = CorrectionRules::keepLeadFields($lead, self::obj('{"person":"anna","event_uid":"e1","level":"b"}'));
		$this->assertSame(['title' => 'Werkstatt', 'level' => 'b'], CorrectionRules::changeOf($kept));
		$this->assertNull(CorrectionRules::otherThanLevel($lead, $kept));
		$this->assertTrue(CorrectionRules::setsLevel($lead, $kept));
		$this->assertFalse(CorrectionRules::setsLevel($lead, self::obj('{"person":"anna","event_uid":"e1","title":"Werkstatt"}')));
		$e = CorrectionRules::leadOnly('level');
		$this->assertSame(['error' => 'forbidden', 'code' => 'correction_lead_only', 'field' => 'level'], array_diff_key($e->toData(), ['message' => 1]));
		// The day of a booking: the occurrence's, else the first start's.
		$this->assertSame('2027-03-02', CorrectionRules::bookingDay('2027-01-05', '20270302T080000'));
		$this->assertSame('2027-03-02', CorrectionRules::bookingDay(null, '20270302'));
		$this->assertSame('2027-01-05', CorrectionRules::bookingDay('2027-01-05', null));
		$this->assertNull(CorrectionRules::bookingDay(null, null));
	}

	/** The budget of a booking (the person's right; the rule by which the clients assign bookings). */
	public function testTheBudgetOfABooking(): void {
		$n410 = self::obj('{"id":"N410","codes":["N410"],"subprojects":[{"code":"N411"}],"budgets":['
			. '{"id":"b1","fee_model":"category","start":"2027-01-01","end":"2027-06-30","work_packages":[{"no":"3"},{"no":"31","parent":"3","codes":["N410.31"]},{"no":"4","codes":["N410"]}]},'
			. '{"id":"b2","fee_model":"role","start":"2027-07-01","end":null,"work_packages":[{"no":"31","codes":["n410.31"]}]},'
			. '{"id":"b3","fee_model":"mean_rate","start":"2028-01-01","end":"2028-12-31","work_packages":[{"no":"1","codes":["N410.31.2"]}]},'
			. '{"id":"bx","kind":"aufwand","fee_model":"category"}]}');
		$other = self::obj('{"id":"N4","codes":["N4"],"budgets":[{"id":"o1","fee_model":"role","work_packages":[{"no":"1","codes":["N4"]}]}]}');
		$projects = [$other, $n410];
		$this->assertSame($n410, FeeModelRules::projectOf($projects, 'n410.31.2'), 'the most precise project code, case aside');
		$this->assertSame($n410, FeeModelRules::projectOf($projects, 'N411.1'), 'a subproject’s code');
		$this->assertSame($other, FeeModelRules::projectOf($projects, 'N4.1'));
		$this->assertNull(FeeModelRules::projectOf($projects, 'N41'), 'a prefix without a dot is another code');
		$id = static fn (?\stdClass $b): ?string => $b?->id ?? null;
		// The most precise package code wins; of equal ones the budget holding the day, else the latest before, else the earliest.
		$this->assertSame('b1', $id(FeeModelRules::budgetOf($n410, 'N410.31', '2027-03-01')));
		$this->assertSame('b2', $id(FeeModelRules::budgetOf($n410, 'N410.31', '2027-09-01')), 'open end');
		$this->assertSame('b1', $id(FeeModelRules::budgetOf($n410, 'N410.31.7', '2026-12-01')), 'before every budget: the earliest');
		$this->assertSame('b3', $id(FeeModelRules::budgetOf($n410, 'N410.31.2', '2027-03-01')), 'the most precise code before the day');
		$this->assertSame('b1', $id(FeeModelRules::budgetOf($n410, 'N410.5', '2027-03-01')), 'the project code on a package');
		$this->assertSame('bx', $id(FeeModelRules::budgetOf($n410, 'N411.1', '2027-03-01')), 'no package: the budget by effort');
		$this->assertNull(FeeModelRules::budgetOf(self::obj('{"budgets":[{"id":"z","work_packages":[{"no":"1","codes":["N5"]}]}]}'), 'N4', '2027-01-01'), 'no package, no budget by effort');
		$this->assertSame('b2', $id(FeeModelRules::budgetOf(self::obj('{"budgets":[{"id":"b1","start":"2027-01-01","end":"2027-03-31","work_packages":[{"codes":["X"]}]},{"id":"b2","start":"2027-02-01","end":"2027-03-31","work_packages":[{"codes":["X"]}]},{"id":"b3","start":"2028-01-01","work_packages":[{"codes":["X"]}]}]}'), 'X', '2027-05-01')), 'the one that began last before the day');
		// The person's right: every code in a budget by category.
		$this->assertTrue(FeeModelRules::inCategoryModel($projects, ['N410.31'], '2027-03-01'));
		$this->assertFalse(FeeModelRules::inCategoryModel($projects, ['N410.31'], '2027-09-01'), 'by role from July');
		$this->assertFalse(FeeModelRules::inCategoryModel($projects, ['N410.31', 'N4.1'], '2027-03-01'), 'each code counts');
		$this->assertTrue(FeeModelRules::inCategoryModel($projects, ['N410.31', 'N411'], '2027-03-01'));
		$this->assertFalse(FeeModelRules::inCategoryModel($projects, [], '2027-03-01'), 'no code, no budget');
		$this->assertFalse(FeeModelRules::inCategoryModel($projects, ['Z1'], '2027-03-01'), 'no project');
	}

	/** @param array<string,mixed> $over */
	private static function invoice(array $over = []): \stdClass {
		return (object)array_merge([
			'schema' => 1, 'project' => 'p1', 'budget' => 'b1', 'from' => '2027-01-01', 'to' => '2027-01-31', 'status' => 'draft', 'number' => null, 'bookings' => [],
			'positions' => [(object)['kind' => 'hours', 'level' => 'b', 'level_name' => 'Kategorie B', 'text' => 'Vorprojekt', 'hours' => 62.5, 'rate' => 197, 'amount' => 12312.5, 'work_package' => 0, 'vat' => 8.1]],
		], $over);
	}

	public function testLevelOnHoursLinesAndBookings(): void {
		$this->assertSame('', self::err(fn () => InvoiceRules::validate(self::invoice())));
		$p = static fn (string $json): string => self::err(fn () => InvoiceRules::validate(self::invoice(['positions' => [self::obj($json)]])));
		$this->assertSame('', $p('{"kind":"hours","text":"x","hours":1,"rate":1,"amount":1}'), 'without a level as before');
		$this->assertSame('', $p('{"kind":"hours","level":null,"level_name":null,"text":"x","hours":1,"amount":1}'));
		$this->assertSame('', $p('{"kind":"hours","level":"d","text":"x","hours":1,"rate":144,"amount":144}'), 'a name is optional');
		$this->assertSame('422 - level', $p('{"kind":"hours","level":"B","text":"x","hours":1,"rate":1,"amount":1}'));
		$this->assertSame('422 - level', $p('{"kind":"hours","level":7,"text":"x","hours":1,"rate":1,"amount":1}'));
		$this->assertSame('422 - level_name', $p('{"kind":"hours","level_name":"Kategorie B","text":"x","hours":1,"rate":1,"amount":1}'), 'a name only beside a level');
		$this->assertSame('422 - level_name', $p('{"kind":"hours","level":"b","level_name":"","text":"x","hours":1,"rate":1,"amount":1}'));
		$this->assertSame('422 - level_name', $p('{"kind":"hours","level":"b","level_name":"' . str_repeat('x', 61) . '","text":"x","hours":1,"rate":1,"amount":1}'));
		$this->assertSame('422 - level_name', $p('{"kind":"hours","level":"b","level_name":"a\\u0007b","text":"x","hours":1,"rate":1,"amount":1}'));
		$this->assertSame('422 amount_without_rate rate', $p('{"kind":"hours","level":"b","text":"x","hours":1,"amount":1}'));
		$this->assertSame('422 amount_without_rate rate', $p('{"kind":"hours","level":"b","text":"x","hours":1,"rate":null,"amount":1}'));
		$this->assertSame('422 - level', $p('{"kind":"flat","level":"b","text":"x","amount":1}'), 'only hours lines');
		$this->assertSame('422 - level_name', $p('{"kind":"flat","level_name":"B","text":"x","amount":1}'));
		$this->assertSame('422 - -', $p('{"kind":"category","category":"B","text":"x","hours":1,"rate":1,"amount":1}'), 'the drafts’ kind is unknown');
		$this->assertStringStartsWith('Position 1:', self::message(fn () => InvoiceRules::validate(self::invoice(['positions' => [self::obj('{"kind":"hours","level":"b","text":"x","hours":1,"amount":1}')]]))));
		// A fee line: the base of the expense percentage.
		$lines = [self::obj('{"kind":"hours","level":"b","text":"x","hours":10,"rate":100,"amount":1000}'), self::obj('{"kind":"expense_percent","text":"Spesen","rate":5,"amount":50}')];
		$this->assertSame('', self::err(fn () => ReceiptLineRules::check($lines, [], 'p1', 'b1', 'inv-1', static fn (string $k): string => $k)));
		// bookings[].level: null or an id; carried into the invoice's hours. A leftover category passes, unread.
		$b = static fn (string $more): \stdClass => self::invoice(['bookings' => [self::obj('{"person":"anna","uid":"e1","start":"2027-01-12T08:00:00","hours":2,"rate":197,"code":"N410.31"' . $more . '}')]]);
		$this->assertSame('', self::err(fn () => InvoiceRules::validate($b(',"level":"b"'))));
		$this->assertSame('', self::err(fn () => InvoiceRules::validate($b(',"level":null'))));
		$this->assertSame('', self::err(fn () => InvoiceRules::validate($b(',"category":"B C"'))));
		$this->assertSame('422 - level', self::err(fn () => InvoiceRules::validate($b(',"level":"B C"'))));
		$this->assertStringStartsWith('Booking 1:', self::message(fn () => InvoiceRules::validate($b(',"level":"B"'))));
		$h = IssueRules::hoursOf(IssueRules::bookingsOf($b(',"level":"b","category":"B"')));
		$this->assertSame('b', $h[0]['level']);
		$this->assertArrayNotHasKey('category', $h[0]);
		$this->assertNull(IssueRules::hoursOf(IssueRules::bookingsOf($b('')))[0]['level']);
	}

	public function testInvoiceHoursPresentTheLevelOnlyWhenSet(): void {
		$row = new InvHour();
		$row->setUid('anna');
		$row->setEventUid('e1');
		$row->setStart('2027-01-12T08:00:00');
		$row->setHours('2.00');
		$row->setRate('197.00');
		$row->setAmount('394.00');
		$row->setCode('N410.31');
		$this->assertSame(['uid', 'event_uid', 'start', 'hours', 'rate', 'amount', 'code'], array_keys($row->present()), 'the payload of 0.32.0 stays');
		$row->setLevel('b');
		$this->assertSame('b', $row->present()['level']);
		$this->assertFalse(property_exists($row, 'category'));
	}

	public function testIssueNeedsLevels(): void {
		$settings = self::obj('{"currency":"CHF","levels":[{"id":"b","name":"Kategorie B","rates":[{"from":"2027-01-01","rate":197}]},{"id":"x","name":"Ohne Satz"}]}');
		$none = self::obj('{"currency":"CHF","levels":[]}');
		$budget = static fn (?string $model): \stdClass => self::obj('{"id":"b1"' . ($model === null ? '' : ',"fee_model":"' . $model . '"') . '}');
		$check = static fn (\stdClass $d, ?\stdClass $s, ?\stdClass $budget, ?\stdClass $project = null, string $currency = 'CHF'): string
			=> self::err(fn () => IssueRules::requireLevels($d, $s, $project, $budget, $currency, '2027-02-05'));
		$this->assertSame('', $check(self::invoice(), $settings, $budget('category')));
		// no_levels: hours of a budget by role or by category in a team without levels.
		$plain = self::invoice(['positions' => [self::obj('{"kind":"hours","text":"x","hours":1,"rate":150,"amount":150}')]]);
		$this->assertSame('422 no_levels levels', $check($plain, $none, $budget(null)), 'missing reads as role');
		$this->assertSame('422 no_levels levels', $check($plain, null, $budget('role')));
		$this->assertSame('422 no_levels levels', $check($plain, $none, $budget('category')));
		$this->assertSame('', $check($plain, $none, $budget('mean_rate')), 'the mean rate needs no level');
		$this->assertSame('', $check(self::invoice(['positions' => [self::obj('{"kind":"flat","text":"Pauschale","amount":5000}')]]), $none, $budget('role')), 'no hours billed');
		$booking = self::invoice(['positions' => [], 'bookings' => [self::obj('{"person":"anna","uid":"e1","start":"2027-01-12T08:00:00","hours":2,"rate":150}')]]);
		$this->assertSame('422 no_levels levels', $check($booking, $none, $budget('role')), 'a booking bills hours');
		$this->assertSame('', $check($plain, self::obj('{"levels":[{"id":"x","name":"X"}]}'), $budget('role')), 'a level without a rate counts');
		try {
			IssueRules::requireLevels($plain, $none, null, $budget('role'), 'CHF', '2027-02-05');
			$this->fail('passes without levels');
		} catch (ApiException $e) {
			$this->assertSame(['error' => 'invalid', 'code' => 'no_levels', 'field' => 'levels', 'budget' => 'b1'], array_diff_key($e->toData(), ['message' => 1]));
		}
		// level_rate_missing: every level of the lines has a rate in the currency on `to`, else on the issue day.
		$this->assertSame('422 level_rate_missing level', $check(self::invoice(), $settings, $budget('category'), null, 'EUR'), 'no exchange rate');
		$this->assertSame('422 level_rate_missing level', $check(self::invoice(['to' => '2026-12-31']), $settings, $budget('category')), 'before the first rate');
		$this->assertSame('', $check(self::invoice(['to' => null]), $settings, $budget('category')), 'the issue day');
		$this->assertSame('422 level_rate_missing level', $check(self::invoice(['positions' => [self::obj('{"kind":"hours","level":"x","text":"x","hours":1,"rate":1,"amount":1}')]]), $settings, $budget('category')), 'a level without a rate');
		$this->assertSame('422 level_rate_missing level', $check(self::invoice(['positions' => [self::obj('{"kind":"hours","level":"z","text":"x","hours":1,"rate":1,"amount":1}')]]), $settings, $budget('role')), 'an unknown level');
		$this->assertSame('', $check(self::invoice(['positions' => [self::obj('{"kind":"hours","level":"x","text":"x","hours":1,"rate":1,"amount":1}')]]), $settings, $budget('category'), self::obj('{"level_rates":{"x":120}}')), 'the project’s rate');
		$this->assertSame('', $check(self::invoice(), $settings, $budget('category'), self::obj('{"currency":"EUR","level_rates":{"b":180}}'), 'EUR'), 'the project’s rate in its currency');
		try {
			IssueRules::requireLevels(self::invoice(), $settings, null, $budget('category'), 'EUR', '2027-02-05');
			$this->fail('passes without a rate');
		} catch (ApiException $e) {
			$this->assertSame(['error' => 'invalid', 'code' => 'level_rate_missing', 'field' => 'level', 'level' => 'b'], array_diff_key($e->toData(), ['message' => 1]));
			$this->assertStringStartsWith('Position 1: the level b has no rate in EUR on 2027-01-31', $e->getText()->text());
		}
	}
}
