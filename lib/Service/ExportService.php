<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\InvInvoice;
use OCA\TimeSister\Db\InvInvoiceMapper;
use OCA\TimeSister\Db\InvLedger;
use OCA\TimeSister\Db\InvLedgerMapper;
use OCA\TimeSister\Db\Tenant;
use OCA\TimeSister\Db\TenantMapper;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Exports (0.20.0): the issued invoices of a period as CSV, as a DATEV
 * Buchungsstapel or the ledger as CSV (Team Admins), and the hand-over of
 * an invoice to an accounting system (ledger `exported`, `fixed.external`,
 * since 0.23.1 also `external` on the invoice record). The rules are in
 * ExportRules.
 */
final class ExportService {
	public function __construct(
		private Transaction $tx,
		private TenantService $team,
		private TenantMapper $tenants,
		private RecordService $records,
		private InvInvoiceMapper $invoices,
		private InvLedgerMapper $ledger,
		private InvoiceFixed $fixed,
		private DraftResolver $drafts,
		private LedgerAppender $appender,
		private ITimeFactory $time,
	) {
	}

	/** 404 when the team issues on the Mac (setting off), 403 unless a Team Admin. */
	private function requireAdminOn(Membership $m): Tenant {
		$t = $this->team->tenant($m->tenantId);
		if (!$t->invoicingOn()) {
			throw ApiException::notFound('Invoicing on the server is off for this team.');
		}
		if (!$m->manages()) {
			throw ApiException::forbidden('Only Team Admins export invoices.');
		}
		return $t;
	}

	/**
	 * `GET /invoices/export?kind=csv|datev|ledger&from&to`.
	 *
	 * @return array{content:string,name:string}
	 */
	public function export(Membership $m, mixed $kind, mixed $from, mixed $to): array {
		$t = $this->requireAdminOn($m);
		$kind = ExportRules::kind($kind);
		$from = ExportRules::day($from, 'from');
		$to = ExportRules::day($to, 'to');
		$content = match ($kind) {
			ExportRules::KIND_LEDGER => $this->ledgerCsv($m->tenantId, $from, $to),
			ExportRules::KIND_DATEV => $this->datev($t, $m, $from, $to),
			default => $this->csv($m->tenantId, $from, $to),
		};
		return ['content' => ExportRules::BOM . $content, 'name' => ExportRules::fileName($kind, $from, $to)];
	}

	private function csv(int $tenantId, ?string $from, ?string $to): string {
		$out = ExportRules::line(ExportRules::CSV_HEAD);
		foreach ($this->invoices->findIssuedBetween($tenantId, $from, $to) as $row) {
			$reverses = $row->getReverses();
			$out .= ExportRules::line(ExportRules::csvRow($this->fixed->of($row), $reverses === null ? null : $this->invoices->findByKey($tenantId, $reverses)?->getNumber()));
		}
		return $out;
	}

	private function datev(Tenant $t, Membership $m, ?string $from, ?string $to): string {
		$datev = ExportRules::datev($t->getDatevJson());
		ExportRules::requireAccounts($datev);
		$rows = $this->invoices->findIssuedBetween($m->tenantId, $from, $to);
		$first = $rows[0] ?? null;
		$last = $rows === [] ? null : $rows[count($rows) - 1];
		$today = gmdate('Y-m-d', $this->time->getTime());
		$out = ExportRules::line(ExportRules::datevHeader($datev, $from ?? $first?->getIssuedOn() ?? $today, $to ?? $last?->getIssuedOn() ?? $today, $this->time->getTime(), $m->uid, $first?->getCurrency() ?? 'EUR'));
		$out .= ExportRules::line(ExportRules::DATEV_HEAD);
		$names = [];
		foreach ($rows as $row) {
			$key = $row->getProject();
			$names[$key] ??= DraftResolver::nameOf($this->drafts->live($m->tenantId, 'project', $key), $key);
			$f = $this->fixed->of($row);
			// 0.21.0: pass-through items need their own revenue account.
			if (VatLines::fromFixed($f)->passThrough !== 0.0) {
				ExportRules::requirePassThroughAccount($datev);
			}
			foreach (ExportRules::datevRows($f, $datev, $names[$key]) as $line) {
				$out .= ExportRules::line($line);
			}
		}
		return $out;
	}

	private function ledgerCsv(int $tenantId, ?string $from, ?string $to): string {
		$out = ExportRules::line(ExportRules::LEDGER_HEAD);
		foreach ($this->ledger->findAll($tenantId) as $e) {
			if (ExportRules::inPeriod(substr($e->getAt(), 0, 10), $from, $to)) {
				$out .= ExportRules::line(ExportRules::ledgerRow($e->present()));
			}
		}
		return $out;
	}

	/**
	 * `POST /invoices/{key}/external` – records the hand-over to an
	 * accounting system: ledger `exported`, `fixed.external` and, as the
	 * server's own write (clients may not write an issued invoice), the
	 * field `external` of the invoice record.
	 *
	 * @return array<string,mixed> the fixed content plus `ledger` and `record`
	 */
	public function external(Membership $m, string $key, \stdClass $body): array {
		$this->requireAdminOn($m);
		RecordValidator::checkAddress(InvoiceRules::KIND, $key);
		$v = ExportRules::external($body);
		return $this->tx->run(fn (): array => $this->externalWithin($m, $key, $v));
	}

	/**
	 * @param array{system:string,id:string,url:?string} $v
	 * @return array<string,mixed>
	 */
	private function externalWithin(Membership $m, string $key, array $v): array {
		$tid = $m->tenantId;
		$now = $this->time->getTime();
		$row = $this->invoices->findByKey($tid, $key) ?? throw ApiException::notFound('Nothing is fixed yet: this invoice is a draft.');
		[$cur, $d] = $this->drafts->invoiceRecord($tid, $key);
		// The team lock first, as in IssueService.
		$top = $this->tenants->bumpRevision($tid, 1);
		$entry = $this->appender->append($tid, $m->uid, ExportRules::EXPORTED, $row->getId(), $row->getNumber(),
			LedgerRules::canonical(['key' => $key, 'number' => $row->getNumber(), 'system' => $v['system'], 'id' => $v['id'], 'url' => $v['url']]), $now);
		$external = ['system' => $v['system'], 'id' => $v['id'], 'url' => $v['url'], 'at' => $entry->getAt(), 'by' => $m->uid];
		$row->setExternalJson(json_encode($external, Json::FLAGS));
		$row->setModifiedAt($now);
		$this->invoices->update($row);
		$d->{ExportRules::EXTERNAL_FIELD} = (object)$external;
		$checked = RecordValidator::validate(InvoiceRules::KIND, $key, $d);
		$op = ['kind' => InvoiceRules::KIND, 'key' => $key, 'version' => $cur->getVersion(), 'json' => $checked['json'], 'accounts' => $checked['accounts']];
		$written = $this->records->applyWithin($m, [$op], false, $top, true);
		return $this->fixed->of($row) + ['ledger' => self::block($entry), 'record' => $written['records'][0]];
	}

	/** @return array{seq:int,prev_hash:string,hash:string} */
	private static function block(InvLedger $e): array {
		return ['seq' => $e->getSeq(), 'prev_hash' => $e->getPrevHash(), 'hash' => $e->getHash()];
	}
}
