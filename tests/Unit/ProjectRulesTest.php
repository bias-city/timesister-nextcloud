<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\Json;
use OCA\TimeSister\Service\ProjectRules;
use OCA\TimeSister\Service\RecordValidator;
use PHPUnit\Framework\TestCase;

/** Quotes (0.16.0): status, codes, from_quote, team, currency and work package rates of a project record. */
class ProjectRulesTest extends TestCase {
	private static function obj(string $json): \stdClass {
		$o = Json::decode($json);
		self::assertInstanceOf(\stdClass::class, $o);
		return $o;
	}

	/** '' when fine, otherwise the status and the message. */
	private static function outcome(callable $fn): string {
		try {
			$fn();
		} catch (ApiException $e) {
			return $e->getStatus() . ' ' . $e->getText()->text();
		}
		return '';
	}

	/** @param array<string,mixed> $fields */
	private static function rec(array $fields): string {
		return (string)json_encode($fields);
	}

	private static function validate(string $json): string {
		return self::outcome(static fn () => ProjectRules::validate(self::obj($json)));
	}

	public function testStatus(): void {
		foreach (['draft', 'quoted', 'declined', 'active', 'running', 'paused', 'completed', 'archived', ' Quoted ', 'aktiv', 'archiviert', '', null] as $s) {
			$this->assertSame('', self::validate(self::rec(['id' => 'p', 'codes' => ['P'], 'status' => $s])), var_export($s, true));
		}
		$this->assertSame('', self::validate('{"id":"p","codes":["P"]}'));
		foreach (['offered', 'done', 7, true, []] as $s) {
			$this->assertStringStartsWith('422 Unknown project status. Allowed: draft, quoted, declined, active, running, paused, completed, archived.', self::validate(self::rec(['id' => 'p', 'codes' => ['P'], 'status' => $s])), var_export($s, true));
		}
	}

	/** A quote needs no code; from active on (also with no status) exactly one. */
	public function testCodesWhileAQuote(): void {
		foreach (['draft', 'quoted', 'declined', ' Draft '] as $s) {
			$this->assertSame('', self::validate(self::rec(['id' => 'p', 'status' => $s])), "$s without codes");
			$this->assertSame('', self::validate(self::rec(['id' => 'p', 'status' => $s, 'codes' => []])), "$s with codes []");
			$this->assertSame('', self::validate(self::rec(['id' => 'p', 'status' => $s, 'codes' => [''], 'subprojects' => []])), "$s with an empty code");
			$this->assertSame('', self::validate(self::rec(['id' => 'p', 'status' => $s, 'codes' => ['Q']])), "$s with one code");
			$this->assertSame('422 A project has exactly one code; parts go into “subprojects”.', self::validate(self::rec(['id' => 'p', 'status' => $s, 'codes' => ['Q', 'R']])), "$s with two codes");
		}
		foreach (['active', 'running', 'paused', 'completed', 'archived', 'aktiv', '', null] as $s) {
			$this->assertSame('422 An active project needs a code.', self::validate(self::rec(['id' => 'p', 'status' => $s])), var_export($s, true) . ' without codes');
			$this->assertSame('422 An active project needs a code.', self::validate(self::rec(['id' => 'p', 'status' => $s, 'codes' => []])), var_export($s, true) . ' with codes []');
			$this->assertSame('422 An active project needs a code.', self::validate(self::rec(['id' => 'p', 'status' => $s, 'codes' => ['', ' ']])), var_export($s, true) . ' with empty codes');
			$this->assertSame('', self::validate(self::rec(['id' => 'p', 'status' => $s, 'codes' => ['P']])), var_export($s, true) . ' with one code');
		}
		$this->assertSame('422 An active project needs a code.', self::validate('{"id":"p"}'), 'no status, no codes');
		$this->assertSame('422 An active project needs a code.', self::validate('{"id":"p","codes":"P"}'), 'codes not a list');
		$this->assertTrue(ProjectRules::isPreliminary('quoted'));
		$this->assertFalse(ProjectRules::isPreliminary('active'));
		$this->assertFalse(ProjectRules::isPreliminary(null));
		$this->assertFalse(ProjectRules::isPreliminary(''));
	}

