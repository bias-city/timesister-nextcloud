<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Controller;

use OCA\TimeSister\AppInfo\Application;
use OCA\TimeSister\Service\CalendarShareService;
use OCA\TimeSister\Service\RecordService;
use OCA\TimeSister\Service\Role;
use OCA\TimeSister\Service\TenantService;
use OCA\TimeSister\Service\Time;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;

final class MeController extends BaseController {
	public function __construct(
		IRequest $request,
		TenantService $tenants,
		private RecordService $records,
		private CalendarShareService $calendarShare,
		private ITimeFactory $time,
	) {
		parent::__construct($request, $tenants);
	}

	/** GET /me – Team, Rolle, Teamgruppe, Admins und Leitungen, eigene Person, Revision. */
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
				// Für die Freigaben der Mac-App: an wen der eigene Zeitkalender geht.
				'admins' => $this->tenants->withRole($m->tenantId, Role::ADMIN),
				'leads' => $this->tenants->withRole($m->tenantId, Role::LEAD),
				'calendar_share' => $this->calendarShare->enabled($m->uid),
				'person_key' => $this->records->personKey($m),
				'revision' => $t->getRevision(),
				'server_time' => Time::iso($this->time->getTime()),
			];
		});
	}

	/** GET /me/calendar-share – Schalter „Zeitkalender für das Team freigeben“. */
	#[ApiRoute(verb: 'GET', url: '/api/v1/me/calendar-share')]
	#[NoAdminRequired]
	public function calendarShare(): DataResponse {
		return $this->run(fn () => $this->calendarShare->get($this->tenants->current()->uid));
	}

	/** PUT /me/calendar-share – nur das eigene Konto. */
	#[ApiRoute(verb: 'PUT', url: '/api/v1/me/calendar-share')]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 300, period: 60)]
	public function setCalendarShare(): DataResponse {
		return $this->run(function () {
			$m = $this->tenants->current();
			$in = array_intersect_key($this->request->getParams(), array_flip(['enabled', 'uid']));
			return $this->calendarShare->set($m->uid, $in);
		});
	}
}
