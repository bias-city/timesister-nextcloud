<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\CounterMapper;
use OCA\TimeSister\Db\InvLedger;
use OCA\TimeSister\Db\Record;
use OCA\TimeSister\Db\RecordMapper;
use OCA\TimeSister\Db\TenantMapper;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Expense reports (0.23.0), since 0.30.0 settled by the person themselves
 * or a Team Admin (PLAN-SPESEN 9): the open expenses of a period are fixed
 * in one transaction – number, frozen lines and sums of the expense table,
 * `report` on every receipt, ledger entry `expense_fixed`. The app renders
 * the files and stores them here (`expense_document`); a Team Admin
 * reverses a report.
 */
final class ExpenseReportService {
	public const REASON_MAX = 500;

	public function __construct(
		private RecordMapper $records,
		private RecordService $recordService,
		private TenantMapper $tenants,
		private TenantService $team,
		private CounterMapper $counters,
		private LedgerAppender $appender,
		private TeamTree $tree,
		private DocumentFolder $folders,
		private Transaction $tx,
		private ITimeFactory $time,
	) {
	}

	// --------------------------------------------------------------- fix

	/** `POST /expense-reports/fix` `{ person, from, to }` – the person or Team Admins. @return array<string,mixed> the new record */
	public function fix(Membership $m, \stdClass $body): array {
		$person = $this->person($m, $body->person ?? null);
		if (!$m->manages() && $person !== $m->uid) {
			throw ApiException::forbidden('Only the person and Team Admins settle expenses.')->code(ErrorCode::SETTLE_OWNER_ONLY);
		}
		[$from, $to] = ExpenseReportRules::period($body->from ?? null, $body->to ?? null);
		$now = $this->time->getTime();
		return $this->tx->run(fn (): array => $this->fixWithin($m, $person, $from, $to, $now));
	}

	/** @return array<string,mixed> */
	private function fixWithin(Membership $m, string $person, string $from, string $to, int $now): array {
		$tid = $m->tenantId;
		// The team lock first, so the receipts cannot change under us.
		$this->tenants->bumpRevision($tid, 0);
		$receipts = [];
		foreach ($this->records->findLiveByKind($tid, ReceiptRules::KIND) as $r) {
			$d = InvoiceRules::decode($r->getData());
			if ($d !== null) {
				$receipts[$r->getRkey()] = $d;
			}
		}
		$reports = $this->reports($tid);
		$settings = $this->live($tid, 'setting', 'settings');
		$currency = ReceiptBooking::teamCurrency($settings);
		$keys = ExpenseReportRules::pick($receipts, $reports, $person, $from, $to, $currency);
		$lines = array_map(static fn (string $k): array => ExpenseTable::line($k, $receipts[$k], $currency), $keys);
		$sums = ExpenseTable::sums($lines, $currency);
		$today = gmdate('Y-m-d', $now);
		$number = $this->takeNumber($tid, $settings, $reports, $today, $now);
		$key = $this->newKey($tid);
		$entry = $this->appender->append($tid, $m->uid, LedgerRules::EXPENSE_FIXED, 0, $number,
			LedgerRules::canonical(ExpenseReportRules::payload($key, $number, $person, $from, $to, $today, $m->uid, $lines, $sums)), $now);
		$d = new \stdClass();
		foreach (['schema' => ExpenseReportRules::SCHEMA, 'person' => $person, 'status' => ExpenseReportRules::FIXED, 'number' => $number,
			'from' => $from, 'to' => $to, 'lines' => $lines, 'sums' => $sums, 'fixed_on' => $today, 'fixed_by' => $m->uid,
			'documents' => [], 'ledger_seq' => $entry->getSeq(), 'hash' => $entry->getHash()] as $f => $v) {
			$d->{$f} = $v;
		}
		$ops = [$this->op($key, 0, $d)];
		foreach ($keys as $k) {
			$ops[] = $this->receiptOp($tid, $k, $key);
		}
		$top = $this->tenants->bumpRevision($tid, count($ops));
		return $this->recordService->applyWithin($m, $ops, false, $top, true)['records'][0];
	}

