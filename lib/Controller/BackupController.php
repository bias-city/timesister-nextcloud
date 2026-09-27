<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Controller;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\BackupService;
use OCA\TimeSister\Service\ConsentService;
use OCA\TimeSister\Service\OwnCopyService;
use OCA\TimeSister\Service\TenantService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Calendar backups: own backups for everyone, others' only for Team
 * Admins. Backups are only made with the person's consent.
 */
final class BackupController extends BaseController {
	public function __construct(
		IRequest $request,
		TenantService $tenants,
		IL10N $l,
		private BackupService $backups,
		private ConsentService $consent,
		private OwnCopyService $ownCopy,
	) {
		parent::__construct($request, $tenants, $l);
	}

	/** POST /backups – the own backup of one day. */
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
				throw ApiException::invalidField('uid', true);
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

	/** POST /backups/now – back up to the server immediately. */
	#[ApiRoute(verb: 'POST', url: '/api/v1/backups/now')]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 60)]
	public function now(): DataResponse {
		return $this->run(function () {
			return $this->backups->now($this->tenants->current(), $this->request->getParam('uid'));
		});
	}

	/** GET /backups/consent – the own consent. */
	#[ApiRoute(verb: 'GET', url: '/api/v1/backups/consent')]
	#[NoAdminRequired]
	public function consent(): DataResponse {
		return $this->run(fn () => $this->consent->get($this->tenants->current()));
	}

	/** PUT /backups/consent – set or withdraw the own consent. */
	#[ApiRoute(verb: 'PUT', url: '/api/v1/backups/consent')]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 300, period: 60)]
	public function setConsent(): DataResponse {
		return $this->run(function () {
			$m = $this->tenants->current();
			$in = array_intersect_key($this->request->getParams(), array_flip(['consent', 'notice', 'uid']));
			return $this->consent->set($m, $in);
		});
	}

	/** GET /backups/own-copy – copy in the own folder, own account. */
	#[ApiRoute(verb: 'GET', url: '/api/v1/backups/own-copy')]
	#[NoAdminRequired]
	public function ownCopy(): DataResponse {
		return $this->run(fn () => $this->ownCopy->get($this->tenants->current()->uid));
	}

	/** PUT /backups/own-copy – turn on or off, own account. */
	#[ApiRoute(verb: 'PUT', url: '/api/v1/backups/own-copy')]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 300, period: 60)]
	public function setOwnCopy(): DataResponse {
		return $this->run(function () {
			$m = $this->tenants->current();
			$in = array_intersect_key($this->request->getParams(), array_flip(['enabled', 'uid']));
			return $this->ownCopy->set($m->uid, $in);
		});
	}
}
