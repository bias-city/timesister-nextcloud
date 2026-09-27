<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\Thinning;
use PHPUnit\Framework\TestCase;

/** Tiers: all for 4 weeks, then monthly up to 12 months, then yearly up to 10 years. Fixed dates, no clock. */
class ThinningTest extends TestCase {
	private const TODAY = '2026-09-26';

	public function testConstants(): void {
		$this->assertSame(4, Thinning::WEEKS);
		$this->assertSame(12, Thinning::MONTHS);
		$this->assertSame(10, Thinning::JAHRE);
		$this->assertGreaterThanOrEqual(5, Thinning::JAHRE, 'never below CH ArGV 1 Art. 73');
	}

	public function testFourWeeksAllKept(): void {
		$days = ['2026-09-26', '2026-09-25', '2026-09-19', '2026-09-01', '2026-08-29'];
		$this->assertSame($days, Thinning::keep($days, self::TODAY));
	}

	public function testMonthlyThenYearly(): void {
		$days = [
			'2026-08-28', '2026-08-10',            // August, older than 4 weeks: only the newest
			'2026-07-31', '2026-07-01',            // July: 07-31
			'2025-10-15', '2025-10-01',            // October 2025: 10-15
			'2025-09-26',                          // exactly 12 months: still monthly tier
			'2025-09-25', '2025-03-01', '2025-01-01', // yearly tier 2025: 09-25
			'2020-12-31', '2020-01-01',            // 2020: 12-31
			'2016-09-26',                          // exactly 10 years: stays
			'2016-09-25', '2010-01-01',            // older than 10 years: gone
		];
		$this->assertSame(
			['2026-08-28', '2026-07-31', '2025-10-15', '2025-09-26', '2025-09-25', '2020-12-31', '2016-09-26'],
			Thinning::keep($days, self::TODAY),
		);
	}

	public function testOrderDuplicatesAndFuture(): void {
		$kept = Thinning::keep(['2026-01-02', '2099-12-31', '2026-01-20', '2026-01-20', '2026-09-26'], self::TODAY);
		$this->assertSame(['2099-12-31', '2026-09-26', '2026-01-20'], $kept, 'the future stays, duplicates once, newest first');
		$this->assertSame([], Thinning::keep([], self::TODAY));
	}

	public function testWeeklyBackupsOverTwoYears(): void {
		// One backup every Monday, 2024-09-02 to 2026-09-21 (105 in all).
		$days = [];
		for ($d = new \DateTimeImmutable('2024-09-02'); $d->format('Y-m-d') <= '2026-09-21'; $d = $d->modify('+7 days')) {
			$days[] = $d->format('Y-m-d');
		}
		$kept = Thinning::keep($days, self::TODAY);
		// 4 weeks: 08-31 to 09-21. Monthly tier up to 2025-09-26: 08/2026 to
		// 10/2025 and 09/2025 (only 09-29 is past the cutoff) = 12 months.
		$recent = array_values(array_filter($kept, fn ($d) => $d >= '2026-08-29'));
		$this->assertSame(['2026-09-21', '2026-09-14', '2026-09-07', '2026-08-31'], $recent);
		$months = array_values(array_filter($kept, fn ($d) => $d < '2026-08-29' && $d >= '2025-09-26'));
		$this->assertCount(12, $months, 'one per month');
		$this->assertSame(count($months), count(array_unique(array_map(fn ($d) => substr($d, 0, 7), $months))));
		$old = array_values(array_filter($kept, fn ($d) => $d < '2025-09-26'));
		$this->assertSame(['2025-09-22', '2024-12-30'], $old, 'the newest per year');
	}
}
