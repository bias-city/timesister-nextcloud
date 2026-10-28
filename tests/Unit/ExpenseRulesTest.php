<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\ExpenseReportRules;
use OCA\TimeSister\Service\ExpenseRuleRules;
use OCA\TimeSister\Service\ExpenseTable;
use OCA\TimeSister\Service\LedgerRules;
use PHPUnit\Framework\TestCase;

/**
 * Expense rules (0.22.0; since 0.30.0 without approval): content, periods,
 * deleting. Expense reports (0.23.0; since 0.30.0 settled over a period by
 * the person): status and rights, which receipts are settled, the ledger
 * payload and the app's files.
 */
class ExpenseRulesTest extends TestCase {
	private static function outcome(callable $fn): string {
		try {
			$fn();
			return 'ok';
		} catch (ApiException $e) {
			return $e->getStatus() . ' ' . $e->getMessage();
		}
	}

	private static function rule(array $over = []): \stdClass {
		return (object)array_merge(['schema' => 1, 'person' => 'alice', 'text' => 'Mobile', 'amount' => 50, 'vat_rate' => null, 'period' => 'monthly',
			'from' => '2026-01-15', 'to' => null, 'project' => null, 'budget' => null, 'cost' => null], $over);
	}

	public function testRuleContent(): void {
		$this->assertSame('ok', self::outcome(fn () => ExpenseRuleRules::validate(self::rule())));
		$this->assertSame('ok', self::outcome(fn () => ExpenseRuleRules::validate(self::rule(['project' => 'd1', 'budget' => 'b1', 'cost' => 1, 'to' => '2026-12-31']))));
		$this->assertSame('422 “period” must be one of monthly, quarterly, yearly.', self::outcome(fn () => ExpenseRuleRules::validate(self::rule(['period' => 'weekly']))));
		$this->assertSame('422 “amount” must be a number ≥ 0.', self::outcome(fn () => ExpenseRuleRules::validate(self::rule(['amount' => -1]))));
		$this->assertSame('422 “from” must not be after “to”.', self::outcome(fn () => ExpenseRuleRules::validate(self::rule(['to' => '2026-01-01']))));
		$this->assertSame('422 “text” must be text of 1 to 300 characters.', self::outcome(fn () => ExpenseRuleRules::validate(self::rule(['text' => ' ']))));
		$this->assertStringStartsWith('422 “project”, “budget” and “cost”', self::outcome(fn () => ExpenseRuleRules::validate(self::rule(['project' => 'd1']))));
	}

	/** 0.30.0: a rule applies from creation; an old approval stays as stored, a new rule gets none. */
	public function testNoApproval(): void {
		$stored = self::rule(['approved_by' => 'admin', 'approved_on' => '2026-01-20']);
		$more = self::rule(['amount' => 60, 'approved_by' => 'mallory', 'approved_on' => '2026-02-01']);
		ExpenseRuleRules::mergeServer($stored, $more);
		$this->assertSame(['admin', '2026-01-20'], [$more->approved_by, $more->approved_on], 'kept as stored, also with a new amount; the client’s value is ignored');
		$new = self::rule(['approved_by' => 'alice']);
		ExpenseRuleRules::mergeServer(null, $new);
		$this->assertSame([null, null], [$new->approved_by, $new->approved_on]);
		$this->assertSame('ok', self::outcome(fn () => ExpenseRuleRules::requireWrite(true, 'admin', $stored, null)), 'an approved rule may go too');
		$this->assertStringStartsWith('403', self::outcome(fn () => ExpenseRuleRules::requireWrite(false, 'bob', null, self::rule())));
		$this->assertStringStartsWith('403', self::outcome(fn () => ExpenseRuleRules::requireWrite(false, 'bob', self::rule(), null)));
		$this->assertSame('ok', self::outcome(fn () => ExpenseRuleRules::requireWrite(false, 'alice', null, self::rule())));
		$this->assertSame('ok', self::outcome(fn () => ExpenseRuleRules::requireDelete(false)));
		$this->assertSame('409 An expense rule with receipts is not deleted; end it by setting “to”.', self::outcome(fn () => ExpenseRuleRules::requireDelete(true)));
	}

	public function testPeriods(): void {
		$this->assertSame(['2026-02-01', '2026-02-28'], ExpenseRuleRules::bounds('2026-02'));
		$this->assertSame(['2026-04-01', '2026-06-30'], ExpenseRuleRules::bounds('2026-Q2'));
		$this->assertSame(['2026-01-01', '2026-12-31'], ExpenseRuleRules::bounds('2026'));
		$r = self::rule(['to' => '2026-06-10']);
		$this->assertTrue(ExpenseRuleRules::covers($r, '2026-01'), 'the month the rule begins in');
		$this->assertTrue(ExpenseRuleRules::covers($r, '2026-06'));
		$this->assertFalse(ExpenseRuleRules::covers($r, '2025-12'));
		$this->assertFalse(ExpenseRuleRules::covers($r, '2026-07'));
		$this->assertFalse(ExpenseRuleRules::covers($r, '2026-Q1'), 'not the rule’s form');
		$this->assertTrue(ExpenseRuleRules::covers(self::rule(['period' => 'quarterly']), '2026-Q1'));
		$this->assertTrue(ExpenseRuleRules::covers(self::rule(['period' => 'yearly']), '2027'));
	}

