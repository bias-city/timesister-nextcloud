<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\InvLedgerMapper;
use OCA\TimeSister\Db\RecordMapper;
use OCA\TimeSister\Db\Tenant;
use OCA\TimeSister\Db\TenantMapper;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * The PDF of a fixed expense report (0.23.0), like an invoice's document:
 * rendered from the frozen lines right after fixing, stored in
 * `Buchhaltung/<YYYY>/Spesen/<person>/`, its hash in the ledger
 * (`expense_document`) and in the record; a failure leaves
 * `document_state: failed` for the retry job.
 */
final class ExpenseReportDocuments {
	private const ERROR_MAX = 1000;

	public function __construct(
		private RecordMapper $records,
		private RecordService $recordService,
		private TenantMapper $tenants,
		private TenantService $team,
		private InvLedgerMapper $ledger,
		private LedgerAppender $appender,
		private DocumentFolder $folders,
		private ReceiptFiles $files,
		private ExpenseReportPdf $pdf,
		private Transaction $tx,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/** Renders and stores the document; never throws – the record tells the state. */
	public function render(int $tid, string $key, string $actor, int $now): void {
		$t = $this->tenants->find($tid);
		$d = $this->live($tid, $key);
		if ($t === null || $d === null || !ExpenseReportRules::isCurrent($d) || $d->status === ExpenseReportRules::OPEN) {
			return;
		}
		$tries = (int)($d->document_tries ?? 0) + 1;
		try {
			$owner = $this->team->backupOwner($t) ?? throw new \RuntimeException('The team has no backup owner; documents need one.');
			$content = $this->build($t, $key, $d);
			$fixedOn = (string)$d->fixed_on;
			$folder = DocumentTree::expenseFolder(TeamTree::rootOf($t), $fixedOn, $this->team->displayName((string)$d->person));
			$old = Fields::str(Fields::obj($d, 'document'), 'path');
			$written = $this->folders->store($owner, $folder, DocumentTree::reportName($fixedOn, (string)$d->number), $content, $old);
			$doc = ['path' => $written['path'], 'sha256' => hash('sha256', $content), 'size' => $written['size']];
			$this->save($tid, $key, $actor, $now, static function (\stdClass $x) use ($doc, $tries): void {
				$x->document = (object)$doc;
				$x->document_state = ExpenseReportRules::DOCUMENT_DONE;
				$x->document_error = null;
				$x->document_tries = $tries;
			}, $doc);
		} catch (\Throwable $e) {
			$this->logger->warning('TimeSister: document of expense report {key} not rendered (try {n}): {message}', [
				'app' => 'timesister', 'key' => $key, 'n' => $tries, 'message' => $e->getMessage(),
			]);
			try {
				$this->save($tid, $key, $actor, $now, static function (\stdClass $x) use ($e, $tries): void {
					$x->document_state = ExpenseReportRules::DOCUMENT_FAILED;
					$x->document_error = mb_substr($e->getMessage(), 0, self::ERROR_MAX);
					$x->document_tries = $tries;
				}, null);
			} catch (\Throwable) {
				// The state could not be written either; the next try will tell.
			}
		}
	}

	/** The retry job: documents still pending or failed, up to ten tries each. @return array{done:int,failed:int} */
	public function retry(int $limit, int $now): array {
		$n = ['done' => 0, 'failed' => 0];
		foreach ($this->tenants->findAll() as $t) {
			foreach ($this->records->findLiveByKind($t->getId(), ExpenseReportRules::KIND) as $r) {
				if ($n['done'] + $n['failed'] >= $limit) {
					return $n;
				}
				$d = InvoiceRules::decode($r->getData());
				if ($d === null || !ExpenseReportRules::isCurrent($d) || $d->status === ExpenseReportRules::OPEN
					|| !in_array($d->document_state ?? null, [ExpenseReportRules::DOCUMENT_PENDING, ExpenseReportRules::DOCUMENT_FAILED], true)
					|| (int)($d->document_tries ?? 0) >= DocumentRules::MAX_TRIES) {
					continue;
				}
				$this->render($t->getId(), $r->getRkey(), DocumentService::ACTOR_SYSTEM, $now);
				$n[($this->live($t->getId(), $r->getRkey())?->document_state ?? null) === ExpenseReportRules::DOCUMENT_DONE ? 'done' : 'failed']++;
			}
		}
		return $n;
	}

	/**
	 * Writes the document fields onto the stored report under the team
	 * lock; a stored document also gets its ledger entry.
	 *
	 * @param callable(\stdClass):void $apply
	 * @param ?array{path:string,sha256:string,size:int} $doc
	 */
	private function save(int $tid, string $key, string $actor, int $now, callable $apply, ?array $doc): void {
		$this->tx->run(function () use ($tid, $key, $actor, $now, $apply, $doc): bool {
			$top = $this->tenants->bumpRevision($tid, 1);
			$rec = $this->records->findOne($tid, ExpenseReportRules::KIND, $key);
			$d = $rec === null || $rec->isTombstone() ? null : InvoiceRules::decode($rec->getData());
			if ($rec === null || $d === null) {
				return false;
			}
			$apply($d);
			if ($doc !== null) {
				$this->appender->append($tid, $actor, LedgerRules::EXPENSE_DOCUMENT, 0, (string)$d->number, LedgerRules::canonical([
					'key' => $key, 'number' => (string)$d->number, 'kind' => DocumentRules::KIND_PDF, ...$doc,
				]), $now);
			}
			$v = RecordValidator::validate(ExpenseReportRules::KIND, $key, $d);
			$m = new Membership($actor, $tid, Role::ADMIN);
			$this->recordService->applyWithin($m, [['kind' => ExpenseReportRules::KIND, 'key' => $key, 'version' => $rec->getVersion(), 'json' => $v['json'], 'accounts' => $v['accounts']]], false, $top, true);
			return true;
		});
	}

	/** The PDF from the frozen lines, with the copies of the receipts' files. */
	private function build(Tenant $t, string $key, \stdClass $d): string {
		$tid = $t->getId();
		$settings = $this->live($tid, 'settings', 'setting');
		$lang = DocumentRules::language($settings, $this->config->getSystemValueString('default_language', DocumentRules::EN));
		$texts = DocumentRules::texts($lang);
		$team = ReceiptBooking::teamCurrency($settings);
		$rows = [];
		$codes = [];
		$keys = [];
		$dates = [];
		foreach (Fields::list($d, 'lines') as $l) {
			if (!$l instanceof \stdClass) {
				continue;
			}
			$p = Fields::str($l, 'project');
			if ($p !== null && !isset($codes[$p])) {
				$codes[$p] = ReceiptRules::projectCode($this->live($tid, $p, 'project'), $p);
			}
			$e = ReceiptBooking::forExpenses(ExpenseReportRules::asReceipt($l), $team);
			$rates = ExpenseReportRules::ratesOf($l);
			// In another currency: the amount paid counts, the original stands beside the text.
			$own = $e['converted'] ? sprintf(' (%s %s)', (string)($l->currency ?? ''), number_format((float)($l->gross ?? 0), 2, '.', '')) : '';
			$rows[] = [
				'date' => (string)($l->date ?? ''), 'supplier' => (string)($l->supplier ?? ''), 'text' => (string)($l->text ?? '') . $own,
				'project' => $p === null ? '' : $codes[$p], 'net' => array_sum(array_column($e['rates'], 'net')),
				'vat_rate' => count($rates) === 1 ? (float)$rates[0] : null, 'vat_rates' => count($rates) > 1 ? array_values($rates) : [],
				'vat' => array_sum(array_column($e['rates'], 'vat')), 'gross' => $e['gross'], 'without' => ($l->without_file ?? false) === true,
			];
			$keys[] = (string)($l->receipt ?? '');
			$dates[] = (string)($l->date ?? '');
		}
		$sums = Fields::obj($d, 'sums');
		$seq = is_int($d->ledger_seq ?? null) ? $d->ledger_seq : 0;
		$entry = $this->ledger->findSince($tid, $seq - 1, 1)[0] ?? null;
		$fixedBy = Fields::str($d, 'fixed_by');
		$receipts = $this->files->receipts($tid, $keys);
		return $this->pdf->render([
			'lang' => $lang, 'team' => $t->getName(), 'person' => $this->team->displayName((string)$d->person), 'number' => (string)$d->number,
			'chain' => DocumentRules::chainLine((string)$d->number, $seq, Fields::str($d, 'hash'), $entry?->getPrevHash()),
			'from' => $dates === [] ? (string)$d->fixed_on : min($dates), 'to' => $dates === [] ? (string)$d->fixed_on : max($dates),
			'currency' => (string)($sums->currency ?? $team), 'rows' => $rows,
			'sums' => [
				'net' => (float)($sums->net ?? 0), 'gross' => (float)($sums->gross ?? 0),
				'vat_lines' => array_values(array_map(static fn (mixed $x): array => $x instanceof \stdClass ? ['rate' => (float)($x->rate ?? 0), 'net' => (float)($x->net ?? 0), 'vat' => (float)($x->vat ?? 0)] : ['rate' => 0.0, 'net' => 0.0, 'vat' => 0.0], Fields::list($sums, 'vat_lines'))),
			],
			'fixed_by' => $fixedBy === null ? null : $this->team->displayName($fixedBy), 'fixed_on' => Fields::str($d, 'fixed_on'),
			'copies' => $this->files->copies($tid, $receipts, $lang, $texts),
		]);
	}

	private function live(int $tid, string $key, string $kind = ExpenseReportRules::KIND): ?\stdClass {
		$r = $this->records->findOne($tid, $kind, $key);
		return $r === null || $r->isTombstone() ? null : InvoiceRules::decode($r->getData());
	}
}
