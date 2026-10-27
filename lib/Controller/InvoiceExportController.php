<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Controller;

use OCA\TimeSister\Service\ExportService;
use OCA\TimeSister\Service\HandoverRules;
use OCA\TimeSister\Service\HandoverService;
use OCA\TimeSister\Service\Json;
use OCA\TimeSister\Service\TenantService;
use OCA\TimeSister\Service\WebhookService;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Exports and hand-overs (0.20.0): the invoices of a period as CSV, DATEV
 * Buchungsstapel or the ledger, the hand-over to an accounting system and
 * the webhook status. Team Admins; 404 when the team setting `invoicing`
 * is off. The hand-over to an accounting system that issues the invoice
 * (0.24.0, team setting `invoicing_mode` external) does not need it.
 */
final class InvoiceExportController extends BaseController {
	private const MAX_BODY = 16 * 1024;

	public function __construct(
		IRequest $request,
		TenantService $tenants,
		IL10N $l,
		private ExportService $exports,
		private WebhookService $webhooks,
		private HandoverService $handovers,
	) {
		parent::__construct($request, $tenants, $l);
	}

	/** GET /invoices/export?kind=csv|datev|ledger&from=&to= – a CSV download (UTF-8 with BOM, semicolons). */
	#[ApiRoute(verb: 'GET', url: '/api/v1/invoices/export')]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function export(): Response {
		return $this->respond(function (): Response {
			$r = $this->exports->export($this->tenants->current(), $this->request->getParam('kind'), $this->request->getParam('from'), $this->request->getParam('to'));
			return new DataDownloadResponse($r['content'], $r['name'], 'text/csv; charset=utf-8');
		});
	}

	/** POST /invoices/{key}/external {"system", "id", "url"?} – records the hand-over to an accounting system. */
	#[ApiRoute(verb: 'POST', url: '/api/v1/invoices/{key}/external')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function external(string $key): DataResponse {
		// `id` here is the accounting system's id, not a record id: the body is read without the path-field check.
		return $this->run(fn () => $this->exports->external($this->tenants->current(), $key, Json::body(Json::readInput(self::MAX_BODY), self::MAX_BODY)));
	}

	/** POST /invoices/{key}/handover {"system", "id", "number", "url"?, "issued_on"?} – the accounting system issued the draft (0.24.0). */
	#[ApiRoute(verb: 'POST', url: '/api/v1/invoices/{key}/handover')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function handover(string $key): DataResponse {
		// `id` is the accounting system's id: the body is read without the path-field check.
		return $this->run(fn () => $this->handovers->handover($this->tenants->current(), $key, Json::body(Json::readInput(self::MAX_BODY), self::MAX_BODY)));
	}

	/** POST /invoices/{key}/handover/revoke {"reason"?} – the invoice is a draft again (0.24.0). */
	#[ApiRoute(verb: 'POST', url: '/api/v1/invoices/{key}/handover/revoke')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function revokeHandover(string $key): DataResponse {
		return $this->run(fn () => $this->handovers->revoke($this->tenants->current(), $key, $this->body(self::MAX_BODY)));
	}

	/** PUT /invoices/{key}/external-document – the body is the accounting system's PDF of a handed-over invoice, at most 20 MB (0.24.1). */
	#[ApiRoute(verb: 'PUT', url: '/api/v1/invoices/{key}/external-document')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function externalDocument(string $key): DataResponse {
		return $this->run(fn () => $this->handovers->document($this->tenants->current(), $key, Json::readInput(HandoverRules::DOCUMENT_MAX)));
	}

	/** POST /invoices/{key}/external-cancelled – the accounting system cancelled the handed-over invoice; noted once (0.24.1). */
	#[ApiRoute(verb: 'POST', url: '/api/v1/invoices/{key}/external-cancelled')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function externalCancelled(string $key): DataResponse {
		return $this->run(fn () => $this->handovers->cancelled($this->tenants->current(), $key));
	}

	/** GET /invoices/webhooks/status – per hook: waiting, delivered, failed, the last error. */
	#[ApiRoute(verb: 'GET', url: '/api/v1/invoices/webhooks/status')]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	public function webhookStatus(): DataResponse {
		return $this->run(fn () => $this->webhooks->status($this->tenants->current()));
	}
}
