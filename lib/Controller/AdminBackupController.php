<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Controller;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\TeamAdminService;
use OCA\TimeSister\Service\TeamExportService;
use OCA\TimeSister\Service\TeamImportService;
use OCA\TimeSister\Service\TenantService;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The team backup as a ZIP (0.10.0): download, preview of an upload and
 * import. **Without** `#[NoAdminRequired]`: Nextcloud itself only lets
 * admins in; the check here is the second door.
 */
final class AdminBackupController extends BaseController {
	public function __construct(
		IRequest $request,
		TenantService $tenants,
		IL10N $l,
		private TeamAdminService $admin,
		private TeamExportService $export,
		private TeamImportService $import,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
	) {
		parent::__construct($request, $tenants, $l);
	}

	/** GET /admin/teams/{id}/export – the ZIP as a download. */
	#[ApiRoute(verb: 'GET', url: '/api/v1/admin/teams/{id}/export', requirements: ['id' => '\d+'])]
	#[UserRateLimit(limit: 20, period: 60)]
	public function export(int $id): Response {
		return $this->respond(function () use ($id): Response {
			$this->requireAdmin();
			$built = $this->export->build($this->admin->find($id));
			$content = (string)file_get_contents($built['path']);
			@unlink($built['path']);
			return new DataDownloadResponse($content, $built['name'], 'application/zip');
		});
	}

	/** POST /admin/teams/{id}/import/preview – multipart field `file`. */
	#[ApiRoute(verb: 'POST', url: '/api/v1/admin/teams/{id}/import/preview', requirements: ['id' => '\d+'])]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[UserRateLimit(limit: 20, period: 60)]
	public function preview(int $id): DataResponse {
		return $this->run(function () use ($id) {
			$this->requireAdmin();
			return $this->import->preview($this->admin->find($id), $this->request->getUploadedFile('file'));
		});
	}

	/** POST /admin/teams/{id}/import – `{ token, mapping, mode }`. */
	#[ApiRoute(verb: 'POST', url: '/api/v1/admin/teams/{id}/import', requirements: ['id' => '\d+'])]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[UserRateLimit(limit: 20, period: 60)]
	public function import(int $id): DataResponse {
		return $this->run(function () use ($id) {
			$actor = $this->requireAdmin();
			$in = array_intersect_key($this->request->getParams(), array_flip(['token', 'mapping', 'mode']));
			return $this->import->import($this->admin->find($id), $actor, $in);
		});
	}

	/** @return string the Nextcloud admin's identifier */
	private function requireAdmin(): string {
		$user = $this->userSession->getUser();
		if ($user === null || !$this->groupManager->isAdmin($user->getUID())) {
			throw ApiException::forbidden('Only Nextcloud admins manage teams.');
		}
		return $user->getUID();
	}
}
