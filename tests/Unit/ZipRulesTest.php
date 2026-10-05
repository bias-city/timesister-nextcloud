<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\Json;
use OCA\TimeSister\Service\ZipRules;
use PHPUnit\Framework\TestCase;

/** Team backup 0.10.0: names, paths, manifest, mapping, merge/replace. */
class ZipRulesTest extends TestCase {
	private static function code(callable $fn): string {
		try {
			$fn();
		} catch (ApiException $e) {
			return $e->getStatus() . ' ' . $e->getErrorCode();
		}
		return 'ok';
	}

	public function testNames(): void {
		$this->assertSame('timesister-at-2026-10-05_1430.zip', ZipRules::zipName('at', gmmktime(14, 30, 0, 10, 5, 2026)));
		$this->assertSame('records/person/mia.x-1.json', ZipRules::recordPath('person', 'mia.x-1'));
		$this->assertSame('calendars/pbuser1.ics', ZipRules::calendarPath('pbuser1'));
		$this->assertStringStartsWith('calendars/h-', ZipRules::calendarPath("o'neil x"));
	}

	public function testSafePath(): void {
		foreach (['team.json', 'records/person/a.json', 'calendars/h-abc.ics', 'a/..b/c'] as $ok) {
			$this->assertTrue(ZipRules::isSafePath($ok), $ok);
		}
		foreach (['', '/etc/passwd', '../x', 'a/../b', 'a/./b', 'a//b', 'a\\b', "a\nb", 'a/', str_repeat('a', 251)] as $bad) {
			$this->assertFalse(ZipRules::isSafePath($bad), $bad);
		}
	}

	/** @return array<string,mixed> */
	private static function manifest(array $files = []): array {
		return ZipRules::manifest(['id' => 3, 'name' => 'AT', 'slug' => 'at', 'group' => 'at'], '0.10.0', 2, 1_800_000_000, $files);
	}

	public function testManifestRoundTrip(): void {
		$team = '{"name":"AT"}';
		$m = self::manifest(['team.json' => ZipRules::fileEntry($team), 'calendars/x.ics' => ['missing' => true, 'uid' => 'x']]);
		$this->assertTrue($m['contains_secrets']);
		$this->assertSame(1, $m['format']);
		$decoded = json_decode(json_encode($m), true);
		$v = ZipRules::checkManifest($decoded);
		$this->assertSame('at', $v['team']['slug']);
		$this->assertSame(hash('sha256', $team), $v['files']['team.json']['sha256']);
		$this->assertTrue($v['files']['calendars/x.ics']['missing']);
		$this->assertSame('x', $v['files']['calendars/x.ics']['uid']);
		// Verification: sizes, checksums, unlisted files.
		$read = static fn (string $p): string => $p === 'team.json' ? $team : '';
		ZipRules::verify($v['files'], ['team.json' => strlen($team)], $read);
		$this->assertSame('422 invalid', self::code(fn () => ZipRules::verify($v['files'], ['team.json' => 3], $read)));
		$this->assertSame('422 invalid', self::code(fn () => ZipRules::verify($v['files'], [], $read)));
		$this->assertSame('422 invalid', self::code(fn () => ZipRules::verify($v['files'], ['team.json' => strlen($team), 'extra.txt' => 1], $read)));
		$this->assertSame('422 invalid', self::code(fn () => ZipRules::verify($v['files'], ['team.json' => strlen($team)], static fn () => 'other')));
	}

	public function testManifestRejected(): void {
		$this->assertSame('422 invalid', self::code(fn () => ZipRules::checkManifest(null)));
		$this->assertSame('422 invalid', self::code(fn () => ZipRules::checkManifest(['format' => 2])));
		$base = json_decode(json_encode(self::manifest()), true);
		$bad = $base;
		$bad['files'] = ['../x' => ['sha256' => str_repeat('a', 64), 'size' => 1]];
		$this->assertSame('422 invalid', self::code(fn () => ZipRules::checkManifest($bad)));
		$bad = $base;
		$bad['files'] = ['x' => ['sha256' => 'zz', 'size' => 1]];
		$this->assertSame('422 invalid', self::code(fn () => ZipRules::checkManifest($bad)));
		$bad = $base;
		$bad['taken_at'] = 'yesterday';
		$this->assertSame('422 invalid', self::code(fn () => ZipRules::checkManifest($bad)));
	}

