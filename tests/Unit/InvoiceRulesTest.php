<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Capabilities;
use OCA\TimeSister\Db\Record;
use OCA\TimeSister\Db\RecordMapper;
use OCA\TimeSister\Service\AccessPolicy;
use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\InvoiceNumbering;
use OCA\TimeSister\Service\InvoiceRules;
use OCA\TimeSister\Service\Json;
use OCA\TimeSister\Service\Membership;
use OCA\TimeSister\Service\RecordRights;
use OCA\TimeSister\Service\RecordValidator;
use OCA\TimeSister\Service\TenantService;
use OCA\TimeSister\Service\ZipRules;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * Invoices (0.16.0): the record's content, who reads and writes it (a
 * Lead only drafts without a number of their projects), the number
 * pattern in the settings and the counter's arithmetic.
 */
class InvoiceRulesTest extends TestCase {
	private const DRAFT = ['schema' => 1, 'project' => 'd200', 'budget' => 'b1', 'from' => '2026-09-01', 'to' => '2026-09-30',
		'status' => 'draft', 'number' => null,
		'bookings' => [['person' => 'alice', 'uid' => '7f3a-1@example.test', 'start' => '2026-10-13T09:00:00']],
		'note' => null, 'created_by' => 'pblead', 'created_on' => '2026-10-13', 'issued_on' => null, 'paid_on' => null];

	private static function data(array $over = [], array $drop = []): \stdClass {
		$d = array_diff_key(array_merge(self::DRAFT, $over), array_flip($drop));
		$v = Json::decode((string)json_encode($d));
		self::assertInstanceOf(\stdClass::class, $v);
		return $v;
	}

	/** Status and message of an ApiException, or `ok`. */
	private static function outcome(callable $fn): string {
		try {
			$fn();
		} catch (ApiException $e) {
			return $e->getStatus() . ' ' . $e->getMessage();
		}
		return 'ok';
	}

	private static function check(array $over = [], array $drop = []): string {
		return self::outcome(fn () => RecordValidator::validate('invoice', 'inv-1a2b3c4d', self::data($over, $drop)));
	}

	/** 0.27.0 (controlling): the dunning level, the day of the last reminder and the payments. */
	public function testPaymentsAndDunning(): void {
		$this->assertSame('ok', self::check(['dunning_level' => 2, 'dunned_on' => '2026-10-20']));
		$this->assertSame('ok', self::check(['dunning_level' => null, 'dunned_on' => null, 'payments' => null]));
		$this->assertSame('ok', self::check(['payments' => [['date' => '2026-10-01', 'amount' => 300], ['date' => '2026-10-15', 'amount' => 700.5, 'note' => 'rest']]]));
		$this->assertSame('422 “dunning_level” must be null or an integer from 0 to 3.', self::check(['dunning_level' => 4]));
		$this->assertSame('422 “dunning_level” must be null or an integer from 0 to 3.', self::check(['dunning_level' => '1']));
		$this->assertSame('422 “dunned_on” must be a day in the format YYYY-MM-DD.', self::check(['dunned_on' => '20.10.2026']));
		$bad = '422 “payments” must be null or a list of at most 200 payments, each with a day “date”, an “amount” above 0 and an optional “note”.';
		$this->assertSame($bad, self::check(['payments' => 'x']));
		$this->assertSame($bad, self::check(['payments' => [['date' => '2026-10-01', 'amount' => 0]]]));
		$this->assertSame($bad, self::check(['payments' => [['date' => '2026-10-1', 'amount' => 1]]]));
		$this->assertSame($bad, self::check(['payments' => [['date' => '2026-10-01', 'amount' => 1, 'note' => str_repeat('n', 501)]]]));
		$d = self::data(['payments' => [['date' => '2026-10-01', 'amount' => 300], ['date' => '2026-10-15', 'amount' => 700.5]]]);
		$this->assertSame(1000.5, InvoiceRules::paymentsSum($d));
		$this->assertSame(0.0, InvoiceRules::paymentsSum(self::data()));
	}

	public function testKindAndKey(): void {
		$this->assertContains('invoice', RecordValidator::KINDS);
		$this->assertTrue(RecordValidator::isKey('inv-1a2b3c4d'));
		$this->assertContains('invoice', RecordRights::PER_RECORD);
	}

	public function testValidDraftStoredAsSent(): void {
		$r = RecordValidator::validate('invoice', 'inv-1a2b3c4d', self::data(['extra' => ['kept' => true]]));
		$this->assertSame([], $r['accounts']);
		$this->assertStringContainsString('"extra":{"kept":true}', $r['json'], 'other fields pass, as for projects');
		$this->assertSame('ok', self::check([], ['note', 'created_by', 'created_on', 'issued_on', 'paid_on', 'number', 'schema']), 'optional fields may be missing');
		$this->assertSame('ok', self::check(['status' => 'issued', 'number' => 'RE-1', 'hours' => 12.5, 'amount' => 1875, 'currency' => 'CHF']), 'frozen when issued');
		$r = RecordValidator::validate('invoice', 'inv-1a2b3c4d', self::data(['hours' => 0, 'amount' => 0.0, 'currency' => 'chf']));
		$this->assertStringContainsString('"currency":"CHF"', $r['json'], 'currency upper case');
		$this->assertSame('ok', self::check(['bookings' => []]));
		$this->assertSame('ok', self::check(['from' => '2026-09-30', 'to' => '2026-09-30']));
		foreach (['issued', 'paid', 'cancelled'] as $s) {
			$this->assertSame('ok', self::check(['status' => $s, 'number' => 'RE-2026-0012', 'issued_on' => '2026-10-14']), $s);
		}
		$this->assertSame('ok', self::check(['note' => "Line one\nLine two\twith tab"]));
		$this->assertSame('ok', self::check(['bookings' => [['person' => 'alice', 'uid' => 'u1', 'start' => '2026-09-02'], ['person' => 'alice', 'uid' => 'u1', 'start' => '2026-09-03'],
			['person' => 'bob', 'uid' => 'u1', 'start' => '2026-09-02'], ['person' => 'alice', 'uid' => 'u1', 'start' => '2026-09-02T08:00:00']]]), 'occurrences of a series differ by start');
	}

