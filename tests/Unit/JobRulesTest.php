<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\JobFlow;
use OCA\TimeSister\Service\JobRules;
use PHPUnit\Framework\TestCase;

/** Jobs: fields, target in the project, volume, overlap, who sees. */
class JobRulesTest extends TestCase {
	private const NOW = '2026-09-28T08:00:00Z';

	/** @return array{0:string,1:array<string,mixed>} status and error data, or 'ok' */
	private static function err(callable $fn): array {
		try {
			$fn();
		} catch (ApiException $e) {
			return [$e->getStatus() . ' ' . $e->getErrorCode(), $e->getExtra()];
		}
		return ['ok', []];
	}

	private static function code(callable $fn): string {
		return self::err($fn)[0];
	}

	/** @param array<string,mixed> $v */
	private static function obj(array $v): \stdClass {
		return json_decode((string)json_encode($v));
	}

	/** @return array<string,mixed> */
	private static function project(): array {
		return [
			'id' => 'D200', 'codes' => ['D200'], 'subprojects' => [['code' => 'D200.1', 'name' => 'Analysis'], ['code' => 'D200.2', 'name' => 'Interviews']],
			'leads' => ['pblead'],
			'budgets' => [['id' => 'b1', 'work_packages' => [
				['no' => 'AP1', 'name' => 'Basics', 'codes' => ['D200.1'], 'hours' => [30, 10.5]],
				['no' => 'AP2', 'name' => '', 'codes' => [], 'hours' => [20]],
			]]],
		];
	}

	/** @param array<string,mixed> $over @return array<string,mixed> */
	private static function offer(array $over = []): array {
		return array_merge(['project' => 'D200', 'code' => 'D200.1', 'budget' => 'b1', 'work_package' => 'AP1',
			'hours' => 20, 'start' => '2026-10-01', 'end' => '2026-10-31', 'recipients' => ['pbuser1']], $over);
	}

	/** @param array<string,mixed> $over @return array<string,mixed> */
	private static function job(string $key, array $over = []): array {
		$f = JobRules::offer(self::obj(self::offer($over)));
		$job = JobFlow::create($key, $f, JobRules::target(self::project(), $f['code'], $f['budget'], $f['work_package']), 'pblead', self::NOW);
		return ['id' => $key] + $job;
	}

	public function testOfferFields(): void {
		$f = JobRules::offer(self::obj(self::offer(['title' => '  ', 'description' => ' Interviews ', 'recipients' => ['a', 'b', 'a']])));
		$this->assertSame(20.0, $f['hours']);
		$this->assertNull($f['title']);
		$this->assertSame('Interviews', $f['description']);
		$this->assertSame(['a', 'b'], $f['recipients']);
		$this->assertSame('422 invalid', self::code(fn () => JobRules::offer(self::obj(self::offer(['hours' => 0])))));
		$this->assertSame('422 invalid', self::code(fn () => JobRules::offer(self::obj(self::offer(['hours' => '8'])))));
		$this->assertSame('422 invalid', self::code(fn () => JobRules::offer(self::obj(self::offer(['end' => '2026-09-30'])))));
		$this->assertSame('422 invalid', self::code(fn () => JobRules::offer(self::obj(self::offer(['start' => '2026-02-30'])))));
		$this->assertSame('422 invalid', self::code(fn () => JobRules::offer(self::obj(self::offer(['end' => '2037-01-01'])))));
		$this->assertSame('422 invalid', self::code(fn () => JobRules::offer(self::obj(self::offer(['recipients' => []])))));
		$this->assertSame('422 invalid', self::code(fn () => JobRules::offer(self::obj(self::offer(['work_package' => null])))));
		$this->assertSame('422 invalid', self::code(fn () => JobRules::offer(self::obj(self::offer(['description' => str_repeat('x', 4001)])))));
		$this->assertSame('422 invalid', self::code(fn () => JobRules::offer(self::obj(['project' => 'D200']))));
		$this->assertSame('400 invalid', self::code(fn () => JobRules::checkKey(str_repeat('a', 65))));
		$this->assertSame('ok', self::code(fn () => JobRules::checkKey('j-2026-abc')));
	}

	public function testChangeAndCounterFields(): void {
		$this->assertSame(['hours' => 12.5], JobRules::change(self::obj(['hours' => 12.5])));
		$this->assertSame('422 invalid', self::code(fn () => JobRules::change(new \stdClass())));
		$this->assertSame('422 invalid', self::code(fn () => JobRules::change(self::obj(['budget' => 'b2']))));
		$this->assertSame(['end' => '2026-11-15', 'note' => 'later'], JobRules::counter(self::obj(['end' => '2026-11-15', 'note' => 'later'])));
		$this->assertSame('422 invalid', self::code(fn () => JobRules::counter(self::obj(['note' => 'only a note']))));
		$this->assertSame([['key' => 'j1', 'hours' => 3.33, 'invoiced' => false], ['key' => '42', 'hours' => 1.0, 'invoiced' => true]],
			JobRules::progress(self::obj(['jobs' => ['j1' => ['hours' => 3.333], '42' => ['hours' => 1, 'invoiced' => true]]])));
		$this->assertSame('422 invalid', self::code(fn () => JobRules::progress(self::obj(['jobs' => ['j1' => ['hours' => -1]]]))));
		$this->assertSame('422 invalid', self::code(fn () => JobRules::progress(self::obj(['jobs' => []]))));
	}

