<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Controller;

use OCA\TimeSister\AppInfo\Application;
use OCA\TimeSister\Service\AbsenceService;
use OCA\TimeSister\Service\EntryFetcher;
use OCA\TimeSister\Service\EntryService;
use OCA\TimeSister\Service\RecordService;
use OCA\TimeSister\Service\TenantService;
use OCA\TimeSister\Service\Time;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
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
		private ITimeFactory $time,
		private AbsenceService $absences,
		private EntryService $entries,
		private EntryFetcher $fetcher,
	) {
		parent::__construct($request, $tenants, $l);
	}

	/** GET /me – team, role, team group, settings, own person, revision, time calendar. */
	#[ApiRoute(verb: 'GET', url: '/api/v1/me')]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
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
				// Since 0.11.0: the active projects that make the caller a Lead.
				'lead_of' => $this->tenants->leadOf($m->tenantId, $m->uid),
				'groups' => $this->tenants->groupsOf($m->tenantId),
				'settings' => TenantService::settingsOf($t),
				'person_key' => $this->records->personKey($m),
				'revision' => $t->getRevision(),
				// Since 0.17.0: the named time calendar, the entries' counter and the effective cutoff.
				'time_calendar' => $this->entries->mine($m),
				'entries_revision' => $t->getEntriesRevision(),
				'entries_since' => EntryService::since($t),
				'server_time' => Time::iso($this->time->getTime()),
			];
		});
	}

	/** PUT /me/time-calendar – names the own time calendar (0.17.0) and reads a small one at once. */
	#[ApiRoute(verb: 'PUT', url: '/api/v1/me/time-calendar')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function setTimeCalendar(): DataResponse {
		return $this->run(fn () => $this->fetcher->name($this->tenants->current(), $this->body(self::MAX_RECORD_BODY)));
	}

	/** DELETE /me/time-calendar – un-names it; the entries become tombstones, kept ones stay. */
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/me/time-calendar')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function unsetTimeCalendar(): DataResponse {
		return $this->run(fn () => $this->entries->unname($this->tenants->current()));
	}

	/**
	 * PUT /me/absences – the complete state of the own absences (this and
	 * next year) for the shared vacation calendar; replaces what was there.
	 */
	#[ApiRoute(verb: 'PUT', url: '/api/v1/me/absences')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function setAbsences(): DataResponse {
		return $this->run(fn () => $this->absences->replace($this->tenants->current(), $this->body(self::MAX_RECORD_BODY)));
	}
}
