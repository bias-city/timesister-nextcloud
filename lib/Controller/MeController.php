<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Controller;

use OCA\TimeSister\AppInfo\Application;
use OCA\TimeSister\Service\RecordService;
use OCA\TimeSister\Service\TenantService;
use OCA\TimeSister\Service\Time;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;

final class MeController extends BaseController {
	public function __construct(
		IRequest $request,
		TenantService $tenants,
		private RecordService $records,
		private ITimeFactory $time,
	) {
		parent::__construct($request, $tenants);
	}

	/** GET /me – Team, Rolle, Gruppen, eigene Person, Revision. */
	#[ApiRoute(verb: 'GET', url: '/api/v1/me')]
	#[NoAdminRequired]
	public function show(): DataResponse {
		return $this->run(function () {
			$m = $this->tenants->current();
			$t = $this->tenants->tenant($m->tenantId);
			return [
				'api' => Application::API_VERSION,
				'uid' => $m->uid,
				'display_name' => $this->tenants->displayName($m->uid),
				'team' => ['id' => $t->getId(), 'name' => $t->getName(), 'slug' => $t->getSlug()],
				'role' => $m->role,
				'groups' => $this->tenants->groupsOf($m->tenantId),
				'settings' => TenantService::settingsOf($t),
				'person_key' => $this->records->personKey($m),
				'revision' => $t->getRevision(),
				'server_time' => Time::iso($this->time->getTime()),
			];
		});
	}
}
