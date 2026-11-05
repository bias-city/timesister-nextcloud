<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Capabilities;
use OCA\TimeSister\Db\Record;
use OCA\TimeSister\Db\RecordMapper;
use OCA\TimeSister\Service\AccessPolicy;
use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\EntryInvoices;
use OCA\TimeSister\Service\HandoverRules;
use OCA\TimeSister\Service\InvoiceRules;
use OCA\TimeSister\Service\IssueRules;
use OCA\TimeSister\Service\Json;
use OCA\TimeSister\Service\LedgerRules;
use OCA\TimeSister\Service\Membership;
use OCA\TimeSister\Service\RecordRights;
use OCA\TimeSister\Service\RecordValidator;
use OCA\TimeSister\Service\TenantService;
use OCA\TimeSister\Service\VatLines;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * Invoices without a budget (0.38.0): optional project and budget, the
 * customer, payment term, kind and companion on the record, the status
 * `planned` with its plan, the customer and period when issuing, the
 * ledger payload without budget, `assign` and the invoice of an entry.
 */
class InvoicesFreeTest extends TestCase {
	private const DRAFT = ['schema' => 1, 'project' => 'd200', 'budget' => 'b1', 'from' => '2026-09-01', 'to' => '2026-09-30',
		'status' => 'draft', 'number' => null,
		'bookings' => [['person' => 'alice', 'uid' => 'ev-1@example.test', 'start' => '2026-09-14T09:00:00']],
		'note' => null, 'created_by' => 'pblead', 'created_on' => '2026-10-13', 'issued_on' => null, 'paid_on' => null];
	private const PLAN = ['target_amount' => 12000, 'share' => null, 'due' => '2026-12-31', 'codes' => ['D200.31'], 'until' => '2026-12-15'];

	private static function data(array $over = [], array $drop = []): \stdClass {
		$d = array_diff_key(array_merge(self::DRAFT, $over), array_flip($drop));
		$v = Json::decode((string)json_encode($d));
		self::assertInstanceOf(\stdClass::class, $v);
		return $v;
	}

	private static function planned(array $over = []): \stdClass {
		return self::data(array_merge(['status' => 'planned', 'bookings' => [], 'plan' => self::PLAN], $over));
	}

	/** Status, rule code (or the message) of an ApiException, or `ok`. */
	private static function outcome(callable $fn, bool $message = false): string {
		try {
			$fn();
		} catch (ApiException $e) {
			return $e->getStatus() . ' ' . ($message ? $e->getMessage() : ($e->getRuleCode() ?? $e->getField() ?? $e->getMessage()));
		}
		return 'ok';
	}

	private static function check(\stdClass $d, bool $message = false): string {
		return self::outcome(fn () => RecordValidator::validate('invoice', 'inv-1a2b3c4d', $d), $message);
	}

	/** The stored JSON of a valid record. @return array<string,mixed> */
	private static function stored(\stdClass $d): array {
		$v = json_decode(RecordValidator::validate('invoice', 'inv-1a2b3c4d', $d)['json'], true);
		self::assertIsArray($v);
		return $v;
	}

	public function testProjectBudgetCustomerOptional(): void {
		$s = self::stored(self::data(['project' => '', 'budget' => ''], ['from', 'to']));
		$this->assertNull($s['project'], '"" is «without» and stored as null');
		$this->assertNull($s['budget']);
		$s = self::stored(self::data(['project' => null, 'budget' => null]));
		$this->assertSame([null, null], [$s['project'], $s['budget']]);
		$this->assertSame(['d200', 'b1'], [self::stored(self::data())['project'], self::stored(self::data())['budget']], 'with a budget unchanged');
		$this->assertSame('422 invoice_budget_without_project', self::check(self::data(['project' => null])));
		$this->assertSame('422 invoice_budget_without_project', self::check(self::data([], ['project'])));
		$this->assertSame('ok', self::check(self::data(['customer' => 'k-muster'])));
		$this->assertNull(self::stored(self::data(['customer' => '']))['customer']);
		$this->assertSame('422 “customer” must be null or the key of a customer.', self::check(self::data(['customer' => 'a/b']), true));
		$this->assertSame('422 “customer” must be null or the key of a customer.', self::check(self::data(['customer' => 7]), true));
		$this->assertArrayNotHasKey('customer', self::stored(self::data()), 'an absent customer stays absent');
	}

