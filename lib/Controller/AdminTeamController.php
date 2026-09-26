<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Controller;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\TeamAdminService;
use OCA\TimeSister\Service\TenantService;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Verwaltung der Teams. **Ohne** `#[NoAdminRequired]`: Nextcloud selbst
 * lässt nur Admins herein. Die eigene Prüfung hier ist die zweite Tür.
 */
final class AdminTeamController extends BaseController {
	private const FIELDS = ['name', 'slug', 'groups'];

	public function __construct(
		IRequest $request,
		TenantService $tenants,
		private TeamAdminService $admin,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
	) {
		parent::__construct($request, $tenants);
	}

	/** GET /admin/teams */
	#[ApiRoute(verb: 'GET', url: '/api/v1/admin/teams')]
	public function index(): DataResponse {
		return $this->run(function () {
			$this->requireAdmin();
			return $this->admin->list();
		});
	}

	/** POST /admin/teams */
	#[ApiRoute(verb: 'POST', url: '/api/v1/admin/teams')]
	#[UserRateLimit(limit: 300, period: 60)]
	public function create(): DataResponse {
		return $this->run(function () {
			$this->requireAdmin();
			return $this->admin->create($this->input());
		});
	}

	/** PUT /admin/teams/{id} */
	#[ApiRoute(verb: 'PUT', url: '/api/v1/admin/teams/{id}', requirements: ['id' => '\d+'])]
	#[UserRateLimit(limit: 300, period: 60)]
	public function update(int $id): DataResponse {
		return $this->run(function () use ($id) {
			$this->requireAdmin();
			return $this->admin->update($id, $this->input());
		});
	}

	/** DELETE /admin/teams/{id} */
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/admin/teams/{id}', requirements: ['id' => '\d+'])]
	#[UserRateLimit(limit: 300, period: 60)]
	public function destroy(int $id): DataResponse {
		return $this->run(function () use ($id) {
			$this->requireAdmin();
			$this->admin->delete($id);
			return ['id' => $id, 'deleted' => true];
		});
	}

	private function requireAdmin(): void {
		$user = $this->userSession->getUser();
		if ($user === null || !$this->groupManager->isAdmin($user->getUID())) {
			throw ApiException::forbidden('Teams verwalten nur Nextcloud-Admins.');
		}
	}

	/** @return array<string,mixed> */
	private function input(): array {
		return array_intersect_key($this->request->getParams(), array_flip(self::FIELDS));
	}
}
