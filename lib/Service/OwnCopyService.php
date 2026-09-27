<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\AppInfo\Application;
use OCP\Config\IUserConfig;

/**
 * Copy of the backup in the person's own folder: a setting per account,
 * independent of consent with the admin, default off. Stored as a
 * Nextcloud setting of the account; it disappears with the account.
 */
final class OwnCopyService {
	private const ENABLED = 'own_copy';

	public function __construct(
		private IUserConfig $config,
	) {
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
		$this->config->setValueBool($uid, Application::APP_ID, self::ENABLED, $enabled);
		return ['enabled' => $enabled];
	}

	public function enabled(string $uid): bool {
		return $this->config->getValueBool($uid, Application::APP_ID, self::ENABLED);
	}

	/** @return array<string,true> all accounts with the copy turned on */
	public function enabledUsers(): array {
		$out = [];
		foreach ($this->config->searchUsersByValueBool(Application::APP_ID, self::ENABLED, true) as $uid) {
			$out[$uid] = true;
		}
		return $out;
	}
}
