<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ITempManager;

/**
 * The team's Word templates: the invoice template (0.16.0) and the quote
 * template (0.34.0), one file each per team, the source for every Mac of
 * the team. Since 0.34.0 every member reads them (to fill documents);
 * only Team Admins replace or remove them.
 */
final class InvoiceTemplateService {
	public function __construct(
		private InvoiceTemplateStore $store,
		private ITempManager $temp,
		private ITimeFactory $time,
	) {
	}

	/** @return array{name:string,size:int,sha256:string,modified_at:string,modified_by:string} */
	public function info(Membership $m, string $kind = InvoiceTemplateStore::INVOICE): array {
		return $this->store->info($m->tenantId, $kind) ?? throw self::none($kind);
	}

	/** @return array{name:string,size:int,sha256:string,modified_at:string,modified_by:string,base64:string} */
	public function get(Membership $m, string $kind = InvoiceTemplateStore::INVOICE): array {
		$t = $this->store->get($m->tenantId, $kind) ?? throw self::none($kind);
		return $t['meta'] + ['base64' => base64_encode($t['content'])];
	}

	/**
	 * `{ name, base64 }`: checked, then stored in place of the old one.
	 *
	 * @return array{name:string,size:int,sha256:string,modified_at:string,modified_by:string}
	 */
	public function put(Membership $m, \stdClass $body, string $kind = InvoiceTemplateStore::INVOICE): array {
		self::allowed($m, $kind);
		$name = InvoiceTemplateRules::checkName($body->name ?? null);
		$bytes = InvoiceTemplateRules::decode($body->base64 ?? null);
		$this->check($bytes);
		$meta = InvoiceTemplateRules::meta($name, $bytes, $this->time->getTime(), $m->uid);
		$this->store->put($m->tenantId, $meta, $bytes, $kind);
		return $meta;
	}

	/** @return array{deleted:bool} false when none was stored */
	public function delete(Membership $m, string $kind = InvoiceTemplateStore::INVOICE): array {
		self::allowed($m, $kind);
		return ['deleted' => $this->store->delete($m->tenantId, $kind)];
	}

	/** A Word document; ZipArchive needs the bytes as a file. */
	public function check(string $bytes): void {
		if (!InvoiceTemplateRules::looksLikeZip($bytes)) {
			throw ApiException::invalid('The template must be a Word document (.docx).');
		}
		$path = $this->temp->getTemporaryFile('.docx');
		if ($path === false || file_put_contents($path, $bytes) !== strlen($bytes)) {
			throw new \RuntimeException('No temporary file for the template.');
		}
		try {
			InvoiceTemplateRules::checkDocx($bytes, $path);
		} finally {
			@unlink($path);
		}
	}

	/** Writing is for Team Admins only; the controller asks before it reads a large body. */
	public static function allowed(Membership $m, string $kind = InvoiceTemplateStore::INVOICE): void {
		if (!$m->manages()) {
			throw $kind === InvoiceTemplateStore::QUOTE
				? ApiException::forbidden('Only Team Admins manage the quote template.')
				: ApiException::forbidden('Only Team Admins manage the invoice template.');
		}
	}

	private static function none(string $kind): ApiException {
		return $kind === InvoiceTemplateStore::QUOTE
			? ApiException::notFound('The team has no quote template.')
			: ApiException::notFound('The team has no invoice template.');
	}
}
