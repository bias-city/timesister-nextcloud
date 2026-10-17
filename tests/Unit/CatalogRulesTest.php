<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Db\Tenant;
use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\CatalogRules;
use OCA\TimeSister\Service\TeamRules;
use OCA\TimeSister\Service\TenantService;
use PHPUnit\Framework\TestCase;

/** Team setting `catalogs` (0.25.1): null, or up to 50 distinct `id@edition`. */
class CatalogRulesTest extends TestCase {
	private static function outcome(callable $fn): string {
		try {
			$fn();
		} catch (ApiException $e) {
			return $e->getStatus() . ' ' . $e->getMessage();
		}
		return 'ok';
	}

	public function testValidate(): void {
		$this->assertNull(CatalogRules::validate(null));
		$this->assertSame([], CatalogRules::validate([]), 'an empty list: none active');
		$this->assertSame(['sia-102@2020', 'hoai.lp@2021_A-1'], CatalogRules::validate(['sia-102@2020', 'hoai.lp@2021_A-1']));
		$fifty = array_map(static fn (int $i) => "c$i@1", range(1, 50));
		$this->assertSame($fifty, CatalogRules::validate($fifty));

		$list = '422 “catalogs” must be null or a list of at most 50 catalogs.';
		foreach (['sia@2020', ['a' => 'sia@2020'], 7, [...$fifty, 'c51@1']] as $bad) {
			$this->assertSame($list, self::outcome(fn () => CatalogRules::validate($bad)), json_encode($bad));
		}
		$item = '422 Each catalog in “catalogs” is “id@edition”: the id in lowercase letters, digits, dots, dashes and underscores, the edition in letters, digits, dots, dashes and underscores, each at most 100.';
		foreach (['sia', 'SIA@2020', '-sia@2020', 'sia@', '@2020', 'sia@20 20', 'sia@2020@x', 'si/a@1', str_repeat('a', 101) . '@1', 'a@' . str_repeat('1', 101), '', 7, null, ['sia@2020']] as $bad) {
			$this->assertSame($item, self::outcome(fn () => CatalogRules::validate([$bad])), json_encode($bad));
		}
		$this->assertSame('ok', self::outcome(fn () => CatalogRules::validate([str_repeat('a', 100) . '@' . str_repeat('B', 100)])), 'both at 100');
		$this->assertSame('422 “catalogs” names “sia@2020” twice.', self::outcome(fn () => CatalogRules::validate(['sia@2020', 'hoai@2021', 'sia@2020'])));
	}

	public function testStoredAndSettings(): void {
		$this->assertNull(CatalogRules::encode(null));
		$this->assertSame('[]', CatalogRules::encode([]));
		$this->assertSame('["sia@2020"]', CatalogRules::encode(['sia@2020']));
		$this->assertSame(['sia@2020'], CatalogRules::decode('["sia@2020"]'));
		$this->assertSame([], CatalogRules::decode('[]'));
		foreach ([null, '', 'x', '{"a":1}', '["BAD"]'] as $raw) {
			$this->assertNull(CatalogRules::decode($raw), (string)$raw);
		}

		$this->assertSame(['catalogs' => ['sia@2020']], TeamRules::settings(['settings' => ['catalogs' => ['sia@2020']]]));
		$this->assertSame(['catalogs' => null], TeamRules::settings(['settings' => ['catalogs' => null]]));
		$this->assertSame('422 “catalogs” names “a@1” twice.', self::outcome(fn () => TeamRules::settings(['settings' => ['catalogs' => ['a@1', 'a@1']]])));

		$t = new Tenant();
		$this->assertNull($t->catalogs());
		$this->assertNull(TenantService::settingsOf($t)['catalogs'], 'null by default: the client chooses by country');
		$t->setCatalogsJson(CatalogRules::encode(['sia@2020', 'hoai@2021']));
		$this->assertSame(['sia@2020', 'hoai@2021'], TenantService::settingsOf($t)['catalogs']);
		$t->setCatalogsJson('[]');
		$this->assertSame([], TenantService::settingsOf($t)['catalogs']);
	}
}
