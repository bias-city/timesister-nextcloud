<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\AppInfo\Application;
use OCP\Config\IUserConfig;

/**
 * Kopie der Sicherung im eigenen Ordner der Person: eine Einstellung je
 * Konto, unabhängig von der Freigabe beim Admin, Standard aus. Gespeichert
 * als Nextcloud-Einstellung des Kontos; sie fällt mit dem Konto weg.
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
	 * Nur für das eigene Konto: Die Kennung kommt aus der Anmeldung.
	 *
	 * @param array<string,mixed> $in
	 * @return array{enabled:bool}
	 */
	public function set(string $uid, array $in): array {
		if (array_key_exists('uid', $in)) {
			throw ApiException::badRequest('Die Einstellung gilt nur für das eigene Konto; „uid“ gehört nicht in den Rumpf.');
		}
		$enabled = $in['enabled'] ?? null;
		if (!is_bool($enabled)) {
			throw ApiException::badRequest('„enabled“ muss true oder false sein.');
		}
		$this->config->setValueBool($uid, Application::APP_ID, self::ENABLED, $enabled);
		return ['enabled' => $enabled];
	}

	public function enabled(string $uid): bool {
		return $this->config->getValueBool($uid, Application::APP_ID, self::ENABLED);
	}

	/** @return array<string,true> alle Konten mit eingeschalteter Kopie */
	public function enabledUsers(): array {
		$out = [];
		foreach ($this->config->searchUsersByValueBool(Application::APP_ID, self::ENABLED, true) as $uid) {
			$out[$uid] = true;
		}
		return $out;
	}
}
