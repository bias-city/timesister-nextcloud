<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Controller;

use OCA\TimeSister\Service\DossierService;
use OCA\TimeSister\Service\Json;
use OCA\TimeSister\Service\QuoteDocumentService;
use OCA\TimeSister\Service\ReceiptRules;
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

/** The document tree (0.22.0): quote documents through the server and the project dossier. */
final class DocumentTreeController extends BaseController {
	public function __construct(
		IRequest $request,
		TenantService $tenants,
		IL10N $l,
		private QuoteDocumentService $quotes,
		private DossierService $dossiers,
	) {
		parent::__construct($request, $tenants, $l);
	}

	/** PUT /quotes/{key}/document – the body is the PDF, at most 20 MB. */
	#[ApiRoute(verb: 'PUT', url: '/api/v1/quotes/{key}/document')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function putQuote(string $key): DataResponse {
		return $this->run(fn () => $this->quotes->put($this->tenants->current(), $key, Json::readInput(ReceiptRules::FILE_MAX)));
	}

	/** GET /quotes/{key}/document – the stored PDF. */
	#[ApiRoute(verb: 'GET', url: '/api/v1/quotes/{key}/document')]
	#[NoAdminRequired]
	public function getQuote(string $key): Response {
		return $this->respond(function () use ($key): Response {
			$r = $this->quotes->get($this->tenants->current(), $key);
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
