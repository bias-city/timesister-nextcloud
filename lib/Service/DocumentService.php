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
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

/**
 * Invoice documents (0.19.0): renders the PDF of an issued invoice – since
 * 0.20.0 as PDF/A-3 with the EN 16931 XML inside (ZUGFeRD), plus an
 * XRechnung file when the customer wants one, since 0.22.0 with the
 * receipt copies –, stores it under `Buchhaltung/<YYYY>/Rechnungen/` of the
 * team's document tree in the backup owner's files, appends the ledger action `document`
 * with the file's hash and keeps `document_state`;
 * serves the watermarked preview of a draft (never stored) and the stored
 * document; renders again on request and through the retry job; writes
 * the daily chain head file and mails it; checks the folders of the team
 * setting `documents`.
 */
final class DocumentService {
	public const ACTOR_SYSTEM = 'system';

	public function __construct(
		private IDBConnection $db,
		private TenantMapper $tenants,
		private TenantService $team,
		private RecordMapper $records,
		private RecordRights $rights,
		private InvInvoiceMapper $invoices,
		private InvLedgerMapper $ledger,
		private DraftResolver $drafts,
		private LedgerAppender $appender,
		private InvoiceFixed $fixed,
		private InvoicePdf $pdf,
		private EInvoiceXml $einvoice,
		private DocumentFolder $folders,
		private ReceiptFiles $receiptFiles,
		private IConfig $config,
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
			throw ApiException::forbidden('Only Team Admins render documents and read the chain head.');
		}
	}

	/** Team Admins and the project's Leads, like `fixed`; the record must be live. */
	private function requireReader(Membership $m, string $key): void {
		RecordValidator::checkAddress(InvoiceRules::KIND, $key);
		$r = $this->records->findOne($m->tenantId, InvoiceRules::KIND, $key);
		if (!$m->manages()) {
			$project = InvoiceRules::projectOf($r?->getData());
			if ($project === null || !isset($this->rights->ledProjects($m)[$project])) {
				throw ApiException::forbidden('Only Team Admins and the project’s Leads read the fixed invoice.');
			}
		}
		if ($r === null || $r->isTombstone()) {
			throw ApiException::notFound('This invoice does not exist.');
		}
	}

	// ----------------------------------------------------------- rendering

	/**
	 * Renders and stores the document of a frozen invoice and appends the
	 * ledger entry; on failure the row says `failed` with the error. Never
	 * throws: the callers (issue, job, render endpoint) read the state.
	 */
	public function render(Tenant $t, InvInvoice $row, string $actor, int $now): void {
		$row->setDocumentTries($row->getDocumentTries() + 1);
		try {
			$owner = $this->team->backupOwner($t) ?? throw new \RuntimeException('The team has no backup owner; documents need one.');
			$doc = $this->fromRow($t, $row);
			// 0.20.0: the PDF becomes PDF/A-3 with the EN 16931 XML inside (ZUGFeRD); an XRechnung file beside it when wanted.
			$content = $this->einvoice->embed($doc, $this->pdf->render($doc));
			$xml = $doc->wantsXRechnung() ? $this->einvoice->xrechnung($doc) : null;
			// 0.22.0: the tree, `Buchhaltung/<YYYY>/Rechnungen/<date>_<number>_<customer>.pdf`.
			$folder = DocumentTree::invoiceFolder(TeamTree::rootOf($t), $row->getIssuedOn());
			$customer = is_string($doc->customer['name'] ?? null) ? $doc->customer['name'] : '';
			$name = DocumentTree::invoiceName($row->getIssuedOn(), $row->getNumber(), $customer);
			$written = $this->folders->write($owner, $folder, $name, $content);
			$sha = hash('sha256', $content);
			$writtenXml = $xml === null ? null : $this->folders->write($owner, $folder, DocumentTree::invoiceName($row->getIssuedOn(), $row->getNumber(), $customer, DocumentRules::KIND_XML), $xml);
			$shaXml = $xml === null ? null : hash('sha256', $xml);
			$this->db->beginTransaction();
			try {
				$this->appender->append($t->getId(), $actor, DocumentRules::ACTION, $row->getId(), $row->getNumber(), LedgerRules::canonical([
					'key' => $row->getRkey(), 'number' => $row->getNumber(), 'kind' => DocumentRules::KIND_ZUGFERD,
					'path' => $written['path'], 'size' => $written['size'], 'sha256' => $sha,
				]), $now);
				if ($writtenXml !== null) {
					$this->appender->append($t->getId(), $actor, DocumentRules::ACTION, $row->getId(), $row->getNumber(), LedgerRules::canonical([
						'key' => $row->getRkey(), 'number' => $row->getNumber(), 'kind' => DocumentRules::KIND_XRECHNUNG,
						'path' => $writtenXml['path'], 'size' => $writtenXml['size'], 'sha256' => $shaXml,
					]), $now);
				}
				$row->setDocumentState(InvInvoice::DOCUMENT_DONE);
				$row->setDocumentError(null);
				$row->setDocumentPath($written['path']);
				$row->setDocumentSha256($sha);
				$row->setPdfFileId($written['file_id']);
				$row->setXmlPath($writtenXml['path'] ?? null);
				$row->setXmlSha256($shaXml);
				$row->setXmlFileId($writtenXml['file_id'] ?? null);
				$row->setModifiedAt($now);
				$this->invoices->update($row);
				$this->db->commit();
			} catch (\Throwable $e) {
				if ($this->db->inTransaction()) {
					$this->db->rollBack();
				}
				throw $e;
			}
		} catch (\Throwable $e) {
			$row->setDocumentState(InvInvoice::DOCUMENT_FAILED);
			// The cause stays in the log; the client gets a plain text.
			$row->setDocumentError(Message::of('The document could not be rendered; see the server log.')->text());
			$row->setModifiedAt($now);
			try {
				$this->invoices->update($row);
			} catch (\Throwable) {
				// The state could not be written either; the next try will tell.
			}
			$this->logger->warning('TimeSister: document of invoice {number} not rendered (try {n})', [
				'app' => 'timesister', 'number' => $row->getNumber(), 'n' => $row->getDocumentTries(), 'exception' => $e,
			]);
		}
	}

	/** `POST /invoices/{key}/render` – Team Admins: renders again, now. @return array{document_state:string,document_error:?string} */
	public function renderNow(Membership $m, string $key): array {
		$t = $this->requireOn($m);
		$this->requireAdmin($m);
		RecordValidator::checkAddress(InvoiceRules::KIND, $key);
		$row = $this->invoices->findByKey($m->tenantId, $key) ?? throw ApiException::notFound('Nothing is fixed yet: this invoice is a draft.');
		$owner = $this->team->backupOwner($t);
		$path = $row->getDocumentPath();
		$stored = $row->getDocumentState() === InvInvoice::DOCUMENT_DONE && $owner !== null && $path !== null
			&& $this->folders->read($owner, $path) !== null;
		if (!$stored) {
			$this->render($t, $row, $m->uid, $this->time->getTime());
		}
		return ['document_state' => $row->getDocumentState(), 'document_error' => $row->getDocumentError()];
	}

	/** The retry job: pending and failed documents, up to ten tries each. @return array{done:int,failed:int} */
	public function retry(int $limit): array {
		$n = ['done' => 0, 'failed' => 0];
		$now = $this->time->getTime();
		foreach ($this->invoices->findToRender(DocumentRules::MAX_TRIES, $limit) as $row) {
			$t = $this->tenants->find($row->getTenantId());
			if ($t === null) {
				continue;
			}
			$this->render($t, $row, self::ACTOR_SYSTEM, $now);
			$n[$row->getDocumentState() === InvInvoice::DOCUMENT_DONE ? 'done' : 'failed']++;
		}
		return $n;
	}

	// ------------------------------------------------------------- reading

	/** `GET /invoices/{key}/preview` – the draft as it would be issued, watermarked; never stored. @return array{content:string,name:string} */
	public function preview(Membership $m, string $key): array {
		$t = $this->requireOn($m);
		$this->requireReader($m, $key);
		if ($this->invoices->findByKey($m->tenantId, $key) !== null) {
			throw ApiException::issued();
		}
		$r = $this->drafts->resolve($m->tenantId, $key);
		$now = $this->time->getTime();
		$issuedOn = gmdate('Y-m-d', $now);
		$d = $r['d'];
		$f = [
			'number' => '', 'reverses' => null, 'credits' => null,
			'issuer' => $r['issuer'], 'customer' => $r['customerBlock'],
			'issued_on' => $issuedOn, 'due_on' => IssueRules::dueOn($issuedOn, IssueRules::DUE_DAYS),
			'period' => ['from' => (string)$d->from, 'to' => (string)$d->to],
			'project' => $r['projectKey'], 'budget' => (string)$d->budget,
			'greeting' => IssueRules::textOf($d, 'greeting'), 'body' => IssueRules::textOf($d, 'body'), 'footer' => IssueRules::textOf($d, 'footer'),
			'positions' => $r['positions'], 'currency' => $r['currency'], 'vat' => $r['vat'],
			...$r['sums']->taxable(), ...$r['sums']->frozen(),
			'hours' => $r['hours'], 'hours_total' => IssueRules::totalHours($r['hours']),
			'chain' => ['seq' => null, 'prev_hash' => null, 'hash' => null],
			'customer_key' => $r['customerKey'], 'qr_reference' => null,
			'layout' => InvoiceLayout::check($d->{InvoiceLayout::FIELD} ?? null),
		];
		$doc = $this->build($t, $f, $r['settings']);
		$doc->watermark = DocumentRules::watermark($doc->lang);
		$doc->chainLine = $doc->watermark . ' – ' . $doc->texts()['not_issued'];
		return ['content' => $this->pdf->render($doc), 'name' => DocumentRules::fileName('preview-' . $key)];
	}

	/** `GET /invoices/{key}/document?kind=pdf|xml` – the stored file. @return array{content:string,name:string,type:string} */
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
		$path = $kind === DocumentRules::KIND_XML ? $row->getXmlPath() : $row->getDocumentPath();
		if ($row->getDocumentState() !== InvInvoice::DOCUMENT_DONE || $path === null) {
			throw $kind === DocumentRules::KIND_XML && $row->getDocumentState() === InvInvoice::DOCUMENT_DONE
				? ApiException::notFound('This invoice has no XRechnung file (the customer asks for none).')
				: ApiException::notFound('The document is not rendered yet.');
		}
		$owner = $this->team->backupOwner($t);
		$file = $owner === null ? null : $this->folders->read($owner, $path);
		if ($file === null) {
			throw ApiException::notFound('The document is missing in the files; render it again.');
		}
		return ['content' => $file->getContent(), 'name' => DocumentRules::fileName($row->getNumber(), $kind), 'type' => $kind === DocumentRules::KIND_XML ? 'application/xml' : 'application/pdf'];
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

	// ------------------------------------------------------------ building

	/** The document of a frozen row. */
	private function fromRow(Tenant $t, InvInvoice $row): InvoiceDocument {
		$f = $this->fixed->of($row);
		$doc = $this->build($t, $f, $this->drafts->live($t->getId(), 'setting', 'settings'));
		$doc->chainLine = DocumentRules::chainLine($row->getNumber(), $row->getSeq(), $row->getHash(), $row->getPrevHash());
		return $doc;
	}

	/**
	 * The document from a fixed-like array (the frozen content, or the
	 * draft resolved for a preview): names, language, appendix, IBAN.
	 *
	 * @param array<string,mixed> $f
	 */
	private function build(Tenant $t, array $f, ?\stdClass $settings): InvoiceDocument {
		$tid = $t->getId();
		$doc = new InvoiceDocument();
		$doc->lang = DocumentRules::language($settings, $this->config->getSystemValueString('default_language', DocumentRules::EN));
		$doc->number = self::str($f['number'] ?? '');
		$doc->reverses = self::nullable($f['reverses'] ?? null);
		$doc->credits = self::nullable($f['credits'] ?? null);
		if ($doc->reverses !== null) {
			$doc->originalNumber = $this->invoices->findByKey($tid, $doc->reverses)?->getNumber();
		}
		$doc->issuer = self::toArray($f['issuer'] ?? null);
		$doc->customer = self::toArray($f['customer'] ?? null);
		$doc->issuedOn = self::str($f['issued_on'] ?? '');
		$doc->dueOn = self::str($f['due_on'] ?? '');
		$period = self::toArray($f['period'] ?? null);
		$doc->from = self::str($period['from'] ?? '');
		$doc->to = self::str($period['to'] ?? '');
		$projectKey = self::str($f['project'] ?? '');
		$budgetId = self::str($f['budget'] ?? '');
		$project = $this->drafts->live($tid, 'project', $projectKey);
		$doc->project = DraftResolver::nameOf($project, $projectKey);
		$doc->budget = DraftResolver::nameOf($project === null ? null : IssueRules::budgetOf($project, $budgetId), $budgetId);
		$doc->greeting = self::nullable($f['greeting'] ?? null);
		$doc->body = self::nullable($f['body'] ?? null);
		$doc->footer = self::nullable($f['footer'] ?? null);
		$doc->positions = array_values(array_map(static fn (mixed $p): array => self::toArray($p), is_array($f['positions'] ?? null) ? $f['positions'] : []));
		$layout = self::toArray($f['layout'] ?? null);
		$doc->layout = $layout === [] ? null : $layout;
		$doc->currency = self::str($f['currency'] ?? 'CHF');
		$doc->vat = self::float($f['vat'] ?? 0);
		$doc->sums = VatLines::fromFixed($f);
		$doc->hoursTotal = self::float($f['hours_total'] ?? 0);
		foreach (is_array($f['hours'] ?? null) ? $f['hours'] : [] as $h) {
			$h = self::toArray($h);
			$uid = self::str($h['uid'] ?? '');
			$eventUid = self::str($h['event_uid'] ?? '');
			$doc->appendix[] = [
				'date' => substr(self::str($h['start'] ?? ''), 0, 10),
				'person' => $this->team->displayName($uid),
				'code' => self::str($h['code'] ?? ''),
				'title' => $this->drafts->titleOf($tid, $uid, $eventUid),
				'hours' => self::float($h['hours'] ?? 0),
			];
		}
		// 0.22.0: the copies of the receipts the positions name.
		$doc->copies = $this->receiptFiles->copies($tid, $this->receiptFiles->receipts($tid, ReceiptFiles::keysOf($doc->positions)), $doc->lang, $doc->texts());
		$doc->iban = self::ibanFor($doc->issuer, $doc->currency);
		// 0.20.0: the e-invoice's account, the tax case and the QR reference, the customer's key and Leitweg-ID.
		$issuerObject = json_decode(json_encode($doc->issuer, Json::FLAGS), false);
		$account = $issuerObject instanceof \stdClass ? IssueRules::accountOf($issuerObject, $doc->currency) : null;
		$doc->accountIban = $account[0] ?? null;
		$doc->accountBic = $account[1] ?? null;
		$doc->taxCase = TaxRules::ofBlock($doc->customer);
		$doc->qrReference = self::nullable($f['qr_reference'] ?? null);
		$doc->customerKey = self::nullable($f['customer_key'] ?? null);
		$doc->leitwegId = self::nullable($doc->customer['leitweg_id'] ?? null);
		$doc->eInvoice = ($doc->customer['e_invoice'] ?? null) === true;
		return $doc;
	}

	/** The issuer's IBAN in this currency when `issuer.qr` is not false. @param array<string,mixed> $issuer */
	private static function ibanFor(array $issuer, string $currency): ?string {
		if (($issuer['qr'] ?? null) === false) {
			return null;
		}
		foreach (is_array($issuer['accounts'] ?? null) ? $issuer['accounts'] : [] as $a) {
			$a = self::toArray($a);
			$iban = $a['iban'] ?? null;
			if (strtoupper(self::str($a['currency'] ?? '')) === $currency && is_string($iban) && $iban !== '') {
				return strtoupper(str_replace(' ', '', $iban));
			}
		}
		return null;
	}

	/** @return array<string,mixed> */
	private static function toArray(mixed $v): array {
		if ($v instanceof \stdClass) {
			$v = get_object_vars($v);
		}
		if (!is_array($v)) {
			return [];
		}
		foreach ($v as $k => $e) {
			if ($e instanceof \stdClass) {
				$v[$k] = self::toArray($e);
			}
		}
		return $v;
	}

	private static function str(mixed $v): string {
		return is_string($v) ? $v : (is_int($v) || is_float($v) ? (string)$v : '');
	}

	private static function nullable(mixed $v): ?string {
		return is_string($v) && $v !== '' ? $v : null;
	}

	private static function float(mixed $v): float {
		return is_int($v) || is_float($v) ? (float)$v : (is_numeric($v) ? (float)$v : 0.0);
	}
}
