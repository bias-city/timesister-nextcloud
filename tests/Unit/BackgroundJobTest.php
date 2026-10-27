<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCP\BackgroundJob\Job;
use PHPUnit\Framework\TestCase;

/**
 * Every background job runs one at a time: a second cron would only double
 * the work. The constructors need Nextcloud, so the source is read.
 */
class BackgroundJobTest extends TestCase {
	public function testEveryJobForbidsParallelRuns(): void {
		$checked = 0;
		foreach (glob(__DIR__ . '/../../lib/BackgroundJob/*.php') ?: [] as $file) {
			$class = 'OCA\\TimeSister\\BackgroundJob\\' . basename($file, '.php');
			if (!(new \ReflectionClass($class))->isSubclassOf(Job::class)) {
				continue;
			}
			$this->assertStringContainsString('$this->setAllowParallelRuns(false);', (string)file_get_contents($file), $class);
			$checked++;
		}
		$this->assertGreaterThanOrEqual(7, $checked);
	}
}