	/** Set once a quote, kept in every status; only the shape is checked. */
	public function testFromQuote(): void {
		foreach ([true, false, null] as $v) {
			$this->assertSame('', self::validate(self::rec(['id' => 'p', 'status' => 'draft', 'from_quote' => $v])), var_export($v, true));
			$this->assertSame('', self::validate(self::rec(['id' => 'p', 'status' => 'active', 'codes' => ['P'], 'from_quote' => $v])), var_export($v, true) . ' active');
		}
		$this->assertSame('', self::validate('{"id":"p","status":"draft"}'), 'missing is fine');
		foreach (['true', 1, 0, [], 'yes'] as $v) {
			$this->assertSame('422 “from_quote” must be true, false or null.', self::validate(self::rec(['id' => 'p', 'status' => 'draft', 'from_quote' => $v])), var_export($v, true));
		}
		$d = self::obj('{"id":"p","status":"active","codes":["P"],"from_quote":true}');
		ProjectRules::validate($d);
		$this->assertTrue($d->from_quote, 'kept as it is');
	}

	/** The validated team as JSON, or the error. */
	private static function team(string $team): string {
		$d = self::obj('{"id":"p","status":"draft","team":' . $team . '}');
		$err = self::outcome(static fn () => ProjectRules::validate($d));
		return $err !== '' ? $err : (string)json_encode($d->team, JSON_UNESCAPED_UNICODE);
	}

	public function testTeamShapeAndDuplicates(): void {
		$this->assertSame('[{"user":"ann","role":"member"},{"user":"bob","role":"member"}]', self::team('["ann","bob","ann"]'), 'strings become members, duplicates removed');
		$d = self::obj('{"id":"p","status":"draft","team":null}');
		ProjectRules::validate($d);
		$this->assertNull($d->team);
		$this->assertSame('[]', self::team('[]'));
		foreach (['"ann"', '{"a":1}', '[7]', '[["ann"]]', '[true]', '[null]'] as $bad) {
			$this->assertSame('422 “team” must be a list of entries with “user” and “role”.', self::team($bad), $bad);
		}
		$many = array_map(static fn (int $i) => "u$i", range(1, 101));
		$this->assertSame('422 A team of a project holds at most 100 people.', self::validate(self::rec(['id' => 'p', 'status' => 'draft', 'team' => $many])));
		$this->assertSame('', self::validate(self::rec(['id' => 'p', 'status' => 'draft', 'team' => array_merge(array_slice($many, 0, 100), ['u1'])])), 'duplicates do not count');
	}

	/** Roles (0.16.0): member, sparring, support, qa; missing or null is member. */
	public function testTeamRoles(): void {
		$this->assertSame('[{"user":"ann","role":"qa"},{"user":"bob","role":"member"},{"user":"cid","role":"sparring"},{"user":"dan","role":"support"}]',
			self::team('[{"user":"ann","role":"qa"},{"user":"bob","role":"member"},{"user":"cid","role":"sparring"},{"user":"dan","role":"support"}]'));
		$this->assertSame('[{"user":"ann","role":"member"},{"user":"bob","role":"member"},{"user":"cid","role":"member"}]',
			self::team('[{"user":"ann"},{"user":"bob","role":null},"cid"]'), 'role missing or null, a string: member');
		$this->assertSame('[{"user":"ann","role":"qa"}]', self::team('[{"user":"ann","role":"qa","name":"Ann","hours":3}]'), 'other fields dropped');
		$this->assertSame('[{"user":"ann","role":"sparring"},{"user":"bob","role":"member"}]',
			self::team('[{"user":"ann","role":"sparring"},"bob","ann",{"user":"ann","role":"qa"}]'), 'the first entry of a user wins');
		foreach (['"lead"', '"addon"', '"QA"', '""', '7', '["qa"]'] as $role) {
			$this->assertSame('422 “role” in “team” is member, sparring, support or qa.', self::team('[{"user":"ann","role":' . $role . '}]'), $role);
		}
		foreach (['[{"role":"qa"}]', '[{"user":null}]', '[{"user":""}]', '[{"user":7}]', '[""]', '["' . str_repeat('a', 65) . '"]'] as $bad) {
			$this->assertSame('422 “user” in “team” must be a user ID.', self::team($bad), $bad);
		}
	}

