<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Controller;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\MemberService;
use OCA\TimeSister\Service\Role;
use OCA\TimeSister\Service\TeamAdminService;
use OCA\TimeSister\Service\TeamDeleteService;
use OCA\TimeSister\Service\TenantService;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Managing teams. **Without** `#[NoAdminRequired]`: Nextcloud itself only
 * lets admins in. The check here is the second door.
 */
final class AdminTeamController extends BaseController {
	private const FIELDS = ['name', 'slug', 'groups', 'backup_owner', 'settings'];

	public function __construct(
		IRequest $request,
		TenantService $tenants,
		IL10N $l,
		private TeamAdminService $admin,
		private MemberService $members,
		private TeamDeleteService $deleter,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
	) {
		parent::__construct($request, $tenants, $l);
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
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[UserRateLimit(limit: 300, period: 60)]
	public function create(): DataResponse {
		return $this->run(function () {
			$this->requireAdmin();
			return $this->admin->create($this->input());
		});
	}

	/** PUT /admin/teams/{id} */
	#[ApiRoute(verb: 'PUT', url: '/api/v1/admin/teams/{id}', requirements: ['id' => '\d+'])]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[UserRateLimit(limit: 300, period: 60)]
	public function update(int $id): DataResponse {
		return $this->run(function () use ($id) {
			$this->requireAdmin();
			return $this->admin->update($id, $this->input());
		});
	}

	/** DELETE /admin/teams/{id} with `{ "confirm": "<team name>" }`; a team with content is stored as a ZIP first (0.10.1). */
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/admin/teams/{id}', requirements: ['id' => '\d+'])]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[UserRateLimit(limit: 300, period: 60)]
	public function destroy(int $id): DataResponse {
		return $this->run(function () use ($id) {
			$this->requireAdmin();
			return $this->deleter->delete($this->admin->find($id), $this->request->getParam('confirm'));
		});
	}

	/**
	 * PUT /admin/teams/{id}/members/{uid} – role and leaving date from the
	 * admin page; the same service as PUT /team/members, with the rights of
	 * a team admin.
	 */
	#[ApiRoute(verb: 'PUT', url: '/api/v1/admin/teams/{id}/members/{uid}', requirements: ['id' => '\d+'])]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[UserRateLimit(limit: 300, period: 60)]
	public function updateMember(int $id, string $uid): DataResponse {
		return $this->run(function () use ($id, $uid) {
			$actor = $this->requireAdmin();
			$this->admin->find($id);
			$in = array_intersect_key($this->request->getParams(), array_flip(['role', 'left', 'may_override']));
			return $this->members->update($id, $actor, Role::ADMIN, $uid, $in);
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

	/** @return array<string,mixed> */
	private function input(): array {
		return array_intersect_key($this->request->getParams(), array_flip(self::FIELDS));
	}
}
