<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The team's module switches in `setting/settings.modules`, pure: jobs,
 * budgets, customers (older `kunden`) and since 0.21.0 costs. Off means
 * hidden by the clients, never deleted; the server keeps checking and
 * storing the data either way.
 */
final class ModuleRules {
	public const FIELD = 'modules';
	public const COSTS = 'costs';
	public const KNOWN = ['jobs', 'budgets', 'customers', 'kunden', self::COSTS];

	/** An object of switches; each known one null or a boolean. Other keys pass, as the Mac app may add modules. */
	public static function validate(mixed $m): void {
		if ($m === null) {
			return;
		}
		if (!($m instanceof \stdClass)) {
			throw ApiException::invalid(Message::of('“{field}” must be an object.', ['field' => self::FIELD]));
		}
		foreach (self::KNOWN as $k) {
			$v = $m->{$k} ?? null;
			if ($v !== null && !is_bool($v)) {
				throw ApiException::notBool(self::FIELD . '.' . $k);
			}
		}
	}
}
