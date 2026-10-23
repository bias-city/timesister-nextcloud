<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * Reads a team backup: manifest and checksums checked, every part brought
 * into a checked form. Pure, so the checks are testable without a server.
 */
final class ZipReader {
	/**
	 * @return array{manifest:array<string,mixed>,team:array<string,mixed>,records:list<array<string,mixed>>,status:array<string,array<string,mixed>>,absences:array<string,list<array{source_uid:string,kind:string,start:string,end:string}>>,calendars:list<array{uid:string,path:string,size:int,missing:bool,list:bool}>,template:?array{meta:array{name:string,size:int,sha256:string,modified_at:string,modified_by:string},path:string}}
	 */
	public static function read(ZipFile $zip): array {
		$present = $zip->entries();
		if (!isset($present[ZipRules::MANIFEST])) {
			throw ApiException::invalid('This is not a TimeSister team backup, or its format is newer than this app.');
		}
		try {
			$manifest = ZipRules::checkManifest(json_decode($zip->read(ZipRules::MANIFEST), true));
		} catch (\JsonException) {
			throw ApiException::invalid('This is not a TimeSister team backup, or its format is newer than this app.');
		}
		unset($present[ZipRules::MANIFEST]);
		ZipRules::verify($manifest['files'], $present, static fn (string $p): string => $zip->read($p));
		if (!isset($present[ZipRules::TEAM])) {
			throw ApiException::invalid('The backup has no team.json.');
		}
		$records = [];
		foreach (array_map('strval', array_keys($present)) as $p) {
			if (str_starts_with($p, ZipRules::RECORDS) && str_ends_with($p, '.json')) {
				$records[] = ZipRules::checkRecordFile($zip->readJson($p));
			}
		}
		$calendars = [];
		foreach ($manifest['files'] as $p => $e) {
			if (str_starts_with($p, ZipRules::CALENDARS)) {
				$uid = $e['uid'] ?? substr($p, strlen(ZipRules::CALENDARS), -4);
				$calendars[] = ['uid' => $uid, 'path' => $p, 'size' => $e['size'] ?? 0, 'missing' => $e['missing'], 'list' => $e['list'] ?? false];
			}
		}
		return [
			'manifest' => $manifest,
			'team' => self::team($zip->readJson(ZipRules::TEAM)),
			'records' => $records,
			'status' => isset($present[ZipRules::STATUS]) ? self::status($zip->readJson(ZipRules::STATUS)) : [],
			'absences' => isset($present[ZipRules::ABSENCES]) ? self::absences($zip->readJson(ZipRules::ABSENCES)) : [],
			'calendars' => $calendars,
			'template' => self::template($zip, $present),
		];
	}

	/**
	 * The invoice template (0.16.0): file and meta together, the meta
	 * matching the file; null when the backup has none (older ones).
	 *
	 * @param array<string,int> $present
	 * @return ?array{meta:array{name:string,size:int,sha256:string,modified_at:string,modified_by:string},path:string}
	 */
	private static function template(ZipFile $zip, array $present): ?array {
		$file = isset($present[ZipRules::TEMPLATE]);
		if (!$file && !isset($present[ZipRules::TEMPLATE_META])) {
			return null;
		}
		$meta = $file && isset($present[ZipRules::TEMPLATE_META])
			? InvoiceTemplateRules::readMeta($zip->readJson(ZipRules::TEMPLATE_META))
			: null;
		if ($meta === null || $meta['size'] !== $present[ZipRules::TEMPLATE]
			|| hash('sha256', $zip->read(ZipRules::TEMPLATE)) !== $meta['sha256']) {
			throw self::bad(ZipRules::TEMPLATE_META);
		}
		return ['meta' => $meta, 'path' => ZipRules::TEMPLATE];
	}

	/**
	 * Every account the backup names: members, consents, status, absences.
	 *
	 * @param array<string,mixed> $b from {@see read}
	 * @return list<string>
	 */
	public static function accounts(array $b): array {
		$out = [];
		foreach ($b['team']['members'] as $m) {
			$out[$m['uid']] = true;
		}
		foreach ($b['team']['consents'] as $c) {
			$out[$c['uid']] = true;
		}
		foreach (array_map('strval', array_keys($b['status'])) as $uid) {
			$out[$uid] = true;
		}
		foreach (array_map('strval', array_keys($b['absences'])) as $uid) {
			$out[$uid] = true;
		}
		foreach ($b['calendars'] as $c) {
			$out[$c['uid']] = true;
		}
		foreach ($b['team']['time_calendars'] as $c) {
			$out[$c['uid']] = true;
		}
		return array_map('strval', array_keys($out));
	}

	private static function bad(string $what): ApiException {
		return ApiException::invalid(Message::of('“{file}” in the backup is malformed.', ['file' => $what]));
	}

	/** A JSON object or list as rows. @return list<\stdClass> */
	private static function rows(mixed $v, string $what): array {
		if ($v === null) {
			return [];
		}
		if ($v instanceof \stdClass) {
			$v = get_object_vars($v);
		}
		if (!is_array($v)) {
			throw self::bad($what);
		}
		$out = [];
		foreach ($v as $e) {
			if (!($e instanceof \stdClass)) {
				throw self::bad($what);
			}
			$out[] = $e;
		}
		return $out;
	}