	public function testInvalidFields(): void {
		$this->assertSame('422 “project” must be the key of a project.', self::check([], ['project']));
		$this->assertSame('422 “project” must be the key of a project.', self::check(['project' => 'a/b']));
		$this->assertSame('422 “project” must be the key of a project.', self::check(['project' => 7]));
		foreach ([[], ['budget' => ''], ['budget' => '  '], ['budget' => 3], ['budget' => null]] as $i => $over) {
			$this->assertSame('422 “budget” is required: every invoice belongs to a budget.', $i === 0 ? self::check([], ['budget']) : self::check($over), (string)$i);
		}
		$this->assertSame('422 “budget” must be text of at most 128 characters.', self::check(['budget' => str_repeat('b', 129)]));
		foreach ([-1, '12', true, [1]] as $bad) {
			$this->assertSame('422 “hours” must be null or a number ≥ 0.', self::check(['hours' => $bad]), var_export($bad, true));
		}
		foreach (['12', true, [1]] as $bad) {
			$this->assertSame('422 “amount” must be null or a number.', self::check(['amount' => $bad]), var_export($bad, true));
		}
		$this->assertSame('ok', self::check(['amount' => -200]), '0.18.0: a reversal’s amount is negative');
		$this->assertSame('ok', self::check(['vat' => 8.1]));
		$this->assertSame('ok', self::check(['vat' => 0]));
		foreach ([-1, 100.5, '8.1', true] as $bad) {
			$this->assertSame('422 “vat” must be null or a percentage from 0 to 100.', self::check(['vat' => $bad]), var_export($bad, true));
		}
		foreach (['CH', 'CHFR', 7] as $bad) {
			$this->assertSame('422 “currency” must be three letters, for example CHF.', self::check(['currency' => $bad]), var_export($bad, true));
		}
		$this->assertSame('422 “from” must be a day in the format YYYY-MM-DD.', self::check(['from' => '2026-02-30']));
		$this->assertSame('422 “to” must be a day in the format YYYY-MM-DD.', self::check([], ['to']));
		$this->assertSame('422 “from” must not be after “to”.', self::check(['from' => '2026-10-01']));
		$this->assertSame('422 “status” of an invoice is one of draft, issuing, issued, paid, cancelled, external.', self::check(['status' => 'open']));
		$this->assertSame('422 “status” of an invoice is one of draft, issuing, issued, paid, cancelled, external.', self::check([], ['status']));
		foreach (['', str_repeat('x', 65), 12, "RE\n1"] as $n) {
			$this->assertSame('422 “number” must be null or text of 1 to 64 characters.', self::check(['number' => $n]), var_export($n, true));
		}
		$this->assertSame('ok', self::check(['number' => str_repeat('x', 64)]));
		$this->assertSame('422 “note” must be text of at most 2000 characters.', self::check(['note' => str_repeat('x', 2001)]));
		$this->assertSame('422 “note” must be text of at most 2000 characters.', self::check(['note' => "bell\x07"]));
		$this->assertSame('422 “created_by” must be text of at most 64 characters.', self::check(['created_by' => str_repeat('x', 65)]));
		$this->assertSame('422 “issued_on” must be a day in the format YYYY-MM-DD.', self::check(['issued_on' => '14.10.2026']));
		$this->assertSame('422 “schema” is invalid.', self::check(['schema' => '1']));
	}

