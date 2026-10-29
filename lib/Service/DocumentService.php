<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\InvInvoice;
use OCA\TimeSister\Db\InvInvoiceMapper;
use OCA\TimeSister\Db\InvLedgerMapper;
use OCA\TimeSister\Db\RecordMapper;
use OCA\TimeSister\Db\Tenant;
use OCA\TimeSister\Db\TenantMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

/**
 * Invoice documents: since 0.32.0 the server renders nothing – it files
 * the app's PDF and XML under `Buchhaltung/<YYYY>/Rechnungen/` of the
 * team's document tree in the backup owner's files, serves the filed
 * files, re-hashes them for `chain/verify?files=true`, writes the daily
 * chain head file and mails it, and checks the folders of the team
 * setting `documents`.
 */
final class DocumentService {
	public const ACTOR_SYSTEM = 'system';

	public function __construct(
		private TenantMapper $tenants,
		private TenantService $team,
		private RecordMapper $records,
		private RecordRights $rights,
		private InvInvoiceMapper $invoices,
		private InvLedgerMapper $ledger,
		private DocumentFolder $folders,
		private IMailer $mailer,
		private IL10N $l,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	// ------------------------------------------------------------- guards

	/** 404 when the team issues on the Mac (setting off). */
	private function requireOn(Membership $m): Tenant {
		$t = $this->team->tenant($m->tenantId);
		if (!$t->invoicingOn()) {
			throw ApiException::notFound('Invoicing on the server is off for this team.');
		}
		return $t;
	}

	private function requireAdmin(Membership $m): void {
		if (!$m->manages()) {
			throw ApiException::forbidden('Only Team Admins read the chain head.');
		}
	}

	/** Team Admins and the project's Leads, like `fixed`; the record must be live. */
	private function requireReader(Membership $m, string $key): void {
		RecordValidator::checkAddress(InvoiceRules::KIND, $key);
		$r = $this->records->findOne($m->tenantId, InvoiceRules::KIND, $key);
		if (!$m->manages()) {
			$project = InvoiceRules::projectOf($r?->getData());
			if ($project === null || !isset($this->rights->ledProjects($m)[$project])) {
				throw ApiException::forbidden('Only Team Admins and the project’s Leads read the fixed invoice.')->code(ErrorCode::INVOICE_READ_LEAD_ONLY);
			}
		}
		if ($r === null || $r->isTombstone()) {
			throw ApiException::notFound('This invoice does not exist.');
		}
	}

	// ------------------------------------------------------------- filing

	/**
	 * Files the app's document of a frozen invoice:
	 * `Buchhaltung/<YYYY>/Rechnungen/<issued_on>_<number>_<customer>.<pdf|xml>`;
	 * a taken name gets `_2` …; the XML goes into the PDF's folder.
	 *
	 * @return array{path:string,file_id:int,size:int,name:string}
	 */
	public function file(Tenant $t, InvInvoice $row, string $bytes, string $kind): array {
		$owner = $this->team->backupOwner($t) ?? throw ApiException::invalid('The team has no backup owner; documents need one.');
		$pdf = $row->document(DocumentRules::KIND_PDF);
		$folder = $kind === DocumentRules::KIND_XML && $pdf !== null ? dirname($pdf['path']) : DocumentTree::invoiceFolder(TeamTree::rootOf($t), $row->getIssuedOn());
		$customerName = InvoiceRules::decode($row->getCustomerJson())?->name ?? null;
		$name = DocumentTree::invoiceName($row->getIssuedOn(), $row->getNumber(), is_string($customerName) ? $customerName : '', $kind);
		$free = $this->folders->freeName($owner, $folder, $name);
		return $this->folders->write($owner, $folder, $free, $bytes) + ['name' => $free];
	}

	// ------------------------------------------------------------- reading

	/** `GET /invoices/{key}/document?kind=pdf|xml` – the filed file. @return array{content:string,name:string,type:string} */
	public function document(Membership $m, string $key, mixed $kind): array {
		$t = $this->requireOn($m);
		$this->requireReader($m, $key);
		if ($kind === null || $kind === '') {
			$kind = DocumentRules::KIND_PDF;
		}
		if ($kind !== DocumentRules::KIND_PDF && $kind !== DocumentRules::KIND_XML) {
			throw ApiException::invalid('“kind” must be “pdf” or “xml”.');
		}
		$row = $this->invoices->findByKey($m->tenantId, $key) ?? throw ApiException::notFound('Nothing is fixed yet: this invoice is a draft.');
		if ($row->isIssuing() || $row->document(DocumentRules::KIND_PDF) === null) {
			throw IssueService::documentMissing();
		}
		$doc = $row->document($kind) ?? throw ApiException::notFound('This invoice has no XRechnung file (the customer asks for none).');
		$owner = $this->team->backupOwner($t);
		$file = $owner === null ? null : $this->folders->read($owner, $doc['path']);
		if ($file === null) {
			throw ApiException::notFound('The document is missing in the files.');
		}
		return ['content' => $file->getContent(), 'name' => DocumentRules::fileName($row->getNumber(), $kind), 'type' => $kind === DocumentRules::KIND_XML ? 'application/xml' : 'application/pdf'];
	}

	/**
	 * `chain/verify?files=true`: every filed file named in a payload is
	 * read and hashed; the first difference (or a missing file) breaks.
	 *
	 * @param list<array{seq:int,invoice:int,path:?string,sha256:string}> $files
	 * @return ?array{ok:false,first_break:int,field:string,expected:string,found:string}
	 */
	public function verifyFiles(Tenant $t, array $files): ?array {
		$owner = $this->team->backupOwner($t);
		foreach ($files as $f) {
			$path = $f['path'];
			if ($path === null) {
				// The issue's own document: the path stands on the frozen row.
				$row = $this->invoices->findById($t->getId(), $f['invoice']);
				$path = $row?->document(DocumentRules::KIND_PDF)['path'] ?? null;
			}
			$node = $owner === null || $path === null ? null : $this->folders->read($owner, $path);
			$found = $node === null ? null : hash('sha256', $node->getContent());
			if ($found !== $f['sha256']) {
				return DocumentRules::fileBreak($f['seq'], $f['sha256'], $found);
			}
		}
		return null;
	}

	/** `GET /invoices/chain/head` – Team Admins. @return array{seq:int,hash:?string,at:?string,entries:int} */
	public function chainHead(Membership $m): array {
		$this->requireOn($m);
		$this->requireAdmin($m);
		$last = $this->ledger->last($m->tenantId);
		return [
			'seq' => $last?->getSeq() ?? 0,
			'hash' => $last?->getHash(),
			'at' => $last?->getAt(),
			'entries' => $last === null ? 0 : $this->ledger->count($m->tenantId),
		];
	}

	// ---------------------------------------------------------- chain head

	/**
	 * The daily job: for every team with a ledger, `chain-<day>.txt` in
	 * `Buchhaltung/<YYYY>/Kette/` of the tree, and the same text by mail
	 * when `documents.chain_mail` is set.
	 *
	 * @return array{written:int,mailed:int,failed:int}
	 */
	public function chainHeads(): array {
		$n = ['written' => 0, 'mailed' => 0, 'failed' => 0];
		$day = gmdate('Y-m-d', $this->time->getTime());
		foreach ($this->tenants->findAll() as $t) {
			$last = $this->ledger->last($t->getId());
			if ($last === null) {
				continue;
			}
			$text = DocumentRules::chainHeadText($t->getName(), $day, $this->ledger->count($t->getId()), $last->getSeq(), $last->getHash());
			$settings = DocumentRules::settings($t->getDocumentsJson());
			try {
				$owner = $this->team->backupOwner($t) ?? throw new \RuntimeException('no backup owner');
				$this->folders->write($owner, DocumentTree::chainFolder(TeamTree::rootOf($t), $day), DocumentTree::chainName($day), $text);
				$n['written']++;
			} catch (\Throwable $e) {
				$n['failed']++;
				$this->logger->warning('TimeSister: chain head of team {team} not written: {message}', ['app' => 'timesister', 'team' => $t->getSlug(), 'message' => $e->getMessage()]);
			}
			$mail = $settings['chain_mail'];
			if ($mail === null) {
				continue;
			}
			try {
				$message = $this->mailer->createMessage();
				$message->setTo([$mail => $mail]);
				$message->setSubject('TimeSister – chain head ' . $t->getName() . ' ' . $day);
				$message->setPlainBody($text);
				$this->mailer->send($message);
				$n['mailed']++;
			} catch (\Throwable $e) {
				$n['failed']++;
				$this->logger->warning('TimeSister: chain head of team {team} not mailed: {message}', ['app' => 'timesister', 'team' => $t->getSlug(), 'message' => $e->getMessage()]);
			}
		}
		return $n;
	}

	// ------------------------------------------------------------- folders

	/**
	 * `POST /admin/teams/{id}/documents/check` `{ root }`: the root as typed
	 * (null: `TimeSister/<team>`) in the backup owner's files.
	 *
	 * @return array{root:array{ok:bool,message:string,path:string}}
	 */
	public function checkFolders(Tenant $t, mixed $root): array {
		$path = DocumentTree::root(DocumentRules::path($root, DocumentRules::FIELD . '.root'), $t->getName());
		$r = $this->folders->check($this->team->backupOwner($t), $path);
		return ['root' => ['ok' => $r['ok'], 'message' => $this->folders->message($r)->text($this->l), 'path' => $path]];
	}

	/** The root of a setting must be there or creatable and writable: 422 otherwise. @param array{root:?string,...} $s */
	public function requireFolders(?string $owner, array $s, string $teamName): void {
		$this->folders->require($owner, DocumentRules::rootOf($s, $teamName));
	}
}
