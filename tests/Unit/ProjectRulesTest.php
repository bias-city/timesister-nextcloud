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
		// Things the app does not compute with pass: budgets that are no objects.
		$this->assertSame('', self::validate('{"id":"p","status":"draft","budgets":"old","work_packages":7}'));
		$this->assertSame('', self::validate('{"id":"p","status":"draft","budgets":[7,"x",null,{"work_packages":"none"}]}'));
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