	/** The next number of the expense counter in the team's pattern; unique among the reports. @param array<string,?\stdClass> $reports */
	private function takeNumber(int $tid, ?\stdClass $settings, array $reports, string $day, int $now): string {
		$f = InvoiceNumbering::EXPENSE_FIELD;
		$n = InvoiceNumbering::of($settings, $f);
		$year = (int)substr($day, 0, 4);
		$no = $this->counters->takeWithin($tid, InvoiceNumbering::counter($n['per_year'], $year, InvoiceNumbering::EXPENSE_COUNTER), $n['start'], $now);
		$number = InvoiceNumbering::fill(InvoiceNumbering::patternOf($settings, $f), $no, $year, '', null, InvoiceNumbering::EXPENSE_DEFAULT);
		foreach ($reports as $d) {
			if (($d?->number ?? null) === $number) {
				throw ApiException::conflict(Message::of('The number {number} is already taken; check the expense number pattern.', ['number' => $number]))->code(ErrorCode::REPORT_NUMBER_TAKEN);
			}
		}
		return $number;
	}

	// ------------------------------------------------------------ reverse

	/** `POST /expense-reports/{key}/reverse` `{ reason }` – Team Admins, only a fixed report. @return array<string,mixed> the record plus `ledger` */
	public function reverse(Membership $m, string $key, \stdClass $body): array {
		if (!$m->manages()) {
			throw ApiException::forbidden('Only Team Admins reverse expense reports.')->code(ErrorCode::REPORT_REVERSE_ADMIN_ONLY);
		}
		$reason = IssueRules::shortText($body->reason ?? null, 'reason');
		$today = $this->today();
		$now = $this->time->getTime();
		RecordValidator::checkAddress(ExpenseReportRules::KIND, $key);
		return $this->tx->run(function () use ($m, $key, $reason, $today, $now): array {
			$tid = $m->tenantId;
			$this->tenants->bumpRevision($tid, 0);
			[$rec, $d] = $this->report($tid, $key);
			if (ExpenseReportRules::is($d, ExpenseReportRules::PAID)) {
				throw ApiException::conflict('A paid expense report is not reversed.')->code(ErrorCode::REPORT_NOT_FIXED);
			}
			if (!ExpenseReportRules::is($d, ExpenseReportRules::FIXED)) {
				throw ApiException::conflict('Only a fixed expense report is reversed.')->code(ErrorCode::REPORT_NOT_FIXED);
			}
			$d->status = ExpenseReportRules::REVERSED;
			$d->reversed_on = $today;
			$d->reverse_reason = $reason;
			// The receipts are open again; the files stay.
			$ops = [$this->op($key, $rec->getVersion(), $d)];
			foreach (Fields::list($d, 'lines') as $l) {
				$k = $l instanceof \stdClass ? Fields::str($l, 'receipt') : null;
				$r = $k === null ? null : $this->live($tid, ReceiptRules::KIND, $k);
				if ($k !== null && ($r?->report ?? null) === $key) {
					$ops[] = $this->receiptOp($tid, $k, null);
				}
			}
			$entry = $this->appender->append($tid, $m->uid, LedgerRules::EXPENSE_REVERSED, 0, (string)$d->number,
				LedgerRules::canonical(['key' => $key, 'number' => (string)$d->number, 'reversed_on' => $today, 'reason' => $reason]), $now);
			$top = $this->tenants->bumpRevision($tid, count($ops));
			$record = $this->recordService->applyWithin($m, $ops, false, $top, true)['records'][0];
			$record['ledger'] = self::ledgerBlock($entry);
			return $record;
		});
	}

	// ---------------------------------------------------------- documents

	/**
	 * `POST /expense-reports/{key}/documents` `{ name, content }` (0.30.0,
	 * Base64): a file the app rendered – the table as PDF and CSV, the
	 * receipts as PDF – stored beside the person's receipts, its hash in
	 * the ledger (`expense_document`) and in `documents`. The same file
	 * again changes nothing. Answer: the record.
	 *
	 * @return array<string,mixed>
	 */
	public function storeDocument(Membership $m, string $key, \stdClass $body): array {
		return $this->store($m, $key, $body->name ?? null, static fn (string $name): string => ExpenseReportRules::documentContent($name, $body->content ?? null));
	}