	/** 0.23.1: `external` on the record – null or {system, id, url?, at?, by?}, stored complete. */
	public function testExternal(): void {
		$this->assertSame('ok', self::check(['external' => null]));
		$r = RecordValidator::validate('invoice', 'inv-1a2b3c4d', self::data(['external' => ['system' => 'sevdesk', 'id' => 4711]]));
		$this->assertStringContainsString('"external":{"system":"sevdesk","id":"4711","url":null,"at":null,"by":null}', $r['json'], 'the number as text, missing fields null');
		$r = RecordValidator::validate('invoice', 'inv-1a2b3c4d', self::data(['status' => 'issued', 'number' => 'RE-1',
			'external' => ['system' => 'ch.example.csv', 'id' => 'R-17', 'url' => 'https://example.org/r/17', 'at' => '2026-10-17T09:45:00Z', 'by' => 'pbadmin']]));
		$this->assertStringContainsString('"external":{"system":"ch.example.csv","id":"R-17","url":"https://example.org/r/17","at":"2026-10-17T09:45:00Z","by":"pbadmin"}', $r['json']);
		$r = RecordValidator::validate('invoice', 'inv-1a2b3c4d', self::data());
		$this->assertStringNotContainsString('"external"', $r['json'], 'absent stays absent');
		foreach (['bexio', 'lexware', 'other', 'datev'] as $name) {
			$this->assertSame('ok', self::check(['external' => ['system' => $name, 'id' => '1']]), $name);
		}
		$this->assertSame('422 “external” has an unknown field “note”; allowed are system, id, number, url, at, by, document and cancelled_at.', self::check(['external' => ['system' => 'bexio', 'id' => '1', 'note' => 'x']]));
		$this->assertSame('422 “external” must be null or an object with “system” and “id”.', self::check(['external' => 'bexio']));
		$this->assertSame('422 “external.system” must be a name of 1 to 64 characters: lowercase letters, digits, “.”, “_” or “-”, starting with a letter or digit (for example “bexio”).', self::check(['external' => ['system' => 'SevDesk', 'id' => '1']]));
		$this->assertSame('422 “external.system” must be a name of 1 to 64 characters: lowercase letters, digits, “.”, “_” or “-”, starting with a letter or digit (for example “bexio”).', self::check(['external' => ['id' => '1']]));
		$this->assertSame('422 “external.id” must be text of at most 128 characters.', self::check(['external' => ['system' => 'bexio', 'id' => str_repeat('1', 129)]]));
		$this->assertSame('422 “external.id” must be text of at most 128 characters.', self::check(['external' => ['system' => 'bexio', 'id' => ' ']]));
		$this->assertSame('422 “external.url” must be an https address of at most 512 characters.', self::check(['external' => ['system' => 'bexio', 'id' => '1', 'url' => 'http://example.org/1']]));
		$this->assertSame('422 “external.by” must be null or text of at most 64 characters.', self::check(['external' => ['system' => 'bexio', 'id' => '1', 'by' => str_repeat('b', 65)]]));
	}

	public function testBookings(): void {
		$b = static fn (array $over = []): array => ['bookings' => [array_merge(['person' => 'alice', 'uid' => 'u1', 'start' => '2026-09-02'], $over)]];
		$this->assertSame('422 “bookings” must be a list of at most 5000 bookings.', self::check([], ['bookings']));
		$this->assertSame('422 “bookings” must be a list of at most 5000 bookings.', self::check(['bookings' => ['a' => 1]]));
		$this->assertSame('422 Booking 1: Each booking must be a JSON object.', self::check(['bookings' => ['x']]));
		$this->assertSame('422 Booking 1: “person” must be text of 1 to 64 characters.', self::check($b(['person' => ''])));
		$this->assertSame('422 Booking 1: “person” must be text of 1 to 64 characters.', self::check($b(['person' => str_repeat('p', 65)])));
		$this->assertSame('422 Booking 1: “uid” must be text of 1 to 1024 characters.', self::check($b(['uid' => null])));
		$this->assertSame('422 Booking 1: “uid” must be text of 1 to 1024 characters.', self::check($b(['uid' => str_repeat('u', 1025)])));
		foreach (['2026-09-02 08:00:00', '2026-09-02T08:00', '2026-09-02T08:00:00Z', '2026-09-31', '2026-09-02T24:00:00', null] as $s) {
			$this->assertSame('422 Booking 1: “start” must be a day (YYYY-MM-DD) or a local time (YYYY-MM-DDTHH:MM:SS).', self::check($b(['start' => $s])), var_export($s, true));
		}
		$two = ['person' => 'alice', 'uid' => 'u1', 'start' => '2026-09-02T08:00:00'];
		$this->assertSame('422 Booking 2: The same booking (person, uid, start) is there twice.', self::check(['bookings' => [$two, $two]]));
		$this->assertTrue(InvoiceRules::isStart('2026-09-02T23:59:59'));
		$this->assertFalse(InvoiceRules::isStart('2026-02-29T08:00:00'), 'not a leap year');
	}

	public function testLimits(): void {
		$many = [];
		for ($i = 0; $i < InvoiceRules::MAX_BOOKINGS; $i++) {
			$many[] = ['person' => 'alice', 'uid' => sprintf('%08d-aaaa-bbbb-cccc-0123456789ab', $i), 'start' => '2026-09-02T08:00:00'];
		}
		$this->assertSame('ok', self::check(['bookings' => $many]), '5000 bookings with UUIDs fit');
		$this->assertGreaterThan(RecordValidator::MAX_DATA_BYTES, strlen((string)json_encode($many)), '… more than other kinds may hold');
		$many[] = ['person' => 'alice', 'uid' => 'one-more', 'start' => '2026-09-02'];
		$this->assertSame('422 “bookings” must be a list of at most 5000 bookings.', self::check(['bookings' => $many]));
		$fat = array_fill(0, 800, ['person' => 'alice', 'uid' => str_repeat('u', 1000), 'start' => '2026-09-02']);
		foreach ($fat as $i => $_) {
			$fat[$i]['uid'] .= $i;
		}
		$this->assertSame('413 An invoice may be at most 768 KB.', self::check(['bookings' => $fat]));
	}

