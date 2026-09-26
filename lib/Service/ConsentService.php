<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\BackupConsent;
use OCA\TimeSister\Db\BackupConsentMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;

/**
 * Freigabe der Sicherung beim Admin, je Konto und Team. Standard: keine.
 * Zurückziehen hält nur künftige Sicherungen an; es löscht nichts.
 */
final class ConsentService {
	public const REFUSED = 'Die Person hat die Sicherung beim Admin nicht freigegeben.';

	public function __construct(
		private BackupConsentMapper $consents,
		private ITimeFactory $time,
	) {
	}

	/** @return array{consent:bool,since:?string,revoked_at:?string,notice:?string} */
	public static function present(?BackupConsent $c): array {
		return [
			'consent' => self::granted($c),
			'since' => $c === null ? null : Time::iso($c->getSince()),
			'revoked_at' => $c === null ? null : Time::iso($c->getRevokedAt()),
			'notice' => $c?->getNotice(),
		];
	}

	public static function granted(?BackupConsent $c): bool {
		return $c !== null && $c->getConsent() === 1;
	}

	/** @return array{consent:bool,since:?string,revoked_at:?string,notice:?string} */
	public function get(Membership $m): array {
		return self::present($this->consents->findOne($m->tenantId, $m->uid));
	}

	/**
	 * Nur für das eigene Konto: Die Kennung kommt aus der Anmeldung.
	 *
	 * @param array<string,mixed> $in
	 * @return array{consent:bool,since:?string,revoked_at:?string,notice:?string}
	 */
	public function set(Membership $m, array $in): array {
		$v = BackupRules::consentInput($in);
		$now = $this->time->getTime();
		for ($attempt = 1; ; $attempt++) {
			$found = $this->consents->findOne($m->tenantId, $m->uid);
			if ($found === null && !$v['consent']) {
				return self::present(null); // nie freigegeben: nichts zu speichern
			}
			$c = $found ?? new BackupConsent();
			if ($found === null) {
				$c->setTenantId($m->tenantId);
				$c->setUid($m->uid);
			}
			if ($v['consent']) {
				if (!self::granted($found)) {
					$c->setConsent(1);
					$c->setSince($now);
					$c->setRevokedAt(null);
				}
				$c->setNotice($v['notice']);
				$c->setNoticeAt($now);
			} elseif (self::granted($found)) {
				$c->setConsent(0);
				$c->setSince(null);
				$c->setRevokedAt($now);
			}
			try {
				$c = $found === null ? $this->consents->insert($c) : $this->consents->update($c);
				return self::present($c);
			} catch (DbException $e) {
				// Zwei Anfragen gleichzeitig: die zweite aktualisiert.
				if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION || $attempt >= 2) {
					throw $e;
				}
			}
		}
	}

	public function has(int $tenantId, string $uid): bool {
		return self::granted($this->consents->findOne($tenantId, $uid));
	}

	/** @throws ApiException 403 ohne Freigabe */
	public function require(int $tenantId, string $uid): void {
		if (!$this->has($tenantId, $uid)) {
			throw ApiException::forbidden(self::REFUSED);
		}
	}

	/** @return array<string,BackupConsent> uid → Freigabe */
	public function byTenant(int $tenantId): array {
		return $this->consents->findByTenant($tenantId);
	}
}
