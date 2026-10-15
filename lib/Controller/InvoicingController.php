<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Controller;

use OCA\TimeSister\Service\IssueService;
use OCA\TimeSister\Service\Json;
use OCA\TimeSister\Service\TenantService;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Invoicing on the server (0.18.0): issue, reverse, paid, the frozen
 * content, the ledger and the chain check. Team Admins (the project's
 * Leads read `fixed`); 404 when the team setting `invoicing` is off.
 */
final class InvoicingController extends BaseController {
	/** `positions` of a credit note may be sent along. */
	private const MAX_BODY = 1024 * 1024;

	public function __construct(
		IRequest $request,
		TenantService $tenants,
		IL10N $l,
		private IssueService $issues,
	) {
		parent::__construct($request, $tenants, $l);
	}

	/** POST /invoices/{key}/issue {"issued_on"?, "due_days"?} – freezes the draft in one transaction. */
	#[ApiRoute(verb: 'POST', url: '/api/v1/invoices/{key}/issue')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function issue(string $key): DataResponse {
		return $this->run(fn () => $this->issues->issue($this->tenants->current(), $key, $this->body(self::MAX_BODY)));
	}

	/** POST /invoices/{key}/reverse {"kind": "reversal"|"credit", "reason"?, "issued_on"?, "positions"?}. */
	#[ApiRoute(verb: 'POST', url: '/api/v1/invoices/{key}/reverse')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function reverse(string $key): DataResponse {
		// `kind` here is reversal or credit, not a record kind: the body is read without the path-field check.
		return $this->run(fn () => $this->issues->reverse($this->tenants->current(), $key, Json::body(Json::readInput(self::MAX_BODY), self::MAX_BODY)));
	}

	/** POST /invoices/{key}/paid {"paid_on", "note"?}. */
	#[ApiRoute(verb: 'POST', url: '/api/v1/invoices/{key}/paid')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function paid(string $key): DataResponse {
		return $this->run(fn () => $this->issues->paid($this->tenants->current(), $key, $this->body(self::MAX_BODY), true));
	}

	/** POST /invoices/{key}/unpaid {"note"?} – reverts a mistaken payment entry. */
	#[ApiRoute(verb: 'POST', url: '/api/v1/invoices/{key}/unpaid')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function unpaid(string $key): DataResponse {
		return $this->run(fn () => $this->issues->paid($this->tenants->current(), $key, $this->body(self::MAX_BODY), false));
	}

	/** GET /invoices/{key}/fixed – the frozen content; Team Admins and the project's Leads. */
	#[ApiRoute(verb: 'GET', url: '/api/v1/invoices/{key}/fixed')]
	#[NoAdminRequired]
	public function fixed(string $key): DataResponse {
		return $this->run(fn () => $this->issues->fixed($this->tenants->current(), $key));
	}

	/** GET /invoices/ledger?since=&limit= – the team's ledger in order; Team Admins. */
	#[ApiRoute(verb: 'GET', url: '/api/v1/invoices/ledger')]
	#[NoAdminRequired]
	public function ledger(): DataResponse {
		return $this->run(fn () => $this->issues->ledger($this->tenants->current(), $this->request->getParam('since'), $this->request->getParam('limit')));
	}

	/** GET /invoices/chain/verify – recomputes every hash; Team Admins. */
	#[ApiRoute(verb: 'GET', url: '/api/v1/invoices/chain/verify')]
	#[NoAdminRequired]
	public function verify(): DataResponse {
		return $this->run(fn () => $this->issues->verify($this->tenants->current()));
	}
}
