<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\InvInvoiceMapper;

/**
 * Filing in the invoicing mode manual (0.48.0): `PUT` and `GET
 * /invoices/{key}/document?kind=pdf|docx|xml` of an invoice the app issued
 * itself. The file goes to `Buchhaltung/<YYYY>/Rechnungen/` under a fixed
 * name and replaces its predecessor; no ledger entry, no fixed row, the
 * record stays the client's. The path is derived from the record, so
 * nothing is stored besides the file.
 */
final class ManualInvoiceService {
	public function __construct(
		private TenantService $team,
		private InvInvoiceMapper $invoices,
		private DraftResolver $drafts,
		private RecordRights $rights,
		private TeamTree $tree,
		private DocumentFolder $folders,
	) {
	}

	/** Does this request belong here: the team's mode is manual and the server never fixed the invoice? */
	public function handles(Membership $m, string $key): bool {
		return InvoicingModeRules::isManual($this->team->tenant($m->tenantId)->invoicingMode())
			&& $this->invoices->findByKey($m->tenantId, $key) === null;
	}

	/**
	 * `PUT …/document?kind=` – Team Admins: files or replaces the document.
	 *
	 * @return array{ok:true,path:string,name:string,size:int}
	 */
	public function put(Membership $m, string $key, mixed $kind, string $bytes): array {
		if (!$m->manages()) {
			throw ApiException::forbidden('Only Team Admins issue, reverse and settle invoices.')->code(ErrorCode::INVOICE_ISSUE_ADMIN_ONLY);
		}
		$kind = ManualInvoiceRules::kind($kind);
		RecordValidator::checkAddress(InvoiceRules::KIND, $key);
		[, $d] = $this->drafts->invoiceRecord($m->tenantId, $key);
		$at = ManualInvoiceRules::fileable($d);
		// A number the server fixed earlier (mode timesister) names a file of the ledger: never replaced from here.
		if ($this->invoices->findByNumber($m->tenantId, $at['number']) !== null) {
			throw ApiException::conflict(Message::of('The number {number} is already taken; check the invoice number pattern.', ['number' => $at['number']]))->code(ErrorCode::INVOICE_NUMBER_TAKEN);
		}
		ManualInvoiceRules::requireBytes($kind, $bytes);
		$place = $this->tree->of($m->tenantId);
		$folder = DocumentTree::invoiceFolder($place['root'], $at['date']);
		$name = ManualInvoiceRules::name($at['date'], $at['number'], $this->drafts->customerName($m->tenantId, $d), $kind);
		$written = $this->folders->write($place['owner'], $folder, $name, $bytes);
		return ['ok' => true, 'path' => $written['path'], 'name' => $name, 'size' => $written['size']];
	}

	/**
	 * `GET …/document?kind=` – Team Admins and the project's Leads: the
	 * filed file; 404 when there is none.
	 *
	 * @return array{content:string,name:string,type:string}
	 */
	public function get(Membership $m, string $key, mixed $kind): array {
		$kind = ManualInvoiceRules::kind($kind);
		RecordValidator::checkAddress(InvoiceRules::KIND, $key);
		$d = $this->drafts->live($m->tenantId, InvoiceRules::KIND, $key);
		// The rights of the fixed invoice's documents: Team Admins and the project's Leads.
		if (!$m->manages()) {
			$project = InvoiceRules::keyOf($d?->project ?? null);
			if ($project === null || !isset($this->rights->ledProjects($m)[$project])) {
				throw ApiException::forbidden('Only Team Admins and the project’s Leads read the fixed invoice.')->code(ErrorCode::INVOICE_READ_LEAD_ONLY);
			}
		}
		if ($d === null) {
			throw ApiException::notFound('This invoice does not exist.');
		}
		$at = ManualInvoiceRules::placed($d) ?? throw ApiException::notFound('The document is missing in the files.');
		$owner = $this->tree->ownerOf($m->tenantId);
		$folder = DocumentTree::invoiceFolder(TeamTree::rootOf($this->team->tenant($m->tenantId)), $at['date']);
		$name = ManualInvoiceRules::name($at['date'], $at['number'], $this->drafts->customerName($m->tenantId, $d), $kind);
		$file = $owner === null ? null : $this->folders->read($owner, $folder . '/' . $name);
		if ($file === null) {
			throw ApiException::notFound('The document is missing in the files.');
		}
		return ['content' => $file->getContent(), 'name' => DocumentRules::fileName($at['number'], $kind), 'type' => ManualInvoiceRules::TYPES[$kind]];
	}
}
