<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\AppInfo\Application;
use OCP\Config\IUserConfig;

/**
 * The person's "share time calendar with the team" switch (API version 2).
 * Default on; stored as a Nextcloud setting of the account.
 */
final class CalendarShareService {
	private const KEY = 'calendar_share';

	public function __construct(
		private IUserConfig $config,
	) {
	}

	public function enabled(string $uid): bool {
		return $this->config->getValueBool($uid, Application::APP_ID, self::KEY, true);
	}

	/** @return array{enabled:bool} */
	public function get(string $uid): array {
		return ['enabled' => $this->enabled($uid)];
	}

	/**
	 * Only for the own account: the identifier comes from the login.
	 *
	 * @param array<string,mixed> $in
	 * @return array{enabled:bool}
	 */
	public function set(string $uid, array $in): array {
		if (array_key_exists('uid', $in)) {
			throw ApiException::ownAccountOnly();
		}
		$enabled = $in['enabled'] ?? null;
		if (!is_bool($enabled)) {
			throw ApiException::notBool('enabled', true);
		}
		$this->config->setValueBool($uid, Application::APP_ID, self::KEY, $enabled);
		return ['enabled' => $enabled];
	}
}