	/** 0.18.0: the document's texts, the positions and the hours per booking. */
	public function testTextsPositionsAndHours(): void {
		$pos = static fn (array $over = []): array => array_merge(['kind' => 'hours', 'text' => 'Planning', 'work_package' => 2, 'person' => 'alice', 'hours' => 12.5, 'rate' => 150, 'amount' => 1875], $over);
		$this->assertSame('ok', self::check(['greeting' => "Dear Sir or Madam\n\nFor the work in September:", 'body' => "Payable within 30 days.\tThank you.", 'footer' => 'Muster · Basel', 'due_on' => '2026-11-14']));
		$this->assertSame('ok', self::check(['greeting' => null, 'body' => null, 'footer' => null, 'positions' => null]));
		$this->assertSame('ok', self::check(['positions' => [$pos(), $pos(['kind' => 'flat', 'amount' => 500.5]), $pos(['kind' => 'discount', 'amount' => -50]), $pos(['kind' => 'text', 'amount' => 0, 'hours' => null, 'rate' => null, 'person' => null, 'work_package' => null])]]));
		$this->assertSame('ok', self::check(['positions' => []]));
		$r = RecordValidator::validate('invoice', 'inv-1a2b3c4d', self::data(['positions' => [$pos()]]));
		$this->assertStringContainsString('"positions":[{"kind":"hours","text":"Planning","work_package":2,"person":"alice","hours":12.5,"rate":150,"amount":1875}]', $r['json'], 'stored as sent');
		foreach (['greeting', 'body', 'footer'] as $f) {
			$this->assertSame("422 “{$f}” must be text of at most 4000 characters.", self::check([$f => str_repeat('x', 4001)]));
			$this->assertSame("422 “{$f}” must be text of at most 4000 characters.", self::check([$f => "bell\x07"]));
			$this->assertSame("422 “{$f}” must be text of at most 4000 characters.", self::check([$f => 7]));
		}
		$this->assertSame('422 “due_on” must be a day in the format YYYY-MM-DD.', self::check(['due_on' => '2026-13-01']));
		$this->assertSame('422 “positions” must be a list of at most 500 positions.', self::check(['positions' => 'x']));
		$this->assertSame('422 “positions” must be a list of at most 500 positions.', self::check(['positions' => array_fill(0, 501, $pos())]));
		$this->assertSame('ok', self::check(['positions' => array_fill(0, 500, $pos())]));
		$this->assertSame('422 Position 1: Each position must be a JSON object.', self::check(['positions' => ['x']]));
		$this->assertSame('422 Position 2: “kind” of a position is one of hours, flat, discount, text, expense_percent, expense_flat, costs, markup, advance, advance_deduction.', self::check(['positions' => [$pos(), $pos(['kind' => 'rebate'])]]));
		$this->assertSame('422 Position 1: “text” must be text of 1 to 500 characters.', self::check(['positions' => [$pos(['text' => ''])]]));
		$this->assertSame('422 Position 1: “text” must be text of 1 to 500 characters.', self::check(['positions' => [$pos(['text' => str_repeat('t', 501)])]]));
		$this->assertSame('422 Position 1: “work_package” must be null or an integer ≥ 0.', self::check(['positions' => [$pos(['work_package' => '2'])]]));
		$this->assertSame('422 Position 1: “work_package” must be null or an integer ≥ 0.', self::check(['positions' => [$pos(['work_package' => -1])]]));
		$this->assertSame('422 Position 1: “person” must be text of at most 64 characters.', self::check(['positions' => [$pos(['person' => str_repeat('p', 65)])]]));
		$this->assertSame('422 Position 1: “hours” must be null or a number ≥ 0.', self::check(['positions' => [$pos(['hours' => -1])]]));
		$this->assertSame('422 Position 1: “rate” must be null or a number ≥ 0.', self::check(['positions' => [$pos(['rate' => '150'])]]));
		$this->assertSame('422 Position 1: “amount” must be a number.', self::check(['positions' => [$pos(['amount' => null])]]));
		$this->assertSame('422 Position 1: “amount” must be a number.', self::check(['positions' => [$pos(['amount' => '1875'])]]));
		// 0.21.0: rate and mode per line, cost positions, expense lines.
		$this->assertSame('ok', self::check(['positions' => [$pos(['vat' => 8.1]), $pos(['kind' => 'expense_percent', 'rate' => 3, 'hours' => null, 'amount' => 56.25, 'cost' => 1, 'vat' => 2.6]),
			$pos(['kind' => 'expense_flat', 'amount' => 250, 'cost' => 2, 'vat' => null, 'vat_mode' => 'taxable']), $pos(['kind' => 'costs', 'amount' => 1200, 'cost' => 3, 'vat_mode' => 'pass_through'])]]));
		$this->assertSame('422 Position 1: “vat” must be null or a percentage from 0 to 100.', self::check(['positions' => [$pos(['vat' => 101])]]));
		$this->assertSame('422 Position 1: “vat” must be null or a percentage from 0 to 100.', self::check(['positions' => [$pos(['vat' => '8.1'])]]));
		$this->assertSame('422 Position 1: “vat_mode” must be one of taxable, pass_through.', self::check(['positions' => [$pos(['vat_mode' => 'exempt'])]]));
		$this->assertSame('422 Position 1: “cost” must be null or an integer ≥ 1.', self::check(['positions' => [$pos(['cost' => 0])]]));
		$this->assertSame('422 Position 1: “rate” must be null or a percentage from 0 to 100.', self::check(['positions' => [$pos(['kind' => 'expense_percent', 'rate' => 150])]]));
		$b = static fn (array $over = []): array => ['bookings' => [array_merge(['person' => 'alice', 'uid' => 'u1', 'start' => '2026-09-02'], $over)]];
		$this->assertSame('ok', self::check($b(['hours' => 2.5, 'rate' => 150, 'code' => 'D196.4'])));
		$this->assertSame('ok', self::check($b(['hours' => 0, 'rate' => null, 'code' => null])));
		$this->assertSame('422 Booking 1: “hours” must be null or a number ≥ 0.', self::check($b(['hours' => -2])));
		$this->assertSame('422 Booking 1: “rate” must be null or a number ≥ 0.', self::check($b(['rate' => '150'])));
		$this->assertSame('422 Booking 1: “code” must be text of at most 64 characters.', self::check($b(['code' => str_repeat('c', 65)])));
		$this->assertSame('ok', self::outcome(fn () => InvoiceRules::checkPositions([(object)$pos()])), 'the public check for a credit note’s positions');
		$this->assertSame('422 Position 1: “amount” must be a number.', self::outcome(fn () => InvoiceRules::checkPositions([(object)$pos(['amount' => true])])));
	}

