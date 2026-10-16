<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\ReceiptRules;
use OCA\TimeSister\Service\RecordValidator;
use PHPUnit\Framework\TestCase;

/**
 * The receipt (0.22.0): content and defaults, the three questions (who
 * paid, reimbursed, where booked), the server's fields, the status and
 * who writes what before and after approval.
 */
class ReceiptRulesTest extends TestCase {
	private static function receipt(array $over = []): \stdClass {
		return (object)array_merge([
			'schema' => 1, 'kind' => 'receipt', 'date' => '2026-10-03', 'supplier' => 'Druckerei Muster AG', 'text' => 'Flyer A5',
			'net' => 1240.00, 'vat_rate' => 8.1, 'vat' => 100.44, 'gross' => 1340.44, 'currency' => 'chf',
			'project' => 'd200', 'budget' => 'b1', 'cost' => 2, 'paid_by' => 'company', 'person' => null, 'note' => null,
		], $over);
	}

	private static function outcome(callable $fn): string {
		try {
			$fn();
			return 'ok';
		} catch (ApiException $e) {
			return $e->getStatus() . ' ' . $e->getMessage();
		}
	}

	private static function check(array $over = [], string $key = 'rc-1'): string {
		return self::outcome(fn () => ReceiptRules::validate($key, self::receipt($over)));
	}

	public function testValidAndDefaults(): void {
		$d = self::receipt();
		ReceiptRules::validate('rc-7f3a', $d);
		$this->assertSame(['CHF', false, 'charge'], [$d->currency, $d->reimburse, $d->invoice_effect]);
		$c = self::receipt(['paid_by' => 'customer']);
		ReceiptRules::validate('rc-1', $c);
		$this->assertSame('show_only', $c->invoice_effect, 'the customer paid: shown with 0.00');
		$g = self::receipt(['project' => null, 'budget' => null, 'cost' => null]);
		ReceiptRules::validate('rc-1', $g);
		$this->assertSame('none', $g->invoice_effect, 'general: never on an invoice');
		$this->assertSame('ok', self::check(['vat_rate' => null, 'vat' => 0, 'gross' => 1240.0]));
		$this->assertSame('ok', self::check(['paid_by' => 'person', 'person' => 'alice', 'reimburse' => true]));
		$this->assertSame('ok', self::check(['net' => 12.5, 'vat_rate' => 2.6, 'vat' => 0.33, 'gross' => 12.83]));
		$this->assertSame('ok', self::check(['kind' => 'recurring', 'rule' => 'er-1', 'period' => '2026-Q3', 'paid_by' => 'person', 'person' => 'alice']));
		// The record validator routes the kind here.
		$this->assertSame('ok', self::outcome(fn () => RecordValidator::validate('receipt', 'rc-1', self::receipt())));
	}

	public function testErrors(): void {
		$this->assertSame('422 The key of a receipt is “rc-” and up to 125 characters.', self::check([], 'x-1'));
		$this->assertSame('422 “kind” must be one of receipt, trust, recurring.', self::check(['kind' => 'scan']));
		$this->assertSame('422 “date” must be a day in the format YYYY-MM-DD.', self::check(['date' => '3.10.2026']));
		$this->assertSame('422 “supplier” must be text of at most 120 characters.', self::check(['supplier' => str_repeat('s', 121)]));
		$this->assertSame('422 “text” must be text of at most 300 characters.', self::check(['text' => str_repeat('t', 301)]));
		$this->assertSame('422 “net” must be a number.', self::check(['net' => '1240']));
		$this->assertSame('422 “net” plus “vat” must equal “gross”.', self::check(['gross' => 1340.50]));
		$this->assertSame('422 “vat” must be “net” × “vat_rate” / 100, rounded to cents.', self::check(['vat' => 99.0, 'gross' => 1339.0]));
		$this->assertSame('422 “vat_rate” must be null or a percentage from 0 to 100.', self::check(['vat_rate' => 101]));
		$this->assertSame('422 “currency” must be three letters, for example CHF.', self::check(['currency' => 'Franken']));
		$this->assertStringStartsWith('422 “project”, “budget” and “cost” are all null', self::check(['cost' => null]));
		$this->assertStringStartsWith('422 “project”, “budget” and “cost” are all null', self::check(['cost' => 0]));
		$this->assertSame('422 “paid_by” must be one of person, company, customer.', self::check(['paid_by' => 'me']));
		$this->assertSame('422 “person” is required when a person paid: the account’s user ID.', self::check(['paid_by' => 'person']));
		$this->assertSame('422 “person” is only set when a person paid.', self::check(['person' => 'alice']));
		$this->assertSame('422 Only what a person paid is reimbursed.', self::check(['reimburse' => true]));
		$this->assertSame('422 “reimburse” must be true or false.', self::check(['reimburse' => 'yes']));
		$this->assertSame('422 A receipt without a project is never on an invoice: “invoice_effect” is “none”.', self::check(['project' => null, 'budget' => null, 'cost' => null, 'invoice_effect' => 'charge']));
		$this->assertSame('422 “invoice_effect” must be one of charge, show_only, none.', self::check(['invoice_effect' => 'maybe']));
		$this->assertSame('422 “rule” and “period” belong only to a recurring receipt.', self::check(['rule' => 'er-1']));
		$this->assertStringStartsWith('422 A recurring receipt names its “rule” and its “period”', self::check(['kind' => 'recurring', 'rule' => 'er-1', 'period' => '2026-13', 'paid_by' => 'person', 'person' => 'alice']));
	}

