<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\Record;

/**
 * The team's own project templates (0.25.0, capability `templates: 1`): a
 * record of kind `template` holding a template in the format
 * `timesister-template` version 3, keyed `<id>@<edition>`. The client checks
 * the template in full; the app checks what keeps the team's data clean:
 * format, key, size and that no person or customer field appears anywhere.
 * Pure.
 *
 * Leads and Team Admins read every template. Team Admins write every one; a
 * Lead writes new templates and changes or removes those they saved last.
 * Members see none.
 */
final class TemplateRules {
	public const KIND = 'template';
	public const FORMAT = 'timesister-template';
	public const VERSION = 3;
	public const MAX_BYTES = 262144;
	public const STATUSES = ['normative', 'draft', 'custom'];
	public const ID_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,99}$/';
	public const EDITION_PATTERN = '/^[A-Za-z0-9._-]{1,100}$/';
	public const TITLE_MAX = 200;
	public const MAX_DEPTH = 32;
	/** Fields that carry people or customers – never in a template (as the client's check). */
	public const PERSON_FIELDS = ['customer', 'client', 'kunde', 'kunden', 'person', 'persons', 'people', 'personen',
		'lead', 'leads', 'leitung', 'owner', 'members', 'member', 'team', 'user', 'users', 'uid', 'email', 'mail', 'phone',
		'address', 'contact', 'entries', 'invoices', 'bookings', 'employee', 'employees'];

	/** @throws ApiException 422 */
	public static function validate(string $key, \stdClass $d): void {
		if (($d->format ?? null) !== self::FORMAT) {
			throw ApiException::invalid(Message::of('“format” of a template must be “{format}”.', ['format' => self::FORMAT]));
		}
		if (($d->format_version ?? null) !== self::VERSION) {
			throw ApiException::invalid(Message::of('“format_version” of a template must be {version}.', ['version' => self::VERSION]));
		}
		$id = $d->id ?? null;
		$edition = $d->edition ?? null;
		if (!is_string($id) || preg_match(self::ID_PATTERN, $id) !== 1) {
			throw ApiException::invalid('“id” of a template: lowercase letters, digits, dots, dashes and underscores, at most 100.');
		}
		if (!is_string($edition) || preg_match(self::EDITION_PATTERN, $edition) !== 1) {
			throw ApiException::invalid('“edition” of a template: letters, digits, dots, dashes and underscores, at most 100.');
		}
		if ($key !== $id . '@' . $edition) {
			throw ApiException::invalid('The key of a template is its “id”, “@” and its “edition”.');
		}
		if (!in_array($d->status ?? null, self::STATUSES, true)) {
			throw ApiException::invalid(Message::of('“status” of a template is one of {statuses}.', ['statuses' => implode(', ', self::STATUSES)]));
		}
		$title = $d->title ?? null;
		if (!is_string($title) || trim($title) === '' || mb_strlen($title) > self::TITLE_MAX || !mb_check_encoding($title, 'UTF-8')) {
			throw ApiException::invalid(Message::of('“{field}” must be text of 1 to {max} characters.', ['field' => 'title', 'max' => self::TITLE_MAX]));
		}
		$items = $d->items ?? null;
		if (!is_array($items) || !array_is_list($items)) {
			throw ApiException::invalid('“items” of a template must be a list.');
		}
		$path = self::personField($d, '', 0);
		if ($path !== null) {
			throw ApiException::invalid(Message::of('A template carries no person or customer data: “{path}”.', ['path' => $path]));
		}
	}

	/** The path of the first person or customer field, or null. */
	public static function personField(mixed $v, string $path, int $depth): ?string {
		if ($depth > self::MAX_DEPTH) {
			return $path;
		}
		if ($v instanceof \stdClass) {
			/** @var mixed $x */
			foreach (get_object_vars($v) as $name => $x) {
				// A numeric field name comes as an int.
				$k = sprintf('%s', $name);
				$p = $path === '' ? $k : $path . '.' . $k;
				if (in_array(strtolower($k), self::PERSON_FIELDS, true)) {
					return $p;
				}
				$inner = self::personField($x, $p, $depth + 1);
				if ($inner !== null) {
					return $inner;
				}
			}
		} elseif (is_array($v)) {
			foreach ($v as $i => $x) {
				$inner = self::personField($x, $path . '[' . $i . ']', $depth + 1);
				if ($inner !== null) {
					return $inner;
				}
			}
		}
		return null;
	}

	/**
	 * A write against the stored record: a Lead creates templates (also on a
	 * tombstone) and changes or removes only those they saved last.
	 *
	 * @throws ApiException 403
	 */
	public static function requireLeadWrite(?Record $cur, string $uid): void {
		if ($cur === null || $cur->isTombstone()) {
			return;
		}
		if ($cur->getModifiedBy() !== $uid) {
			throw ApiException::forbidden('A Lead changes and removes only their own templates.');
		}
	}
}
