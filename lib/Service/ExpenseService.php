<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\Record;
use OCA\TimeSister\Db\RecordMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;

/**
 * Expense rules and reports (0.22.0, B4a): approving a rule, approving a
 * report (its PDF goes to `Spesen/<person>/`), marking it paid, serving
 * its document. Submitting is the record write (CostWrites).
 */
final class ExpenseService {
	public function __construct(
		private RecordMapper $records,
		private RecordService $recordService,
		private RecordRights $rights,
		private TeamTree $tree,
		private DocumentFolder $folders,
		private ReceiptFiles $files,
		private ExpenseReportPdf $pdf,
		private TenantService $team,
		private IConfig $config,
		private ITimeFactory $time,
	) {
	}

	/** A live record of the kind the caller reads. @return array{0:Record,1:\stdClass} */
	private function load(Membership $m, string $kind, string $key, Message $missing): array {
		RecordValidator::checkAddress($kind, $key);
		$r = $this->records->findOne($m->tenantId, $kind, $key);
		$d = $r === null || $r->isTombstone() ? null : InvoiceRules::decode($r->getData());
		if ($r === null || $d === null) {
			throw new ApiException(404, ApiException::NOT_FOUND, $missing);
		}
		if (!$this->rights->canReadCost($m, $kind, $d)) {
			throw ApiException::forbidden('This account may not read this record.');
		}
		return [$r, $d];
	}

	private function today(): string {
		return gmdate('Y-m-d', $this->time->getTime());
	}

	// --------------------------------------------------------------- rule

	/** `POST /expense-rules/{key}/approve` – Team Admins; with a project also its Lead. @return array<string,mixed> the record */
	public function approveRule(Membership $m, string $key): array {
		[$rec, $d] = $this->load($m, ExpenseRuleRules::KIND, $key, Message::of('This expense rule does not exist.'));
		if (!ExpenseRuleRules::canApprove($m->manages(), $m->manages() ? [] : $this->rights->ledProjects($m), $d)) {
			throw ApiException::forbidden('Expense rules are approved by Team Admins and, with a project, by its Leads.');
		}
		$d->approved_by = $m->uid;
		$d->approved_on = $this->today();
		return $this->recordService->writeAsServer($m, ExpenseRuleRules::KIND, $key, $rec->getVersion(), $d);
	}

	// ------------------------------------------------------------- report

	/** `POST /expense-reports/{key}/approve` – renders and stores the PDF. @return array<string,mixed> the record */
	public function approveReport(Membership $m, string $key): array {
		[$rec, $d] = $this->load($m, ExpenseReportRules::KIND, $key, Message::of('This expense report does not exist.'));
		$receipts = $this->files->receipts($m->tenantId, ExpenseReportRules::receiptsOf($d));
		if (!$m->manages() && !ExpenseReportRules::leadApproves($this->rights->ledProjects($m), array_values($receipts))) {
			throw ApiException::forbidden('Expense reports are approved by Team Admins and by a Lead of every receipt’s project.');
		}
		if (($d->status ?? null) !== ExpenseReportRules::SUBMITTED) {
			throw ApiException::conflict('Only a submitted expense report is approved.');
		}
		$place = $this->tree->of($m->tenantId);
		$d->approved_by = $m->uid;
		$d->approved_on = $this->today();
		$content = $this->render($m->tenantId, $key, $d, $receipts);
		$folder = DocumentTree::expenseFolder($place['root'], (string)$d->to, $this->team->displayName((string)$d->person));
		$old = $d->document->path ?? null;
		$written = $this->folders->store($place['owner'], $folder, DocumentTree::reportName((string)$d->to), $content, is_string($old) ? $old : null);
		$d->status = ExpenseReportRules::APPROVED;
		$d->document = (object)['path' => $written['path'], 'sha256' => hash('sha256', $content), 'size' => strlen($content)];
		return $this->recordService->writeAsServer($m, ExpenseReportRules::KIND, $key, $rec->getVersion(), $d);
	}