	private static function receipt(array $over = []): \stdClass {
		return (object)array_merge(['kind' => 'receipt', 'date' => '2026-10-02', 'supplier' => 'Bahn Muster', 'text' => 'Ticket', 'person' => 'alice', 'paid_by' => 'person',
			'reimburse' => true, 'status' => 'approved', 'net' => 100.0, 'vat_rate' => 8.1, 'vat' => 8.1, 'gross' => 108.1, 'currency' => 'CHF',
			'project' => 'd1', 'invoice_effect' => 'charge', 'report' => null, 'category' => 'mobility'], $over);
	}

	/** Schema 2: content, status, no client writes (0.30.0). */
	public function testReport(): void {
		$open = (object)['schema' => 2, 'person' => 'alice', 'status' => 'open', 'ready_on' => null];
		$this->assertSame('ok', self::outcome(fn () => ExpenseReportRules::validate((object)['person' => 'alice', 'ready_on' => '2026-10-31'])));
		$this->assertSame('422 “ready_on” must be a day in the format YYYY-MM-DD.', self::outcome(fn () => ExpenseReportRules::validate((object)['person' => 'alice', 'ready_on' => 'soon'])));
		$this->assertSame('422 “person” is required: the account’s user ID.', self::outcome(fn () => ExpenseReportRules::validate((object)[])));
		$fixed = (object)['schema' => 2, 'person' => 'alice', 'status' => 'fixed', 'number' => 'SP-2026-0001'];
		$old = (object)['schema' => 1, 'person' => 'alice', 'status' => 'approved'];
		$this->assertTrue(ExpenseReportRules::holds($fixed));
		$this->assertTrue(ExpenseReportRules::holds((object)['schema' => 2, 'status' => 'paid']), 'a paid report of 0.23.0 keeps its receipts');
		$this->assertFalse(ExpenseReportRules::holds((object)['schema' => 2, 'status' => 'reversed']), 'a reversed report has released its receipts');
		$this->assertFalse(ExpenseReportRules::holds($open));
		$this->assertFalse(ExpenseReportRules::holds($old), 'a report of 0.22.0 is ignored');
		$this->assertSame('sb-1', ExpenseReportRules::heldBy(self::receipt(['report' => 'sb-1']), ['sb-1' => $fixed]));
		$this->assertNull(ExpenseReportRules::heldBy(self::receipt(['report' => 'sb-2']), ['sb-2' => $open]));

		// A client writes no report: fix, documents and reverse make and change it.
		$this->assertSame('409 An expense report changes only through its actions (fix, documents, reverse).', self::outcome(fn () => ExpenseReportRules::requireWrite()));
		$this->assertTrue(ExpenseReportRules::canRead(false, 'alice', $open));
		$this->assertFalse(ExpenseReportRules::canRead(false, 'bob', $open), 'a Lead reads no expense report of others');
		$this->assertTrue(ExpenseReportRules::canRead(true, 'admin', $open));
	}

