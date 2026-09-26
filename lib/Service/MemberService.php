<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\Member;
use OCA\TimeSister\Db\MemberMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;

/**
 * App-Rolle und Austritt setzen (Fassung 2). Ein Dienst für
 * PUT /team/members/{uid} und die Admin-Seite.
 */
final class MemberService {
	public function __construct(
		private MemberMapper $members,
		private TenantService $tenants,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @param string $actorRole Rolle des Aufrufers im Team; `admin` für Nextcloud-Admins auf der Admin-Seite
	 * @param array<string,mixed> $in `role` und/oder `left`
	 * @return array{uid:string,display_name:string,role:string,left_at:?string} der Eintrag wie in GET /team
	 */
	public function update(int $tenantId, string $actorUid, string $actorRole, string $uid, array $in): array {
		MemberRules::requireManager($actorRole);
		$change = MemberRules::validate($in);
		$current = $this->tenants->members($tenantId)[$uid] ?? null;
		if ($current === null) {
			throw ApiException::notFound('Dieses Konto steht nicht in der Teamgruppe.');
		}
		MemberRules::authorize($actorUid, $actorRole, $uid, $current['role'], $change);
		$now = $this->time->getTime();
		for ($attempt = 1; ; $attempt++) {
			$found = $this->members->findOne($tenantId, $uid);
			$m = $found ?? new Member();
			if ($found === null) {
				$m->setTenantId($tenantId);
				$m->setUid($uid);
			}
			if (array_key_exists('role', $change)) {
				// user wird nicht gespeichert: null heisst keine App-Rolle.
				$m->setRole($change['role'] === Role::USER ? null : $change['role']);
			}
			if (array_key_exists('left', $change)) {
				// Schon ausgetreten: das erste Datum bleibt.
				$m->setLeftAt($change['left'] ? ($m->getLeftAt() ?? $now) : null);
			}
			$m->setUpdatedBy($actorUid);
			$m->setUpdatedAt($now);
			try {
				$found === null ? $this->members->insert($m) : $this->members->update($m);
				break;
			} catch (DbException $e) {
				// Zwei Änderungen gleichzeitig: die zweite aktualisiert.
				if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION || $attempt >= 2) {
					throw $e;
				}
			}
		}
		$this->tenants->reset();
		return $this->tenants->presentMember($uid, $this->tenants->members($tenantId)[$uid] ?? $current);
	}
}
