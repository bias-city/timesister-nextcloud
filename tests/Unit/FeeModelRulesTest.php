<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Capabilities;
use OCA\TimeSister\Db\Record;
use OCA\TimeSister\Db\RecordMapper;
use OCA\TimeSister\Service\AccessPolicy;
use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\FeeModelRules;
use OCA\TimeSister\Service\Json;
use OCA\TimeSister\Service\LevelRules;
use OCA\TimeSister\Service\Membership;
use OCA\TimeSister\Service\ProjectAccess;
use OCA\TimeSister\Service\ProjectRules;
use OCA\TimeSister\Service\QuoteRules;
use OCA\TimeSister\Service\RecordRights;
use OCA\TimeSister\Service\RecordValidator;
use OCA\TimeSister\Service\TenantService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * Fee models (0.33.0, API.md «Fee models»): the budget's fields and their
 * bounds, the amount and the level per work package, the model comparison
 * of the lock, the one list of levels and the rate of a level (team and
 * project), what members read, the catalog's `booking_levels`, the quote's
 * issue check. The drafts' categories are stored as sent and read by nobody.
 */
class FeeModelRulesTest extends TestCase {
	private static function obj(string $json): \stdClass {
		$o = Json::decode($json);
		self::assertInstanceOf(\stdClass::class, $o);
		return $o;
	}

	/** `status code field` of the error, '' when none. */
	private static function err(callable $fn): string {
		try {
			$fn();
		} catch (ApiException $e) {
			return trim($e->getStatus() . ' ' . ($e->getRuleCode() ?? '-') . ' ' . ($e->getField() ?? '-'));
		}
		return '';
	}

	/** A project with one budget (`$budget` merged in) and its packages. */
	private static function project(string $budget, string $wps = '[]'): \stdClass {
		return self::obj('{"id":"p","status":"draft","budgets":[{"id":"b1","currency":"CHF","work_packages":' . $wps . ',' . $budget . '}]}');
	}

	private static function budget(string $budget, string $wps = '[]'): string {
		return self::err(fn () => ProjectRules::validate(self::project($budget, $wps)));
	}

	public function testBudgetFields(): void {
		$this->assertSame('', self::budget('"fee_model":null'));
		foreach (['category', 'mean_rate', 'role'] as $m) {
			$this->assertSame('', self::budget('"fee_model":"' . $m . '"'), $m);
		}
		$this->assertSame('422 fee_model_invalid fee_model', self::budget('"fee_model":"hoai"'));
		$this->assertSame('422 fee_model_invalid fee_model', self::budget('"fee_model":1'));
		// mean_rate: only in its model; a number ≥ 0.
		$this->assertSame('', self::budget('"fee_model":"mean_rate","mean_rate":176'));
		$this->assertSame('', self::budget('"fee_model":"mean_rate","mean_rate":null'), 'null while drafting');
		$this->assertSame('', self::budget('"fee_model":"category","mean_rate":null'));
		$this->assertSame('422 fee_model_invalid mean_rate', self::budget('"fee_model":"category","mean_rate":176'));
		$this->assertSame('422 fee_model_invalid mean_rate', self::budget('"mean_rate":176'), 'missing model reads as role');
		$this->assertSame('422 - mean_rate', self::budget('"fee_model":"mean_rate","mean_rate":-1'));
		$this->assertSame('422 - mean_rate', self::budget('"fee_model":"mean_rate","mean_rate":"176"'));
		// cap and cap_mode.
		$this->assertSame('', self::budget('"cap":30000,"cap_mode":"cap"'));
		$this->assertSame('', self::budget('"cap":30000,"cap_mode":"flat"'));
		$this->assertSame('', self::budget('"cap":30000,"cap_mode":null'));
		$this->assertSame('', self::budget('"cap":null,"cap_mode":null'));
		$this->assertSame('422 cap_mode_invalid cap_mode', self::budget('"cap":30000,"cap_mode":"pauschal"'));
		$this->assertSame('422 cap_mode_invalid cap', self::budget('"cap":null,"cap_mode":"flat"'));
		$this->assertSame('422 cap_mode_invalid cap', self::budget('"cap_mode":"cap"'));
		// tolerance 0–100, now checked.
		foreach (['null', '0', '15', '12.5', '100'] as $t) {
			$this->assertSame('', self::budget('"tolerance":' . $t), $t);
		}
		foreach (['-1', '100.5', '"15"', 'true'] as $t) {
			$this->assertSame('422 - tolerance', self::budget('"tolerance":' . $t), $t);
		}
		// A quote's budget is checked the same way.
		$q = static fn (string $b): string => self::err(fn () => QuoteRules::validate('q-0000abcd', self::obj('{"status":"draft","budgets":[{"id":"b1",' . $b . '}]}')));
		$this->assertSame('', $q('"fee_model":"mean_rate","mean_rate":176,"cap":1000,"cap_mode":"flat","tolerance":10'));
		$this->assertSame('422 fee_model_invalid fee_model', $q('"fee_model":"x"'));
		$this->assertSame('422 cap_mode_invalid cap', $q('"cap_mode":"flat"'));
	}