	/** 9 C: the person's open expenses of the period, by date and key; open receipts too. */
	public function testSettling(): void {
		$this->assertSame(['2026-10-01', '2026-10-31'], ExpenseReportRules::period('2026-10-01', '2026-10-31'));
		$this->assertSame(['2026-10-01', '2026-10-01'], ExpenseReportRules::period('2026-10-01', '2026-10-01'));
		foreach ([[null, '2026-10-31'], ['2026-10-01', '31.10.2026'], ['2026-11-01', '2026-10-31'], ['2026-02-30', '2026-03-01']] as [$from, $to]) {
			$this->assertSame('422 “from” and “to” are days (YYYY-MM-DD), “from” not after “to”.', self::outcome(fn () => ExpenseReportRules::period($from, $to)));
		}
		$reports = ['sb-1' => (object)['schema' => 2, 'status' => 'fixed', 'number' => 'SP-2026-0001', 'person' => 'alice'],
			'sb-r' => (object)['schema' => 2, 'status' => 'reversed', 'person' => 'alice'], 'sb-0' => (object)['schema' => 1, 'status' => 'approved']];
		$receipts = [
			'rc-b' => self::receipt(['date' => '2026-10-05']),
			'rc-a2' => self::receipt(['status' => 'open', 'kind' => 'trust', 'project' => null, 'invoice_effect' => 'none']),
			'rc-a1' => self::receipt(['status' => 'internal', 'report' => 'sb-r']),
			'rc-old' => self::receipt(['report' => 'sb-0', 'date' => '2026-10-01']),
			'rc-held' => self::receipt(['report' => 'sb-1']),
			'rc-rejected' => self::receipt(['status' => 'rejected']),
			'rc-bob' => self::receipt(['person' => 'bob']),
			'rc-company' => self::receipt(['reimburse' => false]),
			'rc-late' => self::receipt(['date' => '2026-11-01']),
		];
		$pick = fn (string $person = 'alice', string $to = '2026-10-31', string $team = 'CHF', array $rs = []): string
			=> implode(',', ExpenseReportRules::pick($rs === [] ? $receipts : $rs, $reports, $person, '2026-10-01', $to, $team));
		$this->assertSame('rc-old,rc-a1,rc-a2,rc-b', $pick(), 'by date and key; open ones too; released and 0.22.0 marks count as none');
		$this->assertSame('422 No open expenses of this person in this period.', self::outcome(fn () => $pick('carol')));
		$this->assertSame('rc-old,rc-a1,rc-a2,rc-b,rc-late', $pick('alice', '2026-11-30'));
		$this->assertTrue(ExpenseReportRules::settleable(self::receipt(), 'alice', '2026-10-02', '2026-10-02', []), 'both ends count');

		// Each needs a category and an amount in the team's currency: the first by date and key says what is missing.
		$lacking = ['rc-2' => self::receipt(['date' => '2026-10-04', 'category' => null]), 'rc-1' => self::receipt(['date' => '2026-10-04', 'currency' => 'EUR']),
			'rc-0' => self::receipt(['date' => '2026-10-09', 'category' => null, 'currency' => 'USD'])];
		$this->assertSame('422 The expense rc-1 of 2026-10-04 has no rate to CHF.', self::outcome(fn () => $pick('alice', '2026-10-31', 'CHF', $lacking)));
		unset($lacking['rc-1']);
		$this->assertSame('422 The expense rc-2 of 2026-10-04 has no category.', self::outcome(fn () => $pick('alice', '2026-10-31', 'CHF', $lacking)));
		$this->assertSame('rc-x', $pick('alice', '2026-10-31', 'CHF', ['rc-x' => self::receipt(['currency' => 'EUR', 'booked' => (object)['gross' => 99.5, 'currency' => 'CHF']])]), 'booked in the team’s currency');
		$this->assertSame('rc-x', $pick('alice', '2026-10-31', 'EUR', ['rc-x' => self::receipt(['rate' => (object)['value' => 1.07, 'date' => '2026-10-01', 'source' => 'manual']])]), 'a rate to the team’s currency');
		$many = [];
		for ($i = 0; $i <= ExpenseReportRules::MAX_RECEIPTS; $i++) {
			$many['rc-' . $i] = self::receipt();
		}
		$this->assertSame('422 An expense report holds at most 500 expenses; choose a shorter period.', self::outcome(fn () => $pick('alice', '2026-10-31', 'CHF', $many)));

		$line = ExpenseTable::line('rc-b', $receipts['rc-b'], 'CHF');
		$sums = ExpenseTable::sums([$line], 'CHF');
		$payload = ExpenseReportRules::payload('sb-9', 'SP-2026-0002', 'alice', '2026-10-01', '2026-10-31', '2026-11-02', 'admin', [$line], $sums);
		$this->assertSame('{"fixed_by":"admin","fixed_on":"2026-11-02","from":"2026-10-01","key":"sb-9","lines":[{"amount":108.1,"category":"mobility","charged":true,"currency":"CHF",'
			. '"date":"2026-10-05","factor":1.0,"gross":108.1,"project":"d1","rate":{"date":null,"source":"team","value":1.0},"receipt":"rc-b","supplier":"Bahn Muster","text":"Ticket",'
			. '"vat_amounts":[{"rate":8.1,"vat":8.1}],"vat_lines":[{"net":100.0,"rate":8.1,"vat":8.1}],"without_file":false}],"number":"SP-2026-0002","person":"alice",'
			. '"sums":{"categories":[{"amount":108.1,"id":"mobility"}],"currency":"CHF","payout":108.1,"total":108.1,"vat":[{"rate":8.1,"vat":8.1}]},"to":"2026-10-31"}',
			LedgerRules::canonical($payload));
		$this->assertTrue(ExpenseReportRules::withoutReceipt(self::receipt(['kind' => 'recurring'])));
		$this->assertFalse(ExpenseReportRules::withoutReceipt(self::receipt()));
	}

