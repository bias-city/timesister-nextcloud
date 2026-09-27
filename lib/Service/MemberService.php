<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\Member;
use OCA\TimeSister\Db\MemberMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;

/**
 * Setting app role and leaving date (API version 2). One service for
 * PUT /team/members/{uid} and the admin page.
 */
final class MemberService {
	public function __construct(
		private MemberMapper $members,
		private TenantService $tenants,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @param string $actorRole the caller's role in the team; `admin` for Nextcloud admins on the admin page
	 * @param array<string,mixed> $in `role` and/or `left`
	 * @return array{uid:string,display_name:string,role:string,left_at:?string} the entry as in GET /team
	 */
	public function update(int $tenantId, string $actorUid, string $actorRole, string $uid, array $in): array {
		MemberRules::requireManager($actorRole);
		$change = MemberRules::validate($in);
		$current = $this->tenants->members($tenantId)[$uid] ?? null;
		if ($current === null) {
			throw ApiException::notFound('This account is not in the team group.');
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
				// user is not stored: null means no app role.
				$m->setRole($change['role'] === Role::USER ? null : $change['role']);
			}
			if (array_key_exists('left', $change)) {
				// Already left: the first date stays.
				$m->setLeftAt($change['left'] ? ($m->getLeftAt() ?? $now) : null);
			}
			$m->setUpdatedBy($actorUid);
			$m->setUpdatedAt($now);
			try {
				$found === null ? $this->members->insert($m) : $this->members->update($m);
				break;
			} catch (DbException $e) {
				// Two changes at the same time: the second updates.
				if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION || $attempt >= 2) {
					throw $e;
				}
			}
		}
		$this->tenants->reset();
		return $this->tenants->presentMember($uid, $this->tenants->members($tenantId)[$uid] ?? $current);
	}
}
