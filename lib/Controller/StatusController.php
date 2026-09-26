<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Controller;

use OCA\TimeSister\Service\StatusService;
use OCA\TimeSister\Service\TenantService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/** Lebenszeichen: melden jedes Mitglied für sich, lesen Verwaltung und Admin. */
final class StatusController extends BaseController {
	private const FIELDS = ['app_version', 'last_sync', 'last_backup', 'calendar_url', 'calendar_shared'];

	public function __construct(
		IRequest $request,
		TenantService $tenants,
		private StatusService $status,
	) {
		parent::__construct($request, $tenants);
	}

	/** POST /status */
	#[ApiRoute(verb: 'POST', url: '/api/v1/status')]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 300, period: 60)]
	public function create(): DataResponse {
		return $this->run(function () {
			$m = $this->tenants->current();
			$params = $this->request->getParams();
			$in = array_intersect_key($params, array_flip(self::FIELDS));
			return $this->status->report($m, $in);
		});
	}

	/** GET /status */
	#[ApiRoute(verb: 'GET', url: '/api/v1/status')]
	#[NoAdminRequired]
	public function index(): DataResponse {
		return $this->run(fn () => $this->status->list($this->tenants->current()));
	}
}