	public function testAmountAndLevelOfPackages(): void {
		$wp = static fn (string $wps): string => self::budget('"fee_model":"category"', $wps);
		$this->assertSame('', $wp('[{"no":"31","level":"b","amount":[12000,3000.5],"hours":[60.9,15.2]}]'));
		$this->assertSame('', $wp('[{"no":"31","amount":null}]'));
		$this->assertSame('', $wp('[{"no":"31","amount":[]}]'));
		$this->assertSame('', $wp('[{"no":"31","amount":[0.01,19.99,1e3]}]'));
		$this->assertSame('422 - amount', $wp('[{"no":"31","amount":[1.005]}]'), 'three decimals');
		$this->assertSame('422 - amount', $wp('[{"no":"31","amount":[-1]}]'));
		$this->assertSame('422 - amount', $wp('[{"no":"31","amount":["12"]}]'));
		$this->assertSame('422 - amount', $wp('[{"no":"31","amount":12}]'));
		$this->assertSame('422 - amount', $wp('[{"no":"31","amount":{"0":1}}]'));
		$this->assertSame('', $wp('[{"no":"31","amount":' . json_encode(array_fill(0, 200, 1)) . '}]'));
		$this->assertSame('422 - amount', $wp('[{"no":"31","amount":' . json_encode(array_fill(0, 201, 1)) . '}]'), 'at most 200');
		// A parent has no amount of its own; zeros pass; its level is the preset below.
		$this->assertSame('', $wp('[{"no":"3","level":"c","amount":[0,0]},{"no":"31","parent":"3","amount":[100]}]'));
		$this->assertSame('422 - amount', $wp('[{"no":"3","amount":[0,5]},{"no":"31","parent":"3","amount":[100]}]'));
		// The level: an id of the team's list (form only, not checked against it).
		foreach (['"a"', '"kat-b-2"', 'null', '"' . str_repeat('x', 40) . '"'] as $l) {
			$this->assertSame('', $wp('[{"no":"31","level":' . $l . '}]'), $l);
		}
		foreach (['""', '"-a"', '"A"', '"a b"', '"a.b"', '1', '"' . str_repeat('x', 41) . '"', '"ä"'] as $l) {
			$this->assertSame('422 - level', $wp('[{"no":"31","level":' . $l . '}]'), $l);
		}
		// In every model (switching back keeps it); a leftover category of the drafts is stored unchecked.
		$this->assertSame('', self::budget('"fee_model":"role"', '[{"no":"31","level":"b","rate":150,"amount":[1500]}]'));
		$this->assertSame('', $wp('[{"no":"31","category":"B C"}]'));
	}