	/** The raw form (0.31.0): the body is the file, its name in `?name=`. @return array<string,mixed> */
	public function storeRaw(Membership $m, string $key, mixed $name, string $bytes): array {
		return $this->store($m, $key, $name, static fn (string $name): string => ExpenseReportRules::documentBytes($name, $bytes));
	}

	/**
	 * @param callable(string):string $bytes the checked file for the checked name – after the rights, so 403/409 come first
	 * @return array<string,mixed>
	 */
	private function store(Membership $m, string $key, mixed $rawName, callable $bytes): array {
		RecordValidator::checkAddress(ExpenseReportRules::KIND, $key);
		$tid = $m->tenantId;
		[$rec, $d] = $this->report($tid, $key);
		if (!ExpenseReportRules::canRead($m->manages(), $m->uid, $d)) {
			throw ApiException::forbidden('Only the person and Team Admins store documents of an expense report.')->code(ErrorCode::REPORT_OWNER_ONLY);
		}
		self::requireFixed($d);
		$name = ExpenseReportRules::documentName($rawName);
		$content = $bytes($name);
		$sha = hash('sha256', $content);
		if (ExpenseReportRules::documentAgain($d, $name, $sha) !== null) {
			return $this->recordService->present($rec);
		}
		// The file first, as with the invoice documents; the record and the ledger in one transaction.
		$place = $this->tree->of($tid);
		$folder = DocumentTree::expenseFolder($place['root'], (string)$d->fixed_on, $this->team->displayName((string)$d->person));
		$written = $this->folders->store($place['owner'], $folder, $name, $content, null);
		$now = $this->time->getTime();
		try {
			[$record, $stored] = $this->tx->run(function () use ($m, $tid, $key, $name, $sha, $written, $now): array {
				$this->tenants->bumpRevision($tid, 0);
				[$rec, $d] = $this->report($tid, $key);
				self::requireFixed($d);
				if (ExpenseReportRules::documentAgain($d, $name, $sha) !== null) {
					return [$this->recordService->present($rec), false];
				}
				$doc = (object)['name' => $name, 'path' => $written['path'], 'sha256' => $sha, 'size' => $written['size']];
				$this->appender->append($tid, $m->uid, LedgerRules::EXPENSE_DOCUMENT, 0, (string)$d->number,
					LedgerRules::canonical(['key' => $key, 'number' => (string)$d->number, 'name' => $name, 'sha256' => $sha]), $now);
				$d->documents = [...ExpenseReportRules::documents($d), $doc];
				$top = $this->tenants->bumpRevision($tid, 1);
				return [$this->recordService->applyWithin($m, [$this->op($key, $rec->getVersion(), $d)], false, $top, true)['records'][0], true];
			});
		} catch (\Throwable $e) {
			// Nothing names the file: it goes to the trash.
			$this->trash($place['owner'], $written['path']);
			throw $e;
		}
		if (!$stored) {
			// The same file came in twice at once; the first one stays.
			$this->trash($place['owner'], $written['path']);
		}
		return $record;
	}

	/** `GET /expense-reports/{key}/document?name=` – the person, Team Admins. @return array{content:string,name:string,type:string} */
	public function document(Membership $m, string $key, mixed $name): array {
		RecordValidator::checkAddress(ExpenseReportRules::KIND, $key);
		[, $d] = $this->report($m->tenantId, $key);
		if (!ExpenseReportRules::canRead($m->manages(), $m->uid, $d)) {
			throw ApiException::forbidden('Only the person and Team Admins read an expense report.')->code(ErrorCode::REPORT_OWNER_ONLY);
		}
		$path = ExpenseReportRules::documentPath($d, $name);
		$owner = $this->tree->ownerOf($m->tenantId);
		$file = $owner === null ? null : $this->folders->read($owner, $path);
		if ($file === null) {
			throw ApiException::notFound('This expense report has no document yet.');
		}
		return ['content' => $file->getContent(), 'name' => basename($path), 'type' => ExpenseReportRules::documentType($path)];
	}

