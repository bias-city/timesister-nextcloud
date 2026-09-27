<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * Projects (API version 2): full only for Team Admins and the
 * project's leads; everyone else gets the booking catalog. The lead is
 * admin within the project: they change all of it, but do not create new
 * ones. Pure: knows only `data` and the person keys.
 */
final class ProjectAccess {
	/** Allowlist: what is missing here is visible only in the full view. */
	public const CATALOG = ['schema', 'id', 'name', 'codes', 'subprojects', 'start', 'end', 'status', 'mapping'];
	public const LEADS = 'leads';

	/** 403 when someone else changes or creates a project. */
	public static function forbidden(): ApiException {
		return ApiException::forbidden('Only Team Admins and the project’s Leads change a project; Team Admins create new ones.');
	}

	/**
	 * Does the caller lead this project? `data.leads` contains one of
	 * their person keys (identifier, or a person with it in `accounts`).
	 *
	 * @param list<string> $ownKeys
	 */
	public static function isLead(mixed $data, array $ownKeys): bool {
		$leads = $data instanceof \stdClass ? ($data->{self::LEADS} ?? null) : null;
		if (!is_array($leads)) {
			return false;
		}
		foreach ($leads as $k) {
			if (is_string($k) && in_array($k, $ownKeys, true)) {
				return true;
			}
		}
		return false;
	}

	/** The booking catalog: only the fields from CATALOG that exist. */
	public static function catalog(\stdClass $data): \stdClass {
		$out = new \stdClass();
		foreach (self::CATALOG as $field) {
			if (property_exists($data, $field)) {
				$out->{$field} = $data->{$field};
			}
		}
		return $out;
	}
}
