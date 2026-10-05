<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The team backup as a ZIP (0.10.0): names, paths, manifest, account
 * mapping and the merge/replace decision. Pure, without Nextcloud.
 */
final class ZipRules {
	public const FORMAT = 1;
	public const MAX_ZIP = 200 * 1024 * 1024;
	public const MAX_ENTRY = 50 * 1024 * 1024;
	public const MAX_TOTAL = 600 * 1024 * 1024;
	public const MAX_ENTRIES = 20000;
	public const MANIFEST = 'manifest.json';
	public const TEAM = 'team.json';
	public const STATUS = 'status.json';
	public const ABSENCES = 'absences.json';
	public const RECORDS = 'records/';
	public const CALENDARS = 'calendars/';
	public const MODE_MERGE = 'merge';
	public const MODE_REPLACE = 'replace';
	public const TOKEN_PATTERN = '/^[0-9a-f]{32}$/';
	/** Uploads older than this are removed before the next preview. */
	public const TOKEN_TTL = 86400;

	/** Fields whose string value is an account; lists of accounts; `names` is keyed by account. */
	private const UID_FIELDS = ['login', 'assignee', 'sender', 'by', 'person', 'uid', 'owner', 'modified_by'];
	private const UID_LISTS = ['accounts', 'recipients', 'declined_by', 'leads'];

	public static function stamp(int $now): string {
		return gmdate('Y-m-d_Hi', $now);
	}

	public static function zipName(string $slug, int $now): string {
		return 'timesister-' . $slug . '-' . self::stamp($now) . '.zip';
	}

	/** Relative, no `.`/`..`/empty segments, no backslash or control characters, at most 250 characters. */
	public static function isSafePath(string $p): bool {
		if ($p === '' || strlen($p) > 250 || str_starts_with($p, '/') || str_contains($p, '\\')
			|| preg_match('/[\x00-\x1F\x7F]/', $p)) {
			return false;
		}
		foreach (explode('/', $p) as $seg) {
			if ($seg === '' || $seg === '.' || $seg === '..') {
				return false;
			}
		}
		return true;
	}

	public static function recordPath(string $kind, string $key): string {
		return self::RECORDS . $kind . '/' . $key . '.json';
	}

	/** A harmless identifier as it is, otherwise a hash; the manifest carries the account. */
	public static function calendarPath(string $uid): string {
		$name = preg_match('/^[A-Za-z0-9_@.-]{1,64}$/', $uid) ? $uid : 'h-' . hash('sha256', $uid);
		return self::CALENDARS . $name . '.ics';
	}

	/**
	 * @param array{id:int,name:string,slug:string,group:?string} $team
	 * @param array<string,array<string,mixed>> $files path → {sha256, size, …}
	 * @return array<string,mixed>
	 */
	public static function manifest(array $team, string $appVersion, int $api, int $now, array $files): array {
		return [
			'format' => self::FORMAT,
			'app_version' => $appVersion,
			'api' => $api,
			'team' => $team,
			'taken_at' => Time::iso($now),
			'contains_secrets' => true,
			'files' => $files,
		];
	}

	/**
	 * @param array<string,mixed> $entry
	 * @return array<string,mixed>
	 */
	public static function fileEntry(string $content, array $entry = []): array {
		return ['sha256' => hash('sha256', $content), 'size' => strlen($content)] + $entry;
	}

	/**
	 * The manifest as decoded: format known, team and files well-formed.
	 *
	 * @return array{format:int,app_version:string,api:int,team:array{id:int,name:string,slug:string,group:?string},taken_at:string,files:array<string,array{sha256:?string,size:?int,uid:?string,missing:bool}>}
	 */
	public static function checkManifest(mixed $m): array {
		if (!is_array($m) || ($m['format'] ?? null) !== self::FORMAT) {
			throw ApiException::invalid('This is not a TimeSister team backup, or its format is newer than this app.');
		}
		$team = $m['team'] ?? null;
		$takenAt = $m['taken_at'] ?? null;
		$files = $m['files'] ?? null;
		if (!is_array($team) || !is_string($team['name'] ?? null) || !is_string($team['slug'] ?? null)
			|| !is_string($takenAt) || Time::parseIso($takenAt) === null || !is_array($files)) {
			throw ApiException::invalid('The manifest of the backup is incomplete.');
		}
		$out = [];
		foreach ($files as $path => $e) {
			if (!is_string($path) || !self::isSafePath($path) || !is_array($e)) {
				throw ApiException::invalid('The manifest of the backup lists an invalid path.');
			}
			$missing = ($e['missing'] ?? false) === true;
			$sha = $e['sha256'] ?? null;
			$size = $e['size'] ?? null;
			if (!$missing && (!is_string($sha) || !preg_match('/^[0-9a-f]{64}$/', $sha) || !is_int($size) || $size < 0)) {
				throw ApiException::invalid('The manifest of the backup lists an invalid checksum.');
			}
			$uid = $e['uid'] ?? null;
			$out[$path] = [
				'sha256' => is_string($sha) ? $sha : null,
				'size' => is_int($size) ? $size : null,
				'uid' => is_string($uid) ? $uid : null,
				'missing' => $missing,
			];
		}
		return [
			'format' => self::FORMAT,
			'app_version' => is_string($m['app_version'] ?? null) ? $m['app_version'] : '',
			'api' => is_int($m['api'] ?? null) ? $m['api'] : 0,
			'team' => [
				'id' => is_int($team['id'] ?? null) ? $team['id'] : 0,
				'name' => $team['name'],
				'slug' => $team['slug'],
				'group' => is_string($team['group'] ?? null) ? $team['group'] : null,
			],
			'taken_at' => $takenAt,
			'files' => $out,
		];
	}