	/** 0.26.0: the advance and its deduction naming the advance invoice. */
	public function testAdvanceAndDeduction(): void {
		$adv = ['kind' => 'advance', 'text' => 'Akonto 1 · Bauprojekt · 30 %', 'amount' => 13500];
		$ded = ['kind' => 'advance_deduction', 'text' => 'Abzüglich Akontorechnung RE-1', 'amount' => -13500, 'vat' => 8.1, 'invoice' => 'inv-0a1b2c3d'];
		$this->assertSame('ok', self::check(['positions' => [$adv]]));
		$this->assertSame('ok', self::check(['positions' => [['kind' => 'hours', 'text' => 'Planung', 'hours' => 300, 'rate' => 150, 'amount' => 45000], $ded]]));
		$this->assertSame('422 Position 1: An advance has an amount ≥ 0.', self::check(['positions' => [array_merge($adv, ['amount' => -1])]]));
		$this->assertSame('422 Position 1: An advance deduction has an amount ≤ 0.', self::check(['positions' => [array_merge($ded, ['amount' => 1])]]));
		$this->assertSame('422 Position 1: “invoice” of an advance deduction must be the key of the advance invoice.', self::check(['positions' => [array_merge($ded, ['invoice' => null])]]));
		$this->assertSame('422 Position 1: “invoice” of an advance deduction must be the key of the advance invoice.', self::check(['positions' => [array_merge($ded, ['invoice' => '../x'])]]));
		$this->assertSame('422 Position 1: Only an advance deduction names an “invoice”.', self::check(['positions' => [array_merge($adv, ['invoice' => 'inv-0a1b2c3d'])]]));
		$this->assertSame('422 Position 1: An advance belongs to the fee: no cost position, not pass-through.', self::check(['positions' => [array_merge($adv, ['cost' => 1])]]));
		$this->assertSame('422 Position 1: An advance belongs to the fee: no cost position, not pass-through.', self::check(['positions' => [array_merge($ded, ['vat_mode' => 'pass_through'])]]));
		// 0.27.0: a reversal or credit note carries the negated lines - the advance signs mirror (a final
		// invoice with an advance deduction was not reversible before: «An advance deduction has an amount ≤ 0»).
		$negAdv = array_merge($adv, ['amount' => -13500]);
		$negDed = array_merge($ded, ['amount' => 13500]);
		foreach (['reverses', 'credits'] as $link) {
			$this->assertSame('ok', self::check([$link => 'inv-0a1b2c3d', 'status' => 'issued', 'number' => 'RE-2', 'positions' => [$negAdv]]), "a reversed advance invoice ($link)");
			$this->assertSame('ok', self::check([$link => 'inv-0a1b2c3d', 'status' => 'issued', 'number' => 'RE-2', 'positions' => [['kind' => 'hours', 'text' => 'Planung', 'hours' => 300, 'rate' => 150, 'amount' => -45000], $negDed]]), "a reversed final invoice ($link)");
			$this->assertSame('422 Position 1: A reversed advance has an amount ≤ 0.', self::check([$link => 'inv-0a1b2c3d', 'status' => 'issued', 'number' => 'RE-2', 'positions' => [$adv]]));
			$this->assertSame('422 Position 1: A reversed advance deduction has an amount ≥ 0.', self::check([$link => 'inv-0a1b2c3d', 'status' => 'issued', 'number' => 'RE-2', 'positions' => [$ded]]));
		}
		$this->assertSame('422 Position 1: An advance deduction has an amount ≤ 0.', self::check(['positions' => [$negDed]]), 'without a link the plain rule');
		$this->assertSame('ok', self::outcome(fn () => InvoiceRules::checkPositions([(object)$negDed], true)), 'the public check for a credit note’s own lines');
		$this->assertTrue(InvoiceRules::isAdvance([$adv]));
		$this->assertTrue(InvoiceRules::isAdvance([(object)$adv]));
		$this->assertFalse(InvoiceRules::isAdvance([$ded]));
		$this->assertFalse(InvoiceRules::isAdvance(null));
	}

	public function testIssuedCode(): void {
		$e = ApiException::issued();
		$this->assertSame([409, 'issued', 'An issued invoice does not change; reverse it.'], [$e->getStatus(), $e->getErrorCode(), $e->getMessage()]);
	}

