<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\AppInfo\Application;
use OCP\Config\IUserConfig;

/**
 * Schalter der Person „Zeitkalender für das Team freigeben“ (Fassung 2).
 * Standard an; gespeichert als Nextcloud-Einstellung des Kontos.
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
	 * Nur für das eigene Konto: Die Kennung kommt aus der Anmeldung.
	 *
	 * @param array<string,mixed> $in
	 * @return array{enabled:bool}
	 */
	public function set(string $uid, array $in): array {
		if (array_key_exists('uid', $in)) {
			throw ApiException::badRequest('Der Schalter gilt nur für das eigene Konto; „uid“ gehört nicht in den Rumpf.');
		}
		$enabled = $in['enabled'] ?? null;
		if (!is_bool($enabled)) {
			throw ApiException::badRequest('„enabled“ muss true oder false sein.');
		}
		$this->config->setValueBool($uid, Application::APP_ID, self::KEY, $enabled);
		return ['enabled' => $enabled];
	}
}
