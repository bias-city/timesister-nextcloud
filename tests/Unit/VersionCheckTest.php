<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\VersionCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Konfliktlogik aus API.md (PUT, DELETE, Batch, Restore). */
class VersionCheckTest extends TestCase {
	/**
	 * [aktuell, Basis, löschen, Ergebnis, neue Fassung]
	 *
	 * @return array<string,array{?array{version:int,deleted:bool},int,bool,string,int}>
	 */
	public static function cases(): array {
		$live3 = ['version' => 3, 'deleted' => false];
		$tomb4 = ['version' => 4, 'deleted' => true];
		return [
			'neu mit 0' => [null, 0, false, 'ok', 1],
			'neu mit 1: Konflikt' => [null, 1, false, 'conflict', 0],
			'passende Fassung' => [$live3, 3, false, 'ok', 4],
			'veraltete Fassung' => [$live3, 2, false, 'conflict', 0],
			'zukünftige Fassung' => [$live3, 9, false, 'conflict', 0],
			'0 auf bestehenden: Konflikt' => [$live3, 0, false, 'conflict', 0],
			'0 auf Grabstein: neu' => [$tomb4, 0, false, 'ok', 5],
			'Grabstein-Fassung: neu' => [$tomb4, 4, false, 'ok', 5],
			'ältere Fassung auf Grabstein: Konflikt' => [$tomb4, 3, false, 'conflict', 0],
			'löschen passend' => [$live3, 3, true, 'ok', 4],
			'löschen veraltet' => [$live3, 1, true, 'conflict', 0],
			'löschen mit 0' => [$live3, 0, true, 'conflict', 0],
			'löschen, gibt es nicht' => [null, 0, true, 'not_found', 0],
			'löschen, schon Grabstein' => [$tomb4, 4, true, 'not_found', 0],
		];
	}

	/** @param array{version:int,deleted:bool}|null $current */
	#[DataProvider('cases')]
	public function testDecide(?array $current, int $base, bool $delete, string $outcome, int $version): void {
		$this->assertSame([$outcome, $version], VersionCheck::decide($current, $base, $delete));
	}
}