	public function testLeadWritesOnlyDrafts(): void {
		$led = ['d200' => true];
		$draft = self::data();
		$other = self::data(['project' => 'x9']);
		$issued = self::data(['status' => 'issued', 'number' => 'RE-2026-0001']);
		$numbered = self::data(['number' => 'RE-2026-0001']);
		$r = static fn (?\stdClass $before, ?\stdClass $after): string => self::outcome(fn () => InvoiceRules::requireLeadWrite($before, $after, $led));
		$this->assertSame('ok', $r(null, $draft), 'create a draft');
		$this->assertSame('ok', $r($draft, $draft), 'change a draft');
		$this->assertSame('ok', $r($draft, null), 'delete a draft');
		$this->assertSame('403 Only Team Admins and the project’s Leads write invoices.', $r(null, $other));
		$this->assertSame('403 Only Team Admins and the project’s Leads write invoices.', $r($draft, $other), 'move to a project not led');
		$this->assertSame('403 Only Team Admins and the project’s Leads write invoices.', $r($other, $draft), 'take over from a project not led');
		$this->assertSame('403 Only Team Admins and the project’s Leads write invoices.', $r($other, null));
		$msg = '403 A Lead writes only drafts without a number; issuing, payment and cancelling are for Team Admins.';
		$this->assertSame($msg, $r($draft, $issued), 'issue');
		$this->assertSame($msg, $r(null, $issued));
		$this->assertSame($msg, $r($draft, $numbered), 'set a number');
		$this->assertSame($msg, $r($issued, $draft), 'back to draft');
		$this->assertSame($msg, $r($issued, null), 'delete an issued one');
		$this->assertSame($msg, $r($numbered, null));
		$this->assertSame($msg, $r(self::data([], ['status']), null), 'no status is no draft');
	}

	/**
	 * RecordRights with the active projects each account leads (as
	 * TenantService::leadOf would give them).
	 *
	 * @param array<string,list<string>> $led
	 */
	private static function rights(array $led): RecordRights {
		$team = (new \ReflectionClass(TenantService::class))->newInstanceWithoutConstructor();
		$byUid = [];
		foreach ($led as $uid => $keys) {
			$byUid[$uid] = array_map(static fn (string $k) => ['kind' => 'project', 'key' => $k, 'code' => null, 'name' => $k], $keys);
		}
		(new \ReflectionProperty(TenantService::class, 'leads'))->setValue($team, [1 => $byUid]);
		$records = (new \ReflectionClass(RecordMapper::class))->newInstanceWithoutConstructor();
		return new RecordRights(new AccessPolicy(), $team, $records);
	}

	private static function record(\stdClass $d, bool $tombstone = false): Record {
		$r = new Record();
		$r->setKind('invoice');
		$r->setRkey('inv-1a2b3c4d');
		$r->setData(Json::encode($d));
		$r->setDeleted($tombstone ? 1 : 0);
		return $r;
	}

	/** 0.27.0: the approval mark – content, and what a Lead may do with it. */
	public function testReview(): void {
		$ok = ['state' => 'requested', 'by' => 'lea', 'at' => '2026-10-21T14:05:00', 'note' => 'please check'];
		$this->assertSame('ok', self::check(['review' => $ok]));
		$this->assertSame('ok', self::check(['review' => null]));
		$this->assertSame('ok', self::check(['review' => ['state' => 'returned', 'by' => 'lea', 'at' => '2026-10-21', 'returned_by' => 'petra', 'returned_at' => '2026-10-22T09:00:00', 'returned_note' => 'rate?']]));
		$this->assertStringStartsWith('422', self::check(['review' => 'requested']), 'not an object');
		$this->assertStringStartsWith('422', self::check(['review' => ['state' => 'approved']]), 'unknown state');
		$this->assertStringStartsWith('422', self::check(['review' => $ok + ['changed_at' => '21.10.2026']]), 'time format');
		$this->assertStringStartsWith('422', self::check(['review' => ['state' => 'requested', 'note' => str_repeat('x', 2001)]]), 'note too long');
		$this->assertStringStartsWith('422', self::check(['review' => ['state' => 'requested', 'by' => str_repeat('b', 65)]]), 'by too long');
		// Normalised: only the known members, missing ones null.
		$d = self::data(['review' => ['state' => 'requested', 'by' => 'lea', 'extra' => 1]]);
		InvoiceRules::validate($d);
		$this->assertSame(['state' => 'requested', 'by' => null, 'returned_by' => null, 'at' => null, 'changed_at' => null, 'returned_at' => null, 'note' => null, 'returned_note' => null],
			array_merge(get_object_vars($d->review), ['by' => null]));
		$this->assertSame('lea', $d->review->by);

		// A Lead submits and withdraws, never returns; the return block is the Team Admin's.
		$led = ['d200' => true];
		$r = static fn (?\stdClass $before, ?\stdClass $after): string => self::outcome(fn () => InvoiceRules::requireLeadWrite($before, $after, $led));
		$plain = self::data();
		$requested = self::data(['review' => $ok]);
		$returned = self::data(['review' => ['state' => 'returned', 'by' => 'lea', 'at' => '2026-10-21', 'returned_by' => 'petra', 'returned_at' => '2026-10-22', 'returned_note' => 'rate?']]);
		$resubmitted = self::data(['review' => ['state' => 'requested', 'by' => 'lea', 'at' => '2026-10-23', 'returned_by' => 'petra', 'returned_at' => '2026-10-22', 'returned_note' => 'rate?']]);
		$this->assertSame('ok', $r($plain, $requested), 'submit');
		$this->assertSame('ok', $r($requested, $plain), 'withdraw');
		$this->assertSame('ok', $r($requested, null), 'delete');
		$this->assertSame('ok', $r($returned, $resubmitted), 'submit again, the return stays as history');
		$this->assertSame('ok', $r($returned, $plain), 'withdraw after a return');
		$msg = '403 Only Team Admins return a draft submitted for approval; a Lead submits or withdraws it.';
		$this->assertSame($msg, $r($requested, $returned), 'a Lead cannot return');
		$this->assertSame($msg, $r(null, $returned));
		$this->assertSame($msg, $r($returned, self::data(['review' => ['state' => 'requested', 'by' => 'lea']])), 'dropping the return block');
		$this->assertSame($msg, $r($returned, self::data(['review' => ['state' => 'returned', 'returned_by' => 'petra', 'returned_at' => '2026-10-22', 'returned_note' => 'changed']])), 'changing the note');
	}