	public function testTeamMembers(): void {
		$members = ['ann', 'bob'];
		$this->assertSame('', self::outcome(static fn () => ProjectRules::requireTeamMembers(self::obj('{"team":["ann","bob"]}'), $members)));
		$this->assertSame('', self::outcome(static fn () => ProjectRules::requireTeamMembers(self::obj('{"team":[{"user":"ann","role":"qa"},"bob"]}'), $members)));
		$this->assertSame('', self::outcome(static fn () => ProjectRules::requireTeamMembers(self::obj('{"id":"p"}'), $members)));
		$this->assertSame('', self::outcome(static fn () => ProjectRules::requireTeamMembers('not an object', $members)));
		$this->assertSame('', self::outcome(static fn () => ProjectRules::requireTeamMembers(self::obj('{"team":[7,{"role":"qa"},{"user":["x"]}]}'), $members)), 'a wrong shape is left to validate');
		$this->assertSame('422 Not members of this team: cid, dan.', self::outcome(static fn () => ProjectRules::requireTeamMembers(self::obj('{"team":["ann","cid","dan"]}'), $members)));
		$this->assertSame('422 Not members of this team: cid, dan.',
			self::outcome(static fn () => ProjectRules::requireTeamMembers(self::obj('{"team":[{"user":"cid","role":"support"},"ann",{"user":"dan"},"cid"]}'), $members)), 'objects and strings, each named once');
	}

	public function testCurrency(): void {
		$d = self::obj('{"id":"p","status":"draft","budgets":[{"currency":"chf"},{"currency":null},{}]}');
		ProjectRules::validate($d);
		$this->assertSame('CHF', $d->budgets[0]->currency, 'upper case');
		$this->assertNull($d->budgets[1]->currency);
		foreach (['"CH"', '"CHFR"', '"CH1"', '"€"', '7', '["CHF"]'] as $bad) {
			$this->assertSame('422 “currency” must be three letters, for example CHF.', self::validate('{"id":"p","status":"draft","budgets":[{"currency":' . $bad . '}]}'), $bad);
		}
	}

