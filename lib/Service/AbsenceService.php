<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\AppInfo\Application;
use OCA\TimeSister\Db\AbsenceMapper;
use OCA\TimeSister\Db\Record;
use OCA\TimeSister\Db\RecordMapper;
use OCA\TimeSister\Db\TenantMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Calendar\CalendarExportOptions;
use OCP\Calendar\ICalendarExport;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/**
 * The shared vacation calendar: the clients report their absences
 * (`PUT /me/absences`), the background job writes them into the Team
 * Admin's calendar named in the settings record (`vacation_calendar`),
 * filtered by `absence_calendar_kinds`. Rules in `AbsenceRules`.
 */
final class AbsenceService {
	public function __construct(
		private AbsenceMapper $absences,
		private RecordMapper $records,
		private TenantMapper $tenants,
		private TenantService $team,
		private IManager $calendars,
		private IFactory $l10n,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The complete state of the calling person's absences; with opt-out
	 * nothing is stored and what was there goes.
	 *
	 * @return array{stored:int,opted_out:bool}
	 */
	public function replace(Membership $m, \stdClass $body): array {
		if (!property_exists($body, 'items')) {
			throw ApiException::missing('items');
		}
		$items = AbsenceRules::parseItems($body->items);
		$person = $this->personOf($m->uid, $this->persons($m->tenantId));
		if (AbsenceRules::optedOut($person)) {
			$this->absences->deleteFor($m->tenantId, $m->uid);
			return ['stored' => 0, 'opted_out' => true];
		}
		$this->absences->replace($m->tenantId, $m->uid, $items, $this->time->getTime());
		return ['stored' => count($items), 'opted_out' => false];
	}

	/**
	 * Every team with a vacation calendar: write what changed.
	 *
	 * @return array{teams:int,written:int,cancelled:int,failed:int}
	 */
	public function mirrorAll(): array {
		$n = ['teams' => 0, 'written' => 0, 'cancelled' => 0, 'failed' => 0];
		foreach ($this->tenants->findAll() as $t) {
			try {
				$r = $this->mirror($t->getId());
				if ($r === null) {
					continue;
				}
				$n['teams']++;
				$n['written'] += $r['written'];
				$n['cancelled'] += $r['cancelled'];
			} catch (\Throwable $e) {
				$n['failed']++;
				$this->logger->error('TimeSister: vacation calendar of team {team} not written', [
					'app' => 'timesister', 'team' => $t->getId(), 'exception' => $e,
				]);
			}
		}
		return $n;
	}

	/**
	 * One team. Null: no vacation calendar configured, or the owner's
	 * calendar is not there (deleted, or the owner left).
	 *
	 * @return ?array{written:int,cancelled:int}
	 */
	public function mirror(int $tenantId): ?array {
		$settings = $this->settings($tenantId);
		$vc = AbsenceRules::vacationCalendar($settings);
		if ($vc === null) {
			return null;
		}
		// Checked again here: the job has no rights context, so it must never enter a foreign account.
		try {
			AbsenceRules::checkVacationCalendar($vc, $this->team->memberRoles($tenantId), false);
		} catch (ApiException $e) {
			$this->logger->warning('TimeSister: vacation calendar of team {team} skipped – {reason}', ['app' => 'timesister', 'team' => $tenantId, 'reason' => $e->getText()->text()]);
			return null;
		}
		$cal = $this->calendar($vc['owner'], $vc['uri']);
		if ($cal === null) {
			$this->logger->info('TimeSister: vacation calendar of team {team} not found in the owner’s account', ['app' => 'timesister', 'team' => $tenantId]);
			return null;
		}
		$persons = $this->persons($tenantId);
		$rows = array_map(static fn ($a) => $a->row(), $this->absences->byTenant($tenantId));
		$names = [];
		$optedOut = [];
		foreach (array_unique(array_column($rows, 'uid')) as $uid) {
			$p = $this->personOf($uid, $persons);
			$names[$uid] = AbsenceRules::personName($p, $uid);
			if (AbsenceRules::optedOut($p)) {
				$optedOut[$uid] = true;
			}
		}
		$l = $this->l10n->get(Application::APP_ID, $this->l10n->findGenericLanguage(Application::APP_ID));
		$labels = [];
		foreach (AbsenceRules::KINDS as $k) {
			$labels[$k] = AbsenceRules::label($l, $k);
		}
		$existing = [];
		foreach ($cal->export(new CalendarExportOptions()) as $vcal) {
			$e = AbsenceRules::parseMirror((string)$vcal->serialize());
			if ($e !== null) {
				$existing[] = $e;
			}
		}
		$plan = AbsenceRules::plan($rows, AbsenceRules::kinds($settings), $names, $optedOut, $existing, $labels);
		$stamp = gmdate('Ymd\THis\Z', $this->time->getTime());
		foreach ($plan['write'] as $r) {
			$name = $names[$r['uid']] ?? AbsenceRules::personName($this->personOf($r['uid'], $persons), $r['uid']);
			$cal->createFromString(AbsenceRules::objectName(AbsenceRules::eventUid($r['uid'], $r['source_uid'], $r['kind'])),
				AbsenceRules::ics($r, $labels[$r['kind']] ?? $r['kind'], $name, $stamp));
		}
		foreach ($plan['cancel'] as $r) {
			$name = AbsenceRules::personName($this->personOf($r['uid'], $persons), $r['uid']);
			$cal->createFromString(AbsenceRules::objectName(AbsenceRules::eventUid($r['uid'], $r['source_uid'], $r['kind'])),
				AbsenceRules::ics($r, $labels[$r['kind']] ?? $r['kind'], $name, $stamp, true));
		}
		return ['written' => count($plan['write']), 'cancelled' => count($plan['cancel'])];
	}

	/** The owner's calendar by URI – writable and exportable, or null. */
	private function calendar(string $owner, string $uri): (ICreateFromString&ICalendarExport)|null {
		foreach ($this->calendars->getCalendarsForPrincipal('principals/users/' . $owner, [$uri]) as $cal) {
			if ($cal instanceof ICreateFromString && $cal instanceof ICalendarExport && $cal->getUri() === $uri && !$cal->isDeleted()) {
				return $cal;
			}
		}
		return null;
	}

	private function settings(int $tenantId): ?\stdClass {
		$r = $this->records->findOne($tenantId, 'setting', 'settings');
		return $r === null || $r->getDeleted() === 1 ? null : self::data($r);
	}

	/** @return list<Record> */
	private function persons(int $tenantId): array {
		return $this->records->findLiveByKind($tenantId, 'person');
	}

	/**
	 * The person record of an account: key = identifier, else `accounts`
	 * names it.
	 *
	 * @param list<Record> $persons
	 */
	private function personOf(string $uid, array $persons): ?\stdClass {
		foreach ($persons as $p) {
			if ($p->getRkey() === $uid) {
				return self::data($p);
			}
		}
		foreach ($persons as $p) {
			$accounts = Json::decode($p->getAccounts() ?? '[]');
			if (is_array($accounts) && in_array($uid, $accounts, true)) {
				return self::data($p);
			}
		}
		return null;
	}

	private static function data(Record $r): ?\stdClass {
		try {
			$d = Json::decode($r->getData() ?? 'null');
		} catch (\JsonException) {
			return null;
		}
		return $d instanceof \stdClass ? $d : null;
	}
}