	public function testRightsPerRole(): void {
		$rights = self::rights(['lea' => ['d200'], 'luca' => ['x9']]);
		$admin = new Membership('petra', 1, 'admin');
		$lea = new Membership('lea', 1, 'lead');
		$luca = new Membership('luca', 1, 'lead');
		$mia = new Membership('mia', 1, 'user');
		$rec = self::record(self::data());
		$read = static fn (Membership $m, ?Record $r): bool => $rights->canRead($m, 'invoice', 'inv-1a2b3c4d', [], [$m->uid], $r);

		$this->assertTrue($read($admin, $rec));
		$this->assertTrue($read($admin, null), 'Team Admin: not there is 404, not 403');
		$this->assertTrue($read($lea, $rec), 'Lead of d200');
		$this->assertTrue($read($lea, self::record(self::data(), true)), 'also the tombstone, by its kept data');
		$this->assertFalse($read($lea, null), 'unknown key: 403, existence not revealed');
		$this->assertFalse($read($luca, $rec), 'Lead of another project');
		$this->assertFalse($read($mia, $rec), 'Member');

		$code = static function (callable $fn): string {
			try {
				$fn();
			} catch (ApiException $e) {
				return $e->getStatus() . ' ' . $e->getErrorCode();
			}
			return 'ok';
		};
		$this->assertSame('ok', $code(fn () => $rights->requireWrite($admin, 'invoice', 'inv-1')));
		$this->assertSame('ok', $code(fn () => $rights->requireWrite($lea, 'invoice', 'inv-1')), 'a Lead passes the first check');
		$this->assertSame('403 forbidden', $code(fn () => $rights->requireWrite($mia, 'invoice', 'inv-1')));

		$draft = Json::encode(self::data());
		$issued = Json::encode(self::data(['status' => 'issued', 'number' => 'RE-2026-0001']));
		$this->assertSame('ok', $code(fn () => $rights->requireInvoiceWrite($admin, $rec, $issued)), 'Team Admin issues');
		$this->assertSame('ok', $code(fn () => $rights->requireInvoiceWrite($admin, self::record(self::data(['status' => 'paid'])), null)));
		$this->assertSame('ok', $code(fn () => $rights->requireInvoiceWrite($lea, null, $draft)));
		$this->assertSame('ok', $code(fn () => $rights->requireInvoiceWrite($lea, $rec, null)));
		$this->assertSame('403 forbidden', $code(fn () => $rights->requireInvoiceWrite($lea, $rec, $issued)));
		$this->assertSame('403 forbidden', $code(fn () => $rights->requireInvoiceWrite($luca, null, $draft)));
		$this->assertSame('403 forbidden', $code(fn () => $rights->requireInvoiceWrite($lea, self::record(self::data(['status' => 'issued']), true), $draft)), 'the tombstone of an issued one');

		$w = static fn (string $kind): \stdClass => (object)['kind' => $kind, 'key' => 'k'];
		$this->assertTrue(RecordRights::perRecordOnly([$w('invoice'), $w('billable')]), 'a Lead batches invoices');
		$this->assertFalse(RecordRights::perRecordOnly([$w('invoice'), $w('setting')]));
	}

	public function testProjectOf(): void {
		$this->assertSame('d200', InvoiceRules::projectOf(Json::encode(self::data())));
		$this->assertNull(InvoiceRules::projectOf(null));
		$this->assertNull(InvoiceRules::projectOf('[]'));
		$this->assertNull(InvoiceRules::projectOf('{"project":7}'));
		$this->assertNull(InvoiceRules::projectOf('{'));
	}

