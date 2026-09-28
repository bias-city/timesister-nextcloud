<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Controller;

use OCA\TimeSister\Service\JobService;
use OCA\TimeSister\Service\JobWeeksService;
use OCA\TimeSister\Service\Json;
use OCA\TimeSister\Service\TenantService;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Jobs (0.6.0; the market with counter-proposals per person 0.7.0): offer,
 * change and delete (Lead, Team Admin), accept,
 * counter-propose, decline, return and declare Done (the person), answer a
 * counter-proposal, paid (Team Admin), and the booked hours from the
 * person's client; the weekly numbers of each person and the capacity
 * check of a counter-proposal (0.7.2). Reading goes through /records
 * (kind `job`).
 */
final class JobController extends BaseController {
	public function __construct(
		IRequest $request,
		TenantService $tenants,
		IL10N $l,
		private JobService $jobs,
		private JobWeeksService $weeks,
	) {
		parent::__construct($request, $tenants, $l);
	}

	/** PUT /jobs/{key} – offer a new Job; sent again, it stays as it is. */
	#[ApiRoute(verb: 'PUT', url: '/api/v1/jobs/{key}')]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 300, period: 60)]
	public function offer(string $key): DataResponse {
		return $this->run(fn () => $this->jobs->offer($this->tenants->current(), $key, $this->body(self::MAX_RECORD_BODY)));
	}

	/** DELETE /jobs/{key} – only whoever created it. */
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/jobs/{key}')]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 300, period: 60)]
	public function destroy(string $key): DataResponse {
		return $this->run(fn () => $this->jobs->delete($this->tenants->current(), $key));
	}

	/** POST /jobs/{key}/change */
	#[ApiRoute(verb: 'POST', url: '/api/v1/jobs/{key}/change')]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 300, period: 60)]
	public function change(string $key): DataResponse {
		return $this->run(fn () => $this->jobs->change($this->tenants->current(), $key, $this->body(self::MAX_RECORD_BODY)));
	}

	/** POST /jobs/{key}/accept – the first recipient wins. */
	#[ApiRoute(verb: 'POST', url: '/api/v1/jobs/{key}/accept')]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 300, period: 60)]
	public function accept(string $key): DataResponse {
		return $this->run(fn () => $this->jobs->accept($this->tenants->current(), $key));
	}

	/** POST /jobs/{key}/counter */
	#[ApiRoute(verb: 'POST', url: '/api/v1/jobs/{key}/counter')]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 300, period: 60)]
	public function counter(string $key): DataResponse {
		return $this->run(fn () => $this->jobs->counter($this->tenants->current(), $key, $this->body(self::MAX_RECORD_BODY)));
	}

	/** POST /jobs/{key}/accept-counter – optional `{ by }`: whose, while several wait. */
	#[ApiRoute(verb: 'POST', url: '/api/v1/jobs/{key}/accept-counter')]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 300, period: 60)]
	public function acceptCounter(string $key): DataResponse {
		return $this->run(fn () => $this->jobs->answerCounter($this->tenants->current(), $key, true, $this->optionalBody()));
	}

	/** POST /jobs/{key}/reject-counter – optional `{ by }`. */
	#[ApiRoute(verb: 'POST', url: '/api/v1/jobs/{key}/reject-counter')]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 300, period: 60)]
	public function rejectCounter(string $key): DataResponse {
		return $this->run(fn () => $this->jobs->answerCounter($this->tenants->current(), $key, false, $this->optionalBody()));
	}

	/** POST /jobs/{key}/decline */
	#[ApiRoute(verb: 'POST', url: '/api/v1/jobs/{key}/decline')]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 300, period: 60)]
	public function decline(string $key): DataResponse {
		return $this->run(fn () => $this->jobs->decline($this->tenants->current(), $key));
	}

	/** POST /jobs/{key}/return – optional `{ note }`. */
	#[ApiRoute(verb: 'POST', url: '/api/v1/jobs/{key}/return')]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 300, period: 60)]
	public function giveBack(string $key): DataResponse {
		return $this->run(fn () => $this->jobs->giveBack($this->tenants->current(), $key, $this->optionalBody()));
	}

	/** POST /jobs/{key}/done */
	#[ApiRoute(verb: 'POST', url: '/api/v1/jobs/{key}/done')]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 300, period: 60)]
	public function done(string $key): DataResponse {
		return $this->run(fn () => $this->jobs->done($this->tenants->current(), $key));
	}

	/** POST /jobs/{key}/paid – `{ paid: bool }`, default true; Team Admins. */
	#[ApiRoute(verb: 'POST', url: '/api/v1/jobs/{key}/paid')]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 300, period: 60)]
	public function paid(string $key): DataResponse {
		return $this->run(fn () => $this->jobs->paid($this->tenants->current(), $key, $this->optionalBody()));
	}

	/** POST /jobs/progress – `{ jobs: { key: { hours, invoiced } } }` from the person's client. */
	#[ApiRoute(verb: 'POST', url: '/api/v1/jobs/progress')]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 300, period: 60)]
	public function progress(): DataResponse {
		return $this->run(fn () => $this->jobs->progress($this->tenants->current(), $this->body(self::MAX_RECORD_BODY)));
	}

	/** POST /jobs/weeks – the caller's weekly numbers from their client (0.7.2). */
	#[ApiRoute(verb: 'POST', url: '/api/v1/jobs/weeks')]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	public function reportWeeks(): DataResponse {
		return $this->run(fn () => $this->weeks->report($this->tenants->current(), $this->body(self::MAX_RECORD_BODY)));
	}

	/** GET /jobs/weeks?uid= – the weekly numbers the caller may read. */
	#[ApiRoute(verb: 'GET', url: '/api/v1/jobs/weeks')]
	#[NoAdminRequired]
	public function weeks(?string $uid = null): DataResponse {
		return $this->run(fn () => $this->weeks->list($this->tenants->current(), $uid));
	}

	/** GET /jobs/{key}/capacity?by= – does the counter-proposal fit its person? */
	#[ApiRoute(verb: 'GET', url: '/api/v1/jobs/{key}/capacity')]
	#[NoAdminRequired]
	public function capacity(string $key, ?string $by = null): DataResponse {
		return $this->run(fn () => $this->jobs->capacity($this->tenants->current(), $key, $by === '' ? null : $by));
	}

	/** A body may be left out; then null. */
	private function optionalBody(): ?\stdClass {
		$raw = Json::readInput(self::MAX_RECORD_BODY);
		return trim($raw) === '' ? null : Json::body($raw, self::MAX_RECORD_BODY);
	}
}
