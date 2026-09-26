<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\ClientStatus;
use OCA\TimeSister\Db\ClientStatusMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;

/** Lebenszeichen der Clients und „Wer fehlt“. */
final class StatusService {
	public function __construct(
		private ClientStatusMapper $status,
		private TenantService $tenants,
		private AccessPolicy $policy,
		private ITimeFactory $time,
	) {
	}

	/**
	 * Lebenszeichen des Aufrufers. Nur die geschickten Felder ändern sich;
	 * `null` löscht ein Feld.
	 *
	 * @param array<string,mixed> $in
	 * @return array{seen_at:string}
	 */
	public function report(Membership $m, array $in): array {
		$v = StatusRules::validate($in);
		$now = $this->time->getTime();
		for ($attempt = 1; ; $attempt++) {
			$found = $this->status->findOne($m->tenantId, $m->uid);
			$new = $found === null;
			$s = $found ?? new ClientStatus();
			if ($new) {
				$s->setTenantId($m->tenantId);
				$s->setUid($m->uid);
			}
			if (array_key_exists('app_version', $in)) {
				$s->setAppVersion($v['app_version']);
			}
			if (array_key_exists('last_sync', $in)) {
				$s->setLastSync($v['last_sync']);
			}
			if (array_key_exists('last_backup', $in)) {
				$s->setLastBackup($v['last_backup']);
			}
			if (array_key_exists('calendar_url', $in)) {
				$s->setCalendarUrl($v['calendar_url']);
			}
			$s->setSeenAt($now);
			try {
				$new ? $this->status->insert($s) : $this->status->update($s);
				return ['seen_at' => (string)Time::iso($now)];
			} catch (DbException $e) {
				// Zwei Lebenszeichen gleichzeitig: das zweite aktualisiert.
				if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION || $attempt >= 2) {
					throw $e;
				}
			}
		}
	}

	/** @return list<array<string,mixed>> alle Mitglieder, auch ohne Lebenszeichen */
	public function list(Membership $m): array {
		$this->policy->requireTeamRead($m);
		$byUid = $this->status->findByTenant($m->tenantId);
		$out = [];
		foreach ($this->tenants->memberRoles($m->tenantId) as $uid => $role) {
			$s = $byUid[$uid] ?? null;
			$out[] = [
				'uid' => $uid,
				'display_name' => $this->tenants->displayName($uid),
				'role' => $role,
				'app_version' => $s?->getAppVersion(),
				'last_sync' => Time::iso($s?->getLastSync()),
				'last_backup' => $s?->getLastBackup(),
				'calendar_url' => $s?->getCalendarUrl(),
				'seen_at' => $s === null ? null : Time::iso($s->getSeenAt()),
			];
		}
		return $out;
	}
}
