<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

// The unit tests need no Nextcloud: they only check the pure classes
// in lib/Service (roles, permissions, rules, conflict logic).
require_once __DIR__ . '/../vendor/autoload.php';

// The public interfaces (nextcloud/ocp) have no autoloader of their own;
// the controller tests need the attributes and the response classes.
spl_autoload_register(static function (string $class): void {
	if (str_starts_with($class, 'OCP\\')) {
		$file = __DIR__ . '/../vendor/nextcloud/ocp/' . str_replace('\\', '/', $class) . '.php';
		if (is_file($file)) {
			require_once $file;
		}
	}
});
