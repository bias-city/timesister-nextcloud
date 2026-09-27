<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The shares matrix: who (row, `viewer`) sees whose time calendar (column,
 * `owner`), and how. Pure.
 *
 * - Levels: `none`, `view` (read), `edit` (write).
 * - Defaults: rows of Team Admins `edit`, everything else `none`.
 * - A Team Admin's entry (`admin`) replaces the default; the owner's own
 *   entry (`self`) wins over both, but only while the owner may override.
 *   Otherwise it rests: kept, not applied.
 * - The owner's client sets the shares in Nextcloud (only the owner can)
 *   and reports them (`applied_shares`); a field is effective once the
 *   reported level equals the target.
 */
final class AccessRules {
	public const NONE = 'none';
	public const VIEW = 'view';
	public const EDIT = 'edit';
	/** In PUT: remove the entry, back to what applies without it. */
	public const DEFAULT = 'default';

	public const LEVELS = [self::NONE, self::VIEW, self::EDIT];

	public const SOURCE_ADMIN = 'admin';
	public const SOURCE_SELF = 'self';

	/** At most this many changes in one PUT /team/access. */
	public const MAX_CHANGES = 5000;

	public static function rank(string $level): int {
		return (int)array_search($level, self::LEVELS, true);
	}

	/** Without an entry: Team Admins edit, everyone else nothing. */
	public static function defaultLevel(string $viewerRole): string {
		return $viewerRole === Role::ADMIN ? self::EDIT : self::NONE;
	}

	/** Nextcloud's share right for a level; null for none. */
	public static function access(string $level): ?string {
		return match ($level) {
			self::VIEW => 'read',
			self::EDIT => 'write',
			default => null,
		};
	}

	/** The level for a reported share right. */
	public static function levelOf(?string $access): string {
		return match ($access) {
			'write' => self::EDIT,
			'read' => self::VIEW,
			default => self::NONE,
		};
	}

	/** A level from a request: none, view, edit or default (422 otherwise). */
	public static function validateLevel(mixed $level): string {
		if (!is_string($level) || !in_array($level, [...self::LEVELS, self::DEFAULT], true)) {
			throw ApiException::invalid('“level” must be none, view, edit or default.');
		}
		return $level;
	}

	/**
	 * The body of PUT /team/access: `changes`, a list of
	 * `{viewer, owner, level}`.
	 *
	 * @return list<array{viewer:string,owner:string,level:string}>
	 */
	public static function validateChanges(mixed $changes): array {
		if (!is_array($changes) || !array_is_list($changes) || $changes === []) {
			throw ApiException::invalid('“changes” must be a list of {viewer, owner, level}.');
		}
		if (count($changes) > self::MAX_CHANGES) {
			throw ApiException::tooLarge('Too many changes at once.');
		}
		$out = [];
		foreach ($changes as $c) {
			$viewer = is_array($c) ? ($c['viewer'] ?? null) : null;
			$owner = is_array($c) ? ($c['owner'] ?? null) : null;
			if (!is_string($viewer) || !is_string($owner)) {
				throw ApiException::invalid('“changes” must be a list of {viewer, owner, level}.');
			}
			$out[] = ['viewer' => $viewer, 'owner' => $owner, 'level' => self::validateLevel(is_array($c) ? ($c['level'] ?? null) : null)];
		}
		return $out;
	}

	/**
	 * Who may set a field, and as what: Team Admins every field (as
	 * `admin`), everyone else only their own column (as `self`), and only
	 * while their Team Admin allows overriding.
	 *
	 * @param array<string,string> $roles uid → role, active members
	 * @param array<string,bool> $mayOverride uid → “allow overriding”
	 * @return string the source, admin or self
	 */
	public static function authorize(string $actorUid, string $actorRole, string $viewer, string $owner, array $roles, array $mayOverride): string {
		if (!isset($roles[$viewer]) || !isset($roles[$owner])) {
			throw ApiException::notFound('This account is not in the team.');
		}
		if ($viewer === $owner) {
			throw ApiException::invalid('A person always sees their own time calendar.');
		}
		if (Role::manages($actorRole)) {
			return self::SOURCE_ADMIN;
		}
		if ($actorUid !== $owner) {
			throw ApiException::forbidden('Only Team Admins set shares; everyone else only for their own time calendar.');
		}
		if (!($mayOverride[$owner] ?? false)) {
			throw ApiException::forbidden('Your Team Admin has not allowed overriding.');
		}
		return self::SOURCE_SELF;
	}

