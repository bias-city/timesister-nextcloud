<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\Record;
use OCA\TimeSister\Db\RecordMapper;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Receipts (0.22.0, B3): the file under `Belege/` or `Spesen/<person>/`
 * of the document tree, approving (the file is renamed once to its
 * current name) and rejecting. The record's own writes go through
 * RecordService and CostWrites.
 */
final class ReceiptService {
	public const REASON_MAX = 300;

	public function __construct(
		private RecordMapper $records,
		private RecordService $recordService,
		private RecordRights $rights,
		private TeamTree $tree,
		private DocumentFolder $folders,
		private ReceiptPlace $places,
		private ITimeFactory $time,
	) {
	}

	/** The live receipt the caller reads. @return array{0:Record,1:\stdClass} */
	private function load(Membership $m, string $key): array {
		RecordValidator::checkAddress(ReceiptRules::KIND, $key);
		$r = $this->records->findOne($m->tenantId, ReceiptRules::KIND, $key);
		$d = $r === null || $r->isTombstone() ? null : InvoiceRules::decode($r->getData());
		if ($r === null || $d === null) {
			throw ApiException::notFound('This receipt does not exist.');
		}
		if (!$this->rights->canReadCost($m, ReceiptRules::KIND, $d)) {
			throw ApiException::forbidden('This account may not read this receipt.');
		}
		return [$r, $d];
	}

	/** @return array<string,true> */
	private function led(Membership $m): array {
		return $m->manages() ? [] : $this->rights->ledProjects($m);
	}

	// --------------------------------------------------------------- file

	/** `PUT /receipts/{key}/file` – PDF, JPEG or PNG, while open. @return array{path:string,sha256:string,size:int,mime:string,version:int} */
	public function putFile(Membership $m, string $key, string $content): array {
		[$rec, $d] = $this->load($m, $key);
		ReceiptRules::requireWrite($m->manages(), $m->uid, $this->led($m), $d, $d);
		if (($d->status ?? null) !== ReceiptRules::OPEN) {
			throw ApiException::conflict('A file is added only while the receipt is open.');
		}
		if (($d->kind ?? null) !== ReceiptRules::RECEIPT) {
			throw ApiException::invalid('Only a receipt of kind “receipt” has a file.');
		}
		if (strlen($content) > ReceiptRules::FILE_MAX) {
			throw ApiException::tooLarge('A file may be at most 20 MB.');
		}
		$mime = ReceiptRules::sniff($content) ?? throw ApiException::invalid('The file must be a PDF, JPEG or PNG.');
		$place = $this->tree->of($m->tenantId);
		[$folder, $name] = $this->places->place($m->tenantId, $place['root'], $d, DocumentTree::extension($mime));
		$old = $d->file->path ?? null;
		$written = $this->folders->store($place['owner'], $folder, $name, $content, is_string($old) ? $old : null);
		$d->file = (object)['path' => $written['path'], 'sha256' => hash('sha256', $content), 'size' => strlen($content), 'mime' => $mime];
		$record = $this->recordService->writeAsServer($m, ReceiptRules::KIND, $key, $rec->getVersion(), $d);
		return (array)$d->file + ['version' => (int)$record['version']];
	}

	/** `GET /receipts/{key}/file`. @return array{content:string,name:string,type:string} */
	public function getFile(Membership $m, string $key): array {
		[, $d] = $this->load($m, $key);
		$path = Fields::str(Fields::obj($d, 'file'), 'path');
		$owner = $this->tree->ownerOf($m->tenantId);
		$file = $path === null || $owner === null ? null : $this->folders->read($owner, $path);
		if ($path === null || $file === null) {
			throw ApiException::notFound('This receipt has no file.');
		}
		return ['content' => $file->getContent(), 'name' => basename($path), 'type' => Fields::str(Fields::obj($d, 'file'), 'mime') ?? 'application/octet-stream'];
	}

	// ----------------------------------------------------------- approval

	/** Lead of the project or Team Admins; general and trust receipts Team Admins. */
	private function requireApprover(Membership $m, \stdClass $d): void {
		if ($m->manages()) {
			return;
		}
		if (($d->kind ?? null) === ReceiptRules::TRUST || !ReceiptRules::ofLed($d, $this->led($m))) {
			throw ApiException::forbidden('Receipts are approved by the project’s Leads and Team Admins; general receipts and those without a file by Team Admins.');
		}
	}

	/** `POST /receipts/{key}/approve`: renamed once, approved (internal without project). @return array<string,mixed> the record */
	public function approve(Membership $m, string $key): array {
		[$rec, $d] = $this->load($m, $key);
		$this->requireApprover($m, $d);
		if (($d->status ?? null) !== ReceiptRules::OPEN) {
			throw ApiException::conflict('Only an open receipt is approved or rejected.');
		}
		if (($d->kind ?? null) === ReceiptRules::RECEIPT && Fields::obj($d, 'file') === null) {
			throw ApiException::invalid('The receipt has no file yet.');
		}
		$project = Fields::str($d, 'project');
		$p = $project === null ? null : $this->records->findOne($m->tenantId, 'project', $project);
		$pd = $p === null || $p->isTombstone() ? null : InvoiceRules::decode($p->getData());
		ReceiptRules::requireApprovable($d, $pd);
		$s = $this->records->findOne($m->tenantId, 'setting', 'settings');
		$team = ReceiptBooking::teamCurrency($s === null || $s->isTombstone() ? null : InvoiceRules::decode($s->getData()));
		ReceiptBooking::requireApprovable($d, $team, ReceiptBooking::budgetCurrencyOf($pd, $d, $team));
		$moved = $this->places->follow($m->tenantId, $d);
		$d->status = ReceiptRules::approvedStatus($d);
		$d->approved_by = $m->uid;
		$d->approved_on = gmdate('Y-m-d', $this->time->getTime());
		$d->reject_reason = null;
		ReceiptRecognition::clear($d);
		try {
			return $this->recordService->writeAsServer($m, ReceiptRules::KIND, $key, $rec->getVersion(), $d);
		} catch (\Throwable $e) {
			if ($moved !== null) {
				// The record still names the old path: move the file back.
				$this->places->back($moved);
			}
			throw $e;
		}
	}

	/** `POST /receipts/{key}/reject` `{ reason }`. @return array<string,mixed> the record */
	public function reject(Membership $m, string $key, \stdClass $body): array {
		$reason = $body->reason ?? null;
		if (!is_string($reason) || trim($reason) === '' || mb_strlen($reason) > self::REASON_MAX || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)) {
			throw ApiException::invalid(Message::of('“{field}” must be text of 1 to {max} characters.', ['field' => 'reason', 'max' => self::REASON_MAX]));
		}
		[$rec, $d] = $this->load($m, $key);
		$this->requireApprover($m, $d);
		if (($d->status ?? null) !== ReceiptRules::OPEN) {
			throw ApiException::conflict('Only an open receipt is approved or rejected.');
		}
		$d->status = ReceiptRules::REJECTED;
		$d->reject_reason = trim($reason);
		return $this->recordService->writeAsServer($m, ReceiptRules::KIND, $key, $rec->getVersion(), $d);
	}
}