	public function testTarget(): void {
		$p = self::project();
		$this->assertSame(['codes' => ['D200.1'], 'ap_hours' => 40.5, 'ap_name' => 'Basics'], JobRules::target($p, 'd200.1', 'b1', 'AP1'));
		// A work package without codes and name: the chosen code, the number as name.
		$this->assertSame(['codes' => ['D200.2'], 'ap_hours' => 20.0, 'ap_name' => 'AP2'], JobRules::target($p, 'D200.2', 'b1', 'AP2'));
		$this->assertSame(['codes' => ['D200'], 'ap_hours' => null, 'ap_name' => null], JobRules::target($p, 'D200', null, null));
		$this->assertSame(['422 invalid', ['rule' => 'code']], self::err(fn () => JobRules::target($p, 'D200.2', 'b1', 'AP1')));
		$this->assertSame(['422 invalid', ['rule' => 'code']], self::err(fn () => JobRules::target($p, 'X9', null, null)));
		$this->assertSame(['422 invalid', ['rule' => 'work_package']], self::err(fn () => JobRules::target($p, 'D200.1', 'b1', 'AP9')));
		$this->assertSame(['422 invalid', ['rule' => 'budget']], self::err(fn () => JobRules::target($p, 'D200.1', 'b9', 'AP1')));
	}

	public function testVolume(): void {
		$a = self::job('a', ['hours' => 30]);
		$this->assertSame('ok', self::code(fn () => JobRules::checkVolume(self::job('b', ['hours' => 10.5]), 40.5, [$a])));
		[$code, $extra] = self::err(fn () => JobRules::checkVolume(self::job('b', ['hours' => 11]), 40.5, [$a]));
		$this->assertSame('422 invalid', $code);
		$this->assertSame(['rule' => 'volume', 'free' => 10.5, 'total' => 40.5], $extra);
		// The Job itself does not count twice (a change).
		$this->assertSame('ok', self::code(fn () => JobRules::checkVolume(['hours' => 40.5] + $a, 40.5, [$a])));
		// Declined gives everything back, returned keeps what was booked.
		$declined = JobFlow::decline($a, 'pbuser1', self::NOW);
		$this->assertSame(0.0, JobRules::hold($declined));
		$running = JobFlow::accept($a, 'pbuser1', self::NOW);
		$running = (array)JobFlow::progress($running, 12, false, 'pbuser1', self::NOW);
		$this->assertSame(30.0, JobRules::hold($running));
		$returned = JobFlow::giveBack($running, null, 'pbuser1', self::NOW);
		$this->assertSame(12.0, JobRules::hold($returned));
		$this->assertSame('ok', self::code(fn () => JobRules::checkVolume(self::job('b', ['hours' => 28.5]), 40.5, [$returned])));
		// Another work package, or no budget: no limit from this one.
		$this->assertSame('ok', self::code(fn () => JobRules::checkVolume(self::job('c', ['work_package' => 'AP2', 'code' => 'D200.2', 'hours' => 20]), 20, [$a])));
		$this->assertSame('ok', self::code(fn () => JobRules::checkVolume(self::job('d', ['budget' => null, 'work_package' => null, 'hours' => 999]), 0, [$a])));
	}

	public function testOverlap(): void {
		$a = self::job('a', ['recipients' => ['pbuser1', 'pbuser2']]);
		$same = self::job('b', ['start' => '2026-10-31', 'end' => '2026-11-30']);
		[$code, $extra] = self::err(fn () => JobRules::checkOverlap($same, ['pbuser1'], [$a], fn (string $u) => "Name of $u"));
		$this->assertSame('422 invalid', $code);
		$this->assertSame(['rule' => 'overlap', 'user' => 'pbuser1', 'other' => 'a'], $extra);
		// Another period, another person, another code: fine.
		$this->assertSame('ok', self::code(fn () => JobRules::checkOverlap(self::job('b', ['start' => '2026-11-01', 'end' => '2026-11-30']), ['pbuser1'], [$a])));
		$this->assertSame('ok', self::code(fn () => JobRules::checkOverlap($same, ['pbuser3'], [$a])));
		$this->assertSame('ok', self::code(fn () => JobRules::checkOverlap(self::job('b', ['work_package' => 'AP2', 'code' => 'D200.2']), ['pbuser1'], [$a])));
		// A project Job on the same code collides with the work package's Job.
		$this->assertSame('422 invalid', self::code(fn () => JobRules::checkOverlap(self::job('b', ['budget' => null, 'work_package' => null]), ['pbuser2'], [$a])));
		// Once pbuser1 declined, the offer no longer counts for them; for pbuser2 it does.
		$d = JobFlow::decline($a, 'pbuser1', self::NOW);
		$this->assertSame('ok', self::code(fn () => JobRules::checkOverlap($same, ['pbuser1'], [$d])));
		$this->assertSame('422 invalid', self::code(fn () => JobRules::checkOverlap($same, ['pbuser2'], [$d])));
		// Done no longer blocks.
		$done = JobFlow::done(JobFlow::accept($a, 'pbuser2', self::NOW), 'pbuser2', self::NOW);
		$this->assertSame('ok', self::code(fn () => JobRules::checkOverlap($same, ['pbuser2'], [$done])));
	}