	public function testRates(): void {
		$this->assertSame('', self::validate('{"id":"p","status":"draft","budgets":[{"work_packages":[{"no":1,"rate":180},{"no":2,"rate":null},{"no":3}]}]}'));
		// Rates per person are the person's cost_rates now: a client's empty default passes, content is refused.
		$this->assertSame('', self::validate('{"id":"p","status":"draft","budgets":[{"rates":null,"rates_x":"ignored"}]}'));
		$this->assertSame('', self::validate('{"id":"p","status":"draft","budgets":[{"rates":{}}]}'), 'an empty object is fine');
		foreach (['{"ann":150}', '{"ann":-1}', '{"ann":"150"}', '[150]', '[]', '150', '"x"'] as $bad) {
			$this->assertSame('422 “rates” is no longer part of a budget; cost rates live on the person.', self::validate('{"id":"p","status":"draft","budgets":[{"rates":' . $bad . '}]}'), $bad);
		}
		foreach (['-0.5', '"180"', 'true', '[180]', '{"a":1}'] as $bad) {
			$this->assertSame('422 “rate” must be a number ≥ 0.', self::validate('{"id":"p","status":"draft","budgets":[{"work_packages":[{"no":1,"rate":' . $bad . '}]}]}'), $bad);
		}
		// The cost cap: null or a number ≥ 0.
		$this->assertSame('', self::validate('{"id":"p","status":"draft","budgets":[{"cap":12000},{"cap":0.5},{"cap":null}]}'));
		foreach (['-1', '"12000"', 'true', '[1]'] as $bad) {
			$this->assertSame('422 “cap” must be null or a number ≥ 0.', self::validate('{"id":"p","status":"draft","budgets":[{"cap":' . $bad . '}]}'), $bad);
		}
		// The planned invoice dates: days, each once.
		$this->assertSame('', self::validate('{"id":"p","status":"draft","budgets":[{"invoice_dates":["2026-12-31","2027-03-31"]},{"invoice_dates":null},{"invoice_dates":[]}]}'));
		foreach (['"2026-12-31"', '["2026-02-30"]', '["2026-12-31","2026-12-31"]', '[20261231]', '{"a":"2026-12-31"}'] as $bad) {
			$this->assertSame('422 “invoice_dates” must be a list of at most 200 days (YYYY-MM-DD), each once.', self::validate('{"id":"p","status":"draft","budgets":[{"invoice_dates":' . $bad . '}]}'), $bad);
		}
		// 0.24.1: progress a whole percent or null; addendum a bool or null.
		$this->assertSame('', self::validate('{"id":"p","status":"draft","budgets":[{"progress":0},{"progress":100},{"progress":45},{"progress":null},{}]}'));
		foreach (['-1', '101', '50.5', '"50"', 'true', '[50]'] as $bad) {
			$this->assertSame('422 “progress” must be null or a whole number from 0 to 100.', self::validate('{"id":"p","status":"draft","budgets":[{"progress":' . $bad . '}]}'), $bad);
		}
		$this->assertSame('', self::validate('{"id":"p","status":"draft","budgets":[{"work_packages":[{"no":1,"addendum":true},{"no":2,"addendum":false},{"no":3,"addendum":null},{"no":4}]}]}'));
		foreach (['1', '"yes"', '[]', '{}'] as $bad) {
			$this->assertSame('422 “addendum” must be true, false or null.', self::validate('{"id":"p","status":"draft","budgets":[{"work_packages":[{"no":1,"addendum":' . $bad . '}]}]}'), $bad);
		}
		// 0.25.0: budget_label text of 1 to 60 or null.
		$this->assertSame('', self::validate('{"id":"p","status":"draft","budget_label":"Phasen"}'));
		$this->assertSame('', self::validate('{"id":"p","status":"draft","budget_label":null}'));
		foreach (['""', '" "', '7', '"' . str_repeat('x', 61) . '"', '["Phasen"]'] as $bad) {
			$this->assertSame('422 “budget_label” must be text of 1 to 60 characters.', self::validate('{"id":"p","status":"draft","budget_label":' . $bad . '}'), $bad);
		}
		// Things the app does not compute with pass: budgets that are no objects.
		$this->assertSame('', self::validate('{"id":"p","status":"draft","budgets":"old","work_packages":7}'));
		$this->assertSame('', self::validate('{"id":"p","status":"draft","budgets":[7,"x",null,{"work_packages":"none"}]}'));
	}