	/**
	 * One field of the matrix.
	 *
	 * @param array<string,string> $roles uid → role, active members
	 * @param array<string,bool> $mayOverride
	 * @param array<string,array<string,array<string,array{level:string,at:int,by:string}>>> $entries source → viewer → owner → entry
	 * @return array{level:string,admin_level:string,self_level:?string,overridden:?string,resting:bool,changed_at:?int,changed_by:?string}
	 */
	public static function field(string $viewer, string $owner, array $roles, array $mayOverride, array $entries): array {
		$a = $entries[self::SOURCE_ADMIN][$viewer][$owner] ?? null;
		$s = $entries[self::SOURCE_SELF][$viewer][$owner] ?? null;
		$adminLevel = $a['level'] ?? self::defaultLevel($roles[$viewer] ?? Role::USER);
		$applies = $s !== null && ($mayOverride[$owner] ?? false);
		$level = $applies ? $s['level'] : $adminLevel;
		$overridden = null;
		if ($applies && $s['level'] !== $adminLevel) {
			$overridden = self::rank($s['level']) < self::rank($adminLevel) ? 'restricted' : 'granted';
		}
		$used = $applies ? $s : $a;
		return [
			'level' => $level,
			'admin_level' => $adminLevel,
			'self_level' => $s['level'] ?? null,
			'overridden' => $overridden,
			'resting' => $s !== null && !$applies,
			'changed_at' => $used['at'] ?? null,
			'changed_by' => $used['by'] ?? null,
		];
	}

	/**
	 * Effective: the owner's client has set what applies. Null while the
	 * owner has never reported (clients before 0.5.0).
	 *
	 * @param ?array<string,string> $applied viewer → access, as reported by the owner
	 */
	public static function effective(string $viewer, string $level, ?array $applied): ?bool {
		if ($applied === null) {
			return null;
		}
		return self::levelOf($applied[$viewer] ?? null) === $level;
	}

	/** Still waiting for the owner's client (never reported counts unless nothing is to be set). */
	public static function pending(string $level, ?bool $effective): bool {
		return $effective === false || ($effective === null && $level !== self::NONE);
	}

	/**
	 * `/me.share_targets`: whom the owner's client shares the time calendar
	 * with, sorted by identifier.
	 *
	 * @param array<string,string> $roles
	 * @param array<string,bool> $mayOverride
	 * @param array<string,array<string,array<string,array{level:string,at:int,by:string}>>> $entries
	 * @return list<array{uid:string,access:string}>
	 */
	public static function shareTargets(string $owner, array $roles, array $mayOverride, array $entries): array {
		$out = [];
		$viewers = array_map('strval', array_keys($roles));
		sort($viewers, SORT_STRING);
		foreach ($viewers as $viewer) {
			if ($viewer === $owner) {
				continue;
			}
			$access = self::access(self::field($viewer, $owner, $roles, $mayOverride, $entries)['level']);
			if ($access !== null) {
				$out[] = ['uid' => $viewer, 'access' => $access];
			}
		}
		return $out;
	}

	/**
	 * `applied_shares` from a status report: a list of `{uid, access}`
	 * with access read or write, at most 1000 entries.
	 *
	 * @return array<string,string> uid → access
	 */
	public static function validateApplied(mixed $in): array {
		if (!is_array($in) || count($in) > 1000 || !array_is_list($in)) {
			throw ApiException::invalid('“applied_shares” must be a list of {uid, access}.');
		}
		$out = [];
		foreach ($in as $e) {
			$uid = is_array($e) ? ($e['uid'] ?? null) : null;
			$access = is_array($e) ? ($e['access'] ?? null) : null;
			if (!is_string($uid) || $uid === '' || strlen($uid) > 64 || !in_array($access, ['read', 'write'], true)) {
				throw ApiException::invalid('“applied_shares” must be a list of {uid, access}.');
			}
			$out[$uid] = $access;
		}
		ksort($out, SORT_STRING);
		return $out;
	}
}
