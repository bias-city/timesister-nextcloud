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
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Datensätze. Jede Methode holt zuerst die Mitgliedschaft (Team und Rolle
 * aus den Gruppen des Aufrufers); die Rechte prüft RecordService über die
 * AccessPolicy.
 */
final class RecordController extends BaseController {
	public function __construct(
		IRequest $request,
		TenantService $tenants,
		private RecordService $records,
	) {
		parent::__construct($request, $tenants);
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
				throw ApiException::badRequest('„since“ muss eine Revision (ganze Zahl ≥ 0) sein.');
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
	#[NoAdminRequired]
	#[UserRateLimit(limit: 300, period: 60)]
	public function restore(string $kind, string $key): DataResponse {
		return $this->run(function () use ($kind, $key) {
			$m = $this->tenants->current();
			return $this->records->restore($m, $kind, $key, $this->body(self::MAX_RECORD_BODY));
		});
	}
}
