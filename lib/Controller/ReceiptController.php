<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Controller;

use OCA\TimeSister\Service\ExpenseExportService;
use OCA\TimeSister\Service\ExpenseService;
use OCA\TimeSister\Service\Json;
use OCA\TimeSister\Service\ReceiptRules;
use OCA\TimeSister\Service\ReceiptService;
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
 * Receipts, expense rules and expense reports (0.22.0): the receipt's
 * file, approving and rejecting, the rule's approval, the report's
 * approval, payment and document, and the export of expenses. The records
 * themselves are written through /records.
 */
final class ReceiptController extends BaseController {
	private const MAX_BODY = 4 * 1024;

	public function __construct(
		IRequest $request,
		TenantService $tenants,
		IL10N $l,
		private ReceiptService $receipts,
		private ExpenseService $expenses,
		private ExpenseExportService $exports,
	) {
		parent::__construct($request, $tenants, $l);
	}

	/** PUT /receipts/{key}/file – the body is the file: PDF, JPEG or PNG, at most 20 MB. */
	#[ApiRoute(verb: 'PUT', url: '/api/v1/receipts/{key}/file')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function putFile(string $key): DataResponse {
		return $this->run(fn () => $this->receipts->putFile($this->tenants->current(), $key, Json::readInput(ReceiptRules::FILE_MAX)));
	}

	/** GET /receipts/{key}/file – the stored file. */
	#[ApiRoute(verb: 'GET', url: '/api/v1/receipts/{key}/file')]
	#[NoAdminRequired]
	public function getFile(string $key): Response {
		return $this->respond(function () use ($key): Response {
			$r = $this->receipts->getFile($this->tenants->current(), $key);
			return new DataDownloadResponse($r['content'], $r['name'], $r['type']);
		});
	}

	/** POST /receipts/{key}/approve. */
	#[ApiRoute(verb: 'POST', url: '/api/v1/receipts/{key}/approve')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	public function approve(string $key): DataResponse {
		return $this->run(fn () => $this->receipts->approve($this->tenants->current(), $key));
	}

	/** POST /receipts/{key}/reject `{ reason }`. */
	#[ApiRoute(verb: 'POST', url: '/api/v1/receipts/{key}/reject')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	public function reject(string $key): DataResponse {
		return $this->run(fn () => $this->receipts->reject($this->tenants->current(), $key, $this->body(self::MAX_BODY)));
	}

	/** POST /expense-rules/{key}/approve. */
	#[ApiRoute(verb: 'POST', url: '/api/v1/expense-rules/{key}/approve')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	public function approveRule(string $key): DataResponse {
		return $this->run(fn () => $this->expenses->approveRule($this->tenants->current(), $key));
	}

	/** POST /expense-reports/{key}/approve – renders and stores the PDF. */
	#[ApiRoute(verb: 'POST', url: '/api/v1/expense-reports/{key}/approve')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function approveReport(string $key): DataResponse {
		return $this->run(fn () => $this->expenses->approveReport($this->tenants->current(), $key));
	}

	/** POST /expense-reports/{key}/paid `{ date }`. */
	#[ApiRoute(verb: 'POST', url: '/api/v1/expense-reports/{key}/paid')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	public function paid(string $key): DataResponse {
		return $this->run(fn () => $this->expenses->paid($this->tenants->current(), $key, $this->optionalBody()));
	}

	/** GET /expense-reports/{key}/document – the PDF. */
	#[ApiRoute(verb: 'GET', url: '/api/v1/expense-reports/{key}/document')]
	#[NoAdminRequired]
	public function reportDocument(string $key): Response {
		return $this->respond(function () use ($key): Response {
			$r = $this->expenses->document($this->tenants->current(), $key);
			return new DataDownloadResponse($r['content'], $r['name'], 'application/pdf');
		});
	}

	/** GET /exports/expenses?from=&to=&kind=csv|datev – Team Admins. */
	#[ApiRoute(verb: 'GET', url: '/api/v1/exports/expenses')]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function export(): Response {
		return $this->respond(function (): Response {
			$r = $this->exports->export($this->tenants->current(), $this->request->getParam('from'), $this->request->getParam('to'), $this->request->getParam('kind'));
			return new DataDownloadResponse($r['content'], $r['name'], 'text/csv; charset=utf-8');
		});
	}

	/** A body that may be empty. */
	private function optionalBody(): \stdClass {
		$raw = Json::readInput(self::MAX_BODY);
		return trim($raw) === '' ? new \stdClass() : Json::body($raw, self::MAX_BODY);
	}
}
