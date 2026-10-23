<?php

declare(strict_types=1);

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Db\Record;
use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\Json;
use OCA\TimeSister\Service\ProgressLog;
use OCA\TimeSister\Service\ProjectRules;
use PHPUnit\Framework\TestCase;

/** 0.27.0: the progress log per work package – checked on write, joined with the stored one. */
final class ProgressLogTest extends TestCase {
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

	private static function wp(string $log): string {
		return self::outcome(static fn () => ProgressLog::check(self::obj('{"no":"1","progress_log":' . $log . '}')));
	}

	private static function record(?string $json): Record {
		$r = new Record();
		$r->setKind('project');
		$r->setRkey('p');
		$r->setData($json);
		return $r;
	}

	public function testCheck(): void {
		foreach (['null', '[]', '[{"date":"2026-10-21","done":40}]', '[{"date":"2026-10-21","remaining":12.5,"by":"lea"}]', '[{"date":"2026-10-21","done":0,"remaining":0}]'] as $ok) {
			$this->assertSame('', self::wp($ok), $ok);
		}
		$list = '422 “progress_log” must be null or a list of at most 500 entries.';
		$this->assertSame($list, self::wp('{}'));
		$this->assertSame($list, self::wp('"x"'));
		$this->assertSame($list, self::wp(json_encode(array_fill(0, 501, ['date' => '2026-01-01', 'done' => 1]))));
		$day = '422 Each “progress_log” entry needs a day “date” (YYYY-MM-DD).';
		$this->assertSame($day, self::wp('[{"done":1}]'));
		$this->assertSame($day, self::wp('[{"date":"gestern","done":1}]'));
		$this->assertSame($day, self::wp('[7]'));
		$this->assertSame('422 “done” must be null or a whole number from 0 to 100.', self::wp('[{"date":"2026-10-21","done":101}]'));
		$this->assertSame('422 “done” must be null or a whole number from 0 to 100.', self::wp('[{"date":"2026-10-21","done":"40"}]'));
		$this->assertSame('422 “remaining” must be null or a number ≥ 0.', self::wp('[{"date":"2026-10-21","remaining":-1}]'));
		$this->assertSame('422 “remaining” must be null or a number ≥ 0.', self::wp('[{"date":"2026-10-21","remaining":"12"}]'));
		$this->assertSame('422 A “progress_log” entry needs “done” or “remaining”.', self::wp('[{"date":"2026-10-21","by":"lea"}]'));
		$this->assertSame('422 “by” must be a text of at most 100 characters.', self::wp('[{"date":"2026-10-21","done":1,"by":7}]'));
		$this->assertSame('422 “by” must be a text of at most 100 characters.', self::wp('[{"date":"2026-10-21","done":1,"by":"' . str_repeat('x', 101) . '"}]'));
		// Stored trimmed and ordered by date; the whole project passes through ProjectRules.
		$o = self::obj('{"id":"p","status":"draft","budgets":[{"id":"b1","work_packages":[{"no":1,"progress":40,"progress_log":[{"date":" 2026-10-21 ","done":40,"by":" lea "},{"date":"2026-09-01","remaining":80}]}]}]}');
		ProjectRules::validate($o);
		$this->assertSame('[{"date":"2026-09-01","remaining":80,"by":""},{"date":"2026-10-21","done":40,"by":"lea"}]', Json::encode($o->budgets[0]->work_packages[0]->progress_log));
	}

	public function testMergeKeepsStoredEntries(): void {
		$stored = self::record('{"id":"p","budgets":[{"id":"b1","work_packages":[{"no":"1","progress":40,"progress_log":[{"date":"2026-09-01","done":20,"by":"lea"},{"date":"2026-10-01","done":40,"by":"bob"}]},{"no":"2","progress_log":[{"date":"2026-09-01","remaining":50,"by":"lea"}]}]}]}');
		// A client that loaded the budget before bob's entry sends the log without it - it comes back.
		$in = '{"id":"p","budgets":[{"id":"b1","work_packages":[{"no":"1","progress":55,"progress_log":[{"date":"2026-09-01","done":20,"by":"lea"},{"date":"2026-10-21","done":55,"by":"lea"}]},{"no":"2","progress_log":[]}]}]}';
		$out = self::obj((string)ProgressLog::merge($stored, $in, 'lea', '2026-10-21'));
		$wps = $out->budgets[0]->work_packages;
		$this->assertSame(['2026-09-01', '2026-10-01', '2026-10-21'], array_map(static fn ($e) => $e->date, $wps[0]->progress_log));
		$this->assertSame(55, $wps[0]->progress, 'the client keeps progress');
		$this->assertSame(['2026-09-01'], array_map(static fn ($e) => $e->date, $wps[1]->progress_log), 'an emptied list gets the stored entries back');
		// Same date and person: the incoming entry wins (a correction of the day), nothing doubles.
		$in2 = '{"id":"p","budgets":[{"id":"b1","work_packages":[{"no":"1","progress_log":[{"date":"2026-10-01","done":45,"by":"bob"}]}]}]}';
		$out2 = self::obj((string)ProgressLog::merge($stored, $in2, 'bob', '2026-10-21'));
		$log = $out2->budgets[0]->work_packages[0]->progress_log;
		$this->assertCount(2, $log);
		$this->assertSame([['2026-09-01', 20], ['2026-10-01', 45]], array_map(static fn ($e) => [$e->date, $e->done], $log));
	}

	public function testMergeLogsAnOldClientsProgress(): void {
		$stored = self::record('{"id":"p","budgets":[{"id":"b1","work_packages":[{"no":"1","progress":40,"progress_log":[{"date":"2026-10-01","done":40,"by":"bob"}]}]}]}');
		// No `progress_log` key at all: an old client. Its changed progress becomes today's entry by the caller.
		$in = '{"id":"p","budgets":[{"id":"b1","work_packages":[{"no":"1","progress":60}]}]}';
		$out = self::obj((string)ProgressLog::merge($stored, $in, 'lea', '2026-10-21'));
		$log = $out->budgets[0]->work_packages[0]->progress_log;
		$this->assertSame([['2026-10-01', 40, 'bob'], ['2026-10-21', 60, 'lea']], array_map(static fn ($e) => [$e->date, $e->done, $e->by], $log));
		// The same progress as the newest entry: the stored log comes along, nothing is added.
		$same = '{"id":"p","budgets":[{"id":"b1","work_packages":[{"no":"1","progress":40}]}]}';
		$out = self::obj((string)ProgressLog::merge($stored, $same, 'lea', '2026-10-21'));
		$this->assertCount(1, $out->budgets[0]->work_packages[0]->progress_log);
		// Nothing stored, a new package, a deletion, a tombstone: the record passes as it is.
		$this->assertSame($in, ProgressLog::merge(null, $in, 'lea', '2026-10-21'));
		$this->assertSame($in, ProgressLog::merge(self::record(null), $in, 'lea', '2026-10-21'));
		$this->assertNull(ProgressLog::merge($stored, null, 'lea', '2026-10-21'));
		$new = '{"id":"p","budgets":[{"id":"b2","work_packages":[{"no":"1","progress":60}]}]}';
		$this->assertSame($new, ProgressLog::merge($stored, $new, 'lea', '2026-10-21'));
	}
}
