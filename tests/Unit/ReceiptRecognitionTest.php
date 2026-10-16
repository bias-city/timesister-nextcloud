<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\ExpenseReportRules;
use OCA\TimeSister\Service\ReceiptLineRules;
use OCA\TimeSister\Service\ReceiptRecognition;
use OCA\TimeSister\Service\ReceiptRules;
use PHPUnit\Framework\TestCase;

/**
 * The recognition fields of a receipt (0.22.0): the mark «please check»,
 * its source and QR reference, VAT lines for several rates, who clears
 * the mark, and the receipt on the invoice and in the expense report.
 */
class ReceiptRecognitionTest extends TestCase {
	private static function receipt(array $over = []): \stdClass {
		return (object)array_merge([
			'schema' => 1, 'kind' => 'receipt', 'date' => '2026-10-03', 'supplier' => 'Market', 'text' => 'Lunch',
			'net' => 100.0, 'vat_rate' => null, 'vat' => 5.35, 'gross' => 105.35, 'currency' => 'CHF',
			'project' => 'd1', 'budget' => 'b1', 'cost' => 1, 'paid_by' => 'company', 'person' => null, 'note' => null,
			'vat_lines' => [(object)['rate' => 2.6, 'net' => 50.0, 'vat' => 1.3], (object)['rate' => 8.1, 'net' => 50.0, 'vat' => 4.05]],
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

	private static function check(array $over = []): string {
		return self::outcome(fn () => ReceiptRules::validate('rc-1', self::receipt($over)));
	}

	public function testDefaults(): void {
		$d = self::receipt(['vat_lines' => null, 'vat_rate' => 8.1, 'vat' => 8.1, 'gross' => 108.1]);
		ReceiptRules::validate('rc-1', $d);
		$this->assertSame([false, [], null, null, null], [$d->review, $d->review_reasons, $d->recognized_by, $d->qr_reference, $d->vat_lines]);
		$m = self::receipt(['vat' => 5.35, 'gross' => 105.35, 'review' => true, 'review_reasons' => ['Total not found'], 'recognized_by' => 'model', 'qr_reference' => '210000000003139471430009017']);
		ReceiptRules::validate('rc-1', $m);
		$this->assertSame([true, ['Total not found'], 'model', 2], [$m->review, $m->review_reasons, $m->recognized_by, count($m->vat_lines)]);
		$n = self::receipt(['vat' => 5.35, 'gross' => 105.35, 'review' => false, 'review_reasons' => ['stale']]);
		ReceiptRules::validate('rc-1', $n);
		$this->assertSame([], $n->review_reasons, 'no reasons without the mark');
		$e = self::receipt(['vat_lines' => [], 'vat_rate' => 8.1, 'vat' => 8.1, 'gross' => 108.1]);
		ReceiptRules::validate('rc-1', $e);
		$this->assertNull($e->vat_lines, 'an empty list is none');
	}

	public function testErrors(): void {
		$ok = ['vat' => 5.35, 'gross' => 105.35];
		$this->assertSame('ok', self::check($ok));
		$this->assertSame('422 “review” must be true or false.', self::check($ok + ['review' => 1]));
		$this->assertStringStartsWith('422 “review_reasons” must be a list of at most 10 texts', self::check($ok + ['review' => true, 'review_reasons' => array_fill(0, 11, 'x')]));
		$this->assertStringStartsWith('422 “review_reasons”', self::check($ok + ['review' => true, 'review_reasons' => [str_repeat('x', 201)]]));
		$this->assertStringStartsWith('422 “review_reasons”', self::check($ok + ['review' => true, 'review_reasons' => ['']]));
		$this->assertSame('422 “recognized_by” must be null or one of zugferd, qr, model, heuristic.', self::check($ok + ['recognized_by' => 'ocr']));
		$this->assertSame('422 “qr_reference” must be null or text of at most 35 characters.', self::check($ok + ['qr_reference' => str_repeat('1', 36)]));
		$this->assertSame('422 A receipt with “vat_lines” has no single “vat_rate”: it is null.', self::check($ok + ['vat_rate' => 8.1]));
		$this->assertSame('422 The “vat_lines” add up to “net” and “vat”.', self::check(['net' => 90.0, 'vat' => 5.35, 'gross' => 95.35]));
		$this->assertSame('422 In “vat_lines” the VAT at 8.1 % must be the net × rate / 100, rounded to cents.',
			self::check($ok + ['vat_lines' => [(object)['rate' => 2.6, 'net' => 50.0, 'vat' => 1.3], (object)['rate' => 8.1, 'net' => 50.0, 'vat' => 4.2]]]));
		$this->assertSame('422 Each rate stands once in “vat_lines”.',
			self::check(['vat' => 2.6, 'gross' => 102.6, 'vat_lines' => [(object)['rate' => 2.6, 'net' => 50.0, 'vat' => 1.3], (object)['rate' => 2.6, 'net' => 50.0, 'vat' => 1.3]]]));
		$this->assertStringStartsWith('422 “vat_lines” must be a list', self::check($ok + ['vat_lines' => [(object)['rate' => 101, 'net' => 50.0, 'vat' => 1.3]]]));
		$this->assertStringStartsWith('422 “vat_lines” must be a list', self::check($ok + ['vat_lines' => [(object)['rate' => 2.6, 'net' => '50', 'vat' => 1.3]]]));
		$this->assertStringStartsWith('422 “vat_lines” must be a list', self::check($ok + ['vat_lines' => [(object)['rate' => 2.6, 'net' => 50.0, 'vat' => 1.3, 'x' => 1]]]));
	}

	public function testWhoClears(): void {
		$open = self::receipt(['status' => 'open', 'created_by' => 'alice', 'review' => true, 'review_reasons' => ['Sum differs']]);
		$cleared = self::receipt(['status' => 'open', 'created_by' => 'alice', 'review' => false, 'review_reasons' => []]);
		$w = static fn (bool $manages, string $uid, array $led) => self::outcome(fn () => ReceiptRules::requireWrite($manages, $uid, $led, $open, $cleared));
		$this->assertSame('ok', $w(false, 'alice', []), 'who recorded it');
		$this->assertSame('ok', $w(false, 'lea', ['d1' => true]), 'the Lead');
		$this->assertSame('ok', $w(true, 'admin', []), 'a Team Admin');
		$paid = self::receipt(['status' => 'open', 'created_by' => 'alice', 'paid_by' => 'person', 'person' => 'bob', 'review' => true]);
		$this->assertSame('403 Only Team Admins, the project’s Leads and who recorded the receipt mark it as checked.',
			self::outcome(fn () => ReceiptRules::requireWrite(false, 'bob', [], $paid, self::receipt(['status' => 'open', 'created_by' => 'alice', 'paid_by' => 'person', 'person' => 'bob', 'review' => false]))));
		// Setting the mark is for every writer; an old approved record without the fields equals the defaults.
		$this->assertSame('ok', self::outcome(fn () => ReceiptRules::requireWrite(false, 'bob', [], $paid, $paid)));
		$old = self::receipt(['status' => 'approved', 'created_by' => 'alice']);
		$new = clone $old;
		ReceiptRecognition::validate($new);
		$this->assertSame('ok', self::outcome(fn () => ReceiptRules::requireWrite(true, 'admin', [], $old, $new)));
		$d = self::receipt(['review' => true, 'review_reasons' => ['x']]);
		ReceiptRecognition::clear($d);
		$this->assertSame([false, []], [$d->review, $d->review_reasons]);
	}

	public function testOnTheInvoice(): void {
		$rc = ['rc-1' => (object)['status' => 'approved', 'project' => 'd1', 'budget' => 'b1', 'cost' => 1, 'invoice_effect' => 'charge', 'invoice' => null, 'note' => null,
			'net' => 100.0, 'vat_rate' => null, 'vat' => 5.35, 'vat_lines' => [(object)['rate' => 2.6, 'net' => 50.0, 'vat' => 1.3], (object)['rate' => 8.1, 'net' => 50.0, 'vat' => 4.05]]]];
		$line = static fn (float $amount, mixed $vat, array $over = []): \stdClass => (object)array_merge(['kind' => 'costs', 'text' => 'Market', 'amount' => $amount, 'cost' => 1, 'receipts' => ['rc-1'], 'vat' => $vat], $over);
		$check = static fn (array $p): string => self::outcome(fn () => ReceiptLineRules::check($p, $rc, 'd1', 'b1', 'inv-1', static fn (string $k): string => $k));
		$this->assertSame('ok', $check([$line(50, 2.6), $line(50, 8.1)]));
		$this->assertSame('422 Position 1: The receipt rc-1 has several VAT rates; its line names one of them in “vat” (2.6 %, 8.1 %).', $check([$line(100, null)]));
		$this->assertSame('422 Position 2: The receipt rc-1 is on the invoice twice.', $check([$line(50, 2.6), $line(50, 2.6)]));
		$this->assertSame('422 The receipt rc-1 stands on one line per VAT rate; the line at 8.1 % is missing.', $check([$line(50, 2.6)]));
		// A pass-through line takes it once, with its gross.
		$this->assertSame('ok', $check([$line(105.35, null, ['vat_mode' => 'pass_through'])]));
		$this->assertSame('422 Position 2: The receipt rc-1 is on the invoice twice.', $check([$line(105.35, null, ['vat_mode' => 'pass_through']), $line(50, 2.6)]));
	}

	public function testInTheExpenseReport(): void {
		$s = ExpenseReportRules::sums([self::receipt(['vat' => 5.35, 'gross' => 105.35]), self::receipt(['vat_lines' => null, 'vat_rate' => 8.1, 'vat' => 8.1, 'gross' => 108.1])], 'CHF');
		$this->assertSame([['rate' => 2.6, 'net' => 50.0, 'vat' => 1.3], ['rate' => 8.1, 'net' => 150.0, 'vat' => 12.15]], $s['vat_lines']);
		$this->assertSame([200.0, 213.45], [$s['net'], $s['gross']]);
	}
}
