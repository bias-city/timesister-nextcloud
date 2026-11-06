<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Controller;

use OCA\TimeSister\Service\BankService;
use OCA\TimeSister\Service\TenantService;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Bank entries (0.40.0): the upsert per entry key into `bank/<account>.<year>`.
 * Rules and the month's plan are records (`/records/bank/…`). Team Admins only.
 */
final class BankController extends BaseController {
	/** 2000 entries of at most about 1 KB each. */
	private const MAX_BODY = 2 * 1024 * 1024;

	public function __construct(
		IRequest $request,
		TenantService $tenants,
		IL10N $l,
		private BankService $bank,
	) {
		parent::__construct($request, $tenants, $l);
	}

	/** POST /bank/entries `{ account, entries }` → `{ revision, records, saved, unchanged, kept }`. */
	#[ApiRoute(verb: 'POST', url: '/api/v1/bank/entries')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function entries(): DataResponse {
		return $this->run(fn () => $this->bank->entries($this->tenants->current(), $this->body(self::MAX_BODY)));
	}
}
