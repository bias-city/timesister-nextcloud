<?php

declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Controller;

use OCA\TimeSister\Service\PurgeRules;
use OCA\TimeSister\Service\PurgeService;
use OCA\TimeSister\Service\TenantService;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Removing the app's data from the admin page (0.43.0). **Without**
 * `#[NoAdminRequired]`: Nextcloud itself only lets admins in; the check
 * here is the second door, the password confirmation the third.
 */
final class AdminPurgeController extends BaseController {
	public function __construct(
		IRequest $request,
		TenantService $tenants,
		IL10N $l,
		private PurgeService $purge,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
	) {
		parent::__construct($request, $tenants, $l);
	}

	/** GET /admin/purge – what a deletion would touch, per team, and the sentence to type. */
	#[ApiRoute(verb: 'GET', url: '/api/v1/admin/purge')]
	#[UserRateLimit(limit: 60, period: 60)]
	public function preview(): DataResponse {
		return $this->run(function () {
			$this->requireAdmin();
			// The literal, not the constant: the l10n check reads the sources for translated texts.
			return $this->purge->preview() + ['sentence' => $this->l->t('I want to uninstall all data of the app.'), 'sentence_en' => PurgeRules::SENTENCE];
		});
	}

	/**
	 * POST /admin/purge – `{ confirm, tokens: { <team id>: <sha256> }, folders, vacation, time_calendars }`.
	 * Every team needs a fresh downloaded backup; the sentence must match; Nextcloud asks for the password.
	 */
	#[ApiRoute(verb: 'POST', url: '/api/v1/admin/purge')]
	#[PasswordConfirmationRequired]
	#[BruteForceProtection(action: self::BRUTE_FORCE_ACTION)]
	#[UserRateLimit(limit: 5, period: 60)]
	public function purge(): DataResponse {
		return $this->run(function () {
			$this->requireAdmin();
			$in = $this->request->getParams();
			$tokens = $in['tokens'] ?? [];
			return $this->purge->purgeAll(PurgeRules::options($in), $in['confirm'] ?? null, is_array($tokens) ? $tokens : [],
				array_values(array_unique([PurgeRules::SENTENCE, $this->l->t('I want to uninstall all data of the app.')])));
		});
	}

	private function requireAdmin(): void {
		$user = $this->userSession->getUser();
		if ($user === null || !$this->groupManager->isAdmin($user->getUID())) {
			throw \OCA\TimeSister\Service\ApiException::forbidden();
		}
	}
}
