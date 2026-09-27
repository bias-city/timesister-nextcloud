<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\AppInfo\Application;
use OCP\Config\IUserConfig;

/**
 * When the weekly job last checked an account for each store, as a
 * Nextcloud setting of the account. This way it exports at most once per
 * ISO week, even when nothing changed and no file is created.
 */
final class WeekMarks {
	public const ADMIN = 'admin';
	public const OWN = 'own';

	public function __construct(
		private IUserConfig $config,
	) {
	}

	public function checked(string $uid, string $target, string $today): bool {
		$last = $this->config->getValueString($uid, Application::APP_ID, 'checked_' . $target);
		return Time::isDay($last) && BackupRules::sameIsoWeek($last, $today);
	}

	public function mark(string $uid, string $target, string $day): void {
		$this->config->setValueString($uid, Application::APP_ID, 'checked_' . $target, $day);
	}
}
