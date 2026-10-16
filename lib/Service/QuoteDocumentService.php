<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\Record;
use OCA\TimeSister\Db\RecordMapper;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Quote documents (0.22.0): the Mac app's PDF of a quote goes through the
 * server into `Buchhaltung/<YYYY>/Offerten/` of the tree; the record
 * carries `document: { path, sha256, size }`.
 */
final class QuoteDocumentService {
	public function __construct(
		private RecordMapper $records,
		private RecordService $recordService,
		private RecordRights $rights,
		private TeamTree $tree,
		private DocumentFolder $folders,
		private ITimeFactory $time,
	) {
	}

	/** @return array{0:Record,1:\stdClass} */
	private function load(Membership $m, string $key): array {
		RecordValidator::checkAddress(QuoteRules::KIND, $key);
		$r = $this->records->findOne($m->tenantId, QuoteRules::KIND, $key);
		if (!$this->rights->canRead($m, QuoteRules::KIND, $key, [], $this->recordService->ownKeys($m), $r)) {
			throw ApiException::forbidden('Only Team Admins and the quote’s Leads read and write its document.');
		}
		$d = $r === null || $r->isTombstone() ? null : InvoiceRules::decode($r->getData());
		if ($r === null || $d === null) {
			throw ApiException::notFound('This quote does not exist.');
		}
		return [$r, $d];
	}

	/** `PUT /quotes/{key}/document` – a PDF of at most 20 MB. @return array{path:string,sha256:string,size:int,version:int} */
	public function put(Membership $m, string $key, string $content): array {
		[$rec, $d] = $this->load($m, $key);
		if (strlen($content) > ReceiptRules::FILE_MAX) {
			throw ApiException::tooLarge('A file may be at most 20 MB.');
		}
		if (ReceiptRules::sniff($content) !== 'application/pdf') {
			throw ApiException::invalid('The document must be a PDF.');
		}
		$place = $this->tree->of($m->tenantId);
		$date = Fields::str($d, 'date') ?? Fields::str($d, 'created_on') ?? gmdate('Y-m-d', $this->time->getTime());
		$number = Fields::text($d, 'number');
		$number = $number === '' ? $key : $number;
		$customer = Fields::str($d, 'customer');
		if ($customer !== null) {
			$c = $this->records->findOne($m->tenantId, 'customer', $customer);
			$customer = Fields::str($c === null || $c->isTombstone() ? null : InvoiceRules::decode($c->getData()), 'name') ?? $customer;
		}
		$old = $d->document->path ?? null;
		$written = $this->folders->store($place['owner'], DocumentTree::quoteFolder($place['root'], $date), DocumentTree::quoteName($date, $number, $customer ?? ''), $content, is_string($old) ? $old : null);
		$d->document = (object)['path' => $written['path'], 'sha256' => hash('sha256', $content), 'size' => strlen($content)];
		$record = $this->recordService->writeAsServer($m, QuoteRules::KIND, $key, $rec->getVersion(), $d);
		return (array)$d->document + ['version' => (int)$record['version']];
	}

	/** `GET /quotes/{key}/document`. @return array{content:string,name:string} */
	public function get(Membership $m, string $key): array {
		[, $d] = $this->load($m, $key);
		$path = Fields::str(Fields::obj($d, 'document'), 'path');
		$owner = $this->tree->ownerOf($m->tenantId);
		$file = $path === null || $owner === null ? null : $this->folders->read($owner, $path);
		if ($path === null || $file === null) {
			throw ApiException::notFound('This quote has no document.');
		}
		return ['content' => $file->getContent(), 'name' => basename($path)];
	}
}
