<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\Json;
use OCA\TimeSister\Service\LevelRules;
use OCA\TimeSister\Service\PersonAccess;
use OCA\TimeSister\Service\ProjectRules;
use OCA\TimeSister\Service\RecordValidator;
use PHPUnit\Framework\TestCase;

/** Levels (0.27.0): the team setting, the person's series, the project's overrides, and who reads the rates. */
class LevelRulesTest extends TestCase {
	private static function obj(string $json): \stdClass {
		$o = Json::decode($json);
		self::assertInstanceOf(\stdClass::class, $o);
		return $o;
	}

	private static function outcome(callable $fn): string {
		try {
			$fn();
		} catch (ApiException $e) {
			return $e->getStatus() . ' ' . $e->getText()->text();
		}
		return '';
	}

	public function testSettingsLevels(): void {
		$ok = '{"levels":[{"id":"lead","name":"Leitung","rates":[{"from":"2026-01-01","rate":180},{"from":"2027-01-01","rate":190}]},{"id":"staff-2","name":"Mitarbeit"}]}';
		self::assertSame('', self::outcome(fn () => RecordValidator::validate('setting', 'settings', self::obj($ok))));
		self::assertSame('', self::outcome(fn () => LevelRules::validateSettings(null)));
		self::assertStringContainsString('levels', self::outcome(fn () => LevelRules::validateSettings(self::obj('{"x":1}'))));
		self::assertStringContainsString('id', self::outcome(fn () => LevelRules::validateSettings([self::obj('{"id":"Lead","name":"x"}')])));
		self::assertStringContainsString('name', self::outcome(fn () => LevelRules::validateSettings([self::obj('{"id":"lead","name":""}')])));
		self::assertStringContainsString('twice', self::outcome(fn () => LevelRules::validateSettings([self::obj('{"id":"a","name":"A"}'), self::obj('{"id":"a","name":"B"}')])));
		self::assertStringContainsString('twice', self::outcome(fn () => LevelRules::validateSettings([self::obj('{"id":"a","name":"A","rates":[{"from":"2026-01-01","rate":1},{"from":"2026-01-01","rate":2}]}')])));
		self::assertStringContainsString('rate', self::outcome(fn () => LevelRules::validateSettings([self::obj('{"id":"a","name":"A","rates":[{"from":"2026-01-01","rate":-1}]}')])));
		self::assertSame('', self::outcome(fn () => LevelRules::validateSettings([self::obj('{"id":"c","name":"Kategorie C","description":"Hohe Anforderungen"}')])));
		self::assertStringContainsString('description', self::outcome(fn () => LevelRules::validateSettings([self::obj('{"id":"c","name":"C","description":"' . str_repeat('x', 301) . '"}')])));
		self::assertStringContainsString('description', self::outcome(fn () => LevelRules::validateSettings([self::obj('{"id":"c","name":"C","description":7}')])));
		$many = array_map(static fn (int $i): \stdClass => self::obj('{"id":"l' . $i . '","name":"L"}'), range(1, 51));
		self::assertStringContainsString('50', self::outcome(fn () => LevelRules::validateSettings($many)));
	}

	public function testPersonLevels(): void {
		self::assertSame('', self::outcome(fn () => RecordValidator::validate('person', 'a', self::obj('{"login":"a","levels":[{"from":"2026-01-01","level":"lead"}]}'))));
		self::assertSame('', self::outcome(fn () => LevelRules::validatePerson(null)));
		self::assertStringContainsString('levels', self::outcome(fn () => LevelRules::validatePerson(self::obj('{"from":"x"}'))));
		self::assertStringContainsString('level', self::outcome(fn () => LevelRules::validatePerson([self::obj('{"from":"2026-01-01","level":"Lead"}')])));
		self::assertStringContainsString('from', self::outcome(fn () => LevelRules::validatePerson([self::obj('{"from":"2026-1-1","level":"lead"}')])));
		self::assertStringContainsString('twice', self::outcome(fn () => LevelRules::validatePerson([self::obj('{"from":"2026-01-01","level":"a"}'), self::obj('{"from":"2026-01-01","level":"b"}')])));
	}

	public function testProjectLevelRates(): void {
		self::assertSame('', self::outcome(fn () => ProjectRules::validate(self::obj('{"id":"d200","codes":["D200"],"status":"active","level_rates":{"lead":200,"staff":0}}'))));
		self::assertSame('', self::outcome(fn () => LevelRules::validateProject(null)));
		self::assertStringContainsString('level_rates', self::outcome(fn () => LevelRules::validateProject([1])));
		self::assertStringContainsString('level_rates', self::outcome(fn () => LevelRules::validateProject(self::obj('{"lead":-1}'))));
		self::assertStringContainsString('level_rates', self::outcome(fn () => LevelRules::validateProject(self::obj('{"Lead":1}'))));
		self::assertStringContainsString('level_rates', self::outcome(fn () => LevelRules::validateProject(self::obj('{"lead":"x"}'))));
	}

	/** Members read the levels without rates; the person's level reaches Leads only. */
	public function testWhoReadsRates(): void {
		$settings = self::obj('{"currency":"CHF","levels":[{"id":"lead","name":"Leitung","rates":[{"from":"2026-01-01","rate":180}]},{"id":"x","name":"X"}]}');
		$member = LevelRules::withoutRates($settings);
		self::assertSame('CHF', $member->currency);
		self::assertSame('Leitung', $member->levels[0]->name);
		self::assertObjectNotHasProperty('rates', $member->levels[0]);
		self::assertObjectHasProperty('rates', $settings->levels[0], 'the stored record keeps them');
		self::assertSame('CHF', LevelRules::withoutRates(self::obj('{"currency":"CHF"}'))->currency, 'without levels unchanged');

		$person = self::obj('{"login":"a","first_name":"Ann","cost_rates":[{"from":"2026-01-01","rate":1}],"levels":[{"from":"2026-01-01","level":"lead"}]}');
		self::assertObjectNotHasProperty('levels', PersonAccess::own($person));
		self::assertObjectNotHasProperty('cost_rates', PersonAccess::own($person, true));
		self::assertSame('lead', PersonAccess::own($person, true)->levels[0]->level);
		self::assertObjectNotHasProperty('levels', PersonAccess::card($person));
		self::assertSame('lead', PersonAccess::card($person, true)->levels[0]->level);
		self::assertObjectNotHasProperty('cost_rates', PersonAccess::card($person, true));
	}
}
