<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Controller;

use OCA\TimeSister\Service\AccessPolicy;
use OCA\TimeSister\Service\EntryService;
use OCA\TimeSister\Service\MemberService;
use OCA\TimeSister\Service\TenantService;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

final class TeamController extends BaseController {
	public function __construct(
		IRequest $request,
		TenantService $tenants,
		IL10N $l,
		private AccessPolicy $policy,
		private MemberService $memberService,
		private EntryService $entries,
	) {
		parent::__construct($request, $tenants, $l);
	}

	/** GET /team – name, team group, members with role, including those who left. */
	#[ApiRoute(verb: 'GET', url: '/api/v1/team')]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	public function show(): DataResponse {
		return $this->run(function () {
			$m = $this->tenants->current();
			$this->policy->requireTeamRead($m);
			$team = $this->tenants->presentTeam($this->tenants->tenant($m->tenantId));
			$team['members'] = $this->tenants->presentMembers($m->tenantId);
			return $team;
		});
	}

	/** PUT /team/settings – Team Admins; only `entries_since` for now (0.17.0). */
	#[ApiRoute(verb: 'PUT', url: '/api/v1/team/settings')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function updateSettings(): DataResponse {
		return $this->run(fn () => $this->entries->setSince($this->tenants->current(), $this->body(self::MAX_RECORD_BODY)));
	}

	/** PUT /team/members/{uid} – role and leaving date; Team Admins. */
	#[ApiRoute(verb: 'PUT', url: '/api/v1/team/members/{uid}')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
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
