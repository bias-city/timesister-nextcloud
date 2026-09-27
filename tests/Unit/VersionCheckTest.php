<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\VersionCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Conflict logic from API.md (PUT, DELETE, Batch, Restore). */
class VersionCheckTest extends TestCase {
	/**
	 * [current, base, delete, outcome, new version]
	 *
	 * @return array<string,array{?array{version:int,deleted:bool},int,bool,string,int}>
	 */
	public static function cases(): array {
		$live3 = ['version' => 3, 'deleted' => false];
		$tomb4 = ['version' => 4, 'deleted' => true];
		return [
			'new with 0' => [null, 0, false, 'ok', 1],
			'new with 1: conflict' => [null, 1, false, 'conflict', 0],
			'matching version' => [$live3, 3, false, 'ok', 4],
			'stale version' => [$live3, 2, false, 'conflict', 0],
			'future version' => [$live3, 9, false, 'conflict', 0],
			'0 on an existing record: conflict' => [$live3, 0, false, 'conflict', 0],
			'0 on a tombstone: new' => [$tomb4, 0, false, 'ok', 5],
			'tombstone version: new' => [$tomb4, 4, false, 'ok', 5],
			'older version on a tombstone: conflict' => [$tomb4, 3, false, 'conflict', 0],
			'delete matching' => [$live3, 3, true, 'ok', 4],
			'delete stale' => [$live3, 1, true, 'conflict', 0],
			'delete with 0' => [$live3, 0, true, 'conflict', 0],
			'delete, does not exist' => [null, 0, true, 'not_found', 0],
			'delete, already a tombstone' => [$tomb4, 4, true, 'not_found', 0],
		];
	}

	/** @param array{version:int,deleted:bool}|null $current */
	#[DataProvider('cases')]
	public function testDecide(?array $current, int $base, bool $delete, string $outcome, int $version): void {
		$this->assertSame([$outcome, $version], VersionCheck::decide($current, $base, $delete));
	}
}
