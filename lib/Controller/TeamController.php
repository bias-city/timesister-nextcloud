<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Controller;

use OCA\TimeSister\Service\AccessPolicy;
use OCA\TimeSister\Service\MembershipResolver;
use OCA\TimeSister\Service\TenantService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

final class TeamController extends BaseController {
	public function __construct(
		IRequest $request,
		TenantService $tenants,
		private AccessPolicy $policy,
	) {
		parent::__construct($request, $tenants);
	}

	/** GET /team – Name, Rollen-Gruppen, Mitglieder unter ihrer stärksten Rolle. */
	#[ApiRoute(verb: 'GET', url: '/api/v1/team')]
	#[NoAdminRequired]
	public function show(): DataResponse {
		return $this->run(function () {
			$m = $this->tenants->current();
			$this->policy->requireTeamRead($m);
			$team = $this->tenants->presentTeam($this->tenants->tenant($m->tenantId));
			$team['members'] = MembershipResolver::membersByRole($this->tenants->memberRoles($m->tenantId));
			return $team;
		});
	}
}
