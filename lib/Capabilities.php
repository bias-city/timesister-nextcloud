<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister;

use OCA\TimeSister\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\Capabilities\ICapability;

/** `timesister: {api: 1, version}` – fehlt der Eintrag, ist die App nicht da. */
final class Capabilities implements ICapability {
	public function __construct(
		private IAppManager $appManager,
	) {
	}

	/** @return array{timesister: array{api: int, version: string}} */
	public function getCapabilities(): array {
		return [
			'timesister' => [
				'api' => Application::API_VERSION,
				'version' => $this->appManager->getAppVersion(Application::APP_ID),
			],
		];
	}
}
