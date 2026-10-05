<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Controller;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\RecordService;
use OCA\TimeSister\Service\TenantService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Records. Every method first fetches the membership (team and role from
 * the caller's groups); RecordService checks the rights through
 * AccessPolicy.
 */
final class RecordController extends BaseController {
	public function __construct(
		IRequest $request,
		TenantService $tenants,
		IL10N $l,
		private RecordService $records,
	) {
		parent::__construct($request, $tenants, $l);
	}

	/** GET /records?since=<revision> */
	#[ApiRoute(verb: 'GET', url: '/api/v1/records')]
	#[NoAdminRequired]
	public function index(): DataResponse {
		return $this->run(function () {
			$m = $this->tenants->current();
			$since = $this->request->getParam('since', '0');
			if (is_int($since)) {
				$since = (string)$since;
			}
			if (!is_string($since) || !preg_match('/^\d{1,18}$/', $since === '' ? '0' : $since)) {
				throw ApiException::badRequest('“since” must be a revision (an integer ≥ 0).');
			}
			return $this->records->list($m, (int)$since);
		});
	}

	/** GET /records/{kind}/{key} */
	#[ApiRoute(verb: 'GET', url: '/api/v1/records/{kind}/{key}')]
	#[NoAdminRequired]
	public function show(string $kind, string $key): DataResponse {
		return $this->run(fn () => $this->records->get($this->tenants->current(), $kind, $key));
	}

	/** PUT /records/{kind}/{key} */
	#[ApiRoute(verb: 'PUT', url: '/api/v1/records/{kind}/{key}')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 300, period: 60)]
	public function update(string $kind, string $key): DataResponse {
		return $this->run(function () use ($kind, $key) {
			$m = $this->tenants->current();
			return $this->records->put($m, $kind, $key, $this->body(self::MAX_RECORD_BODY));
		});
	}

	/** DELETE /records/{kind}/{key}?version=<n> */
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/records/{kind}/{key}')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 300, period: 60)]
	public function destroy(string $kind, string $key): DataResponse {
		return $this->run(function () use ($kind, $key) {
			$m = $this->tenants->current();
			return $this->records->delete($m, $kind, $key, $this->request->getParam('version'));
		});
	}

	/** POST /records/batch */
	#[ApiRoute(verb: 'POST', url: '/api/v1/records/batch')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 300, period: 60)]
	public function batch(): DataResponse {
		return $this->run(function () {
			$m = $this->tenants->current();
			return $this->records->batch($m, $this->body(self::MAX_BATCH_BODY));
		});
	}

	/** GET /records/{kind}/{key}/history */
	#[ApiRoute(verb: 'GET', url: '/api/v1/records/{kind}/{key}/history')]
	#[NoAdminRequired]
	public function history(string $kind, string $key): DataResponse {
		return $this->run(fn () => $this->records->history($this->tenants->current(), $kind, $key));
	}

	/** POST /records/{kind}/{key}/restore */
	#[ApiRoute(verb: 'POST', url: '/api/v1/records/{kind}/{key}/restore')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 300, period: 60)]
	public function restore(string $kind, string $key): DataResponse {
		return $this->run(function () use ($kind, $key) {
			$m = $this->tenants->current();
			return $this->records->restore($m, $kind, $key, $this->body(self::MAX_RECORD_BODY));
		});
	}
}
