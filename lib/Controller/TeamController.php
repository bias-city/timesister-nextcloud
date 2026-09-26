<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Controller;

use OCA\TimeSister\Service\AccessPolicy;
use OCA\TimeSister\Service\MemberService;
use OCA\TimeSister\Service\TenantService;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

final class TeamController extends BaseController {
	public function __construct(
		IRequest $request,
		TenantService $tenants,
		private AccessPolicy $policy,
		private MemberService $memberService,
	) {
		parent::__construct($request, $tenants);
	}

	/** GET /team – Name, Teamgruppe, Mitglieder mit Rolle, auch Ausgetretene. */
	#[ApiRoute(verb: 'GET', url: '/api/v1/team')]
	#[NoAdminRequired]
	public function show(): DataResponse {
		return $this->run(function () {
			$m = $this->tenants->current();
			$this->policy->requireTeamRead($m);
			$team = $this->tenants->presentTeam($this->tenants->tenant($m->tenantId));
			$team['members'] = $this->tenants->presentMembers($m->tenantId);
			return $team;
		});
	}

	/** PUT /team/members/{uid} – App-Rolle und Austritt; Verwaltung und Admin. */
	#[ApiRoute(verb: 'PUT', url: '/api/v1/team/members/{uid}')]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 300, period: 60)]
	public function updateMember(string $uid): DataResponse {
		return $this->run(function () use ($uid) {
			$m = $this->tenants->current();
			$in = array_intersect_key($this->request->getParams(), array_flip(['role', 'left']));
			return $this->memberService->update($m->tenantId, $m->uid, $m->role, $uid, $in);
		});
	}
}
