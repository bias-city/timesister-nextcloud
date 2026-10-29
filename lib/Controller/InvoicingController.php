<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Controller;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\DocumentRules;
use OCA\TimeSister\Service\DocumentService;
use OCA\TimeSister\Service\IssueService;
use OCA\TimeSister\Service\Json;
use OCA\TimeSister\Service\TenantService;
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
 * Invoicing on the server (0.18.0): issue, reverse, paid, the frozen
 * content, the ledger and the chain check. Team Admins (the project's
 * Leads read `fixed`); 404 when the team setting `invoicing` is off.
 * Since 0.32.0 the issue has two steps: `issue` reserves, the app's PDF
 * sent to `PUT …/document` closes, `issue/cancel` releases; `preview` and
 * `render` are gone (410) – the server renders nothing.
 */
final class InvoicingController extends BaseController {
	/** `positions` of a credit note may be sent along. */
	private const MAX_BODY = 1024 * 1024;

	public function __construct(
		IRequest $request,
		TenantService $tenants,
		IL10N $l,
		private IssueService $issues,
		private DocumentService $documents,
	) {
		parent::__construct($request, $tenants, $l);
	}

	/** POST /invoices/{key}/issue {"issued_on"?, "due_days"?} – reserves number, dates and chain link; the invoice is `issuing`. */
	#[ApiRoute(verb: 'POST', url: '/api/v1/invoices/{key}/issue')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function issue(string $key): DataResponse {
		return $this->run(fn () => $this->issues->issue($this->tenants->current(), $key, $this->body(self::MAX_BODY)));
	}

	/** POST /invoices/{key}/issue/cancel – releases number and chain link of an `issuing` invoice. */
	#[ApiRoute(verb: 'POST', url: '/api/v1/invoices/{key}/issue/cancel')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function cancelIssue(string $key): DataResponse {
		return $this->run(fn () => $this->issues->cancel($this->tenants->current(), $key));
	}

	/** PUT /invoices/{key}/document?kind=pdf|xml – the body is the file (raw, ≤ 20 MB): the PDF closes the issue, the XML is filed next to it. */
	#[ApiRoute(verb: 'PUT', url: '/api/v1/invoices/{key}/document')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function putDocument(string $key): DataResponse {
		return $this->run(function () use ($key) {
			$kind = $this->request->getParam('kind');
			$bytes = Json::readInput(DocumentRules::FILE_MAX);
			if ($kind === null || $kind === '' || $kind === DocumentRules::KIND_PDF) {
				return $this->issues->close($this->tenants->current(), $key, $bytes);
			}
			if ($kind === DocumentRules::KIND_XML) {
				return $this->issues->storeXml($this->tenants->current(), $key, $bytes);
			}
			throw ApiException::invalid('“kind” must be “pdf” or “xml”.')->field('kind');
		});
	}

	/** POST /invoices/{key}/reverse {"kind": "reversal"|"credit", "reason"?, "issued_on"?, "positions"?} – reserves the reversal or credit note. */
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

	/** POST /invoices/{key}/dunning {"level", "on"?} – the dunning level 0–3 with the day of the last reminder (0.27.0). */
	#[ApiRoute(verb: 'POST', url: '/api/v1/invoices/{key}/dunning')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function dunning(string $key): DataResponse {
		return $this->run(fn () => $this->issues->dunning($this->tenants->current(), $key, $this->body(self::MAX_BODY)));
	}

	/** GET /invoices/{key}/fixed – the frozen content; Team Admins and the project's Leads. */
	#[ApiRoute(verb: 'GET', url: '/api/v1/invoices/{key}/fixed')]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	public function fixed(string $key): DataResponse {
		return $this->run(fn () => $this->issues->fixed($this->tenants->current(), $key));
	}

	/** GET /invoices/ledger?since=&limit= – the team's ledger in order; Team Admins. */
	#[ApiRoute(verb: 'GET', url: '/api/v1/invoices/ledger')]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function ledger(): DataResponse {
		return $this->run(fn () => $this->issues->ledger($this->tenants->current(), $this->request->getParam('since'), $this->request->getParam('limit')));
	}

	/** GET /invoices/{key}/preview – gone since 0.32.0 (the app renders its own preview). */
	#[ApiRoute(verb: 'GET', url: '/api/v1/invoices/{key}/preview')]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function preview(string $key): DataResponse {
		return $this->run(static fn () => throw ApiException::gone());
	}

	/** GET /invoices/{key}/document?kind=pdf|xml – the filed document as a download; 409 document_missing while `issuing`. */
	#[ApiRoute(verb: 'GET', url: '/api/v1/invoices/{key}/document')]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	public function document(string $key): Response {
		return $this->respond(function () use ($key): Response {
			$r = $this->documents->document($this->tenants->current(), $key, $this->request->getParam('kind'));
			return new DataDownloadResponse($r['content'], $r['name'], $r['type']);
		});
	}

	/** POST /invoices/{key}/render – gone since 0.32.0 (nothing is rendered on the server). */
	#[ApiRoute(verb: 'POST', url: '/api/v1/invoices/{key}/render')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function render(string $key): DataResponse {
		return $this->run(static fn () => throw ApiException::gone());
	}

	/** GET /invoices/chain/head – the chain head `{ seq, hash, at, entries }`; Team Admins. */
	#[ApiRoute(verb: 'GET', url: '/api/v1/invoices/chain/head')]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	public function chainHead(): DataResponse {
		return $this->run(fn () => $this->documents->chainHead($this->tenants->current()));
	}

	/** GET /invoices/chain/verify?files=true – recomputes every hash, with `files` also every filed PDF and XML; Team Admins. */
	#[ApiRoute(verb: 'GET', url: '/api/v1/invoices/chain/verify')]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function verify(): DataResponse {
		$files = $this->request->getParam('files');
		return $this->run(fn () => $this->issues->verify($this->tenants->current(), $files === 'true' || $files === '1' || $files === true));
	}
}