	public function testModeAndToken(): void {
		$this->assertSame('merge', ZipRules::checkMode('merge'));
		$this->assertSame('replace', ZipRules::checkMode('replace'));
		$this->assertSame('400 invalid', self::code(fn () => ZipRules::checkMode('both')));
		$this->assertSame(str_repeat('ab', 16), ZipRules::checkToken(str_repeat('ab', 16)));
		$this->assertSame('400 invalid', self::code(fn () => ZipRules::checkToken('../x')));
		$this->assertSame('400 invalid', self::code(fn () => ZipRules::checkToken(null)));
	}

	public function testMapping(): void {
		$uids = ['a', 'b', 'c'];
		$this->assertSame([], ZipRules::checkMapping(null, $uids));
		$m = ZipRules::checkMapping((object)['a' => 'x', 'b' => null], $uids);
		$this->assertSame(['a' => 'x', 'b' => null], $m);
		$this->assertSame('x', ZipRules::mapUid('a', $m));
		$this->assertNull(ZipRules::mapUid('b', $m));
		$this->assertSame('c', ZipRules::mapUid('c', $m));
		$this->assertSame('b', ZipRules::keepUid('b', $m));
		$this->assertSame('422 invalid', self::code(fn () => ZipRules::checkMapping(['z' => 'x'], $uids)), 'not in the backup');
		$this->assertSame('422 invalid', self::code(fn () => ZipRules::checkMapping(['a' => 'x', 'b' => 'x'], $uids)), 'same target twice');
		$this->assertSame('422 invalid', self::code(fn () => ZipRules::checkMapping(['a' => "x\n"], $uids)));
		$this->assertSame('422 invalid', self::code(fn () => ZipRules::checkMapping(['a' => 'x/y'], $uids)));
		$this->assertSame('400 invalid', self::code(fn () => ZipRules::checkMapping('x', $uids)));
	}

	public function testMapKeys(): void {
		$m = ['old' => 'new', 'gone' => null];
		$this->assertSame('new', ZipRules::mapKey('person', 'old', $m));
		$this->assertSame('gone', ZipRules::mapKey('person', 'gone', $m), 'without account the record keeps its key');
		$this->assertSame('other', ZipRules::mapKey('person', 'other', $m));
		$hash = str_repeat('0', 32);
		$this->assertSame('new+' . $hash, ZipRules::mapKey('billing', 'old+' . $hash, $m));
		$this->assertSame('old', ZipRules::mapKey('project', 'old', $m), 'project keys are not accounts');
	}

	public function testMapData(): void {
		$m = ['old' => 'new', 'gone' => null];
		$person = Json::decode('{"login":"old","accounts":["old","gone","other"],"name":"Mia"}');
		$out = ZipRules::mapData($person, $m);
		$this->assertSame('new', $out->login);
		$this->assertSame(['new', 'other'], $out->accounts, 'a dropped account leaves the list');
		$this->assertSame('Mia', $out->name);
		$job = Json::decode('{"sender":"old","recipients":["gone","old"],"assignee":"gone","counters":[{"by":"old"}],'
			. '"log":[{"by":"old","to":["gone","old"]}],"names":{"old":"A","gone":"B"},"change":{"fields":{"hours":{"from":1,"to":2}}}}');
		$out = ZipRules::mapData($job, $m);
		$this->assertSame('new', $out->sender);
		$this->assertSame(['new'], $out->recipients);
		$this->assertSame('gone', $out->assignee, 'scalar fields keep a dropped account');
		$this->assertSame('new', $out->counters[0]->by);
		$this->assertSame(['new'], $out->log[0]->to);
		$this->assertSame(['new' => 'A', 'gone' => 'B'], (array)$out->names);
		$this->assertSame(2, $out->change->fields->hours->to, 'change.fields.to is a value, not an account');
		$settings = Json::decode('{"vacation_calendar":{"owner":"old","url":"https://x/remote.php/dav/calendars/old/ts-vac/"}}');
		$out = ZipRules::mapData($settings, $m);
		$this->assertSame('new', $out->vacation_calendar->owner);
		$this->assertSame('https://x/remote.php/dav/calendars/new/ts-vac/', $out->vacation_calendar->url);
		$this->assertSame($person, ZipRules::mapData($person, []), 'no mapping: untouched');
	}

