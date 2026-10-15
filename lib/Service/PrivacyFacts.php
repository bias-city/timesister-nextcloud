<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\AppInfo\Application;
use OCA\TimeSister\Db\Record;
use OCA\TimeSister\Db\RecordMapper;
use OCA\TimeSister\Db\Tenant;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * What the privacy notice (0.10.2) says about a team, read live: members
 * and roles, Leads with their projects, backup owner,
 * consents, modules, absence categories, externals (count only, never a
 * feed address). {@see PrivacyDocument} turns this into the text.
 */
final class PrivacyFacts {
	public function __construct(
		private TenantService $tenants,
		private RecordMapper $records,
		private ConsentService $consents,
		private IAppManager $appManager,
		private ITimeFactory $time,
	) {
	}

	/** @return array<string,mixed> */
	public function collect(Tenant $t): array {
		$tid = $t->getId();
		$all = $this->tenants->members($tid);
		$roles = $this->tenants->memberRoles($tid);
		$name = fn (string $uid): string => $this->tenants->displayName($uid);

		$members = [];
		foreach (array_map('strval', array_keys($all)) as $uid) {
			$members[] = ['uid' => $uid, 'name' => $name($uid), 'role' => $all[$uid]['role'], 'left_at' => Time::iso($all[$uid]['left_at'])];
		}
		usort($members, static fn (array $a, array $b) => [$a['left_at'] !== null, -Role::rank($a['role']), mb_strtolower($a['name'])]
			<=> [$b['left_at'] !== null, -Role::rank($b['role']), mb_strtolower($b['name'])]);

		$projects = $this->records->findLiveByKind($tid, 'project');
		// Leads follow from the active projects (0.11.0); only members who have not left.
		$leads = [];
		foreach ($this->tenants->withRole($tid, Role::LEAD) as $uid) {
			$led = array_map(static fn (array $p): string => $p['name'], $this->tenants->leadOf($tid, $uid));
			sort($led, SORT_STRING | SORT_FLAG_CASE);
			$leads[] = ['uid' => $uid, 'name' => $name($uid), 'projects' => $led];
		}

		$yes = 0;
		$consents = $this->consents->byTenant($tid);
		foreach (array_map('strval', array_keys($roles)) as $uid) {
			if (ConsentService::granted($consents[$uid] ?? null)) {
				$yes++;
			}
		}
		$owner = $this->tenants->backupOwner($t);
		$settingsRecord = $this->records->findOne($tid, 'setting', 'settings');
		$settings = self::data($settingsRecord);
		$privacy = self::data($this->records->findOne($tid, 'setting', PrivacyRules::KEY));

		return [
			'team' => ['id' => $tid, 'name' => $t->getName(), 'slug' => $t->getSlug(), 'group' => $this->tenants->teamGroupOf($tid) ?? ''],
			'app_version' => $this->appManager->getAppVersion(Application::APP_ID),
			'date' => gmdate('Y-m-d', $this->time->getTime()),
			'settings' => PrivacyRules::normalize($privacy),
			'members' => $members,
			'admins' => array_map($name, $this->tenants->adminsOf($tid)),
			'leads' => $leads,
			'backup_owner' => $owner === null ? null : ['uid' => $owner, 'name' => $name($owner)],
			'consents' => ['yes' => $yes, 'no' => max(0, count($roles) - $yes)],
			'modules' => $this->modules($tid, $settings, $projects),
			'absence_kinds' => AbsenceRules::kinds($settings),
			'vacation_calendar' => AbsenceRules::vacationCalendar($settings) !== null,
			'externals' => $this->externals($tid, $roles),
			'customers' => count($this->records->findLiveByKind($tid, 'customer')),
		];
	}

	/**
	 * External persons: live person records with a non-empty `feed` that
	 * belong to no active member. Count only, never an address.
	 *
	 * @param array<string,string> $roles uid → role, active members
	 */
	private function externals(int $tid, array $roles): int {
		$n = 0;
		$uids = array_map('strval', array_keys($roles));
		foreach ($this->records->findLiveByKind($tid, 'person') as $r) {
			$d = self::data($r);
			$feed = $d?->feed ?? null;
			if (!is_string($feed) || trim($feed) === '') {
				continue;
			}
			if (isset($roles[$r->getRkey()]) || array_intersect($r->accountList(), $uids) !== []) {
				continue;
			}
			$n++;
		}
		return $n;
	}

	private static function data(?Record $r): ?\stdClass {
		$raw = $r?->getData();
		if ($r === null || $r->isTombstone() || $raw === null) {
			return null;
		}
		$d = Json::decode($raw);
		return $d instanceof \stdClass ? $d : null;
	}

	/**
	 * `settings.modules` as the Mac app keeps it ({jobs, budgets,
	 * customers}); a switch that is not set counts as on when records of
	 * that module exist.
	 *
	 * @param list<Record> $projects
	 * @return array{jobs:bool,budgets:bool,customers:bool}
	 */
	private function modules(int $tid, ?\stdClass $settings, array $projects): array {
		$m = $settings?->modules ?? null;
		$flag = static function (string ...$keys) use ($m): ?bool {
			foreach ($keys as $k) {
				$v = $m instanceof \stdClass ? ($m->{$k} ?? null) : null;
				if (is_bool($v)) {
					return $v;
				}
			}
			return null;
		};
		$withBudgets = static function () use ($projects): bool {
			foreach ($projects as $p) {
				$d = self::data($p);
				if (is_array($d?->budgets ?? null) && ($d->budgets ?? []) !== []) {
					return true;
				}
			}
			return false;
		};
		return [
			'jobs' => $flag('jobs') ?? $this->records->findLiveByKind($tid, RecordValidator::JOB) !== [],
			'budgets' => $flag('budgets') ?? $withBudgets(),
			'customers' => $flag('customers', 'kunden') ?? $this->records->findLiveByKind($tid, 'customer') !== [],
		];
	}

	/**
	 * The names of the active projects whose `leads` name this account –
	 * by identifier or by a person record that carries it in `accounts`
	 * ({@see LeadResolver}).
	 *
	 * @param list<Record> $persons
	 * @param list<Record> $projects
	 * @return list<string>
	 */
	public static function projectsLedBy(string $uid, array $persons, array $projects): array {
		$out = array_map(static fn (array $p): string => $p['name'], LeadResolver::leadsByUid($persons, $projects)[$uid] ?? []);
		sort($out, SORT_STRING | SORT_FLAG_CASE);
		return $out;
	}
}
