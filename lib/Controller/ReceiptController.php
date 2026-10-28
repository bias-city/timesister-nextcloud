<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Controller;

use OCA\TimeSister\Service\ExpenseExportService;
use OCA\TimeSister\Service\ExpenseReportService;
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
 * Receipts and expense reports: the receipt's file, approving, rejecting
 * and reopening (0.22.0, 0.27.0); settling, the app's files, reversing and
 * reading a report (0.30.0); the export of expenses. The records
 * themselves are written through /records.
 */
final class ReceiptController extends BaseController {
	private const MAX_BODY = 4 * 1024;
	/** A file of the app in Base64 (64 MB are 85.4 MB of Base64) and its name. */
	private const MAX_DOCUMENT_BODY = 90 * 1024 * 1024;

	public function __construct(
		IRequest $request,
		TenantService $tenants,
		IL10N $l,
		private ReceiptService $receipts,
		private ExpenseReportService $reports,
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
	#[UserRateLimit(limit: 120, period: 60)]
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

	/** POST /receipts/{key}/reopen – a Team Admin takes the approval back (0.27.0). */
	#[ApiRoute(verb: 'POST', url: '/api/v1/receipts/{key}/reopen')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	public function reopen(string $key): DataResponse {
		return $this->run(fn () => $this->receipts->reopen($this->tenants->current(), $key));
	}

	/** POST /expense-reports/fix `{ person, from, to }` – the person or Team Admins settle the open expenses of a period (0.30.0). */
	#[ApiRoute(verb: 'POST', url: '/api/v1/expense-reports/fix')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function fix(): DataResponse {
		return $this->run(fn () => $this->reports->fix($this->tenants->current(), $this->body(self::MAX_BODY)));
	}

	/** POST /expense-reports/{key}/documents `{ name, content }` – a file of the app, Base64, at most 64 MB (0.30.0). */
	#[ApiRoute(verb: 'POST', url: '/api/v1/expense-reports/{key}/documents')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function storeDocument(string $key): DataResponse {
		return $this->run(fn () => $this->reports->storeDocument($this->tenants->current(), $key, $this->body(self::MAX_DOCUMENT_BODY)));
	}

	/** POST /expense-reports/{key}/reverse `{ reason }` – only a fixed report. */
	#[ApiRoute(verb: 'POST', url: '/api/v1/expense-reports/{key}/reverse')]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[NoAdminRequired]
	public function reverse(string $key): DataResponse {
		return $this->run(fn () => $this->reports->reverse($this->tenants->current(), $key, $this->optionalBody()));
	}

	/** GET /expense-reports/{key}/document?name= – a stored file; without name the first PDF (old reports: their PDF). */
	#[ApiRoute(verb: 'GET', url: '/api/v1/expense-reports/{key}/document')]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 120, period: 60)]
	public function reportDocument(string $key): Response {
		return $this->respond(function () use ($key): Response {
			$r = $this->reports->document($this->tenants->current(), $key, $this->request->getParam('name'));
			return new DataDownloadResponse($r['content'], $r['name'], $r['type']);
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