	// ------------------------------------------------------------ helpers

	/** 409 unless the report is fixed: documents come only to a settled one. */
	private static function requireFixed(\stdClass $d): void {
		if (!ExpenseReportRules::is($d, ExpenseReportRules::FIXED)) {
			throw ApiException::conflict('Only a settled expense report takes documents.')->code(ErrorCode::REPORT_NOT_FIXED);
		}
	}

	private function trash(string $owner, string $path): void {
		try {
			$this->folders->trash($owner, $path);
		} catch (\Throwable) {
			// A file left behind is harmless.
		}
	}

	/** The person of the body: an account of the team. */
	private function person(Membership $m, mixed $uid): string {
		if (!is_string($uid) || $uid === '' || mb_strlen($uid) > ReceiptRules::UID_MAX) {
			throw ApiException::invalid('“person” is required: the account’s user ID.')->field('person');
		}
		if (!array_key_exists($uid, $this->team->members($m->tenantId))) {
			throw ApiException::invalid(Message::of('“{uid}” is not an account of the team.', ['uid' => $uid]))->code(ErrorCode::PERSON_NOT_MEMBER)->field('person');
		}
		return $uid;
	}

	/** The live report or 404. @return array{0:Record,1:\stdClass} */
	private function report(int $tid, string $key): array {
		$rec = $this->records->findOne($tid, ExpenseReportRules::KIND, $key);
		$d = $rec === null || $rec->isTombstone() ? null : InvoiceRules::decode($rec->getData());
		if ($rec === null || $d === null) {
			throw ApiException::notFound('This expense report does not exist.');
		}
		return [$rec, $d];
	}

	/** The live expense reports by key. @return array<string,?\stdClass> */
	private function reports(int $tid): array {
		$out = [];
		foreach ($this->records->findLiveByKind($tid, ExpenseReportRules::KIND) as $r) {
			$out[$r->getRkey()] = InvoiceRules::decode($r->getData());
		}
		return $out;
	}

	/** A new report key, `sb-` and eight hex digits, free in the team (also among deleted ones). */
	private function newKey(int $tid): string {
		do {
			$key = 'sb-' . bin2hex(random_bytes(4));
		} while ($this->records->findOne($tid, ExpenseReportRules::KIND, $key) !== null);
		return $key;
	}

	/** @return array{kind:string,key:string,version:int,json:?string,accounts:?list<string>} */
	private function op(string $key, int $version, \stdClass $d): array {
		$v = RecordValidator::validate(ExpenseReportRules::KIND, $key, $d);
		return ['kind' => ExpenseReportRules::KIND, 'key' => $key, 'version' => $version, 'json' => $v['json'], 'accounts' => $v['accounts']];
	}

	/** A receipt noting the report that holds it (null: none). @return array{kind:string,key:string,version:int,json:?string,accounts:?list<string>} */
	private function receiptOp(int $tid, string $key, ?string $report): array {
		$cur = $this->records->findOne($tid, ReceiptRules::KIND, $key);
		$d = InvoiceRules::decode($cur?->getData()) ?? throw ApiException::invalid(Message::of('The receipt {key} does not exist.', ['key' => $key]));
		$d->report = $report;
		$v = RecordValidator::validate(ReceiptRules::KIND, $key, $d);
		return ['kind' => ReceiptRules::KIND, 'key' => $key, 'version' => $cur?->getVersion() ?? 0, 'json' => $v['json'], 'accounts' => $v['accounts']];
	}

	/** @return array{seq:int,prev_hash:string,hash:string} */
	private static function ledgerBlock(InvLedger $e): array {
		return ['seq' => $e->getSeq(), 'prev_hash' => $e->getPrevHash(), 'hash' => $e->getHash()];
	}

	private function live(int $tid, string $kind, string $key): ?\stdClass {
		$r = $this->records->findOne($tid, $kind, $key);
		return $r === null || $r->isTombstone() ? null : InvoiceRules::decode($r->getData());
	}

	private function today(): string {
		return gmdate('Y-m-d', $this->time->getTime());
	}
}
