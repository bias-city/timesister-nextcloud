<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

// Stand-ins for the two Doctrine classes that OCP\DB\QueryBuilder\IQueryBuilder
// names in its constants (values as in DBAL 3). Only for the unit tests.

namespace Doctrine\DBAL {
	if (!class_exists(ParameterType::class, false)) {
		final class ParameterType {
			public const NULL = 0;
			public const INTEGER = 1;
			public const STRING = 2;
			public const LARGE_OBJECT = 3;
			public const BOOLEAN = 5;
			public const BINARY = 16;
			public const ASCII = 17;
		}

		final class ArrayParameterType {
			public const INTEGER = 101;
			public const STRING = 102;
			public const BINARY = 116;
			public const ASCII = 117;
		}
	}
}