	public function testTheLockComparesModels(): void {
		$p = static fn (string $budgets): \stdClass => self::obj('{"id":"p","budgets":' . $budgets . '}');
		$this->assertSame([], FeeModelRules::changed(null, $p('[{"id":"b1","fee_model":"category"}]')), 'a new project');
		$this->assertSame([], FeeModelRules::changed($p('[{"id":"b1"}]'), null), 'a deletion');
		$this->assertSame([], FeeModelRules::changed($p('[{"id":"b1"}]'), $p('[{"id":"b1","fee_model":"role"}]')), 'missing reads as role');
		$this->assertSame([], FeeModelRules::changed($p('[{"id":"b1","fee_model":null}]'), $p('[{"id":"b1"}]')));
		$this->assertSame(['b1'], FeeModelRules::changed($p('[{"id":"b1"}]'), $p('[{"id":"b1","fee_model":"category"}]')));
		$this->assertSame(['b2'], FeeModelRules::changed(
			$p('[{"id":"b1","fee_model":"mean_rate"},{"id":"b2","fee_model":"mean_rate"}]'),
			$p('[{"id":"b1","fee_model":"mean_rate","mean_rate":200},{"id":"b2","fee_model":"category"},{"id":"b3","fee_model":"category"}]')),
			'only a changed model; a new budget never');
		$this->assertSame([], FeeModelRules::changed($p('[{"id":"b1","fee_model":"category"}]'), $p('[]')), 'a removed budget never');
		$e = FeeModelRules::locked('b1', 'RE-2027-0001');
		$this->assertSame(409, $e->getStatus());
		$this->assertSame(['error' => 'conflict', 'code' => 'fee_model_locked', 'field' => 'fee_model', 'budget' => 'b1', 'invoice' => 'RE-2027-0001'],
			array_diff_key($e->toData(), ['message' => 1]));
		$this->assertSame('role', FeeModelRules::modelOf(null));
		$this->assertSame('role', FeeModelRules::modelOf(self::obj('{"fee_model":"hoai"}')));
		$this->assertSame('mean_rate', FeeModelRules::modelOf(self::obj('{"fee_model":"mean_rate"}')));
	}

	public function testTeamSettings(): void {
		$s = static fn (string $json): string => self::err(fn () => RecordValidator::validate('setting', 'settings', self::obj($json)));
		$this->assertSame('', $s('{"fee_model":"category","levels":[{"id":"b","name":"Kategorie B","rates":[{"from":"2025-01-01","rate":197}]}]}'));
		$this->assertSame('', $s('{"fee_model":null,"levels":[]}'), 'no level is writable');
		$this->assertSame('422 fee_model_invalid fee_model', $s('{"fee_model":"kbob"}'));
		// The drafts' list is gone: stored as sent, never checked.
		$this->assertSame('', $s('{"category_rates":{"A":"x"}}'));
		$this->assertSame('', $s('{"category_rates":[{"key":"A B"}]}'));
	}

	public function testMembersReadWithoutRates(): void {
		$settings = self::obj('{"currency":"CHF","fee_model":"category","levels":[{"id":"b","name":"Kategorie B","rates":[{"from":"2025-01-01","rate":197}]},{"id":"lead","name":"Lead"}]}');
		$member = LevelRules::withoutRates($settings);
		$this->assertSame('{"id":"b","name":"Kategorie B"}', Json::encode($member->levels[0]));
		$this->assertSame('category', $member->fee_model);
		$this->assertSame(197, $settings->levels[0]->rates[0]->rate, 'the stored record is untouched');
	}

