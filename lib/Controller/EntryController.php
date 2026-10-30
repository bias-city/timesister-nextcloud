<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Controller;

use OCA\TimeSister\Service\CorrectionService;
use OCA\TimeSister\Service\EntryService;
use OCA\TimeSister\Service\TenantService;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/** The time entries (0.17.0): the delta for the clients, the sources' state, a re-read. */
final class EntryController extends BaseController {
	public function __construct(
		IRequest $request,
		TenantService $tenants,
		IL10N $l,
		private EntryService $entries,
		private CorrectionService $corrections,
	) {
		parent::__construct($request, $tenants, $l);
	}

	/** GET /entries?since=&limit= – every member, filtered by role. */
	#[ApiRoute(verb: 'GET', url: '/api/v1/entries')]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	public function index(int $since = 0, int $limit = EntryService::PAGE): DataResponse {
		return $this->run(fn () => $this->entries->page($this->tenants->current(), $since, $limit));
	}

	/** GET /entries/status – Team Admins: every named calendar and feed. */
	#[ApiRoute(verb: 'GET', url: '/api/v1/entries/status')]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	public function status(): DataResponse {
		return $this->run(fn () => $this->entries->statusList($this->tenants->current()));
	}

	/** POST /entries/correct – Team Admins and Leads: title, code, billable, level of bookings, written into the events (0.27.0); the person the level of their own in a budget by category (0.33.0). */
	#[ApiRoute(verb: 'POST', url: '/api/v1/entries/correct')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	public function correct(): DataResponse {
		return $this->run(fn () => $this->corrections->correct($this->tenants->current(), $this->body(262144)));
	}

	/** POST /entries/list – Team Admins: the entries of an external person, straight into the book (0.28.0). */
	#[ApiRoute(verb: 'POST', url: '/api/v1/entries/list')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	public function writeList(): DataResponse {
		return $this->run(fn () => $this->entries->writeList($this->tenants->current(), $this->body(8 * 1024 * 1024)));
	}

	/** GET /entries/corrections?uid=&event_uid=&limit= – Team Admins and Leads: the protocol of corrections (0.27.0). */
	#[ApiRoute(verb: 'GET', url: '/api/v1/entries/corrections')]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	public function corrections(?string $uid = null, ?string $event_uid = null, int $limit = CorrectionService::PAGE): DataResponse {
		return $this->run(fn () => $this->corrections->list($this->tenants->current(), $uid, $event_uid, $limit));
	}

	/** POST /entries/reread – Team Admins: a full read of every source on the next run. */
	#[ApiRoute(verb: 'POST', url: '/api/v1/entries/reread')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function reread(): DataResponse {
		return $this->run(fn () => $this->entries->markReread($this->tenants->current()));
	}
}
