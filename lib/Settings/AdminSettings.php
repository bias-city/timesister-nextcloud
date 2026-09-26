<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Settings;

use OCA\TimeSister\AppInfo\Application;
use OCA\TimeSister\Service\TeamAdminService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * Die Admin-Seite: Teams (über die Admin-Endpunkte) und Zustand. Ein
 * PHP-Template mit etwas Vanilla-JavaScript, ohne Build.
 */
final class AdminSettings implements ISettings {
	public function __construct(
		private TeamAdminService $admin,
		private IInitialState $initialState,
		private IAppManager $appManager,
		private IURLGenerator $url,
	) {
	}

	public function getForm(): TemplateResponse {
		$this->initialState->provideInitialState('overview', [
			'api' => Application::API_VERSION,
			// Die Seite braucht keine OC-Globals: Adresse der Schnittstelle von hier.
			'api_base' => $this->url->getWebroot() . '/ocs/v2.php/apps/' . Application::APP_ID . '/api/v1',
			'version' => $this->appManager->getAppVersion(Application::APP_ID),
			'silent_days' => TeamAdminService::SILENT_DAYS,
			'state' => $this->admin->overview(),
			'groups' => $this->admin->allGroups(),
		]);
		Util::addScript(Application::APP_ID, 'admin');
		Util::addStyle(Application::APP_ID, 'admin');
		return new TemplateResponse(Application::APP_ID, 'admin', [], '');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 10;
	}
}
