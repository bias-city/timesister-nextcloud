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
	public const CATALOG = ['schema', 'id', 'name', 'codes', 'subprojects', 'start', 'end', 'status', 'mapping', 'never_billable'];
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

	/** The booking catalog: only the fields from CATALOG that exist, plus
	 *  `led` (the project has a Lead) - so a client knows which bookings to
	 *  report (`booking-report`), without learning who leads it. */
	public static function catalog(\stdClass $data): \stdClass {
		$out = new \stdClass();
		foreach (self::CATALOG as $field) {
			if (property_exists($data, $field)) {
				$out->{$field} = $data->{$field};
			}
		}
		$leads = $data->{self::LEADS} ?? null;
		if (is_array($leads) && $leads !== []) {
			$out->led = true;
		}
		// 0.33.0: the level per code of the budgets in the category model, to preset a booking's.
		$levels = LevelRules::bookingLevels($data);
		if ($levels !== null) {
			$out->{LevelRules::CATALOG_FIELD} = $levels;
		}
		return $out;
	}

	/** The customer key a project names (`customer`, older records `kunde`), or null. */
	public static function customerOf(mixed $data): ?string {
		if (!($data instanceof \stdClass)) {
			return null;
		}
		$c = $data->customer ?? $data->kunde ?? null;
		return is_string($c) && $c !== '' ? $c : null;
	}

	/**
	 * The customers a Lead reads (0.14.0): those named by a project they
	 * lead (as for the full project, whatever its status).
	 *
	 * @param list<mixed> $projects the live projects' data
	 * @param list<string> $ownKeys
	 * @return array<string,true>
	 */
	public static function customersLedBy(array $projects, array $ownKeys): array {
		$out = [];
		foreach ($projects as $d) {
			$c = self::customerOf($d);
			if ($c !== null && self::isLead($d, $ownKeys)) {
				$out[$c] = true;
			}
		}
		return $out;
	}

	/**
	 * What a project write changes for the Leads' view (0.14.0): the
	 * customers to deliver again (old and new, when leads, customer or the
	 * active state change) and whether the project's booking reports and
	 * marks go out again (leads or active state). Null data: none or a
	 * tombstone.
	 *
	 * @return array{customers:list<string>,bookings:bool}
	 */
	public static function scopeChange(?\stdClass $old, ?\stdClass $new): array {
		$leads = static function (?\stdClass $d): array {
			$l = $d === null ? [] : array_values(array_filter((array)($d->{self::LEADS} ?? []), 'is_string'));
			sort($l, SORT_STRING);
			return array_values(array_unique($l));
		};
		$active = static fn (?\stdClass $d): bool => $d !== null && LeadResolver::isActive($d);
		$leadsChanged = $leads($old) !== $leads($new);
		$activeChanged = $active($old) !== $active($new);
		$customers = [];
		if ($leadsChanged || $activeChanged || self::customerOf($old) !== self::customerOf($new)) {
			foreach ([self::customerOf($old), self::customerOf($new)] as $c) {
				if ($c !== null && !in_array($c, $customers, true)) {
					$customers[] = $c;
				}
			}
		}
		return ['customers' => $customers, 'bookings' => $leadsChanged || $activeChanged];
	}
}
