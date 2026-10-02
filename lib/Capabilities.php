<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister;

use OCA\TimeSister\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\Capabilities\ICapability;

/**
 * `timesister: {api, version, jobs, billing}` – if the entry is missing, the
 * app is not installed. `jobs: 1` since 0.6.0: the Job endpoints are there;
 * `jobs: 2` since 0.7.0: counter-proposals per recipient in a market;
 * `jobs: 3` since 0.7.2: weekly numbers per person and the capacity check;
 * `jobs: 4` since 0.7.4: the weekly numbers carry the non-billable booked
 * hours. `billing: 1` since 0.7.6: billing marks as records of kind
 * `billing`, externals in the shares matrix, feed only for those who may.
 */
final class Capabilities implements ICapability {
	public function __construct(
		private IAppManager $appManager,
	) {
	}

	/** @return array{timesister: array{api: int, version: string, jobs: int, billing: int}} */
	public function getCapabilities(): array {
		return [
			'timesister' => [
				'api' => Application::API_VERSION,
				'version' => $this->appManager->getAppVersion(Application::APP_ID),
				'jobs' => 4,
				'billing' => 1,
			],
		];
	}
}