	/** RecordRights::shape: members read the levels without rates and projects as the catalog with `booking_levels`. */
	public function testShapeByRole(): void {
		$team = (new \ReflectionClass(TenantService::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(TenantService::class, 'leads'))->setValue($team, [1 => ['lea' => [['kind' => 'project', 'key' => 'P1', 'code' => null, 'name' => 'P1']]]]);
		$rights = new RecordRights(new AccessPolicy(), $team, (new \ReflectionClass(RecordMapper::class))->newInstanceWithoutConstructor());
		$record = static function (string $kind, string $key, string $json): Record {
			$r = new Record();
			$r->setKind($kind);
			$r->setRkey($key);
			$r->setData($json);
			return $r;
		};
		$json = '{"fee_model":"category","levels":[{"id":"a","name":"Kategorie A","rates":[{"from":"2025-01-01","rate":252}]}]}';
		$settings = $record('setting', 'settings', $json);
		$member = $rights->shape(new Membership('mia', 1, 'user'), $settings, self::obj($json), ['mia']);
		$this->assertSame('{"fee_model":"category","levels":[{"id":"a","name":"Kategorie A"}]}', Json::encode($member));
		$this->assertSame($json, Json::encode($rights->shape(new Membership('lea', 1, 'lead'), $settings, self::obj($json), ['lea'])), 'Leads read the rates');
		$this->assertSame($json, Json::encode($rights->shape(new Membership('petra', 1, 'admin'), $settings, self::obj($json), ['petra'])), 'Team Admins read the rates');
		$pj = '{"id":"P2","codes":["N410"],"status":"active","leads":["lea"],"level_rates":{"b":190},"budgets":[{"id":"b1","fee_model":"category","work_packages":[{"no":"1","codes":["N410.1"],"level":"b"}]}]}';
		$catalog = $rights->shape(new Membership('mia', 1, 'user'), $record('project', 'P2', $pj), self::obj($pj), ['mia']);
		$this->assertInstanceOf(\stdClass::class, $catalog);
		$this->assertFalse(property_exists($catalog, 'level_rates'));
		$this->assertSame([['code' => 'N410.1', 'level' => 'b', 'start' => null, 'end' => null]], $catalog->booking_levels);
	}

	public function testCatalogBookingLevels(): void {
		// booking_levels is derived: a client's write is dropped; the drafts' fields are unknown and stay.
		$d = self::obj('{"id":"p","codes":["N410"],"status":"active","booking_levels":[{"code":"x"}],"category_rates":{"B B":-1},"booking_categories":[]}');
		$this->assertSame('', self::err(fn () => ProjectRules::validate($d)));
		$this->assertFalse(property_exists($d, 'booking_levels'));
		$this->assertTrue(property_exists($d, 'category_rates'));

		$project = self::obj('{"id":"p","name":"N410","codes":["N410"],"status":"active","leads":["lea"],"level_rates":{"b":190},'
			. '"budgets":[{"id":"b1","fee_model":"category","start":"2027-01-01","end":"2027-12-31","work_packages":['
			. '{"no":"3","name":"Projektierung","level":"c"},'
			. '{"no":"31","parent":"3","codes":["N410.31"],"level":"b","amount":[12000]},'
			. '{"no":"32","parent":"3","codes":["N410.32","N410.32.1"],"amount":[15000]},'
			. '{"no":"4","codes":["N410.4"],"category":"A"}]},'
			. '{"id":"b2","fee_model":"role","work_packages":[{"no":"1","codes":["N410.9"],"level":"a"}]}]}');
		$c = ProjectAccess::catalog($project);
		$this->assertSame([
			['code' => 'N410.31', 'level' => 'b', 'start' => '2027-01-01', 'end' => '2027-12-31'],
			['code' => 'N410.32', 'level' => 'c', 'start' => '2027-01-01', 'end' => '2027-12-31'],
			['code' => 'N410.32.1', 'level' => 'c', 'start' => '2027-01-01', 'end' => '2027-12-31'],
			['code' => 'N410.4', 'level' => null, 'start' => '2027-01-01', 'end' => '2027-12-31'],
		], $c->booking_levels, 'own, else the nearest above, else null; parents and other models left out; a category is not read');
		$this->assertFalse(property_exists($c, 'level_rates'), 'the rates stay in the full record');
		$this->assertFalse(property_exists($c, 'budgets'));
		$this->assertFalse(property_exists($c, 'booking_categories'));
		$this->assertFalse(property_exists(ProjectAccess::catalog(self::obj('{"id":"p","budgets":[{"id":"b1"}]}')), 'booking_levels'), 'missing without the category model');
		$this->assertSame('[]', Json::encode(ProjectAccess::catalog(self::obj('{"id":"p","budgets":[{"id":"b1","fee_model":"category"}]}'))->booking_levels));
	}

	public function testRateOfALevel(): void {
		$settings = self::obj('{"currency":"CHF","levels":['
			. '{"id":"b","name":"B","rates":[{"from":"2025-01-01","rate":197},{"from":"2027-01-01","rate":205}]},'
			. '{"id":"x","name":"Ohne Satz"},{"id":"y","name":"Leer","rates":[]}]}');
		$this->assertSame(197.0, LevelRules::rateOf($settings, null, 'b', 'CHF', '2026-12-31'));
		$this->assertSame(205.0, LevelRules::rateOf($settings, null, 'b', 'chf', '2027-01-01'), 'the latest from on or before the day');
		$this->assertNull(LevelRules::rateOf($settings, null, 'b', 'CHF', '2024-12-31'), 'before the first entry');
		$this->assertNull(LevelRules::rateOf($settings, null, 'b', 'EUR', '2026-06-01'), 'the team’s rates are in the team’s currency');
		$this->assertNull(LevelRules::rateOf($settings, null, 'x', 'CHF', '2026-06-01'), 'a level without a rate');
		$this->assertNull(LevelRules::rateOf($settings, null, 'y', 'CHF', '2026-06-01'));
		$this->assertNull(LevelRules::rateOf($settings, null, 'z', 'CHF', '2026-06-01'), 'an unknown level');
		$this->assertNull(LevelRules::rateOf(null, null, 'b', 'CHF', '2026-01-01'));
		$this->assertSame(197.0, LevelRules::rateOf(self::obj('{"levels":[{"id":"b","name":"B","rates":[{"from":"2025-01-01","rate":197}]}]}'), null, 'b', 'CHF', '2026-01-01'), 'without a team currency: CHF');
		$project = self::obj('{"currency":"CHF","level_rates":{"b":150,"z":99}}');
		$this->assertSame(150.0, LevelRules::rateOf($settings, $project, 'b', 'CHF', '2026-01-01'), 'the project overrides');
		$this->assertSame(99.0, LevelRules::rateOf(null, $project, 'z', 'CHF', '2026-01-01'));
		$this->assertSame(197.0, LevelRules::rateOf($settings, self::obj('{"level_rates":{"z":99}}'), 'b', 'CHF', '2026-01-01'), 'what the project lacks takes the team’s');
		$eur = self::obj('{"currency":"EUR","level_rates":{"b":180}}');
		$this->assertSame(180.0, LevelRules::rateOf($settings, $eur, 'b', 'EUR', '2026-01-01'), 'the project’s rate is in its currency');
		$this->assertNull(LevelRules::rateOf($settings, $eur, 'x', 'EUR', '2026-01-01'), 'no exchange rate');
		$this->assertSame(197.0, LevelRules::rateOf($settings, $eur, 'b', 'CHF', '2026-01-01'), 'in the team’s currency the team’s rate');
		$this->assertTrue(LevelRules::hasLevels($settings));
		$this->assertFalse(LevelRules::hasLevels(self::obj('{"levels":[]}')));
		$this->assertFalse(LevelRules::hasLevels(self::obj('{"levels":null,"category_rates":[{"key":"A"}]}')), 'the drafts’ categories are no levels');
		$this->assertFalse(LevelRules::hasLevels(null));
	}

	public function testQuoteIssueIsCompleteInMoney(): void {
		$settings = self::obj('{"currency":"CHF","levels":[{"id":"b","name":"B","rates":[{"from":"2025-01-01","rate":197}]},{"id":"d","name":"D"}]}');
		$q = static fn (string $budget, ?\stdClass $project = null, string $currency = 'CHF', ?\stdClass $s = null): string => self::err(fn () => QuoteRules::requireComplete(
			self::obj('{"status":"draft","currency":"' . $currency . '","budgets":[{"id":"b1",' . $budget . '}]}'), $s ?? $settings, $project, '2027-01-15'));
		$this->assertSame('', $q('"work_packages":[{"no":"1","amount":[1000]}]'), 'role: nothing more');
		$this->assertSame('', $q('"fee_model":"mean_rate","mean_rate":176,"work_packages":[{"no":"1","amount":[1000]}]'));
		$this->assertSame('422 amount_without_rate mean_rate', $q('"fee_model":"mean_rate","work_packages":[{"no":"1","amount":[1000]}]'));
		$this->assertSame('', $q('"fee_model":"mean_rate","work_packages":[{"no":"1","amount":[0]},{"no":"2","hours":[10]}]'), 'no money, or hours-led');
		$this->assertSame('', $q('"fee_model":"category","work_packages":[{"no":"3","level":"b"},{"no":"31","parent":"3","amount":[1000]}]'), 'the level from above');
		$this->assertSame('422 amount_without_rate level', $q('"fee_model":"category","work_packages":[{"no":"3"},{"no":"31","parent":"3","amount":[1000]}]'));
		$this->assertSame('422 amount_without_rate level', $q('"fee_model":"category","work_packages":[{"no":"31","category":"B","amount":[1000]}]'), 'a category is no level');
		$this->assertSame('422 level_rate_missing level', $q('"fee_model":"category","work_packages":[{"no":"31","level":"d","amount":[1000]}]'), 'a level without a rate');
		$this->assertSame('422 level_rate_missing level', $q('"fee_model":"category","work_packages":[{"no":"31","level":"b","amount":[1000]}]', null, 'EUR'), 'no rate in EUR');
		$this->assertSame('', $q('"fee_model":"category","work_packages":[{"no":"31","level":"d","amount":[1000]}]', self::obj('{"level_rates":{"d":140}}')), 'the project’s rate');
		// no_levels: a budget by role or by category in a team without levels; the mean rate needs none.
		$none = self::obj('{"currency":"CHF","levels":[]}');
		$this->assertSame('422 no_levels levels', $q('"work_packages":[]', null, 'CHF', $none));
		$this->assertSame('422 no_levels levels', $q('"fee_model":"category","work_packages":[{"no":"1","hours":[10]}]', null, 'CHF', $none));
		$this->assertSame('', $q('"fee_model":"mean_rate","mean_rate":176,"work_packages":[{"no":"1","amount":[1000]}]', null, 'CHF', $none));
		$this->assertSame('', self::err(fn () => QuoteRules::requireComplete(self::obj('{"status":"draft","budgets":[]}'), $none, null, '2027-01-15')), 'no budget, nothing to check');
		try {
			QuoteRules::requireComplete(self::obj('{"budgets":[{"id":"b1","fee_model":"category","work_packages":[{"no":"31","level":"d","amount":[5]}]}]}'), $settings, null, '2027-01-15');
			$this->fail('passes without a rate');
		} catch (ApiException $e) {
			$this->assertSame(['error' => 'invalid', 'code' => 'level_rate_missing', 'field' => 'level', 'level' => 'd', 'budget' => 'b1', 'package' => '31'],
				array_diff_key($e->toData(), ['message' => 1]));
		}
		try {
			QuoteRules::requireComplete(self::obj('{"budgets":[{"id":"bq","work_packages":[]}]}'), $none, null, '2027-01-15');
			$this->fail('passes without levels');
		} catch (ApiException $e) {
			$this->assertSame(['error' => 'invalid', 'code' => 'no_levels', 'field' => 'levels', 'budget' => 'bq'], array_diff_key($e->toData(), ['message' => 1]));
		}
		// The single `budget` of an older quote is checked too.
		$this->assertSame('422 amount_without_rate mean_rate', self::err(fn () => QuoteRules::requireComplete(
			self::obj('{"budget":{"id":"b1","fee_model":"mean_rate","work_packages":[{"no":"1","amount":[1]}]}}'), $settings, null, '2027-01-15')));
	}

	public function testLevelIdForm(): void {
		foreach (['a', 'kat-b', '0', str_repeat('x', 40)] as $id) {
			$this->assertTrue(LevelRules::isId($id), $id);
		}
		foreach (['', 'A', '-a', 'a b', 'a.b', 'a_b', str_repeat('x', 41), 1, null] as $id) {
			$this->assertFalse(LevelRules::isId($id), var_export($id, true));
		}
		$this->assertSame('', self::err(fn () => LevelRules::checkId(null)));
		$this->assertSame('422 - level', self::err(fn () => LevelRules::checkId('B')));
		$byNo = LevelRules::packagesByNo([self::obj('{"no":"3","level":"c"}'), self::obj('{"no":"31","parent":"3"}'), self::obj('{"no":"311","parent":"31","level":"b"}'), self::obj('{"no":"9","parent":"9"}')]);
		$this->assertSame('c', LevelRules::levelOf($byNo['31'], $byNo));
		$this->assertSame('b', LevelRules::levelOf($byNo['311'], $byNo));
		$this->assertNull(LevelRules::levelOf($byNo['9'], $byNo), 'a cycle ends');
	}

	public function testCapability(): void {
		$cap = (new Capabilities($this->createMock(IAppManager::class)))->getCapabilities()['timesister'];
		$this->assertSame(1, $cap['fee_models']);
		$this->assertSame([2, 2], [$cap['invoice_layout'], $cap['corrections']], 'the other flags stay');
	}
}
