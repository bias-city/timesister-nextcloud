<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Controller;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\InvoiceTemplateRules;
use OCA\TimeSister\Service\InvoiceTemplateService;
use OCA\TimeSister\Service\InvoiceTemplateStore;
use OCA\TimeSister\Service\Json;
use OCA\TimeSister\Service\TenantService;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * The team's Word templates: invoice (0.16.0) and quote (0.34.0), one file
 * each. Every member reads them, Team Admins replace or remove them.
 */
final class TemplateController extends BaseController {
	public function __construct(
		IRequest $request,
		TenantService $tenants,
		IL10N $l,
		private InvoiceTemplateService $templates,
	) {
		parent::__construct($request, $tenants, $l);
	}

	/** GET /templates/invoice/info – name, size, sha256, modified_at, modified_by; 404 when none. */
	#[ApiRoute(verb: 'GET', url: '/api/v1/templates/invoice/info')]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	public function info(): DataResponse {
		return $this->infoOf(InvoiceTemplateStore::INVOICE);
	}

	/** GET /templates/invoice – the same plus the file as `base64`. */
	#[ApiRoute(verb: 'GET', url: '/api/v1/templates/invoice')]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function show(): DataResponse {
		return $this->showOf(InvoiceTemplateStore::INVOICE);
	}

	/** PUT /templates/invoice {"name": "Invoice.docx", "base64": "…"} – stores it in place of the old one. */
	#[ApiRoute(verb: 'PUT', url: '/api/v1/templates/invoice')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function update(): DataResponse {
		return $this->updateOf(InvoiceTemplateStore::INVOICE);
	}

	/** DELETE /templates/invoice – `{ "deleted": true }`, false when none was stored. */
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/templates/invoice')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function destroy(): DataResponse {
		return $this->destroyOf(InvoiceTemplateStore::INVOICE);
	}

	/** GET /templates/quote/info (0.34.0) – like the invoice template. */
	#[ApiRoute(verb: 'GET', url: '/api/v1/templates/quote/info')]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	public function quoteInfo(): DataResponse {
		return $this->infoOf(InvoiceTemplateStore::QUOTE);
	}

	/** GET /templates/quote – the quote template with `base64`. */
	#[ApiRoute(verb: 'GET', url: '/api/v1/templates/quote')]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function quoteShow(): DataResponse {
		return $this->showOf(InvoiceTemplateStore::QUOTE);
	}

	/** PUT /templates/quote {"name": "Quote.docx", "base64": "…"} – Team Admins. */
	#[ApiRoute(verb: 'PUT', url: '/api/v1/templates/quote')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function quoteUpdate(): DataResponse {
		return $this->updateOf(InvoiceTemplateStore::QUOTE);
	}

	/** DELETE /templates/quote – back to the template shipped with the app. */
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/templates/quote')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function quoteDestroy(): DataResponse {
		return $this->destroyOf(InvoiceTemplateStore::QUOTE);
	}

	private function infoOf(string $kind): DataResponse {
		return $this->run(fn () => $this->templates->info($this->tenants->current(), $kind));
	}

	private function showOf(string $kind): DataResponse {
		return $this->run(fn () => $this->templates->get($this->tenants->current(), $kind));
	}

	private function updateOf(string $kind): DataResponse {
		return $this->run(function () use ($kind) {
			$m = $this->tenants->current();
			InvoiceTemplateService::allowed($m, $kind);
			$raw = Json::readInput(InvoiceTemplateRules::MAX_BODY);
			if (strlen($raw) > InvoiceTemplateRules::MAX_BODY) {
				// The body is the file in Base64: too large means the file is.
				throw ApiException::tooLarge('A template may be at most 10 MB.');
			}
			return $this->templates->put($m, Json::body($raw, InvoiceTemplateRules::MAX_BODY), $kind);
		});
	}

	private function destroyOf(string $kind): DataResponse {
		return $this->run(fn () => $this->templates->delete($this->tenants->current(), $kind));
	}
}
