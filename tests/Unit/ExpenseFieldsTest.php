<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\ReceiptRules;
use PHPUnit\Framework\TestCase;

/**
 * The expense fields of a receipt (0.30.0, PLAN-SPESEN 9 A/B): their form,
 * who sets category, factor and rate, and what a settled receipt keeps.
 */
class ExpenseFieldsTest extends TestCase {
	private static function receipt(array $over = []): \stdClass {
		return (object)array_merge([
			'schema' => 1, 'kind' => 'receipt', 'date' => '2026-10-03', 'supplier' => 'Bahn Muster', 'text' => 'Basel–Bern',
			'net' => 100.0, 'vat_rate' => 8.1, 'vat' => 8.1, 'gross' => 108.1, 'currency' => 'CHF',
			'project' => 'd200', 'budget' => 'b1', 'cost' => 1, 'paid_by' => 'person', 'person' => 'alice', 'reimburse' => true, 'note' => null,
			'status' => 'open', 'created_by' => 'alice',
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

	private static function check(array $over): string {
		return self::outcome(fn () => ReceiptRules::validate('rc-1', self::receipt($over)));
	}

	/** A: the form only, each may be missing or null. */
	public function testForm(): void {
		$this->assertSame('ok', self::check([]));
		$this->assertSame('ok', self::check(['category' => null, 'factor' => null, 'rate' => null, 'template' => null, 'quantity' => null]));
		$this->assertSame('ok', self::check(['category' => 'mobility', 'factor' => 0.5, 'template' => 'km-own-car', 'quantity' => 160,
			'rate' => (object)['value' => 0.9412, 'date' => '2026-10-02', 'source' => 'ecb']]));
		$this->assertSame('ok', self::check(['factor' => 100, 'quantity' => 1000000, 'rate' => (object)['value' => 999999.9, 'date' => '2026-10-02', 'source' => 'manual']]));
		$key = '422 “{f}” must be null or a key of 1 to 40 lower-case letters, digits or dashes.';
		foreach (['Mobility', '-x', 'a b', str_repeat('a', 41), '', 7] as $bad) {
			$this->assertSame(str_replace('{f}', 'category', $key), self::check(['category' => $bad]), var_export($bad, true));
			$this->assertSame(str_replace('{f}', 'template', $key), self::check(['template' => $bad]), var_export($bad, true));
		}
		foreach ([0, -1, 100.5, '1', true] as $bad) {
			$this->assertSame('422 “factor” must be null or a number above 0 and at most 100.', self::check(['factor' => $bad]), var_export($bad, true));
		}
		foreach ([0, 1000001, '160'] as $bad) {
			$this->assertSame('422 “quantity” must be null or a number above 0 and at most 1000000.', self::check(['quantity' => $bad]), var_export($bad, true));
		}
		$rate = '422 “rate” must be null or { “value” above 0 and below 1000000, “date” as YYYY-MM-DD, “source” “ecb” or “manual” }.';
		foreach ([
			(object)['value' => 0, 'date' => '2026-10-02', 'source' => 'ecb'],
			(object)['value' => 1000000, 'date' => '2026-10-02', 'source' => 'ecb'],
			(object)['value' => 0.9, 'date' => '', 'source' => 'manual'],
			(object)['value' => 0.9, 'date' => '2026-10-02', 'source' => 'booked'],
			(object)['value' => 0.9, 'date' => '2026-10-02'],
			(object)['value' => 0.9, 'date' => '2026-10-02', 'source' => 'ecb', 'currency' => 'EUR'],
			0.9,
		] as $bad) {
			$this->assertSame($rate, self::check(['rate' => $bad]), json_encode($bad));
		}
	}

	/** B 1: category, factor and rate are the person's and Team Admins', in any status; a Lead's only on their own expense. */
	public function testOwner(): void {
		$led = ['d200' => true];
		foreach (['open', 'approved', 'internal', 'billed'] as $status) {
			$before = self::receipt(['status' => $status, 'category' => 'mobility', 'project' => $status === 'internal' ? null : 'd200']);
			if ($status === 'internal') {
				$before->budget = null;
				$before->cost = null;
			}
			$after = clone $before;
			$after->factor = 0.5;
			$after->category = 'food';
			$after->rate = (object)['value' => 1.0, 'date' => '2026-10-03', 'source' => 'manual'];
			$this->assertSame('ok', self::outcome(fn () => ReceiptRules::requireWrite(false, 'alice', [], $before, $after)), "the person, $status");
			$this->assertSame('ok', self::outcome(fn () => ReceiptRules::requireWrite(true, 'admin', [], $before, $after)), "a Team Admin, $status");
			$this->assertSame('403 Only the person and Team Admins set category, factor and rate of an expense.',
				self::outcome(fn () => ReceiptRules::requireWrite(false, 'lea', $led, $before, $after)), "the project’s Lead, $status");
		}
		$lead = self::receipt(['person' => 'lea', 'created_by' => 'lea', 'status' => 'approved']);
		$own = clone $lead;
		$own->category = 'office';
		$this->assertSame('ok', self::outcome(fn () => ReceiptRules::requireWrite(false, 'lea', ['d200' => true], $lead, $own)), 'a Lead on their own expense');
		// Not reimbursed: the fields follow the receipt's rules (after approval they stay).
		$company = self::receipt(['paid_by' => 'company', 'person' => null, 'reimburse' => false, 'status' => 'approved']);
		$c2 = clone $company;
		$c2->category = 'office';
		$this->assertStringStartsWith('409 An approved receipt changes only in', self::outcome(fn () => ReceiptRules::requireWrite(true, 'admin', [], $company, $c2)));
		// Approved: the person still changes nothing else, and a Lead may not mix in an expense field.
		$ok = self::receipt(['status' => 'approved']);
		$note = clone $ok;
		$note->note = 'x';
		$note->factor = 0.5;
		$this->assertStringStartsWith('403 After approval only Team Admins and the project’s Leads', self::outcome(fn () => ReceiptRules::requireWrite(false, 'alice', [], $ok, $note)));
		$this->assertStringStartsWith('403 Only the person and Team Admins', self::outcome(fn () => ReceiptRules::requireWrite(false, 'lea', ['d200' => true], $ok, $note)));
		$amount = clone $ok;
		$amount->quantity = 3;
		$this->assertStringStartsWith('403 After approval only', self::outcome(fn () => ReceiptRules::requireWrite(false, 'alice', [], $ok, $amount)), 'template and quantity are content');
		$this->assertStringStartsWith('409 An approved receipt changes only in', self::outcome(fn () => ReceiptRules::requireWrite(true, 'admin', [], $ok, $amount)));
		// Creating with the fields is anyone's who may write the receipt.
		$this->assertSame('ok', self::outcome(fn () => ReceiptRules::requireWrite(false, 'lea', ['d200' => true], null, self::receipt(['category' => 'x']))));
	}

	/** B 2: a settled receipt keeps everything but the correctable fields; Team Admins and the project's Leads change those, in any status. */
	public function testSettled(): void {
		$led = ['d200' => true];
		foreach (['open', 'approved'] as $status) {
			$held = self::receipt(['status' => $status, 'report' => 'sb-1', 'category' => 'mobility']);
			foreach (['category' => 'food', 'factor' => 0.5, 'gross' => 120.0, 'quantity' => 2, 'text' => 'x', 'reimburse' => false] as $f => $v) {
				$after = clone $held;
				$after->{$f} = $v;
				$this->assertSame('409 This expense is settled; reverse the expense report first. “' . $f . '” stays.',
					self::outcome(fn () => ReceiptRules::requireWrite(true, 'admin', [], $held, $after, true)), "$f, $status");
			}
			$moved = clone $held;
			$moved->cost = 2;
			$moved->note = 'K2';
			$this->assertSame('ok', self::outcome(fn () => ReceiptRules::requireWrite(true, 'admin', [], $held, $moved, true)), "Team Admin, $status");
			$this->assertSame('ok', self::outcome(fn () => ReceiptRules::requireWrite(false, 'lea', $led, $held, $moved, true)), "the project’s Lead, $status");
			$this->assertSame('403 Only Team Admins and the project’s Leads change a settled expense.', self::outcome(fn () => ReceiptRules::requireWrite(false, 'alice', [], $held, $moved, true)), "the person, $status");
			$this->assertSame('ok', self::outcome(fn () => ReceiptRules::requireWrite(false, 'alice', [], $held, clone $held, true)), 'nothing changed');
			$this->assertSame('409 This expense is settled; reverse the expense report first.', self::outcome(fn () => ReceiptRules::requireWrite(true, 'admin', [], $held, null, true)), 'not deleted');
		}
		$billed = self::receipt(['status' => 'billed', 'report' => 'sb-1', 'invoice' => 'inv-1']);
		$cost = clone $billed;
		$cost->cost = 2;
		$this->assertSame('409 A billed receipt changes only in “note”; “cost” stays.', self::outcome(fn () => ReceiptRules::requireWrite(true, 'admin', [], $billed, $cost, true)));
		$bare = self::receipt(['status' => 'approved', 'report' => 'sb-1']);
		$noBudget = clone $bare;
		$noBudget->budget = null;
		$noBudget->cost = null;
		$this->assertSame('422 An approved receipt of a project keeps a budget and a cost position.', self::outcome(fn () => ReceiptRules::requireWrite(true, 'admin', [], $bare, $noBudget, true)));
	}
}
