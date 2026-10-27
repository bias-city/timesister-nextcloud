<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Controller;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\InvoiceTemplateRules;
use OCA\TimeSister\Service\InvoiceTemplateService;
use OCA\TimeSister\Service\Json;
use OCA\TimeSister\Service\TenantService;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/** The team's invoice template (0.16.0): one Word file, Team Admins only. */
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
		return $this->run(fn () => $this->templates->info($this->tenants->current()));
	}

	/** GET /templates/invoice – the same plus the file as `base64`. */
	#[ApiRoute(verb: 'GET', url: '/api/v1/templates/invoice')]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function show(): DataResponse {
		return $this->run(fn () => $this->templates->get($this->tenants->current()));
	}

	/** PUT /templates/invoice {"name": "Invoice.docx", "base64": "…"} – stores it in place of the old one. */
	#[ApiRoute(verb: 'PUT', url: '/api/v1/templates/invoice')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function update(): DataResponse {
		return $this->run(function () {
			$m = $this->tenants->current();
			InvoiceTemplateService::allowed($m);
			$raw = Json::readInput(InvoiceTemplateRules::MAX_BODY);
			if (strlen($raw) > InvoiceTemplateRules::MAX_BODY) {
				// The body is the file in Base64: too large means the file is.
				throw ApiException::tooLarge('A template may be at most 10 MB.');
			}
			return $this->templates->put($m, Json::body($raw, InvoiceTemplateRules::MAX_BODY));
		});
	}

	/** DELETE /templates/invoice – `{ "deleted": true }`, false when none was stored. */
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/templates/invoice')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function destroy(): DataResponse {
		return $this->run(fn () => $this->templates->delete($this->tenants->current()));
	}
}
