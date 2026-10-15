<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Controller;

use OCA\TimeSister\Service\InvoiceService;
use OCA\TimeSister\Service\TenantService;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/** Invoice numbers (0.16.0): the team's counter, Team Admins only. */
final class InvoiceController extends BaseController {
	/** The body holds only `year`. */
	private const MAX_BODY = 4096;

	public function __construct(
		IRequest $request,
		TenantService $tenants,
		IL10N $l,
		private InvoiceService $invoices,
	) {
		parent::__construct($request, $tenants, $l);
	}

	/** GET /invoices/next-number?year=2026 – the number the next POST would give. */
	#[ApiRoute(verb: 'GET', url: '/api/v1/invoices/next-number')]
	#[NoAdminRequired]
	public function preview(): DataResponse {
		return $this->run(fn () => $this->invoices->nextNumber($this->tenants->current(), $this->request->getParam('year'), false));
	}

	/** POST /invoices/next-number {"year": 2026} – takes the next number. */
	#[ApiRoute(verb: 'POST', url: '/api/v1/invoices/next-number')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	public function take(): DataResponse {
		return $this->run(function () {
			$m = $this->tenants->current();
			$body = $this->body(self::MAX_BODY);
			return $this->invoices->nextNumber($m, $body->year ?? null, true);
		});
	}
}