	/**
	 * Every listed file has the right size and checksum; nothing in the
	 * ZIP is unlisted. The manifest itself is not listed.
	 *
	 * @param array<string,array{sha256:?string,size:?int,uid:?string,missing:bool}> $listed
	 * @param array<string,int> $present path → size, without the manifest
	 * @param callable(string):string $read
	 */
	public static function verify(array $listed, array $present, callable $read): void {
		foreach ($listed as $path => $e) {
			if ($e['missing']) {
				continue;
			}
			if (!isset($present[$path]) || $present[$path] !== $e['size']) {
				throw ApiException::invalid(Message::of('The backup is incomplete: “{file}” is missing or has the wrong size.', ['file' => $path]));
			}
			if (hash('sha256', $read($path)) !== $e['sha256']) {
				throw ApiException::invalid(Message::of('The checksum of “{file}” does not match the manifest.', ['file' => $path]));
			}
		}
		foreach (array_map('strval', array_keys($present)) as $path) {
			if (!isset($listed[$path]) || $listed[$path]['missing']) {
				throw ApiException::invalid(Message::of('The backup contains a file the manifest does not list: “{file}”.', ['file' => $path]));
			}
		}
	}

	public static function checkMode(mixed $mode): string {
		if (!in_array($mode, [self::MODE_MERGE, self::MODE_REPLACE], true)) {
			throw ApiException::badRequest('“mode” must be “merge” or “replace”.');
		}
		return $mode;
	}

	public static function checkToken(mixed $token): string {
		if (!is_string($token) || !preg_match(self::TOKEN_PATTERN, $token)) {
			throw ApiException::badRequest('“token” is invalid. Upload the backup again for a preview.');
		}
		return $token;
	}

	/**
	 * `mapping`: old account → new account or null (without account). Only
	 * accounts of the backup may be mapped; unmapped ones keep their name.
	 *
	 * @param list<string> $uids accounts in the backup
	 * @return array<string,?string>
	 */
	public static function checkMapping(mixed $in, array $uids): array {
		if ($in === null) {
			return [];
		}
		if ($in instanceof \stdClass) {
			$in = get_object_vars($in);
		}
		if (!is_array($in)) {
			throw ApiException::badRequest('“mapping” must be an object: old account → new account or null.');
		}
		$out = [];
		foreach ($in as $old => $new) {
			$old = (string)$old;
			if (!in_array($old, $uids, true)) {
				throw ApiException::invalid(Message::of('“{uid}” is not an account of the backup.', ['uid' => $old]));
			}
			if ($new !== null && (!is_string($new) || $new === '' || strlen($new) > 64 || preg_match('/[\x00-\x1F\x7F\/\\\\]/', $new))) {
				throw ApiException::invalid(Message::of('The account for “{uid}” is invalid.', ['uid' => $old]));
			}
			$out[$old] = $new;
		}
		$targets = array_filter($out, 'is_string');
		if (count($targets) !== count(array_unique($targets))) {
			throw ApiException::invalid('Two accounts of the backup cannot become the same account here.');
		}
		return $out;
	}

	/** Mapped name; null when the account falls away; unmapped stays. */
	public static function mapUid(string $uid, array $mapping): ?string {
		return array_key_exists($uid, $mapping) ? $mapping[$uid] : $uid;
	}

	/** Mapped name, but a dropped account keeps its name (the record stays). */
	public static function keepUid(string $uid, array $mapping): string {
		return self::mapUid($uid, $mapping) ?? $uid;
	}

	/** Person keys follow the account; billing keys carry the person in front of `+`. */
	public static function mapKey(string $kind, string $key, array $mapping): string {
		if ($kind === 'person') {
			return self::keepUid($key, $mapping);
		}
		if ($kind === RecordValidator::BILLING) {
			$person = BillingRules::personOf($key);
			if ($person !== null) {
				return self::keepUid($person, $mapping) . substr($key, strlen($person));
			}
		}
		return $key;
	}

