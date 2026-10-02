<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The billing mark (0.7.6): a record of kind `billing`, one per person and
 * event UID. Pure: key, content and the rights rule.
 *
 * - Key: the person's key, `+` and the first 32 hex digits of the UID's
 *   SHA-256 – so a key stays within the record key pattern whatever the
 *   UID contains, and the person can be read off a tombstone.
 * - Data: `person`, `uid`, `billed_on` (date), optional `by`, `checksum`
 *   (of the billed state, the client's), and – for later hand-over to an
 *   accounting system, carried through only – `source`, `external_id`,
 *   `document`.
 * - Rights: Team Admins everything; a Lead whoever they may at least view
 *   in the shares matrix (members and externals); Users never. Reading:
 *   the same, plus the person themselves (their events are locked).
 */
final class BillingRules {
	public const KIND = 'billing';
	public const HASH_LENGTH = 32;
	/** The person's key must leave room for `+` and the hash within 128. */
	public const PERSON_MAX = 128 - 1 - self::HASH_LENGTH;
	public const UID_MAX = 1024;
	public const TEXT_MAX = 256;
	/** Optional text fields and their maximum length. */
	public const TEXTS = ['by' => 64, 'checksum' => 128, 'source' => 64, 'external_id' => self::TEXT_MAX, 'document' => self::TEXT_MAX];

	public static function key(string $person, string $uid): string {
		return $person . '+' . substr(hash('sha256', $uid), 0, self::HASH_LENGTH);
	}

	/** The person's key out of a billing key; null if the key is not one. */
	public static function personOf(string $key): ?string {
		$i = strrpos($key, '+');
		if ($i === false || $i === 0) {
			return null;
		}
		$hash = substr($key, $i + 1);
		if (!preg_match('/^[0-9a-f]{' . self::HASH_LENGTH . '}$/', $hash)) {
			return null;
		}
		return substr($key, 0, $i);
	}

	/** The person of a billing key, or 422. */
	public static function requirePerson(string $key): string {
		$p = self::personOf($key);
		if ($p === null) {
			throw ApiException::invalid('The key of a billing mark is the person’s key, “+” and 32 hex digits of the event UID’s SHA-256.');
		}
		return $p;
	}

	/** Content check: key fields, date, text lengths. */
	public static function validate(string $key, \stdClass $data): void {
		$person = $data->person ?? null;
		$uid = $data->uid ?? null;
		if (!is_string($person) || !is_string($uid) || $uid === '' || strlen($uid) > self::UID_MAX
			|| strlen($person) > self::PERSON_MAX || !RecordValidator::isKey($person)) {
			throw ApiException::invalid('For a billing mark, “person” must be the person’s key and “uid” the event’s UID.');
		}
		if (self::key($person, $uid) !== $key) {
			throw ApiException::invalid('The key of a billing mark is the person’s key, “+” and 32 hex digits of the event UID’s SHA-256.');
		}
		$on = $data->billed_on ?? null;
		if (!is_string($on) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $on) || !checkdate((int)substr($on, 5, 2), (int)substr($on, 8, 2), (int)substr($on, 0, 4))) {
			throw ApiException::invalid('“billed_on” must be a date (YYYY-MM-DD).');
		}
		foreach (self::TEXTS as $field => $max) {
			$v = $data->$field ?? null;
			if ($v !== null && (!is_string($v) || strlen($v) > $max)) {
				throw ApiException::invalid(Message::of('“{field}” must be text of at most {max} characters.', ['field' => $field, 'max' => $max]));
			}
		}
	}

	/**
	 * May this role mark the person's events as billed? `$level` is the
	 * caller's level on the person from the shares matrix (null: none).
	 */
	public static function canWrite(string $role, ?string $level): bool {
		if (Role::manages($role)) {
			return true;
		}
		if ($role !== Role::LEAD) {
			return false;
		}
		return AccessRules::rank($level ?? AccessRules::NONE) >= AccessRules::rank(AccessRules::VIEW);
	}

	/** Reading: whoever may write, and the person themselves. */
	public static function canRead(string $role, ?string $level, bool $own): bool {
		return $own || self::canWrite($role, $level);
	}
}