	public function testDecide(): void {
		$src = ['modified_at' => 200, 'deleted' => false, 'data' => '{"a":1}'];
		$this->assertSame('insert', ZipRules::decide(null, $src, 'merge'));
		$same = ['modified_at' => 100, 'deleted' => false, 'data' => '{"a":1}'];
		$this->assertSame('skip', ZipRules::decide($same, $src, 'merge'));
		$this->assertSame('skip', ZipRules::decide($same, $src, 'replace'), 'same content: nothing to write');
		$older = ['modified_at' => 100, 'deleted' => false, 'data' => '{"a":0}'];
		$this->assertSame('update', ZipRules::decide($older, $src, 'merge'));
		$newer = ['modified_at' => 300, 'deleted' => false, 'data' => '{"a":0}'];
		$this->assertSame('skip', ZipRules::decide($newer, $src, 'merge'), 'merge keeps the younger target');
		$this->assertSame('update', ZipRules::decide($newer, $src, 'replace'), 'replace takes the backup');
		$tomb = ['modified_at' => 300, 'deleted' => true, 'data' => null];
		$this->assertSame('update', ZipRules::decide($tomb, $src, 'replace'));
		$this->assertSame('skip', ZipRules::decide($tomb, $src, 'merge'));
	}

	public function testRecordFile(): void {
		$r = Json::decode('{"kind":"person","key":"mia","version":3,"deleted":false,"modified_by":"adm","modified_at":"2026-10-01T10:00:00Z",'
			. '"data":{"login":"mia"},"accounts":["mia"],"history":[{"version":3,"deleted":false,"modified_by":"adm","modified_at":"2026-10-01T10:00:00Z","data":{"login":"mia"}},'
			. '{"version":1,"deleted":false,"modified_by":"adm","modified_at":"2026-09-01T10:00:00Z","data":{"login":"mia"}},'
			. '{"version":1,"deleted":false,"modified_by":"adm","modified_at":"2026-09-01T10:00:00Z","data":{"login":"mia"}}]}');
		$v = ZipRules::checkRecordFile($r);
		$this->assertSame('person', $v['kind']);
		$this->assertSame(3, $v['version']);
		$this->assertSame(['mia'], $v['accounts']);
		$this->assertSame([1, 3], array_column($v['history'], 'version'), 'ascending, duplicates dropped');
		$tomb = Json::decode('{"kind":"project","key":"p1","version":2,"deleted":true,"modified_by":"adm","modified_at":"2026-10-01T10:00:00Z","data":null}');
		$this->assertNull(ZipRules::checkRecordFile($tomb)['data']);
		foreach (['{"kind":"person"}', '{"kind":"x","key":"a","version":1,"deleted":false,"modified_by":"a","modified_at":"2026-10-01T10:00:00Z","data":{}}',
			'{"kind":"person","key":"../a","version":1,"deleted":false,"modified_by":"a","modified_at":"2026-10-01T10:00:00Z","data":{}}',
			'{"kind":"person","key":"a","version":1,"deleted":false,"modified_by":"a","modified_at":"soon","data":{}}',
			'{"kind":"person","key":"a","version":1,"deleted":false,"modified_by":"a","modified_at":"2026-10-01T10:00:00Z","data":null}'] as $bad) {
			$this->assertNotSame('ok', self::code(fn () => ZipRules::checkRecordFile(Json::decode($bad))), $bad);
		}
	}
}