	/** 0.25.1: a work package's group (trimmed text 1–200) and role. */
	public function testWorkPackageGroupAndRole(): void {
		$wp = static fn (string $f): string => self::validate('{"id":"p","status":"draft","budgets":[{"work_packages":[{"no":1,' . $f . '}]}]}');
		foreach (['"group":"Vorprojekt"', '"group":null', '"group":"' . str_repeat('ä', 200) . '"', '"role":"gesamtleitung"', '"role":"fachplanung"', '"role":"bauleitung"', '"role":null', '"group":"A","role":"bauleitung"'] as $ok) {
			$this->assertSame('', $wp($ok), $ok);
		}
		$group = '422 “group” must be null or text of 1 to 200 characters without control characters.';
		foreach (['""', '"   "', '"' . str_repeat('x', 201) . '"', '"a\u0007b"', '"a\nb"', '7', 'true', '["A"]', '{}'] as $bad) {
			$this->assertSame($group, $wp('"group":' . $bad), $bad);
		}
		$o = self::obj('{"id":"p","status":"draft","budgets":[{"work_packages":[{"no":1,"group":"  Vorprojekt  "}]}]}');
		ProjectRules::validate($o);
		$this->assertSame('{"id":"p","status":"draft","budgets":[{"work_packages":[{"no":1,"group":"Vorprojekt"}]}]}', Json::encode($o), 'stored trimmed');
		$this->assertSame($group, self::outcome(static fn () => ProjectRules::checkBudget((object)['work_packages' => [(object)['group' => "\xC3\x28"]]])), 'not UTF-8');
		$role = '422 “role” must be null, “gesamtleitung”, “fachplanung” or “bauleitung”.';
		foreach (['""', '"Bauleitung"', '"lead"', '1', 'true', '["bauleitung"]'] as $bad) {
			$this->assertSame($role, $wp('"role":' . $bad), $bad);
		}
		// The same in a quote's budget.
		$this->assertSame($role, self::outcome(static fn () => ProjectRules::checkBudget(self::obj('{"id":"b1","work_packages":[{"role":"x"}]}'))));
	}

	/** 0.25.1: the structured invoice plan beside `invoice_dates`. */
	public function testBillingPlan(): void {
		$bp = static fn (string $v): string => self::validate('{"id":"p","status":"draft","budgets":[{"invoice_dates":["2026-12-31"],"billing_plan":' . $v . '}]}');
		foreach (['null', '[]', '[{"date":"2026-12-31"}]',
			'[{"date":"2026-06-30","share":30,"title":"Vorprojekt"},{"date":"2026-12-31","share":70.5},{"date":"2027-03-31","amount":1200.5},{"date":"2027-06-30","amount":0,"share":null,"title":null},{"date":"2027-09-30","share":0},{"date":"2027-12-31","share":100,"title":""}]'] as $ok) {
			$this->assertSame('', $bp($ok), $ok);
		}
		$list = '422 “billing_plan” must be null or a list of at most 200 entries.';
		$many = json_encode(array_map(static fn (int $i) => ['date' => date('Y-m-d', 1767225600 + $i * 86400)], range(0, 200)));
		$this->assertSame('', $bp((string)json_encode(array_slice((array)json_decode((string)$many), 0, 200))), '200 entries');
		foreach (['{}', '"2026-12-31"', '7', '{"0":{"date":"2026-12-31"}}', (string)$many] as $bad) {
			$this->assertSame($list, $bp($bad), substr($bad, 0, 40));
		}
		$cases = [
			'["2026-12-31"]' => 'Entry 1 of “billing_plan” must be an object with “date”.',
			'[{"date":"2026-12-31"},null]' => 'Entry 2 of “billing_plan” must be an object with “date”.',
			'[{"date":"2026-12-31","note":"x"}]' => 'Entry 1 of “billing_plan” has an unknown field “note”; allowed are date, share, amount and title.',
			'[{"share":10}]' => 'Entry 1 of “billing_plan”: “date” must be a day (YYYY-MM-DD).',
			'[{"date":"2026-02-30"}]' => 'Entry 1 of “billing_plan”: “date” must be a day (YYYY-MM-DD).',
			'[{"date":"31.12.2026"}]' => 'Entry 1 of “billing_plan”: “date” must be a day (YYYY-MM-DD).',
			'[{"date":null}]' => 'Entry 1 of “billing_plan”: “date” must be a day (YYYY-MM-DD).',
			'[{"date":"2026-12-31"},{"date":"2026-12-31"}]' => '“billing_plan” has the date 2026-12-31 twice.',
			'[{"date":"2026-12-31","share":-1}]' => 'Entry 1 of “billing_plan”: “share” must be null or a number from 0 to 100.',
			'[{"date":"2026-12-31","share":100.01}]' => 'Entry 1 of “billing_plan”: “share” must be null or a number from 0 to 100.',
			'[{"date":"2026-12-31","share":"30"}]' => 'Entry 1 of “billing_plan”: “share” must be null or a number from 0 to 100.',
			'[{"date":"2026-12-31","amount":-0.5}]' => 'Entry 1 of “billing_plan”: “amount” must be null or a number ≥ 0.',
			'[{"date":"2026-12-31","amount":"1000"}]' => 'Entry 1 of “billing_plan”: “amount” must be null or a number ≥ 0.',
			'[{"date":"2026-12-31","amount":true}]' => 'Entry 1 of “billing_plan”: “amount” must be null or a number ≥ 0.',
			'[{"date":"2026-12-31","share":50,"amount":1000}]' => 'Entry 1 of “billing_plan”: set “share” or “amount”, not both.',
			'[{"date":"2026-12-31","title":7}]' => 'Entry 1 of “billing_plan”: “title” must be null or text of at most 200 characters without control characters.',
			'[{"date":"2026-12-31","title":"' . str_repeat('x', 201) . '"}]' => 'Entry 1 of “billing_plan”: “title” must be null or text of at most 200 characters without control characters.',
			'[{"date":"2026-12-31","title":"a\tb"}]' => 'Entry 1 of “billing_plan”: “title” must be null or text of at most 200 characters without control characters.',
		];
		foreach ($cases as $bad => $msg) {
			$this->assertSame('422 ' . $msg, $bp($bad), $bad);
		}
		// The same in a quote's budget; `invoice_dates` unchanged beside it.
		$this->assertSame('422 Entry 1 of “billing_plan”: set “share” or “amount”, not both.',
			self::outcome(static fn () => ProjectRules::checkBudget(self::obj('{"id":"b1","billing_plan":[{"date":"2026-12-31","share":1,"amount":1}]}'))));
		$this->assertSame('422 “invoice_dates” must be a list of at most 200 days (YYYY-MM-DD), each once.', $bp('null') === '' ? self::validate('{"id":"p","status":"draft","budgets":[{"invoice_dates":"x","billing_plan":[]}]}') : 'no');
	}

