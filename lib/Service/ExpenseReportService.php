<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\CounterMapper;
use OCA\TimeSister\Db\InvLedger;
use OCA\TimeSister\Db\RecordMapper;
use OCA\TimeSister\Db\TenantMapper;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Expense reports (0.23.0), fixed like an invoice: the person says
 * «ready», a Team Admin fixes the expense list in one transaction –
 * number, frozen lines and sums, `report` on every receipt, ledger entry
 * `expense_fixed` – then the PDF follows (ExpenseReportDocuments). Paid
 * and reversed are ledger entries too.
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
		private ExpenseReportDocuments $documents,
		private TeamTree $tree,
		private DocumentFolder $folders,
		private Transaction $tx,
		private ITimeFactory $time,
	) {
	}

	// ------------------------------------------------------------- ready

	/** `POST /expense-reports/ready` `{ person }` – the person or Team Admins: the open report with `ready_on` today. @return array<string,mixed> the record */
	public function ready(Membership $m, \stdClass $body): array {
		$person = $this->person($m, $body->person ?? null);
		if (!$m->manages() && $person !== $m->uid) {
			throw ApiException::forbidden('The person says “ready” for themselves; Team Admins for anyone.');
		}
		$today = $this->today();
		return $this->tx->run(function () use ($m, $person, $today): array {
			$tid = $m->tenantId;
			$top = $this->tenants->bumpRevision($tid, 1);
			$key = ExpenseReportRules::openOf($this->reports($tid), $person);
			$cur = $key === null ? null : $this->records->findOne($tid, ExpenseReportRules::KIND, $key);
			$d = InvoiceRules::decode($cur?->getData()) ?? self::fresh($person);
			if ($key === null) {
				$key = $this->newKey($tid);
				ExpenseReportRules::mergeServer(null, $d);
			}
			$d->ready_on = $today;
			return $this->recordService->applyWithin($m, [$this->op($key, $cur?->getVersion() ?? 0, $d)], false, $top, true)['records'][0];
		});
	}

	// --------------------------------------------------------------- fix

	/** `POST /expense-reports/fix` `{ person, receipts? }` – Team Admins. @return array<string,mixed> the record (after the document) */
	public function fix(Membership $m, \stdClass $body): array {
		if (!$m->manages()) {
			throw ApiException::forbidden('Only Team Admins fix expense reports.');
		}
		$person = $this->person($m, $body->person ?? null);
		$now = $this->time->getTime();
		$key = $this->tx->run(fn (): string => $this->fixWithin($m, $person, $body->receipts ?? null, $now));
		// After the transaction, as for an invoice: a failure leaves the state for the retry job.
		$this->documents->render($m->tenantId, $key, $m->uid, $now);
		return $this->recordService->get($m, ExpenseReportRules::KIND, $key);
	}

	private function fixWithin(Membership $m, string $person, mixed $given, int $now): string {
		$tid = $m->tenantId;
		// The team lock first, so the list cannot change under us.
		$this->tenants->bumpRevision($tid, 0);
		$receipts = [];
		foreach ($this->records->findLiveByKind($tid, ReceiptRules::KIND) as $r) {
			$d = InvoiceRules::decode($r->getData());
			if ($d !== null) {
				$receipts[$r->getRkey()] = $d;
			}
		}
		$reports = $this->reports($tid);
		$keys = ExpenseReportRules::pick($receipts, $reports, $person, $given);
		$settings = $this->live($tid, 'setting', 'settings');
		$lines = array_map(static fn (string $k): array => ExpenseReportRules::line($k, $receipts[$k]), $keys);
		$sums = ExpenseReportRules::sums($lines, ReceiptBooking::teamCurrency($settings));
		$today = gmdate('Y-m-d', $now);
		$number = $this->takeNumber($tid, $settings, $reports, $today, $now);

		$key = ExpenseReportRules::openOf($reports, $person);
		$cur = $key === null ? null : $this->records->findOne($tid, ExpenseReportRules::KIND, $key);
		$d = InvoiceRules::decode($cur?->getData()) ?? self::fresh($person);
		$key ??= $this->newKey($tid);
		ExpenseReportRules::mergeServer(null, $d);
		$readyOn = Fields::str($d, 'ready_on');
		$d->ready_on = $readyOn;
		$entry = $this->appender->append($tid, $m->uid, LedgerRules::EXPENSE_FIXED, 0, $number,
			LedgerRules::canonical(ExpenseReportRules::payload($key, $number, $person, $today, $m->uid, $readyOn, $lines, $sums)), $now);
		$d->status = ExpenseReportRules::FIXED;
		$d->number = $number;
		$d->lines = $lines;
		$d->sums = $sums;
		$d->fixed_on = $today;
		$d->fixed_by = $m->uid;
		$d->document_state = ExpenseReportRules::DOCUMENT_PENDING;
		$d->document_tries = 0;
		$d->ledger_seq = $entry->getSeq();
		$d->hash = $entry->getHash();
		$ops = [$this->op($key, $cur?->getVersion() ?? 0, $d)];
		foreach ($keys as $k) {
			$ops[] = $this->receiptOp($tid, $k, $key);
		}
		$top = $this->tenants->bumpRevision($tid, count($ops));
		$this->recordService->applyWithin($m, $ops, false, $top, true);
		return $key;
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
				throw ApiException::conflict(Message::of('The number {number} is already taken; check the expense number pattern.', ['number' => $number]));
			}
		}
		return $number;
	}

	// ------------------------------------------------------ paid, reverse

	/** `POST /expense-reports/{key}/paid` `{ date }` – Team Admins. @return array<string,mixed> the record plus `ledger` */
	public function paid(Membership $m, string $key, \stdClass $body): array {
		if (!$m->manages()) {
			throw ApiException::forbidden('Only Team Admins mark expense reports paid.');
		}
		$date = $body->date ?? $this->today();
		if (!is_string($date) || !Time::isDay($date)) {
			throw ApiException::invalid(Message::of('“{field}” must be a day in the format YYYY-MM-DD.', ['field' => 'date']));
		}
		return $this->action($m, $key, function (\stdClass $d) use ($key, $date): array {
			if (!ExpenseReportRules::is($d, ExpenseReportRules::FIXED)) {
				throw ApiException::conflict('Only a fixed expense report is marked paid.');
			}
			$d->status = ExpenseReportRules::PAID;
			$d->paid_on = $date;
			return [LedgerRules::EXPENSE_PAID, ['key' => $key, 'number' => (string)$d->number, 'paid_on' => $date], []];
		});
	}

	/** `POST /expense-reports/{key}/reverse` `{ reason }` – Team Admins, only a fixed report. @return array<string,mixed> the record plus `ledger` */
	public function reverse(Membership $m, string $key, \stdClass $body): array {
		if (!$m->manages()) {
			throw ApiException::forbidden('Only Team Admins reverse expense reports.');
		}
		$reason = IssueRules::shortText($body->reason ?? null, 'reason');
		$today = $this->today();
		return $this->action($m, $key, function (\stdClass $d) use ($m, $key, $reason, $today): array {
			if (ExpenseReportRules::is($d, ExpenseReportRules::PAID)) {
				throw ApiException::conflict('A paid expense report is not reversed.');
			}
			if (!ExpenseReportRules::is($d, ExpenseReportRules::FIXED)) {
				throw ApiException::conflict('Only a fixed expense report is reversed.');
			}
			$d->status = ExpenseReportRules::REVERSED;
			$d->reversed_on = $today;
			$d->reverse_reason = $reason;
			// The receipts go back to the person's expense list; the PDF stays.
			$release = [];
			foreach (Fields::list($d, 'lines') as $l) {
				$k = $l instanceof \stdClass ? Fields::str($l, 'receipt') : null;
				$r = $k === null ? null : $this->live($m->tenantId, ReceiptRules::KIND, $k);
				if ($k !== null && ($r?->report ?? null) === $key) {
					$release[] = $this->receiptOp($m->tenantId, $k, null);
				}
			}
			return [LedgerRules::EXPENSE_REVERSED, ['key' => $key, 'number' => (string)$d->number, 'reversed_on' => $today, 'reason' => $reason], $release];
		});
	}

	/**
	 * One action on a fixed report under the team lock: the change, its
	 * ledger entry, the record and the receipts it touches.
	 *
	 * @param callable(\stdClass):array{0:string,1:array<string,mixed>,2:list<array{kind:string,key:string,version:int,json:?string,accounts:?list<string>}>} $change
	 * @return array<string,mixed>
	 */
	private function action(Membership $m, string $key, callable $change): array {
		RecordValidator::checkAddress(ExpenseReportRules::KIND, $key);
		$now = $this->time->getTime();
		return $this->tx->run(function () use ($m, $key, $change, $now): array {
			$tid = $m->tenantId;
			$this->tenants->bumpRevision($tid, 0);
			$rec = $this->records->findOne($tid, ExpenseReportRules::KIND, $key);
			$d = $rec === null || $rec->isTombstone() ? null : InvoiceRules::decode($rec->getData());
			if ($rec === null || $d === null) {
				throw ApiException::notFound('This expense report does not exist.');
			}
			[$action, $payload, $more] = $change($d);
			$entry = $this->appender->append($tid, $m->uid, $action, 0, (string)$d->number, LedgerRules::canonical($payload), $now);
			$ops = [$this->op($key, $rec->getVersion(), $d), ...$more];
			$top = $this->tenants->bumpRevision($tid, count($ops));
			$record = $this->recordService->applyWithin($m, $ops, false, $top, true)['records'][0];
			$record['ledger'] = self::ledgerBlock($entry);
			return $record;
		});
	}

	// ----------------------------------------------------------- document

	/** `GET /expense-reports/{key}/document` – the person, Team Admins. @return array{content:string,name:string} */
	public function document(Membership $m, string $key): array {
		RecordValidator::checkAddress(ExpenseReportRules::KIND, $key);
		$d = $this->live($m->tenantId, ExpenseReportRules::KIND, $key) ?? throw ApiException::notFound('This expense report does not exist.');
		if (!ExpenseReportRules::canRead($m->manages(), $m->uid, $d)) {
			throw ApiException::forbidden('Only the person and Team Admins read an expense report.');
		}
		$path = Fields::str(Fields::obj($d, 'document'), 'path');
		$owner = $this->tree->ownerOf($m->tenantId);
		$file = $path === null || $owner === null ? null : $this->folders->read($owner, $path);
		if ($path === null || $file === null) {
			throw ApiException::notFound('This expense report has no document yet; it is written when it is fixed.');
		}
		return ['content' => $file->getContent(), 'name' => basename($path)];
	}

	// ------------------------------------------------------------ helpers

	/** The person of the body: an account of the team. */
	private function person(Membership $m, mixed $uid): string {
		if (!is_string($uid) || $uid === '' || mb_strlen($uid) > ReceiptRules::UID_MAX) {
			throw ApiException::invalid('“person” is required: the account’s user ID.');
		}
		if (!array_key_exists($uid, $this->team->members($m->tenantId))) {
			throw ApiException::invalid(Message::of('“{uid}” is not an account of the team.', ['uid' => $uid]));
		}
		return $uid;
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

	/** A new report's data before the server's fields. */
	private static function fresh(string $person): \stdClass {
		$d = new \stdClass();
		$d->person = $person;
		return $d;
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