	public function testPaymentDaysKindCompanion(): void {
		foreach ([0, 30, 365, null] as $ok) {
			$this->assertSame('ok', self::check(self::data(['payment_days' => $ok])), var_export($ok, true));
		}
		foreach ([-1, 366, '30', 1.5] as $bad) {
			$this->assertSame('422 “payment_days” must be null or an integer from 0 to 365.', self::check(self::data(['payment_days' => $bad]), true), var_export($bad, true));
		}
		foreach ([...InvoiceRules::KINDS, null] as $ok) {
			$this->assertSame('ok', self::check(self::data(['kind' => $ok])), var_export($ok, true));
		}
		$this->assertSame('422 “kind” must be one of hours, flat, advance, final, expenses.', self::check(self::data(['kind' => 'akonto']), true));
		$this->assertSame('ok', self::check(self::data(['companion' => 'inv-9f8e7d6c'])));
		$this->assertSame('422 “companion” must be null or the key of an invoice.', self::check(self::data(['companion' => 'a/b']), true));
		$this->assertSame('ok', self::check(self::data(['from' => '2026-09-01', 'to' => null])), 'one day alone');
	}

	public function testPlanned(): void {
		$s = self::stored(self::planned());
		$this->assertSame(['target_amount' => 12000, 'share' => null, 'due' => '2026-12-31', 'codes' => ['D200.31'], 'until' => '2026-12-15', 'only_recorded' => true], $s['plan'], 'normalised, only_recorded true');
		$this->assertSame('ok', self::check(self::planned(['plan' => ['share' => 30, 'due' => '2027-03-31', 'only_recorded' => false]])));
		$this->assertSame('ok', self::check(self::planned(['project' => null, 'budget' => null])));
		$this->assertSame('422 plan', self::check(self::planned(['plan' => null])), 'planned needs its plan');
		$this->assertSame('422 plan', self::check(self::planned(['plan' => ['due' => '2026-12-31']])), 'a target');
		$this->assertSame('422 plan', self::check(self::planned(['plan' => ['share' => 101, 'due' => '2026-12-31']])));
		$this->assertSame('422 plan', self::check(self::planned(['plan' => ['target_amount' => -1, 'due' => '2026-12-31']])));
		$this->assertSame('422 plan', self::check(self::planned(['plan' => ['share' => 10]])), 'due');
		$this->assertSame('422 plan', self::check(self::planned(['plan' => ['share' => 10, 'due' => '2026-12-31', 'codes' => 'D200']])));
		$this->assertSame('422 plan', self::check(self::planned(['plan' => ['share' => 10, 'due' => '2026-12-31', 'until' => '31.12.2026']])));
		$this->assertSame('422 plan.only_recorded', self::check(self::planned(['plan' => ['share' => 10, 'due' => '2026-12-31', 'only_recorded' => 'yes']])));
		$this->assertSame('422 number', self::check(self::planned(['number' => 'RE-1'])));
		$this->assertSame('422 bookings', self::check(self::planned(['bookings' => self::DRAFT['bookings']])));
		$this->assertSame('422 plan', self::check(self::data(['plan' => self::PLAN])), 'only a planned invoice has a plan');
		$this->assertSame('ok', self::check(self::data(['plan' => null])));
	}

	public function testTransition(): void {
		$planned = self::planned();
		$draft = self::data();
		$t = static fn (?\stdClass $a, ?\stdClass $b): string => self::outcome(fn () => InvoiceRules::checkTransition($a, $b));
		$this->assertSame('ok', $t(null, $planned), 'created planned');
		$this->assertSame('ok', $t($planned, $planned));
		$this->assertSame('ok', $t($planned, $draft), 'planned → draft («Stellen»)');
		$this->assertSame('ok', $t($planned, null), 'deleted');
		$this->assertSame('422 invoice_not_planned', $t($draft, $planned), 'never back');
		$this->assertSame('422 invoice_not_planned', $t(self::data(['status' => 'issued', 'number' => 'RE-1']), $planned));
		$this->assertSame('422 invoice_not_draft', $t($planned, self::data(['status' => 'issued', 'number' => 'RE-1'])), 'never issued as planned');
		$this->assertSame('ok', $t($draft, self::data(['status' => 'issued', 'number' => 'RE-1'])), 'the hand-set status of 0.16.0 stays');
	}

