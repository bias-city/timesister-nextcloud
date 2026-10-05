<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Controller;

use OCA\TimeSister\Service\AccessRules;
use OCA\TimeSister\Service\AccessService;
use OCA\TimeSister\Service\TenantService;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/** The shares matrix: who sees whose time calendar (since 0.5.0). */
final class AccessController extends BaseController {
	public function __construct(
		IRequest $request,
		TenantService $tenants,
		IL10N $l,
		private AccessService $access,
	) {
		parent::__construct($request, $tenants, $l);
	}

	/** GET /team/access – Team Admins the whole team, everyone else their column and row. */
	#[ApiRoute(verb: 'GET', url: '/api/v1/team/access')]
	#[NoAdminRequired]
	public function index(): DataResponse {
		return $this->run(fn () => $this->access->matrix($this->tenants->current()));
	}

	/** PUT /team/access – `{changes: [{viewer, owner, level}]}`, all or none. */
	#[ApiRoute(verb: 'PUT', url: '/api/v1/team/access')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 300, period: 60)]
	public function update(): DataResponse {
		return $this->run(function () {
			$m = $this->tenants->current();
			return $this->access->set($m, AccessRules::validateChanges($this->request->getParam('changes')));
		});
	}

	/** PUT /team/access/{viewer}/{owner} – `{level: none|view|edit|default}`. */
	#[ApiRoute(verb: 'PUT', url: '/api/v1/team/access/{viewer}/{owner}')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 300, period: 60)]
	public function updateField(string $viewer, string $owner): DataResponse {
		return $this->run(function () use ($viewer, $owner) {
			$m = $this->tenants->current();
			$level = AccessRules::validateLevel($this->request->getParam('level'));
			return $this->access->set($m, [['viewer' => $viewer, 'owner' => $owner, 'level' => $level]]);
		});
	}

	/** POST /team/access/remind – a notification to everyone with pending shares. */
	#[ApiRoute(verb: 'POST', url: '/api/v1/team/access/remind')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function remind(): DataResponse {
		return $this->run(fn () => $this->access->remind($this->tenants->current()));
	}
}