	/** A JSON object keyed by account. @return array<string,\stdClass|array> */
	private static function byUid(mixed $v, string $what): array {
		if ($v === null || (is_array($v) && $v === [])) {
			return [];
		}
		if (!($v instanceof \stdClass)) {
			throw self::bad($what);
		}
		$out = [];
		foreach (get_object_vars($v) as $uid => $e) {
			if (!($e instanceof \stdClass) && !is_array($e)) {
				throw self::bad($what);
			}
			$out[$uid] = $e;
		}
		return $out;
	}

	private static function str(mixed $v): ?string {
		return is_string($v) ? $v : null;
	}

	private static function at(mixed $v): ?int {
		return is_string($v) ? Time::parseIso($v) : null;
	}

	/** @return array<string,mixed> */
	private static function team(mixed $t): array {
		if (!($t instanceof \stdClass) || !is_string($t->name ?? null)) {
			throw self::bad(ZipRules::TEAM);
		}
		$members = [];
		foreach (self::rows($t->members ?? null, ZipRules::TEAM) as $m) {
			$uid = self::str($m->uid ?? null);
			$role = self::str($m->role ?? null);
			if ($uid === null || $uid === '' || !in_array($role, Role::ALL, true)) {
				throw self::bad(ZipRules::TEAM);
			}
			$members[] = [
				'uid' => $uid,
				'display_name' => self::str($m->display_name ?? null) ?? $uid,
				'role' => $role,
				'left_at' => self::at($m->left_at ?? null),
			];
		}
		// `access` (the shares matrix, until 0.16.x) is ignored since 0.17.0.
		$consents = [];
		foreach (self::rows($t->consents ?? null, ZipRules::TEAM) as $c) {
			$uid = self::str($c->uid ?? null);
			if ($uid === null || $uid === '') {
				throw self::bad(ZipRules::TEAM);
			}
			$notice = self::str($c->notice ?? null);
			$consents[] = ['uid' => $uid, 'consent' => ($c->consent ?? false) === true, 'since' => self::at($c->since ?? null),
				'revoked_at' => self::at($c->revoked_at ?? null), 'notice' => $notice === null ? null : mb_substr($notice, 0, 32),
				'notice_at' => self::at($c->notice_at ?? null)];
		}
		// 0.17.0: the cutoff and the named time calendars; older ZIPs have neither.
		$settings = $t->settings ?? null;
		$since = $settings instanceof \stdClass ? ($settings->entries_since ?? null) : null;
		if ($since !== null && (!is_string($since) || !Time::isDay($since))) {
			throw self::bad(ZipRules::TEAM);
		}
		$timeCalendars = [];
		foreach (self::rows($t->time_calendars ?? null, ZipRules::TEAM) as $c) {
			$uid = self::str($c->uid ?? null);
			$uri = self::str($c->calendar_uri ?? null);
			if ($uid === null || $uid === '' || $uri === null || EntryRules::calendarUri($uri) !== $uri) {
				throw self::bad(ZipRules::TEAM);
			}
			$timeCalendars[] = ['uid' => $uid, 'calendar_uri' => $uri];
		}
		return [
			'name' => $t->name,
			'slug' => self::str($t->slug ?? null) ?? '',
			'backup_owner' => self::str($t->backup_owner ?? null),
			'members' => $members,
			'consents' => $consents,
			'settings' => ['entries_since' => $since],
			'time_calendars' => $timeCalendars,
		];
	}

	/** @return array<string,array<string,mixed>> */
	private static function status(mixed $v): array {
		$out = [];
		foreach (self::byUid($v, ZipRules::STATUS) as $uid => $s) {
			if (!($s instanceof \stdClass)) {
				throw self::bad(ZipRules::STATUS);
			}
			$weeks = $s->job_weeks ?? null;
			$out[$uid] = [
				'seen_at' => self::at($s->seen_at ?? null),
				'app_version' => self::str($s->app_version ?? null),
				'last_sync' => self::at($s->last_sync ?? null),
				'calendar_url' => self::str($s->calendar_url ?? null),
				'job_weeks' => $weeks === null ? null : json_encode($weeks, Json::FLAGS),
				'job_weeks_at' => self::at($s->job_weeks_at ?? null),
			];
		}
		return $out;
	}

	/** @return array<string,list<array{source_uid:string,kind:string,start:string,end:string}>> */
	private static function absences(mixed $v): array {
		$out = [];
		foreach (self::byUid($v, ZipRules::ABSENCES) as $uid => $items) {
			$list = [];
			foreach (self::rows($items, ZipRules::ABSENCES) as $i) {
				$row = ['source_uid' => self::str($i->source_uid ?? null), 'kind' => self::str($i->kind ?? null),
					'start' => self::str($i->start ?? null), 'end' => self::str($i->end ?? null)];
				foreach ($row as $f) {
					if ($f === null || $f === '' || strlen($f) > 255 || preg_match('/[\x00-\x1F\x7F]/', $f)) {
						throw self::bad(ZipRules::ABSENCES);
					}
				}
				/** @var array{source_uid:string,kind:string,start:string,end:string} $row */
				$list[] = $row;
			}
			if ($list !== []) {
				$out[$uid] = $list;
			}
		}
		return $out;
	}
}