	/** `POST /expense-reports/{key}/paid` `{ date }` – Team Admins. @return array<string,mixed> the record */
	public function paid(Membership $m, string $key, \stdClass $body): array {
		if (!$m->manages()) {
			throw ApiException::forbidden('Only Team Admins mark expense reports paid.');
		}
		$date = $body->date ?? $this->today();
		if (!is_string($date) || !Time::isDay($date)) {
			throw ApiException::invalid(Message::of('“{field}” must be a day in the format YYYY-MM-DD.', ['field' => 'date']));
		}
		[$rec, $d] = $this->load($m, ExpenseReportRules::KIND, $key, Message::of('This expense report does not exist.'));
		if (($d->status ?? null) !== ExpenseReportRules::APPROVED) {
			throw ApiException::conflict('Only an approved expense report is marked paid.');
		}
		$d->status = ExpenseReportRules::PAID;
		$d->paid_on = $date;
		return $this->recordService->writeAsServer($m, ExpenseReportRules::KIND, $key, $rec->getVersion(), $d);
	}

	/** `GET /expense-reports/{key}/document`. @return array{content:string,name:string} */
	public function document(Membership $m, string $key): array {
		[, $d] = $this->load($m, ExpenseReportRules::KIND, $key, Message::of('This expense report does not exist.'));
		$path = Fields::str(Fields::obj($d, 'document'), 'path');
		$owner = $this->tree->ownerOf($m->tenantId);
		$file = $path === null || $owner === null ? null : $this->folders->read($owner, $path);
		if ($path === null || $file === null) {
			throw ApiException::notFound('This expense report has no document yet; it is written on approval.');
		}
		return ['content' => $file->getContent(), 'name' => basename($path)];
	}

	/** The PDF of the report with the receipts' copies. @param array<string,\stdClass> $receipts */
	private function render(int $tid, string $key, \stdClass $d, array $receipts): string {
		$settingsRecord = $this->records->findOne($tid, 'setting', 'settings');
		$settings = $settingsRecord === null || $settingsRecord->isTombstone() ? null : InvoiceRules::decode($settingsRecord->getData());
		$lang = DocumentRules::language($settings, $this->config->getSystemValueString('default_language', DocumentRules::EN));
		$t = DocumentRules::texts($lang);
		$rows = [];
		$projects = [];
		foreach ($receipts as $r) {
			$p = is_string($r->project ?? null) ? $r->project : null;
			if ($p !== null && !isset($projects[$p])) {
				$pr = $this->records->findOne($tid, 'project', $p);
				$projects[$p] = ReceiptRules::projectCode($pr === null ? null : InvoiceRules::decode($pr->getData()), $p);
			}
			$rate = $r->vat_rate ?? null;
			$rows[] = [
				'date' => (string)($r->date ?? ''), 'supplier' => (string)($r->supplier ?? ''), 'text' => (string)($r->text ?? ''),
				'project' => $p === null ? '' : $projects[$p], 'net' => (float)($r->net ?? 0), 'vat_rate' => is_int($rate) || is_float($rate) ? (float)$rate : null,
				'vat' => (float)($r->vat ?? 0), 'gross' => (float)($r->gross ?? 0), 'without' => ExpenseReportRules::withoutReceipt($r),
			];
		}
		$sums = ExpenseReportRules::sums(array_values($receipts), 'CHF');
		return $this->pdf->render([
			'lang' => $lang, 'team' => $this->tree->of($tid)['tenant']->getName(), 'person' => $this->team->displayName((string)$d->person),
			'from' => (string)$d->from, 'to' => (string)$d->to, 'currency' => $sums['currency'], 'key' => $key,
			'rows' => $rows, 'sums' => $sums,
			'approved_by' => ($by = Fields::str($d, 'approved_by')) === null ? null : $this->team->displayName($by),
			'approved_on' => Fields::str($d, 'approved_on'),
			'copies' => $this->files->copies($tid, $receipts, $lang, $t),
		]);
	}
}