	public function testView(): void {
		$a = self::job('a', ['recipients' => ['pbuser1', 'pbuser2']]);
		$acc = JobRules::accounts($a, []);
		$this->assertSame(['pblead', 'pbuser1', 'pbuser2'], $acc);
		$this->assertSame('full', JobRules::view($a, 'pbuser1', false, false, $acc));
		$this->assertSame('full', JobRules::view($a, 'pblead', false, false, $acc));
		$this->assertSame('full', JobRules::view($a, 'pbadmin', true, false, []));
		$this->assertSame('full', JobRules::view($a, 'otherlead', false, true, []));
		$this->assertSame('none', JobRules::view($a, 'pbuser3', false, false, $acc));
		$taken = JobFlow::accept($a, 'pbuser2', self::NOW);
		$this->assertSame('gone', JobRules::view($taken, 'pbuser1', false, false, $acc));
		$this->assertSame('full', JobRules::view($taken, 'pbuser2', false, false, $acc));
		$returned = JobFlow::giveBack($taken, 'too much', 'pbuser2', self::NOW);
		$this->assertSame('gone', JobRules::view($returned, 'pbuser2', false, false, $acc));
		$this->assertSame('full', JobRules::view(JobFlow::done($taken, 'pbuser2', self::NOW), 'pbuser2', false, false, $acc));
	}

	/** Whoever does not manage a Job reads only their own counter-proposal. */
	public function testTrimView(): void {
		$job = json_decode((string)json_encode([
			'project' => 'P', 'sender' => 'pblead', 'recipients' => ['pbuser1', 'pbuser2', 'pbuser3'], 'names' => new \stdClass(),
			'counters' => [
				['by' => 'pbuser1', 'hours' => 5, 'note' => 'mine', 'at' => self::NOW],
				['by' => 'pbuser2', 'end' => '2027-06-30', 'note' => 'later', 'at' => self::NOW],
				['by' => 'pbuser3', 'hours' => 2, 'note' => 'gone', 'at' => self::NOW, 'answer' => 'rejected'],
			],
			'log' => [
				['at' => self::NOW, 'by' => 'pblead', 'action' => 'offer', 'to' => ['pbuser1', 'pbuser2', 'pbuser3']],
				['at' => self::NOW, 'by' => 'pbuser1', 'action' => 'counter', 'proposal' => ['hours' => 5]],
				['at' => self::NOW, 'by' => 'pbuser2', 'action' => 'counter', 'proposal' => ['end' => '2027-06-30']],
				['at' => self::NOW, 'by' => 'pbuser3', 'action' => 'counter', 'proposal' => ['hours' => 2]],
				['at' => self::NOW, 'by' => 'pblead', 'action' => 'reject_counter', 'from' => 'pbuser3'],
				['at' => self::NOW, 'by' => 'pbuser4', 'action' => 'accept', 'lapsed' => ['pbuser1', 'pbuser2']],
			],
		]));
		$this->assertInstanceOf(\stdClass::class, $job);
		$t = JobRules::trimView($job, 'pbuser1');
		$this->assertSame('{"by":"pbuser1","hours":5,"note":"mine","at":"2026-09-28T08:00:00Z"}', json_encode($t->counters[0]));
		$this->assertSame('{"other":true}', json_encode($t->counters[1]), 'the other waiting one: only that it waits');
		$this->assertCount(2, $t->counters, 'the answered one of someone else: not at all');
		$this->assertSame(['offer', 'counter', 'accept'], array_map(static fn ($e) => $e->action, $t->log));
		$this->assertSame(['pbuser1'], $t->log[2]->lapsed);
		$this->assertStringNotContainsString('later', (string)json_encode($t));
		$this->assertStringNotContainsString('pbuser2', (string)json_encode($t->counters));
		$this->assertSame('{}', json_encode($t->names), 'objects stay objects');
		$this->assertCount(3, $job->counters, 'the original is untouched');
		$t3 = JobRules::trimView($job, 'pbuser3');
		$this->assertSame(['offer', 'counter', 'reject_counter', 'accept'], array_map(static fn ($e) => $e->action, $t3->log));
		$this->assertFalse(isset($t3->log[3]->lapsed));
		$this->assertSame('rejected', $t3->counters[2]->answer);
		$this->assertSame(['other', 'other', 'by'], array_map(static fn ($c) => array_key_first((array)$c), $t3->counters));
	}
}