	public function testServerFieldsAndStatus(): void {
		$new = self::receipt(['status' => 'billed', 'invoice' => 'inv-x', 'created_by' => 'mallory', 'file' => (object)['path' => 'x']]);
		ReceiptRules::mergeServer(null, $new, 'alice', '2026-10-04', ReceiptRules::OPEN);
		$this->assertSame(['open', null, 'alice', '2026-10-04', null, null], [$new->status, $new->invoice, $new->created_by, $new->created_on, $new->file, $new->approved_by], 'what the client sends is ignored');
		$rec = self::receipt(['kind' => 'recurring', 'project' => null]);
		$this->assertSame('internal', ReceiptRules::initialStatus($rec, true), 'a recurring general receipt of an approved rule');
		$this->assertSame('approved', ReceiptRules::initialStatus(self::receipt(['kind' => 'recurring']), true));
		$this->assertSame('open', ReceiptRules::initialStatus(self::receipt(), true));
		$stored = clone $new;
		$stored->status = 'rejected';
		$stored->reject_reason = 'no file';
		$again = self::receipt();
		ReceiptRules::mergeServer($stored, $again, 'alice', '2026-10-05', ReceiptRules::OPEN);
		$this->assertSame(['open', null, 'alice', '2026-10-04'], [$again->status, $again->reject_reason, $again->created_by, $again->created_on], 'a write opens a rejected receipt again');
		$approved = clone $new;
		$approved->status = 'approved';
		$moved = self::receipt(['project' => null, 'budget' => null, 'cost' => null]);
		ReceiptRules::mergeServer($approved, $moved, 'admin', '2026-10-05', ReceiptRules::OPEN);
		$this->assertSame('internal', $moved->status, 'an approved receipt moved to general is internal');
	}

	public function testRights(): void {
		$own = self::receipt(['created_by' => 'alice', 'status' => 'open']);
		$led = ['d200' => true];
		$this->assertTrue(ReceiptRules::canRead(false, 'alice', [], $own));
		$this->assertTrue(ReceiptRules::canRead(false, 'lea', $led, $own));
		$this->assertTrue(ReceiptRules::canRead(true, 'admin', [], $own));
		$this->assertFalse(ReceiptRules::canRead(false, 'bob', [], $own));
		$this->assertTrue(ReceiptRules::canRead(false, 'bob', [], self::receipt(['created_by' => 'alice', 'paid_by' => 'person', 'person' => 'bob'])), 'paid by them');
		$this->assertSame('ok', self::outcome(fn () => ReceiptRules::requireWrite(false, 'alice', [], $own, $own)));
		$this->assertSame('ok', self::outcome(fn () => ReceiptRules::requireWrite(false, 'alice', [], null, $own)));
		$this->assertStringStartsWith('403', self::outcome(fn () => ReceiptRules::requireWrite(false, 'bob', [], $own, $own)));
		$this->assertSame('ok', self::outcome(fn () => ReceiptRules::requireWrite(false, 'lea', $led, $own, $own)), 'the project’s Lead corrects it');
		// After approval: only Lead or Team Admin, only the correctable fields.
		$ok = self::receipt(['created_by' => 'alice', 'status' => 'approved']);
		$note = clone $ok;
		$note->note = 'checked';
		$net = clone $ok;
		$net->net = 1.0;
		$this->assertStringStartsWith('403', self::outcome(fn () => ReceiptRules::requireWrite(false, 'alice', [], $ok, $note)));
		$this->assertSame('ok', self::outcome(fn () => ReceiptRules::requireWrite(false, 'lea', $led, $ok, $note)));
		$this->assertSame('409 An approved receipt changes only in project, budget, cost, invoice effect and note; “net” stays.', self::outcome(fn () => ReceiptRules::requireWrite(true, 'admin', [], $ok, $net)));
		$this->assertSame('409 An approved receipt is not deleted.', self::outcome(fn () => ReceiptRules::requireWrite(true, 'admin', [], $ok, null)));
		$billed = self::receipt(['created_by' => 'alice', 'status' => 'billed']);
		$cost = clone $billed;
		$cost->cost = 3;
		$this->assertSame('409 A billed receipt changes only in “note”; “cost” stays.', self::outcome(fn () => ReceiptRules::requireWrite(true, 'admin', [], $billed, $cost)));
		$this->assertSame(['note'], ReceiptRules::changed($ok, $note));
	}

	public function testHelpers(): void {
		$project = (object)['codes' => ['D196'], 'budgets' => [(object)['id' => 'b1', 'costs' => [(object)['no' => 2]]]]];
		$this->assertTrue(ReceiptRules::hasCost($project, 'b1', 2));
		$this->assertFalse(ReceiptRules::hasCost($project, 'b1', 3));
		$this->assertFalse(ReceiptRules::hasCost($project, 'b2', 2));
		$this->assertSame('D196', ReceiptRules::projectCode($project, 'p1'));
		$this->assertSame('p1', ReceiptRules::projectCode(null, 'p1'));
		$this->assertSame('application/pdf', ReceiptRules::sniff("%PDF-1.4\n"));
		$this->assertSame('image/jpeg', ReceiptRules::sniff("\xFF\xD8\xFF\xE0"));
		$this->assertSame('image/png', ReceiptRules::sniff("\x89PNG\r\n\x1A\n...."));
		$this->assertNull(ReceiptRules::sniff('hello'));
	}
}
