<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Controller;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\PrivacyNotice;
use OCA\TimeSister\Service\TeamAdminService;
use OCA\TimeSister\Service\TenantService;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The privacy notice (0.10.2): every member downloads it for their own
 * team, the Nextcloud admin for any team; the admin also keeps the record
 * `setting/privacy` from the admin page. The admin routes are **without**
 * `#[NoAdminRequired]`; the check here is the second door.
 */
final class PrivacyController extends BaseController {
	public function __construct(
		IRequest $request,
		TenantService $tenants,
		IL10N $l,
		private PrivacyNotice $notice,
		private TeamAdminService $admin,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
	) {
		parent::__construct($request, $tenants, $l);
	}

	/** GET /team/privacy?format=html|md – the notice of the caller's team, for every member. */
	#[ApiRoute(verb: 'GET', url: '/api/v1/team/privacy')]
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function team(): Response {
		return $this->respond(function (): Response {
			$m = $this->tenants->current();
			return $this->download($this->notice->render($this->tenants->tenant($m->tenantId), $this->request->getParam('format')));
		});
	}

	/** GET /admin/teams/{id}/privacy?format=html|md */
	#[ApiRoute(verb: 'GET', url: '/api/v1/admin/teams/{id}/privacy', requirements: ['id' => '\d+'])]
	#[UserRateLimit(limit: 60, period: 60)]
	public function adminDownload(int $id): Response {
		return $this->respond(function () use ($id): Response {
			$this->requireAdmin();
			return $this->download($this->notice->render($this->admin->find($id), $this->request->getParam('format')));
		});
	}

	/** PUT /admin/teams/{id}/privacy with `{ data }` – the record `setting/privacy`, written against its current version. */
	#[ApiRoute(verb: 'PUT', url: '/api/v1/admin/teams/{id}/privacy', requirements: ['id' => '\d+'])]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[UserRateLimit(limit: 60, period: 60)]
	public function adminSave(int $id): DataResponse {
		return $this->run(function () use ($id) {
			$actor = $this->requireAdmin();
			$body = $this->body(self::MAX_RECORD_BODY);
			return $this->notice->save($this->admin->find($id), $actor, $body->data ?? null);
		});
	}

	/** @param array{content:string,name:string,mime:string} $r */
	private function download(array $r): DataDownloadResponse {
		return new DataDownloadResponse($r['content'], $r['name'], $r['mime']);
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
