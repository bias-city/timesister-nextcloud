<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\ClientStatus;
use OCA\TimeSister\Db\ClientStatusMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;

/** Status reports of the clients and "who is missing". */
final class StatusService {
	public function __construct(
		private ClientStatusMapper $status,
		private ConsentService $consent,
		private TenantService $tenants,
		private AccessPolicy $policy,
		private ITimeFactory $time,
		private EntryService $entries,
	) {
	}

	/**
	 * The caller's status report. Only the fields sent change; `null`
	 * clears a field (`projects` too).
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
			// Missing keeps the last report. Stored since 0.12.0; since 0.14.0 no right follows from it.
			if (array_key_exists('projects', $in)) {
				$s->setProjects($v['projects'] === null ? null : json_encode($v['projects'], JSON_THROW_ON_ERROR));
				$s->setProjectsAt($v['projects'] === null ? null : $now);
			}
			// 0.45.0: what the person is editing right now; missing keeps the last report, an empty list clears it.
			if (array_key_exists('editing', $in)) {
				$s->setEditing($v['editing'] === null || $v['editing'] === [] ? null : json_encode($v['editing'], JSON_THROW_ON_ERROR));
				$s->setEditingAt($v['editing'] === null || $v['editing'] === [] ? null : $now);
			}
			$s->setSeenAt($now);
			try {
				$new ? $this->status->insert($s) : $this->status->update($s);
				return ['seen_at' => (string)Time::iso($now)];
			} catch (DbException $e) {
				// Two status reports at the same time: the second updates.
				if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION || $attempt >= 2) {
					throw $e;
				}
			}
		}
	}

	/** @return list<array{kind:string,key:string}> the fresh `editing` of a status row, else empty */
	private static function editingOf(?ClientStatus $s, int $now): array {
		$at = $s?->getEditingAt();
		if ($s === null || $at === null || $at + StatusRules::EDITING_TTL < $now) {
			return [];
		}
		$v = json_decode((string)$s->getEditing(), true);
		return is_array($v) ? array_values(array_filter($v, static fn (mixed $e): bool => is_array($e) && isset($e['kind'], $e['key']))) : [];
	}

	/** @return list<array<string,mixed>> all members, even without a status report */
	public function list(Membership $m): array {
		$now = $this->time->getTime();
		$this->policy->requireTeamRead($m);
		$byUid = $this->status->findByTenant($m->tenantId);
		$consents = $this->consent->byTenant($m->tenantId);
		$named = $this->entries->namedByUid($m->tenantId);
		$out = [];
		$roles = $this->tenants->memberRoles($m->tenantId);
		foreach (array_map('strval', array_keys($roles)) as $uid) {
			$role = $roles[$uid];
			$s = $byUid[$uid] ?? null;
			$c = ConsentService::present($consents[$uid] ?? null);
			$tc = $named[$uid] ?? null;
			$out[] = [
				'uid' => $uid,
				'display_name' => $this->tenants->displayName($uid),
				'role' => $role,
				'app_version' => $s?->getAppVersion(),
				'last_sync' => Time::iso($s?->getLastSync()),
				'last_backup' => $s?->getLastBackup(),
				'calendar_url' => $s?->getCalendarUrl(),
				'seen_at' => $s === null ? null : Time::iso($s->getSeenAt()),
				'backup_consent' => $c['consent'],
				'backup_consent_since' => $c['since'],
				'backup_consent_notice' => $c['notice'],
				// Since 0.17.0: the new “who is missing”.
				'time_calendar' => $tc !== null,
				'entries_read_at' => Time::iso($tc?->getLastReadAt()),
				'entries_error' => $tc?->getLastError(),
				// 0.45.0: presence - what the person reported as open for editing, while the report is fresh.
				'editing' => self::editingOf($s, $now),
				'editing_at' => Time::iso($s?->getEditingAt()),
			];
		}
		return $out;
	}
}