	/**
	 * Rewrites the accounts inside a record (or any JSON value). In lists
	 * a dropped account disappears, scalar fields keep the old name.
	 */
	public static function mapData(mixed $data, array $mapping, ?string $parent = null): mixed {
		if ($mapping === []) {
			return $data;
		}
		if ($data instanceof \stdClass) {
			$out = new \stdClass();
			foreach (get_object_vars($data) as $k => $v) {
				if ($k === 'names' && $v instanceof \stdClass) {
					$names = new \stdClass();
					foreach (get_object_vars($v) as $uid => $name) {
						$names->{self::keepUid($uid, $mapping)} = $name;
					}
					$out->{$k} = $names;
				} elseif (is_string($v) && in_array($k, self::UID_FIELDS, true)) {
					$out->{$k} = self::keepUid($v, $mapping);
				} elseif ($k === 'url' && $parent === 'vacation_calendar' && is_string($v)) {
					$out->{$k} = self::mapCalendarUrl($v, $mapping);
				} elseif (is_array($v) && (in_array($k, self::UID_LISTS, true) || ($k === 'to' && $parent === 'log'))) {
					$out->{$k} = self::mapList($v, $mapping);
				} else {
					$out->{$k} = self::mapData($v, $mapping, $k);
				}
			}
			return $out;
		}
		if (is_array($data)) {
			return array_map(fn (mixed $v): mixed => self::mapData($v, $mapping, $parent), $data);
		}
		return $data;
	}

	/** `…/calendars/<old>/…` → the new owner's path. */
	private static function mapCalendarUrl(string $url, array $mapping): string {
		foreach ($mapping as $old => $new) {
			if ($new !== null) {
				$url = str_replace('/calendars/' . rawurlencode((string)$old) . '/', '/calendars/' . rawurlencode($new) . '/', $url);
			}
		}
		return $url;
	}

	/** @return list<mixed> */
	private static function mapList(array $list, array $mapping): array {
		$out = [];
		foreach ($list as $v) {
			if (!is_string($v)) {
				$out[] = $v;
				continue;
			}
			$new = self::mapUid($v, $mapping);
			if ($new !== null && !in_array($new, $out, true)) {
				$out[] = $new;
			}
		}
		return $out;
	}

	/**
	 * What to do with a record of the backup at the target: `insert`
	 * (missing there), `update` (write the source as a new version) or
	 * `skip`. Merge takes the source only when it is younger; replace
	 * whenever it differs.
	 *
	 * @param ?array{modified_at:int,deleted:bool,data:?string} $target
	 * @param array{modified_at:int,deleted:bool,data:?string} $source
	 */
	public static function decide(?array $target, array $source, string $mode): string {
		if ($target === null) {
			return 'insert';
		}
		$same = $target['deleted'] === $source['deleted'] && $target['data'] === $source['data'];
		if ($same) {
			return 'skip';
		}
		if ($mode === self::MODE_MERGE && $source['modified_at'] <= $target['modified_at']) {
			return 'skip';
		}
		return 'update';
	}

	/**
	 * A record file as the import reads it: address, current state,
	 * accounts and versions checked in form.
	 *
	 * @return array{kind:string,key:string,version:int,deleted:bool,modified_by:string,modified_at:int,data:mixed,accounts:list<string>,history:list<array{version:int,deleted:bool,modified_by:string,modified_at:int,data:mixed}>}
	 */
	public static function checkRecordFile(mixed $r): array {
		$bad = static fn (): ApiException => ApiException::invalid('A record in the backup is malformed.');
		if (!($r instanceof \stdClass) || !is_string($r->kind ?? null) || !is_string($r->key ?? null)) {
			throw $bad();
		}
		RecordValidator::checkAddress($r->kind, $r->key);
		$row = static function (\stdClass $v) use ($bad): array {
			$atRaw = $v->modified_at ?? null;
			$at = is_string($atRaw) ? Time::parseIso($atRaw) : null;
			if (!is_int($v->version ?? null) || $v->version < 0 || !is_bool($v->deleted ?? null) || $at === null
				|| !is_string($v->modified_by ?? null) || ($v->deleted ? false : !(($v->data ?? null) instanceof \stdClass))) {
				throw $bad();
			}
			return ['version' => $v->version, 'deleted' => $v->deleted, 'modified_by' => $v->modified_by,
				'modified_at' => $at, 'data' => $v->deleted ? null : $v->data];
		};
		$cur = $row($r);
		$accounts = [];
		$accountsRaw = $r->accounts ?? null;
		foreach (is_array($accountsRaw) ? $accountsRaw : [] as $a) {
			if (is_string($a) && $a !== '') {
				$accounts[] = $a;
			}
		}
		$history = [];
		$seen = [];
		$historyRaw = $r->history ?? null;
		foreach (is_array($historyRaw) ? $historyRaw : [] as $h) {
			if (!($h instanceof \stdClass)) {
				throw $bad();
			}
			$e = $row($h);
			if (isset($seen[$e['version']])) {
				continue;
			}
			$seen[$e['version']] = true;
			$history[] = $e;
		}
		usort($history, static fn (array $a, array $b) => $a['version'] <=> $b['version']);
		return $cur + ['kind' => $r->kind, 'key' => $r->key, 'accounts' => $accounts, 'history' => $history];
	}
}
