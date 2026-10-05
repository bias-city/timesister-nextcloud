<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Controller;

use OCA\TimeSister\AppInfo\Application;
use OCA\TimeSister\Service\AbsenceService;
use OCA\TimeSister\Service\AccessService;
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
use OCP\IL10N;
use OCP\IRequest;

final class MeController extends BaseController {
	public function __construct(
		IRequest $request,
		TenantService $tenants,
		IL10N $l,
		private RecordService $records,
		private CalendarShareService $calendarShare,
		private AccessService $access,
		private ITimeFactory $time,
		private AbsenceService $absences,
	) {
		parent::__construct($request, $tenants, $l);
	}

	/** GET /me – team, role, team group, share targets, own person, revision. */
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
				// Clients before 0.5.0 shared with admins and leads.
				'admins' => $this->tenants->withRole($m->tenantId, Role::ADMIN),
				'leads' => $this->tenants->withRole($m->tenantId, Role::LEAD),
				'calendar_share' => $this->calendarShare->enabled($m->uid),
				// Since 0.5.0: whom the own time calendar goes to, from the shares matrix.
				'share_targets' => $this->access->shareTargets($m),
				'may_override' => $this->access->mayOverride($m),
				'person_key' => $this->records->personKey($m),
				'revision' => $t->getRevision(),
				'server_time' => Time::iso($this->time->getTime()),
			];
		});
	}

	/** GET /me/calendar-share – the "share time calendar with the team" switch. */
	#[ApiRoute(verb: 'GET', url: '/api/v1/me/calendar-share')]
	#[NoAdminRequired]
	public function calendarShare(): DataResponse {
		return $this->run(fn () => $this->calendarShare->get($this->tenants->current()->uid));
	}

	/** PUT /me/calendar-share – own account only. */
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

	/**
	 * PUT /me/absences – the complete state of the own absences (this and
	 * next year) for the shared vacation calendar; replaces what was there.
	 */
	#[ApiRoute(verb: 'PUT', url: '/api/v1/me/absences')]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function setAbsences(): DataResponse {
		return $this->run(fn () => $this->absences->replace($this->tenants->current(), $this->body(self::MAX_RECORD_BODY)));
	}
}