	/** 9 F: the app's files – name, content, the same file again, the path to read. */
	public function testDocuments(): void {
		$this->assertSame('Spesenabrechnung_SP-2026-0001.pdf', ExpenseReportRules::documentName('Spesenabrechnung_SP-2026-0001.pdf'));
		$this->assertSame('Belege_SP-2026-0001.csv', ExpenseReportRules::documentName('Belege_SP-2026-0001.csv'));
		foreach (['../x.pdf', '.hidden.pdf', 'x.PDF', 'x.zip', 'a b.pdf', 'x/y.pdf', str_repeat('a', 121) . '.pdf', null, 12] as $bad) {
			$this->assertSame('422 “name” is a file name ending in .pdf or .csv.', self::outcome(fn () => ExpenseReportRules::documentName($bad)), var_export($bad, true));
		}
		$this->assertSame('%PDF-1.4 x', ExpenseReportRules::documentContent('a.pdf', base64_encode('%PDF-1.4 x')));
		$this->assertSame("Nr.;Datum\r\n1;2026-10-02\r\n", ExpenseReportRules::documentContent('a.csv', base64_encode("Nr.;Datum\r\n1;2026-10-02\r\n")));
		$this->assertSame('422 “content” is the file in Base64.', self::outcome(fn () => ExpenseReportRules::documentContent('a.pdf', '%PDF-*')));
		$this->assertSame('422 “content” is the file in Base64.', self::outcome(fn () => ExpenseReportRules::documentContent('a.pdf', null)));
		$this->assertSame('422 “content” does not fit its name (PDF or UTF-8 CSV, at most 64 MiB).', self::outcome(fn () => ExpenseReportRules::documentContent('a.pdf', base64_encode('hello'))));
		$this->assertSame('422 “content” does not fit its name (PDF or UTF-8 CSV, at most 64 MiB).', self::outcome(fn () => ExpenseReportRules::documentContent('a.csv', base64_encode("\xFF\xFE"))));
		$this->assertSame('413 A document may be at most 64 MiB.', self::outcome(fn () => ExpenseReportRules::documentContent('a.pdf', str_repeat('A', 4 * intdiv(ExpenseReportRules::DOCUMENT_MAX + 2, 3) + 2048))));

		$report = (object)['documents' => [(object)['name' => 'Spesenabrechnung_1.pdf', 'path' => 'R/Spesenabrechnung_1.pdf', 'sha256' => 'aa', 'size' => 3],
			(object)['name' => 'Spesenabrechnung_1.csv', 'path' => 'R/Spesenabrechnung_1.csv', 'sha256' => 'bb', 'size' => 2]]];
		$this->assertNull(ExpenseReportRules::documentAgain($report, 'Belege_1.pdf', 'cc'));
		$this->assertSame('R/Spesenabrechnung_1.pdf', ExpenseReportRules::documentAgain($report, 'Spesenabrechnung_1.pdf', 'aa')?->path, 'the same file: unchanged');
		$this->assertSame('409 A document of this name is stored already.', self::outcome(fn () => ExpenseReportRules::documentAgain($report, 'Spesenabrechnung_1.pdf', 'dd')));
		$full = (object)['documents' => array_map(static fn (int $i): \stdClass => (object)['name' => "f$i.pdf", 'path' => "R/f$i.pdf", 'sha256' => "h$i", 'size' => 1], range(1, 5))];
		$this->assertSame('409 An expense report holds at most 5 documents.', self::outcome(fn () => ExpenseReportRules::documentAgain($full, 'f6.pdf', 'h6')));
		$this->assertNotNull(ExpenseReportRules::documentAgain($full, 'f5.pdf', 'h5'), 'a stored one again is fine');

		$this->assertSame('R/Spesenabrechnung_1.csv', ExpenseReportRules::documentPath($report, 'Spesenabrechnung_1.csv'));
		$this->assertSame('R/Spesenabrechnung_1.pdf', ExpenseReportRules::documentPath($report, null), 'without a name the first PDF');
		$this->assertSame('404 This expense report has no document “x.pdf”.', self::outcome(fn () => ExpenseReportRules::documentPath($report, 'x.pdf')));
		$this->assertSame('S/2026-10-31_SP-2026-0001_Spesenblatt.pdf', ExpenseReportRules::documentPath((object)['document' => (object)['path' => 'S/2026-10-31_SP-2026-0001_Spesenblatt.pdf']], ''), 'an old report: its PDF');
		$this->assertSame('404 This expense report has no document yet.', self::outcome(fn () => ExpenseReportRules::documentPath((object)['documents' => []], null)));
		$this->assertSame(['application/pdf', 'text/csv; charset=utf-8'], [ExpenseReportRules::documentType('R/a.pdf'), ExpenseReportRules::documentType('R/a.csv')]);
	}
}
