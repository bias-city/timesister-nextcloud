<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\EntryMapper;
use OCA\TimeSister\Db\InvInvoice;
use OCA\TimeSister\Db\InvInvoiceMapper;
use OCA\TimeSister\Db\InvLedgerMapper;
use OCA\TimeSister\Db\RecordMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ITempManager;

/**
 * The project dossier (0.22.0): a ZIP with the project's quote and
 * invoice documents, its receipts' files, `hours.csv` (its entries in the
 * book) and `ledger.txt` (its invoices' ledger lines), written to
 * `Projekte/<code> <name>/Projektakte-<date>.zip`; the same day replaces it.
 */
final class DossierService {
	private const PAGE = 1000;

	public function __construct(
		private RecordMapper $records,
		private RecordRights $rights,
		private TeamTree $tree,
		private DocumentFolder $folders,
		private InvInvoiceMapper $invoices,
		private InvLedgerMapper $ledger,
		private EntryMapper $entries,
		private ITempManager $temp,
		private ITimeFactory $time,
	) {
	}

	/** `POST /projects/{key}/dossier` – Team Admins and the project's Leads. @return array{path:string,size:int,sha256:string} */
	public function create(Membership $m, string $key): array {
		RecordValidator::checkAddress('project', $key);
		$tid = $m->tenantId;
		$r = $this->records->findOne($tid, 'project', $key);
		$project = $r === null || $r->isTombstone() ? null : InvoiceRules::decode($r->getData());
		if ($project === null) {
			throw ApiException::notFound('This project does not exist.');
		}
		if (!$m->manages() && !isset($this->rights->ledProjects($m)[$key])) {
			throw ApiException::forbidden('Project dossiers are made by Team Admins and the project’s Leads.');
		}
		$place = $this->tree->of($tid);
		$owner = $place['owner'];
		$path = $this->temp->getTemporaryFile('.zip');
		if ($path === false) {
			throw new \RuntimeException('No temporary file for the dossier.');
		}
		$zip = ZipFile::create($path);
		$taken = [];
		$add = function (string $folder, mixed $file) use ($zip, $owner, &$taken): void {
			if (!is_string($file) || $file === '') {
				return;
			}
			$node = $this->folders->read($owner, $file);
			if ($node !== null) {
				$name = DocumentTree::unique($folder . '/' . basename($file), static fn (string $n): bool => isset($taken[$n]));
				$taken[$name] = true;
				$zip->add($name, $node->getContent());
			}
		};
		foreach ($this->records->findLiveByKind($tid, QuoteRules::KIND) as $q) {
			$d = InvoiceRules::decode($q->getData());
			if (($d?->project ?? null) === $key) {
				$add('Offerten', $d?->document?->path ?? null);
			}
		}
		$ids = [];
		foreach ($this->invoices->findAll($tid) as $inv) {
			if ($inv->getProject() !== $key) {
				continue;
			}
			$ids[$inv->getId()] = true;
			if ($inv->getDocumentState() === InvInvoice::DOCUMENT_DONE) {
				$add('Rechnungen', $inv->getDocumentPath());
				$add('Rechnungen', $inv->getXmlPath());
			}
		}
		foreach ($this->records->findLiveByKind($tid, ReceiptRules::KIND) as $rc) {
			$d = InvoiceRules::decode($rc->getData());
			if (($d?->project ?? null) === $key) {
				$add('Belege', $d?->file?->path ?? null);
			}
		}
		$zip->add('hours.csv', DossierRules::hoursCsv($this->hours($tid, DossierRules::codesOf($project))));
		$lines = array_values(array_filter(array_map(static fn ($e): array => $e->present(), $this->ledger->findAll($tid)),
			static fn (array $e): bool => isset($ids[$e['invoice']])));
		$zip->add('ledger.txt', DossierRules::ledgerText($lines));
		$zip->close();
		$content = (string)file_get_contents($path);
		@unlink($path);
		$day = gmdate('Y-m-d', $this->time->getTime());
		$code = DossierRules::codesOf($project)[0] ?? null;
		$root = $place['root'];
		$name = Fields::str($project, 'name') ?? $key;
		$written = $this->folders->write($owner, DocumentTree::projectFolder($root, $code, $name), DocumentTree::dossierName($day), $content);
		return ['path' => $written['path'], 'size' => $written['size'], 'sha256' => hash('sha256', $content)];
	}

	/**
	 * The live entries of the book naming one of the project's codes.
	 *
	 * @param list<string> $codes
	 * @return list<array{person:string,event_uid:string,start:string,end:string,hours:?float,series:bool,codes:list<string>,title:string}>
	 */
	private function hours(int $tid, array $codes): array {
		$out = [];
		$since = 0;
		do {
			$page = $this->entries->findChanged($tid, $since, self::PAGE);
			foreach ($page as $e) {
				$since = max($since, $e->getRevision());
				if (!$e->isLive() || $e->getCancelled() === 1 || !DossierRules::matches($e->codeList(), $codes)) {
					continue;
				}
				$o = DossierRules::occurrence((string)$e->getIcs());
				$out[] = ['person' => $e->getUid(), 'event_uid' => $e->getEventUid(), 'title' => $e->getSummary(), 'codes' => $e->codeList()] + $o;
			}
		} while (count($page) === self::PAGE);
		return $out;
	}
}