	/** @param array<string,list<string>> $led */
	private static function rights(array $led): RecordRights {
		$team = (new \ReflectionClass(TenantService::class))->newInstanceWithoutConstructor();
		$byUid = [];
		foreach ($led as $uid => $keys) {
			$byUid[$uid] = array_map(static fn (string $k) => ['kind' => 'project', 'key' => $k, 'code' => null, 'name' => $k], $keys);
		}
		(new \ReflectionProperty(TenantService::class, 'leads'))->setValue($team, [1 => $byUid]);
		return new RecordRights(new AccessPolicy(), $team, (new \ReflectionClass(RecordMapper::class))->newInstanceWithoutConstructor());
	}

	public function testRightsWithoutProject(): void {
		$rights = self::rights(['lea' => ['d200']]);
		$admin = new Membership('petra', 1, 'admin');
		$lea = new Membership('lea', 1, 'lead');
		$free = self::data(['project' => null, 'budget' => null, 'customer' => 'k-muster']);
		$rec = new Record();
		$rec->setKind('invoice');
		$rec->setRkey('inv-1a2b3c4d');
		$rec->setData(Json::encode($free));
		$rec->setDeleted(0);
		$this->assertTrue($rights->canRead($admin, 'invoice', 'inv-1a2b3c4d', [], ['petra'], $rec));
		$this->assertFalse($rights->canRead($lea, 'invoice', 'inv-1a2b3c4d', [], ['lea'], $rec), 'without a project: Team Admins only');
		$led = ['d200' => true];
		$w = static fn (?\stdClass $a, ?\stdClass $b): string => self::outcome(fn () => InvoiceRules::requireLeadWrite($a, $b, $led));
		$this->assertSame('403 invoice_lead_only', $w(null, $free));
		$this->assertSame('403 invoice_lead_only', $w(self::data(), $free), 'no moving out of the project');
		$this->assertSame('ok', $w(null, self::planned()), 'a Lead writes planned invoices of their project');
		$this->assertSame('ok', $w(self::planned(), self::data()), 'and makes them drafts');
		$this->assertSame('403 invoice_lead_only', $w(null, self::planned(['project' => null, 'budget' => null])));
	}

	public function testCustomerAndPeriodWhenIssuing(): void {
		$project = (object)['customer' => 'k-projekt', 'budgets' => [(object)['id' => 'b1']]];
		$quotes = [(object)['status' => 'accepted', 'budget_ids' => ['b1'], 'project' => 'd200', 'customer' => 'k-offerte']];
		$this->assertSame('k-eigen', IssueRules::customerOf((object)['customer' => 'k-eigen'], $project, 'd200', 'b1', $quotes), 'the invoice’s own first');
		$this->assertSame('k-offerte', IssueRules::customerOf((object)[], $project, 'd200', 'b1', $quotes), 'then the quote of the budget');
		$this->assertSame('k-projekt', IssueRules::customerOf((object)['customer' => ''], $project, 'd200', null, $quotes), 'then the project’s');
		$this->assertSame('k-eigen', IssueRules::customerOf((object)['customer' => 'k-eigen'], null, '', null, []), 'without a project');
		$this->assertNull(IssueRules::customerOf((object)[], null, '', null, []));
		$e = IssueRules::noCustomer();
		$this->assertSame([422, 'invoice_no_customer', 'customer'], [$e->getStatus(), $e->getRuleCode(), $e->getField()]);

		$b = static fn (string $start): \stdClass => (object)['person' => 'alice', 'uid' => 'u-' . $start, 'start' => $start];
		$this->assertSame(['2026-09-01', '2026-09-30'], IssueRules::periodOf(self::data(), '2026-10-05'), 'as given');
		$bookings = [$b('2026-09-14T09:00:00'), $b('2026-09-03'), $b('2026-09-28T13:00:00')];
		$this->assertSame(['2026-09-03', '2026-09-28'], IssueRules::periodOf((object)['bookings' => $bookings], '2026-10-05'), 'from the bookings');
		$this->assertSame(['2026-09-01', '2026-09-28'], IssueRules::periodOf((object)['from' => '2026-09-01', 'bookings' => $bookings], '2026-10-05'));
		$this->assertSame(['2026-10-01', '2026-10-01'], IssueRules::periodOf((object)['from' => '2026-10-01', 'bookings' => $bookings], '2026-10-05'), 'never from after to');
		$this->assertSame(['2026-10-05', '2026-10-05'], IssueRules::periodOf((object)['bookings' => []], '2026-10-05'), 'no bookings: the issue day');
		$this->assertSame(['2026-08-31', '2026-08-31'], IssueRules::periodOf((object)['to' => '2026-08-31'], '2026-10-05'));
	}