	/** The record validator runs the rules for projects only. */
	public function testViaRecordValidator(): void {
		$v = RecordValidator::validate('project', 'q1', self::obj('{"id":"q1","status":"draft","from_quote":true,"team":["ann",{"user":"ann","role":"qa"},{"user":"bob","role":"support","x":1}],"budgets":[{"currency":"chf","work_packages":[{"no":1,"rate":180}]}]}'));
		$this->assertSame('{"id":"q1","status":"draft","from_quote":true,"team":[{"user":"ann","role":"member"},{"user":"bob","role":"support"}],"budgets":[{"currency":"CHF","work_packages":[{"no":1,"rate":180}]}]}', $v['json']);
		$this->assertSame('422 An active project needs a code.',
			self::outcome(static fn () => RecordValidator::validate('project', 'q1', self::obj('{"id":"q1","status":"active","from_quote":true,"codes":[]}'))), 'set to active without a code');
		$this->assertSame('', self::outcome(static fn () => RecordValidator::validate('project', 'q1', self::obj('{"id":"q1","status":"active","from_quote":true,"codes":["Q1"]}'))), 'active with a code');
		$this->assertSame('422 Unknown project status. Allowed: draft, quoted, declined, active, running, paused, completed, archived.',
			self::outcome(static fn () => RecordValidator::validate('project', 'q1', self::obj('{"id":"q1","status":"offered"}'))));
		$this->assertSame('', self::outcome(static fn () => RecordValidator::validate('customer', 'k1', self::obj('{"id":"k1","status":"offered"}'))), 'customers are not checked');
	}
}
