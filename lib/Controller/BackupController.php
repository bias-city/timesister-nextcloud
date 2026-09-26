<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Controller;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\BackupService;
use OCA\TimeSister\Service\TenantService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/** Kalendersicherungen: eigene für alle, fremde nur für Verwaltung und Admin. */
final class BackupController extends BaseController {
	public function __construct(
		IRequest $request,
		TenantService $tenants,
		private BackupService $backups,
	) {
		parent::__construct($request, $tenants);
	}

	/** POST /backups – die eigene Sicherung eines Tages. */
	#[ApiRoute(verb: 'POST', url: '/api/v1/backups')]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function create(): DataResponse {
		return $this->run(function () {
			$m = $this->tenants->current();
			return $this->backups->upload(
				$m,
				$this->request->getParam('taken_on'),
				$this->request->getParam('ics_base64'),
			);
		});
	}

	/** GET /backups?uid=<uid> */
	#[ApiRoute(verb: 'GET', url: '/api/v1/backups')]
	#[NoAdminRequired]
	public function index(): DataResponse {
		return $this->run(function () {
			$m = $this->tenants->current();
			$uid = $this->request->getParam('uid');
			if ($uid !== null && !is_string($uid)) {
				throw ApiException::badRequest('„uid“ ist ungültig.');
			}
			return $this->backups->list($m, $uid);
		});
	}

	/** GET /backups/{id} */
	#[ApiRoute(verb: 'GET', url: '/api/v1/backups/{id}', requirements: ['id' => '\d+'])]
	#[NoAdminRequired]
	public function show(int $id): DataResponse {
		return $this->run(fn () => $this->backups->get($this->tenants->current(), $id));
	}
}