	public function testLevelsOnlyWithABudget(): void {
		$d = self::data(['positions' => [(object)['kind' => 'hours', 'text' => 'Beratung', 'hours' => 2, 'rate' => 150, 'amount' => 300, 'level' => 'senior']]]);
		$this->assertSame('ok', self::outcome(fn () => IssueRules::requireLevels($d, null, null, null, 'CHF', '2026-10-05')), 'without a budget the rate stands as sent');
		$this->assertSame('422 no_levels', self::outcome(fn () => IssueRules::requireLevels($d, null, null, (object)['id' => 'b1'], 'CHF', '2026-10-05')), 'with one as before');
	}

	public function testLedgerPayloads(): void {
		$p = IssueRules::payload('inv-7', 'RE-1', '2026-11-05', 'pbadmin', '2026-12-05', null, 'k-muster', '2026-10-01', '2026-10-31', 'CHF',
			VatLines::of([], 8.1, 'domestic'), new \stdClass(), new \stdClass(), [], [], null, null, null, null, null);
		$this->assertArrayNotHasKey('budget', $p);
		$this->assertSame([null, 'k-muster'], [$p['project'], $p['customer_key']]);
		$v = ['system' => 'bexio', 'id' => '4711', 'url' => null, 'number' => 'RE-17', 'issued_on' => '2026-11-05'];
		$h = HandoverRules::payload('inv-7', $v, null, null, 'k-muster', 'CHF', [], []);
		$this->assertSame([null, null], [$h['project'], $h['budget']], 'a hand-over without project and budget');
		$this->assertSame('{"budget":"b2","budget_before":null,"key":"inv-7","number":"RE-1"}', LedgerRules::canonical(InvoiceRules::assignPayload('inv-7', 'RE-1', null, 'b2')));
		$this->assertSame('assign', LedgerRules::ASSIGN);
	}

	public function testAssign(): void {
		$this->assertNull(InvoiceRules::assignedBudget((object)['budget' => null]));
		$this->assertNull(InvoiceRules::assignedBudget((object)['budget' => '']));
		$this->assertSame('b2', InvoiceRules::assignedBudget((object)['budget' => 'b2']));
		$this->assertSame('422 budget', self::outcome(fn () => InvoiceRules::assignedBudget((object)[])));
		$this->assertStringStartsWith('422', self::outcome(fn () => InvoiceRules::assignedBudget((object)['budget' => str_repeat('b', 129)])));
		$issued = self::data(['status' => 'issued', 'number' => 'RE-1']);
		$a = static fn (bool $manages, array $led, \stdClass $d, ?string $budget): string => self::outcome(fn () => InvoiceRules::requireAssign($manages, $led, $d, $budget));
		$this->assertSame('ok', $a(true, [], $issued, 'b2'));
		$this->assertSame('ok', $a(true, [], self::data(['status' => 'paid', 'number' => 'RE-1']), null), 'released');
		$this->assertSame('ok', $a(true, [], self::data(['status' => 'external']), 'b2'));
		$this->assertSame('ok', $a(false, ['d200' => true], $issued, 'b2'), 'the project’s Lead');
		$this->assertSame('403 invoice_lead_only', $a(false, ['x9' => true], $issued, 'b2'));
		$this->assertSame('403 invoice_lead_only', $a(false, ['d200' => true], self::data(['status' => 'issued', 'project' => null, 'budget' => null]), null), 'without a project: Team Admins');
		foreach (['draft', 'planned', 'issuing', 'cancelled'] as $status) {
			$this->assertSame('409 invoice_not_fixed', $a(true, [], self::data(['status' => $status]), 'b2'), $status);
		}
		$this->assertSame('422 invoice_budget_without_project', $a(true, [], self::data(['status' => 'issued', 'project' => null, 'budget' => null]), 'b2'));
		$this->assertSame('RE-1', InvoiceRules::numberOf($issued));
		$this->assertSame('BX-9', InvoiceRules::numberOf((object)['number' => null, 'external' => (object)['number' => 'BX-9']]), 'handed over');
		$this->assertSame('', InvoiceRules::numberOf((object)[]));
	}

