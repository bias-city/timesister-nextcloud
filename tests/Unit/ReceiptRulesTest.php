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
 * who writes what before and after approval; since 0.23.0 approval by
 * assignment.
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
		$this->assertStringStartsWith('422 “budget” and “cost” are both null', self::check(['cost' => null]));
		$this->assertStringStartsWith('422 “budget” and “cost” are both null', self::check(['cost' => 0]));
		$this->assertStringStartsWith('422 “project”, “budget” and “cost” are all null', self::check(['project' => null]));
		$this->assertSame('422 A receipt without a project has no “cost_kind”.', self::check(['project' => null, 'budget' => null, 'cost' => null, 'cost_kind' => 'expense']));
		$this->assertSame('422 “cost_kind” must be one of expense, third_party.', self::check(['cost_kind' => 'travel']));
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

	/** Member triage: spesen or not, project and cost kind; budget and cost position follow later. */
	public function testTriage(): void {
		$this->assertSame('ok', self::check(['budget' => null, 'cost' => null, 'cost_kind' => 'third_party']), 'a project and a kind without budget');
		$this->assertSame('ok', self::check(['budget' => null, 'cost' => null]), 'a project alone');
		$this->assertSame('ok', self::check(['cost_kind' => 'expense']));
		// A member's write keeps the stored assignment while project and kind stay.
		$stored = self::receipt(['cost_kind' => 'third_party', 'invoice_effect' => 'show_only']);
		$w = self::receipt(['cost_kind' => 'third_party', 'budget' => 'b9', 'cost' => 9, 'invoice_effect' => 'none']);
		ReceiptRules::keepAssigned($stored, $w);
		$this->assertSame(['b1', 2, 'show_only'], [$w->budget, $w->cost, $w->invoice_effect], 'the Lead’s fields stay, no error');
		$k = self::receipt(['cost_kind' => 'expense']);
		ReceiptRules::keepAssigned($stored, $k);
		$this->assertSame([null, null, 'show_only'], [$k->budget, $k->cost, $k->invoice_effect], 'another kind: the cost position is cleared');
		$p = self::receipt(['project' => 'd300', 'cost_kind' => 'third_party']);
		ReceiptRules::keepAssigned($stored, $p);
		$this->assertSame([null, null, 'charge'], [$p->budget, $p->cost, $p->invoice_effect], 'another project: cleared, the default effect');
		$n = self::receipt(['cost_kind' => 'expense', 'invoice_effect' => 'none']);
		ReceiptRules::keepAssigned(null, $n);
		$this->assertSame([null, null, 'charge'], [$n->budget, $n->cost, $n->invoice_effect], 'a new receipt of a member');
		$g = self::receipt(['project' => null, 'budget' => null, 'cost' => null, 'invoice_effect' => 'none']);
		ReceiptRules::keepAssigned($stored, $g);
		$this->assertSame([null, null, 'none'], [$g->budget, $g->cost, $g->invoice_effect]);
		// The cost position must be of the receipt's kind.
		$project = (object)['budgets' => [(object)['id' => 'b1', 'costs' => [(object)['no' => 1, 'kind' => 'expense'], (object)['no' => 2, 'kind' => 'third_party']]]]];
		$this->assertSame('third_party', ReceiptRules::costKind($project, 'b1', 2));
		$this->assertNull(ReceiptRules::costKind($project, 'b1', 5));
		$this->assertSame('ok', self::outcome(fn () => ReceiptRules::requireCost(self::receipt(['cost_kind' => 'third_party']), $project)));
		$this->assertSame('ok', self::outcome(fn () => ReceiptRules::requireCost(self::receipt(), $project)), 'no kind: any position');
		$this->assertSame('422 The cost position 2 is not of the receipt’s kind “expense”.', self::outcome(fn () => ReceiptRules::requireCost(self::receipt(['cost_kind' => 'expense']), $project)));
		$this->assertSame('422 The budget of the project has no such cost position.', self::outcome(fn () => ReceiptRules::requireCost(self::receipt(['cost' => 7]), $project)));
		// Approval needs budget and cost position of a project receipt.
		$this->assertSame('422 Before approval a Lead or Team Admin assigns the receipt to a budget and a cost position.', self::outcome(fn () => ReceiptRules::requireApprovable(self::receipt(['budget' => null, 'cost' => null, 'cost_kind' => 'expense']), $project)));
		$this->assertSame('422 The cost position 2 is not of the receipt’s kind “expense”.', self::outcome(fn () => ReceiptRules::requireApprovable(self::receipt(['cost_kind' => 'expense']), $project)));
		$this->assertSame('ok', self::outcome(fn () => ReceiptRules::requireApprovable(self::receipt(['cost_kind' => 'third_party']), $project)));
		$this->assertSame('ok', self::outcome(fn () => ReceiptRules::requireApprovable(self::receipt(['project' => null, 'budget' => null, 'cost' => null]), null)), 'general');
		$this->assertSame('422 The receipt’s project does not exist.', self::outcome(fn () => ReceiptRules::requireApprovable(self::receipt(), null)));
		// After approval a project receipt keeps its assignment.
		$ok = self::receipt(['created_by' => 'alice', 'status' => 'approved']);
		$bare = self::receipt(['created_by' => 'alice', 'status' => 'approved', 'budget' => null, 'cost' => null]);
		$this->assertSame('422 An approved receipt of a project keeps a budget and a cost position.', self::outcome(fn () => ReceiptRules::requireWrite(true, 'admin', [], $ok, $bare)));
		$kind = self::receipt(['created_by' => 'alice', 'status' => 'approved', 'cost_kind' => 'third_party']);
		$this->assertSame('ok', self::outcome(fn () => ReceiptRules::requireWrite(false, 'lea', ['d200' => true], $ok, $kind)), 'a Lead corrects the kind');
		// Before approval the member's triage is a proposal: Team Admins correct anything, a Lead only within their projects.
		$proposal = self::receipt(['created_by' => 'alice', 'status' => 'open', 'paid_by' => 'person', 'person' => 'alice', 'reimburse' => true, 'budget' => null, 'cost' => null]);
		$elsewhere = self::receipt(['created_by' => 'alice', 'status' => 'open', 'project' => 'd300', 'budget' => null, 'cost' => null]);
		$this->assertSame('ok', self::outcome(fn () => ReceiptRules::requireWrite(false, 'lea', ['d200' => true], $proposal, self::receipt(['created_by' => 'alice', 'status' => 'open']))), 'the Lead turns spesen into a company cost');
		$this->assertStringStartsWith('403', self::outcome(fn () => ReceiptRules::requireWrite(false, 'lea', ['d200' => true], $proposal, $elsewhere)), 'a project the Lead does not lead');
		$this->assertSame('ok', self::outcome(fn () => ReceiptRules::requireWrite(true, 'admin', [], $proposal, $elsewhere)));
		// A Lead reads a triaged receipt of their project without budget.
		$this->assertTrue(ReceiptRules::canRead(false, 'lea', ['d200' => true], self::receipt(['created_by' => 'alice', 'budget' => null, 'cost' => null, 'cost_kind' => 'expense'])));
	}

	public function testServerFieldsAndStatus(): void {
		$new = self::receipt(['status' => 'billed', 'invoice' => 'inv-x', 'created_by' => 'mallory', 'file' => (object)['path' => 'x']]);
		ReceiptRules::mergeServer(null, $new, 'alice', '2026-10-04', ReceiptRules::OPEN);
		$this->assertSame(['open', null, 'alice', '2026-10-04', null, null], [$new->status, $new->invoice, $new->created_by, $new->created_on, $new->file, $new->approved_by], 'what the client sends is ignored');
		$rec = self::receipt(['kind' => 'recurring', 'project' => null]);
		$this->assertSame('internal', ReceiptRules::initialStatus($rec), 'a recurring general receipt: its rule applies from creation (0.30.0)');
		$this->assertSame('approved', ReceiptRules::initialStatus(self::receipt(['kind' => 'recurring'])));
		$this->assertSame('open', ReceiptRules::initialStatus(self::receipt()));
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
		$this->assertSame('409 An approved receipt changes only in project, cost kind, budget, cost, amount for the budget, invoice effect and note; “net” stays.', self::outcome(fn () => ReceiptRules::requireWrite(true, 'admin', [], $ok, $net)));
		$this->assertSame('409 An approved receipt is not deleted.', self::outcome(fn () => ReceiptRules::requireWrite(true, 'admin', [], $ok, null)));
		$billed = self::receipt(['created_by' => 'alice', 'status' => 'billed']);
		$cost = clone $billed;
		$cost->cost = 3;
		$this->assertSame('409 A billed receipt changes only in “note”; “cost” stays.', self::outcome(fn () => ReceiptRules::requireWrite(true, 'admin', [], $billed, $cost)));
		$this->assertSame(['note'], ReceiptRules::changed($ok, $note));
	}

	/** 0.23.0: assignment is approval – who approves by writing, what is missing, the company's general receipts. */
	public function testApproveByWrite(): void {
		$file = (object)['path' => 'R/a.pdf'];
		$open = fn (array $over = []): \stdClass => self::receipt($over + ['status' => 'open', 'file' => $file, 'currency' => 'CHF', 'review' => false]);
		$approve = function (bool $manages, string $uid, array $led, \stdClass $d, ?string $budget = 'CHF'): array {
			$missing = ReceiptRules::approveByWrite($manages, $uid, $led, $d, 'CHF', $budget, '2026-10-16');
			return [$missing, $d->status, $d->approved_by ?? null];
		};
		$this->assertSame([[], 'approved', 'lead'], $approve(false, 'lead', ['d200' => true], $open()), 'the Lead assigns completely: approved');
		$this->assertSame([[], 'approved', 'admin'], $approve(true, 'admin', [], $open()));
		$this->assertSame([null, 'open', null], $approve(false, 'ann', [], $open()), 'a member never approves');
		$this->assertSame([null, 'open', null], $approve(false, 'lead', ['d9' => true], $open()), 'not the Lead of this project');
		$this->assertSame([null, 'open', null], $approve(false, 'lead', ['d200' => true], $open(['kind' => 'trust'])), 'trust: Team Admins only');
		$this->assertSame([['budget', 'cost'], 'open', null], $approve(true, 'admin', [], $open(['budget' => null, 'cost' => null])));
		$this->assertSame([['file'], 'open', null], $approve(true, 'admin', [], $open(['file' => null])));
		$this->assertSame([[], 'approved', 'admin'], $approve(true, 'admin', [], $open(['kind' => 'trust', 'file' => null])), 'trust has no file');
		$this->assertSame([['review'], 'open', null], $approve(true, 'admin', [], $open(['review' => true])), '«please check» is not approved by a write');
		$this->assertSame([['booked'], 'open', null], $approve(true, 'admin', [], $open(['currency' => 'USD'])));
		$this->assertSame([[], 'approved', 'admin'], $approve(true, 'admin', [], $open(['currency' => 'USD', 'booked' => (object)['gross' => 90.0, 'currency' => 'CHF']])));
		$this->assertSame([['billed'], 'open', null], $approve(true, 'admin', [], $open(['currency' => 'USD', 'booked' => (object)['gross' => 90.0, 'currency' => 'CHF']]), 'EUR'));
		$this->assertSame([null, 'approved', null], $approve(true, 'admin', [], self::receipt(['status' => 'approved'])), 'only an open receipt');
		// General: a Team Admin's write makes it internal; a company's general receipt needs no approval at all.
		$general = ['project' => null, 'budget' => null, 'cost' => null];
		$this->assertSame([[], 'internal', 'admin'], $approve(true, 'admin', [], $open($general + ['paid_by' => 'person', 'person' => 'ann'])));
		$this->assertSame([null, 'open', null], $approve(false, 'ann', [], $open($general + ['paid_by' => 'person', 'person' => 'ann'])), 'a member’s own expense waits');
		$this->assertSame([[], 'internal', 'ann'], $approve(false, 'ann', [], $open($general)), 'company paid, no project: internal from a member too');
		$this->assertSame([['file'], 'open', null], $approve(false, 'ann', [], $open($general + ['file' => null])));
		$this->assertSame([null, 'open', null], $approve(false, 'ann', [], $open($general + ['kind' => 'trust'])), 'a trust receipt still needs a Team Admin');
		// 2026-10-17: amount and supplier first – not for recurring ones (both from the rule).
		$this->assertSame([['gross', 'supplier'], 'open', null], $approve(true, 'admin', [], $open(['net' => 0, 'vat' => 0, 'gross' => 0, 'supplier' => ' '])));
		$this->assertSame([['gross', 'file'], 'open', null], $approve(true, 'admin', [], $open(['net' => 0, 'vat' => 0, 'gross' => 0, 'file' => null])), 'an upload without amount stays open');
		$this->assertSame([['supplier'], 'open', null], $approve(false, 'ann', [], $open($general + ['supplier' => null])), 'a company’s general one too');
		$lines = ['vat_rate' => null, 'vat_lines' => [(object)['rate' => 8.1, 'net' => 100.0, 'vat' => 8.1], (object)['rate' => 2.6, 'net' => 10.0, 'vat' => 0.26]]];
		$this->assertSame(118.36, ReceiptRules::amount(self::receipt($lines)), 'with vat_lines their sum');
		$this->assertSame([], ReceiptRules::missingContent(self::receipt(['kind' => 'recurring', 'gross' => 0, 'supplier' => null])));
		$this->assertSame('ok', self::outcome(fn () => ReceiptRules::requireContent(self::receipt())));
		$this->assertSame('422 Before approval the receipt needs an amount above 0 and a supplier.', self::outcome(fn () => ReceiptRules::requireContent(self::receipt(['gross' => 0, 'supplier' => '']))));
		$this->assertSame('422 Before approval the receipt needs an amount above 0.', self::outcome(fn () => ReceiptRules::requireContent(self::receipt(['gross' => -5]))));
		$this->assertSame('422 Before approval the receipt needs a supplier.', self::outcome(fn () => ReceiptRules::requireContent(self::receipt(['supplier' => null]))));
		$this->assertTrue(ReceiptRules::canApprove(false, ['d200' => true], self::receipt()));
		$this->assertFalse(ReceiptRules::canApprove(false, [], self::receipt()));
		$this->assertTrue(ReceiptRules::needsNoApproval(self::receipt($general)));
		$this->assertFalse(ReceiptRules::needsNoApproval(self::receipt()));
	}

	/** 0.27.0: a Team Admin takes an approval back – not billed, not on a fixed report. */
	public function testReopen(): void {
		$approved = self::receipt(['status' => 'approved', 'approved_by' => 'lead', 'approved_on' => '2026-10-04', 'created_by' => 'ann']);
		$try = fn (bool $manages, \stdClass $d, ?string $held = null): string => self::outcome(fn () => ReceiptRules::requireReopen($manages, $d, $held));
		$this->assertSame('ok', $try(true, $approved));
		$this->assertSame('ok', $try(true, self::receipt(['status' => 'internal', 'project' => null])));
		$this->assertStringStartsWith('403 ', $try(false, $approved), 'a Lead does not take an approval back');
		$this->assertStringContainsString('reverse the invoice first', $try(true, self::receipt(['status' => 'billed', 'invoice' => 'inv-1'])));
		$this->assertStringContainsString('reverse the invoice first', $try(true, self::receipt(['status' => 'approved', 'invoice' => 'inv-1'])), 'an invoice set counts as billed');
		$this->assertStringContainsString('expense report SB-7', $try(true, $approved, 'SB-7'));
		$this->assertStringStartsWith('409 ', $try(true, self::receipt(['status' => 'open'])), 'only an approved one');
		$this->assertStringStartsWith('409 ', $try(true, self::receipt(['status' => 'rejected'])));
		ReceiptRules::reopen($approved, 'admin', '2026-10-22');
		$this->assertSame(['open', null, null, 'admin', '2026-10-22', 'ann'], [$approved->status, $approved->approved_by, $approved->approved_on, $approved->reopened_by, $approved->reopened_on, $approved->created_by], 'open again, logged, the creator stays');
		$this->assertContains('reopened_by', ReceiptRules::SERVER, 'the log is the server’s');
		// Open again: the date changes, as before the approval.
		$changed = clone $approved;
		$changed->date = '2026-11-01';
		$this->assertSame('ok', self::outcome(fn () => ReceiptRules::requireWrite(true, 'admin', [], $approved, $changed)));
		// A client's write keeps the log.
		$again = self::receipt();
		ReceiptRules::mergeServer($approved, $again, 'ann', '2026-10-23', ReceiptRules::OPEN);
		$this->assertSame(['open', 'admin', '2026-10-22'], [$again->status, $again->reopened_by, $again->reopened_on]);
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
