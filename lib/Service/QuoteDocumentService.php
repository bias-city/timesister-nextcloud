<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\RecordMapper;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Quote documents (0.22.0, since 0.32.0 `quote_documents: 1`): three kinds
 * of PDF, all rendered or received by the Mac app, filed by the server in
 * `Buchhaltung/<YYYY>/Offerten/` of the tree – `timesister` (the app's
 * version, replaces an earlier one), `sent` (what went to the customer)
 * and `signed` (the customer's copy), the last two added with `_2` …,
 * never overwritten, at most 10 per quote. The record carries
 * `documents[]` and, as before, `document` for the TimeSister PDF.
 */
final class QuoteDocumentService {
	public function __construct(
		private RecordMapper $records,
		private RecordService $recordService,
		private QuoteIssueService $quotes,
		private TeamTree $tree,
		private DocumentFolder $folders,
		private ITimeFactory $time,
	) {
	}

	/** `PUT /quotes/{key}/document` – the TimeSister PDF of an issued quote. @return array{document:array<string,mixed>,record:array<string,mixed>} */
	public function put(Membership $m, string $key, string $content): array {
		[$rec, $d] = $this->quotes->load($m, $key);
		$this->quotes->requireLead($m, $d);
		self::requireIssued($d);
		DocumentRules::requirePdf($content);
		$old = Fields::str(Fields::obj($d, 'document'), 'path');
		$written = $this->write($m, $d, $content, QuoteRules::KIND_TIMESISTER, $old);
		$doc = $this->entry($m, QuoteRules::KIND_TIMESISTER, $written, $content);
		$d->document = (object)['path' => $written['path'], 'sha256' => $doc->sha256, 'size' => $doc->size];
		$d->documents = [...array_values(array_filter(QuoteRules::documents($d), static fn (\stdClass $x): bool => ($x->kind ?? null) !== QuoteRules::KIND_TIMESISTER)), $doc];
		$record = $this->recordService->writeAsServer($m, QuoteRules::KIND, $key, $rec->getVersion(), $d);
		return ['document' => (array)$doc, 'record' => $record];
	}

	/** `POST /quotes/{key}/documents?kind=sent|signed` – added, never overwritten. @return array{record:array<string,mixed>,document:array<string,mixed>,stored:bool} */
	public function add(Membership $m, string $key, mixed $kind, string $content): array {
		[$rec, $d] = $this->quotes->load($m, $key);
		$this->quotes->requireLead($m, $d);
		$kind = QuoteRules::addedKind($kind);
		self::requireIssued($d);
		DocumentRules::requirePdf($content);
		$sha = hash('sha256', $content);
		foreach (QuoteRules::documents($d) as $x) {
			if (($x->kind ?? null) === $kind && ($x->sha256 ?? null) === $sha) {
				return ['record' => $this->recordService->present($rec), 'document' => (array)$x, 'stored' => false];
			}
		}
		if (count(QuoteRules::documents($d)) >= QuoteRules::DOCUMENTS_MAX) {
			throw ApiException::conflict('A quote holds at most 10 documents.')->code(ErrorCode::QUOTE_DOCUMENTS_MAX);
		}
		$written = $this->write($m, $d, $content, $kind, null);
		$doc = $this->entry($m, $kind, $written, $content);
		$d->documents = [...QuoteRules::documents($d), $doc];
		$record = $this->recordService->writeAsServer($m, QuoteRules::KIND, $key, $rec->getVersion(), $d);
		return ['record' => $record, 'document' => (array)$doc, 'stored' => true];
	}

	/** `GET /quotes/{key}/documents` – who reads the quote. @return array{documents:list<\stdClass>} */
	public function list(Membership $m, string $key): array {
		[, $d] = $this->quotes->load($m, $key);
		return ['documents' => QuoteRules::documents($d)];
	}

	/** `GET /quotes/{key}/document?kind=&name=` – the file; `kind` default timesister, `name` a particular one. @return array{content:string,name:string} */
	public function get(Membership $m, string $key, mixed $kind, mixed $name): array {
		[, $d] = $this->quotes->load($m, $key);
		$kind = QuoteRules::readKind($kind);
		$path = null;
		foreach (array_reverse(QuoteRules::documents($d)) as $x) {
			if (($x->kind ?? null) === $kind && ($name === null || $name === '' || ($x->name ?? null) === $name)) {
				$path = Fields::str($x, 'path');
				break;
			}
		}
		if ($path === null && $kind === QuoteRules::KIND_TIMESISTER && ($name === null || $name === '')) {
			$path = Fields::str(Fields::obj($d, 'document'), 'path');
		}
		$owner = $this->tree->ownerOf($m->tenantId);
		$file = $path === null || $owner === null ? null : $this->folders->read($owner, $path);
		if ($path === null || $file === null) {
			throw ApiException::notFound('This quote has no such document.');
		}
		return ['content' => $file->getContent(), 'name' => basename($path)];
	}

	private static function requireIssued(\stdClass $d): void {
		if (!is_string($d->number ?? null) || $d->number === '') {
			throw ApiException::conflict('Quote documents need an issued quote with a number.')->code(ErrorCode::QUOTE_NOT_ISSUED);
		}
	}

	/** @return array{path:string,file_id:int,size:int} */
	private function write(Membership $m, \stdClass $d, string $content, string $kind, ?string $replace): array {
		$place = $this->tree->of($m->tenantId);
		$date = Fields::str($d, 'date') ?? Fields::str($d, 'created_on') ?? gmdate('Y-m-d', $this->time->getTime());
		$number = Fields::text($d, 'number');
		$customer = Fields::str($d, 'customer');
		if ($customer !== null) {
			$c = $this->records->findOne($m->tenantId, 'customer', $customer);
			$customer = Fields::str($c === null || $c->isTombstone() ? null : InvoiceRules::decode($c->getData()), 'name') ?? $customer;
		}
		return $this->folders->store($place['owner'], DocumentTree::quoteFolder($place['root'], $date), QuoteRules::documentName($date, $number, $customer ?? '', $kind), $content, $replace);
	}

	/** @param array{path:string,file_id:int,size:int} $written */
	private function entry(Membership $m, string $kind, array $written, string $content): \stdClass {
		return Json::object(['kind' => $kind, 'name' => basename($written['path']), 'path' => $written['path'], 'sha256' => hash('sha256', $content), 'size' => $written['size'], 'at' => LedgerRules::at($this->time->getTime()), 'by' => $m->uid]);
	}
}