	public function testEntryInvoice(): void {
		$b = static fn (string $person, string $uid, string $start): array => ['person' => $person, 'uid' => $uid, 'start' => $start];
		$records = [
			['inv-draft', self::data(['bookings' => [$b('alice', 'ev-1', '2026-09-14'), $b('alice', 'ev-2', '2026-09-15')]])],
			['inv-old', self::data(['status' => 'cancelled', 'number' => 'RE-0', 'bookings' => [$b('alice', 'ev-3', '2026-09-16')]])],
			['inv-ext', self::data(['status' => 'external', 'external' => ['system' => 'bexio', 'number' => 'BX-9'], 'bookings' => [$b('bob', 'ev-4', '2026-09-17')]])],
			['inv-1', self::data(['status' => 'issued', 'number' => 'RE-1', 'project' => 'x9', 'bookings' => [$b('alice', 'ev-2', '2026-09-22')]])],
			['inv-plan', self::planned()],
			['inv-gone', null],
		];
		$index = EntryInvoices::index(array_map(static fn (array $r): array => [$r[0], $r[1] === null ? null : Json::decode((string)json_encode($r[1]))], $records));
		$this->assertSame(['key' => 'inv-draft', 'number' => null, 'status' => 'draft', 'project' => 'd200'], $index[EntryInvoices::id('alice', 'ev-1')]);
		$this->assertSame('inv-1', $index[EntryInvoices::id('alice', 'ev-2')]['key'], 'a billed invoice wins over a draft');
		$this->assertArrayNotHasKey(EntryInvoices::id('alice', 'ev-3'), $index, 'a cancelled one is no longer living');
		$this->assertSame(['key' => 'inv-ext', 'number' => 'BX-9', 'status' => 'external', 'project' => 'd200'], $index[EntryInvoices::id('bob', 'ev-4')], 'the accounting system’s number');
		$draft = $index[EntryInvoices::id('alice', 'ev-1')];
		$this->assertSame(['key' => 'inv-draft', 'number' => null, 'status' => 'draft'], EntryInvoices::shown($draft, true, []), 'Team Admins see drafts');
		$this->assertSame(['key' => 'inv-draft', 'number' => null, 'status' => 'draft'], EntryInvoices::shown($draft, false, ['d200' => true]), 'the project’s Lead too');
		$this->assertNull(EntryInvoices::shown($draft, false, []), 'a Member does not see a draft');
		$this->assertSame(['key' => 'inv-1', 'number' => 'RE-1', 'status' => 'issued'], EntryInvoices::shown($index[EntryInvoices::id('alice', 'ev-2')], false, []), 'a billed one, as the billing mark tells');
		$this->assertNull(EntryInvoices::shown(null, true, []));
	}

	public function testCapabilities(): void {
		$cap = (new Capabilities($this->createMock(IAppManager::class)))->getCapabilities()['timesister'];
		$this->assertSame([1, 1, 1], [$cap['invoices_free'], $cap['invoice_plan_records'], $cap['entry_invoice']]);
		$this->assertSame(2, \OCA\TimeSister\AppInfo\Application::API_VERSION, 'api: 2 stays');
	}
}
