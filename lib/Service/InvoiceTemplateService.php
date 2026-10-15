<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ITempManager;

/**
 * The team's invoice template (0.16.0): one Word file per team, the
 * source for every Mac of the team. Team Admins only – reading, too.
 */
final class InvoiceTemplateService {
	public function __construct(
		private InvoiceTemplateStore $store,
		private ITempManager $temp,
		private ITimeFactory $time,
	) {
	}

	/** @return array{name:string,size:int,sha256:string,modified_at:string,modified_by:string} */
	public function info(Membership $m): array {
		self::allowed($m);
		return $this->store->info($m->tenantId) ?? throw self::none();
	}

	/** @return array{name:string,size:int,sha256:string,modified_at:string,modified_by:string,base64:string} */
	public function get(Membership $m): array {
		self::allowed($m);
		$t = $this->store->get($m->tenantId) ?? throw self::none();
		return $t['meta'] + ['base64' => base64_encode($t['content'])];
	}

	/**
	 * `{ name, base64 }`: checked, then stored in place of the old one.
	 *
	 * @return array{name:string,size:int,sha256:string,modified_at:string,modified_by:string}
	 */
	public function put(Membership $m, \stdClass $body): array {
		self::allowed($m);
		$name = InvoiceTemplateRules::checkName($body->name ?? null);
		$bytes = InvoiceTemplateRules::decode($body->base64 ?? null);
		$this->check($bytes);
		$meta = InvoiceTemplateRules::meta($name, $bytes, $this->time->getTime(), $m->uid);
		$this->store->put($m->tenantId, $meta, $bytes);
		return $meta;
	}

	/** @return array{deleted:bool} false when none was stored */
	public function delete(Membership $m): array {
		self::allowed($m);
		return ['deleted' => $this->store->delete($m->tenantId)];
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

	/** Team Admins only; the controller asks before it reads a large body. */
	public static function allowed(Membership $m): void {
		if (!$m->manages()) {
			throw ApiException::forbidden('Only Team Admins manage the invoice template.');
		}
	}

	private static function none(): ApiException {
		return ApiException::notFound('The team has no invoice template.');
	}
}
