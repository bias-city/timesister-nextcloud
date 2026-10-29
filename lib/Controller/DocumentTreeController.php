<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Controller;

use OCA\TimeSister\Service\DocumentRules;
use OCA\TimeSister\Service\DossierService;
use OCA\TimeSister\Service\Json;
use OCA\TimeSister\Service\QuoteDocumentService;
use OCA\TimeSister\Service\QuoteIssueService;
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
 * The document tree (0.22.0): quote documents through the server and the
 * project dossier. Since 0.32.0 the quote's issue (`quotes: 4`) and its
 * three document kinds (`quote_documents: 1`).
 */
final class DocumentTreeController extends BaseController {
	private const MAX_BODY = 4096;

	public function __construct(
		IRequest $request,
		TenantService $tenants,
		IL10N $l,
		private QuoteDocumentService $quotes,
		private QuoteIssueService $issues,
		private DossierService $dossiers,
	) {
		parent::__construct($request, $tenants, $l);
	}

	/** POST /quotes/{key}/issue {"date"?, "valid_until"?, "hours"?, "amount"?} – the number from the team's counter, the frozen copy `issued`. */
	#[ApiRoute(verb: 'POST', url: '/api/v1/quotes/{key}/issue')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function issueQuote(string $key): DataResponse {
		return $this->run(fn () => $this->issues->issue($this->tenants->current(), $key, $this->body(self::MAX_BODY)));
	}

	/** PUT /quotes/{key}/document – the body is the TimeSister PDF, at most 20 MB; needs an issued quote. */
	#[ApiRoute(verb: 'PUT', url: '/api/v1/quotes/{key}/document')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function putQuote(string $key): DataResponse {
		return $this->run(fn () => $this->quotes->put($this->tenants->current(), $key, Json::readInput(DocumentRules::FILE_MAX)));
	}

	/** POST /quotes/{key}/documents?kind=sent|signed – the body is the PDF, at most 20 MB; added, never overwritten. */
	#[ApiRoute(verb: 'POST', url: '/api/v1/quotes/{key}/documents')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function addQuoteDocument(string $key): DataResponse {
		return $this->run(fn () => $this->quotes->add($this->tenants->current(), $key, $this->request->getParam('kind'), Json::readInput(DocumentRules::FILE_MAX)));
	}

	/** GET /quotes/{key}/documents – the filed documents in order. */
	#[ApiRoute(verb: 'GET', url: '/api/v1/quotes/{key}/documents')]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	public function listQuoteDocuments(string $key): DataResponse {
		return $this->run(fn () => $this->quotes->list($this->tenants->current(), $key));
	}

	/** GET /quotes/{key}/document?kind=timesister|sent|signed&name= – one filed PDF. */
	#[ApiRoute(verb: 'GET', url: '/api/v1/quotes/{key}/document')]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	public function getQuote(string $key): Response {
		return $this->respond(function () use ($key): Response {
			$r = $this->quotes->get($this->tenants->current(), $key, $this->request->getParam('kind'), $this->request->getParam('name'));
			return new DataDownloadResponse($r['content'], $r['name'], 'application/pdf');
		});
	}

	/** POST /projects/{key}/dossier – writes the project dossier ZIP into the tree. */
	#[ApiRoute(verb: 'POST', url: '/api/v1/projects/{key}/dossier')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 60)]
	public function dossier(string $key): DataResponse {
		return $this->run(fn () => $this->dossiers->create($this->tenants->current(), $key));
	}
}