	public function testNumberPattern(): void {
		$s = static fn (mixed $v): string => self::outcome(fn () => RecordValidator::validate('setting', 'settings', Json::decode((string)json_encode(['invoice_number' => $v]))));
		foreach (['RE-{year}-{no:4}', '{nr}', 'R{jj}{nr:8}', '{kunde}/{projekt}/{jahr}-{no:1}', '{customer}-{project}-{yy}-{no}', str_repeat('x', 60) . '{no}'] as $p) {
			$this->assertSame('ok', $s(['pattern' => $p, 'per_year' => true, 'start' => 1]), $p);
		}
		$this->assertSame('ok', $s(null), 'null: no pattern');
		$this->assertSame('ok', $s(['pattern' => 'RE-{no}']), 'per_year and start optional');
		$this->assertSame('ok', self::outcome(fn () => RecordValidator::validate('setting', 'settings', Json::decode('{"vacation_calendar":null}'))), 'missing: no check');
		$need = '422 The invoice number pattern needs exactly one number placeholder: {no}, {no:N}, {nr}, {nr:N} (N 1–8).';
		$this->assertSame($need, $s(['pattern' => 'RE-{year}']));
		$this->assertSame($need, $s(['pattern' => '{no}-{nr}']));
		$this->assertSame('422 Unknown placeholder {no:9} in the invoice number pattern.', $s(['pattern' => 'RE-{no:9}']));
		$this->assertSame('422 Unknown placeholder {no:0} in the invoice number pattern.', $s(['pattern' => 'RE-{no:0}']));
		$this->assertSame('422 Unknown placeholder {month} in the invoice number pattern.', $s(['pattern' => '{month}-{no}']));
		$this->assertSame('422 Unknown placeholder {} in the invoice number pattern.', $s(['pattern' => '{}{no}']));
		$this->assertSame('422 Unknown placeholder {YEAR} in the invoice number pattern.', $s(['pattern' => '{YEAR}{no}']));
		$this->assertSame('422 A brace in the invoice number pattern belongs to no placeholder.', $s(['pattern' => 'RE-{no}}']));
		$this->assertSame('422 A brace in the invoice number pattern belongs to no placeholder.', $s(['pattern' => '{RE-{no}']));
		$this->assertSame('422 “invoice_number.pattern” must be text of 1 to 64 characters.', $s(['pattern' => '']));
		$this->assertSame('422 “invoice_number.pattern” must be text of 1 to 64 characters.', $s(['pattern' => str_repeat('x', 61) . '{no}']));
		$this->assertSame('422 “invoice_number.pattern” must be text of 1 to 64 characters.', $s(['per_year' => true]));
		$this->assertSame('422 “invoice_number.pattern” is invalid.', $s(['pattern' => "RE\n{no}"]));
		$this->assertSame('422 “invoice_number” must be an object.', $s('RE-{no}'));
		$this->assertSame('422 “invoice_number.per_year” must be true or false.', $s(['pattern' => '{no}', 'per_year' => 1]));
		foreach ([0, -1, '1', 1.5, 1000000000] as $start) {
			$this->assertSame('422 “invoice_number.start” must be an integer from 1 to 999999999.', $s(['pattern' => '{no}', 'start' => $start]), var_export($start, true));
		}
	}

	public function testCounter(): void {
		$of = static fn (?array $v): array => InvoiceNumbering::of(Json::decode((string)json_encode(['invoice_number' => $v])));
		$this->assertSame(['per_year' => true, 'start' => 1], InvoiceNumbering::of(null), 'no settings: defaults');
		$this->assertSame(['per_year' => true, 'start' => 1], $of(null));
		$this->assertSame(['per_year' => false, 'start' => 100], $of(['pattern' => '{no}', 'per_year' => false, 'start' => 100]));
		$this->assertSame(['per_year' => true, 'start' => 1], $of(['per_year' => 'no', 'start' => 0]), 'malformed: defaults');
		$this->assertSame('invoice:2026', InvoiceNumbering::counter(true, 2026));
		$this->assertSame('invoice', InvoiceNumbering::counter(false, 2026));
		$this->assertSame(1, InvoiceNumbering::next(null, 1), 'the first');
		$this->assertSame(50, InvoiceNumbering::next(null, 50), 'the first at start');
		$this->assertSame(13, InvoiceNumbering::next(12, 1));
		$this->assertSame(50, InvoiceNumbering::next(12, 50), 'start raised later: continue there');
		$this->assertSame(61, InvoiceNumbering::next(60, 50), 'never back below what was given');
		$this->assertSame(2026, InvoiceNumbering::year(null, 2026), 'missing: this year');
		$this->assertSame(2027, InvoiceNumbering::year('2027', 2026), 'from the query');
		$this->assertSame(2027, InvoiceNumbering::year(2027, 2026));
		foreach (['26', 26, 10000, '2026a', 2026.0, true] as $y) {
			$this->assertSame('422 “year” must be a year from 1900 to 9999.', self::outcome(fn () => InvoiceNumbering::year($y, 2026)), var_export($y, true));
		}
	}

	public function testCapabilityAndZip(): void {
		$cap = (new Capabilities($this->createMock(IAppManager::class)))->getCapabilities()['timesister'];
		$this->assertSame(1, $cap['invoices']);
		$this->assertSame(1, $cap['advances'], 'advance invoices, 0.26.0');
		$this->assertSame(2, $cap['invoice_layout'], 'invoice layout, 0.26.0; six appendices from the client since 0.32.0');
		$mapped = ZipRules::mapData(self::data(), ['alice' => 'alice.new', 'pblead' => 'lea']);
		$this->assertInstanceOf(\stdClass::class, $mapped);
		$this->assertSame('alice.new', $mapped->bookings[0]->person, 'a team ZIP moved to other accounts: the booking’s person follows');
		$this->assertSame('lea', $mapped->created_by);
	}
}
