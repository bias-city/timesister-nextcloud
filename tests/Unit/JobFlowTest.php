<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\JobFlow;
use OCA\TimeSister\Service\JobRules;
use PHPUnit\Framework\TestCase;

/** The transitions of a Job, and that a repeated one changes nothing. */
class JobFlowTest extends TestCase {
	private const NOW = '2026-09-28T08:00:00Z';
	private const LATER = '2026-09-29T09:00:00Z';

	private static function code(callable $fn): string {
		try {
			$fn();
		} catch (ApiException $e) {
			return $e->getStatus() . ' ' . $e->getErrorCode();
		}
		return 'ok';
	}

	/** @param list<string> $to @return array<string,mixed> */
	private static function job(array $to = ['pbuser1']): array {
		$f = ['project' => 'D200', 'code' => 'D200', 'budget' => null, 'work_package' => null, 'title' => 'Survey',
			'description' => 'Ask everyone', 'hours' => 40.0, 'start' => '2026-10-01', 'end' => '2026-10-31', 'recipients' => $to];
		return JobFlow::create('j1', $f, ['codes' => ['D200'], 'ap_hours' => null, 'ap_name' => null], 'pblead', self::NOW);
	}

	/** @param array<string,mixed> $job @return list<string> */
	private static function actions(array $job): array {
		return array_map(fn ($e) => (string)$e['action'], (array)$job['log']);
	}

	public function testCreate(): void {
		$j = self::job();
		$this->assertSame('offered', $j['state']);
		$this->assertSame('pblead', $j['sender']);
		$this->assertSame(['D200'], $j['codes']);
		$this->assertSame(['hours' => 0.0, 'invoiced' => false, 'at' => null], $j['progress']);
		$this->assertSame([['at' => self::NOW, 'by' => 'pblead', 'action' => 'offer', 'to' => ['pbuser1']]], $j['log']);
		// Without a title, the work package's name.
		$f = ['project' => 'D200', 'code' => 'D200.1', 'budget' => 'b1', 'work_package' => 'AP1', 'title' => null,
			'description' => null, 'hours' => 5.0, 'start' => '2026-10-01', 'end' => '2026-10-02', 'recipients' => ['x']];
		$this->assertSame('Basics', JobFlow::create('j2', $f, ['codes' => ['D200.1'], 'ap_hours' => 9.0, 'ap_name' => 'Basics'], 'pblead', self::NOW)['title']);
	}

	public function testAcceptFirstWins(): void {
		$j = self::job(['pbuser1', 'pbuser2']);
		$a = JobFlow::accept($j, 'pbuser2', self::NOW);
		$this->assertSame(['in_progress', 'pbuser2'], [$a['state'], $a['assignee']]);
		$this->assertSame($a, JobFlow::accept($a, 'pbuser2', self::LATER), 'sent again: unchanged');
		$this->assertSame('409 conflict', self::code(fn () => JobFlow::accept($a, 'pbuser1', self::LATER)));
		$this->assertSame('409 conflict', self::code(fn () => JobFlow::accept($j, 'pbuser3', self::NOW)));
	}

	public function testCounterAcceptedAndRejected(): void {
		$c = JobFlow::counter(self::job(), ['hours' => 48.0, 'end' => '2026-11-15', 'note' => 'more time'], 'pbuser1', self::NOW);
		$this->assertSame('counter', $c['state']);
		$this->assertSame([['by' => 'pbuser1', 'hours' => 48.0, 'end' => '2026-11-15', 'note' => 'more time', 'at' => self::NOW]], $c['counters']);
		$this->assertSame($c, JobFlow::counter($c, ['hours' => 48.0, 'end' => '2026-11-15', 'note' => 'more time'], 'pbuser1', self::LATER), 'sent again');
		$this->assertSame('409 conflict', self::code(fn () => JobFlow::accept($c, 'pbuser1', self::NOW)), 'no longer in the Inbox');
		$this->assertSame('409 conflict', self::code(fn () => JobFlow::change($c, ['hours' => 10.0], null, 'pblead', self::NOW)));
		$this->assertSame('422 invalid', self::code(fn () => JobFlow::counter(self::job(), ['start' => '2026-11-01', 'end' => '2026-10-15', 'note' => null], 'pbuser1', self::NOW)));

		$ok = JobFlow::acceptCounter($c, null, 'pblead', self::LATER);
		$this->assertSame(['in_progress', 'pbuser1', 48.0, '2026-11-15', 'accepted'], [$ok['state'], $ok['assignee'], $ok['hours'], $ok['end'], $ok['counters'][0]['answer']]);
		$this->assertSame($ok, JobFlow::acceptCounter($ok, 'pbuser1', 'pblead', self::LATER), 'sent again');
		$no = JobFlow::rejectCounter($c, 'pbuser1', 'pblead', self::LATER);
		$this->assertSame(['rejected', 40.0, 'rejected'], [$no['state'], $no['hours'], $no['counters'][0]['answer']]);
		$this->assertSame($no, JobFlow::rejectCounter($no, 'pbuser1', 'pblead', self::LATER), 'sent again');
		$this->assertSame('409 conflict', self::code(fn () => JobFlow::acceptCounter($no, null, 'pblead', self::LATER)));
		$this->assertSame('409 conflict', self::code(fn () => JobFlow::acceptCounter($no, 'pbuser1', 'pblead', self::LATER)));
		// Rejected: changed and offered again.
		$again = JobFlow::change($no, ['recipients' => ['pbuser2'], 'hours' => 30.0], null, 'pblead', self::LATER);
		$this->assertSame(['offered', ['pbuser2'], []], [$again['state'], $again['recipients'], $again['counters']]);
		$this->assertSame(['offer', 'counter', 'reject_counter', 'reoffer'], self::actions($again));
	}

	/** A Job from 0.6.0 with a single `counter` reads as a list of one. */
	public function testCounterFrom060(): void {
		$old = self::job();
		unset($old['counters']);
		$old['state'] = 'counter';
		$old['counter'] = ['by' => 'pbuser1', 'hours' => 30.0, 'note' => null, 'at' => self::NOW];
		$this->assertTrue(JobRules::activeFor($old, 'pbuser1'));
		$ok = JobFlow::acceptCounter($old, null, 'pblead', self::LATER);
		$this->assertArrayNotHasKey('counter', $ok);
		$this->assertSame(['in_progress', 30.0, 'accepted'], [$ok['state'], $ok['hours'], $ok['counters'][0]['answer']]);
	}

	/** Offered to several: the counter-proposal waits, the market stays open. */
	public function testMarketCounterStaysOpen(): void {
		$m = self::job(['pbuser1', 'pbuser2', 'pbuser3']);
		$c = JobFlow::counter($m, ['hours' => 30.0, 'note' => null], 'pbuser1', self::NOW);
		$this->assertSame(['offered', ['pbuser2', 'pbuser3']], [$c['state'], JobRules::openRecipients($c)]);
		$this->assertTrue(JobRules::activeFor($c, 'pbuser1'), 'still sees it (their counter-proposal)');
		$this->assertSame('409 conflict', self::code(fn () => JobFlow::accept($c, 'pbuser1', self::NOW)), 'not in their Inbox any more');
		$this->assertSame('409 conflict', self::code(fn () => JobFlow::change($c, ['hours' => 10.0], null, 'pblead', self::NOW)), 'answer first');

		// Someone accepts the offer first: the counter-proposal lapses.
		$taken = JobFlow::accept($c, 'pbuser2', self::LATER);
		$this->assertSame(['in_progress', 'pbuser2', 40.0, 'lapsed'], [$taken['state'], $taken['assignee'], $taken['hours'], $taken['counters'][0]['answer']]);
		$this->assertSame(['pbuser1'], $taken['log'][2]['lapsed']);
		$this->assertFalse(JobRules::activeFor($taken, 'pbuser1'));
		$this->assertSame('409 conflict', self::code(fn () => JobFlow::acceptCounter($taken, 'pbuser1', 'pblead', self::LATER)));

		// The sender takes the counter-proposal: pbuser1 gets it with 30 h, the others lose it.
		$ok = JobFlow::acceptCounter($c, 'pbuser1', 'pblead', self::LATER);
		$this->assertSame(['in_progress', 'pbuser1', 30.0], [$ok['state'], $ok['assignee'], $ok['hours']]);
		$this->assertSame('409 conflict', self::code(fn () => JobFlow::accept($ok, 'pbuser2', self::LATER)));
		$this->assertFalse(JobRules::activeFor($ok, 'pbuser2'));

		// Rejected: only pbuser1 is out; the others keep the offer.
		$no = JobFlow::rejectCounter($c, 'pbuser1', 'pblead', self::LATER);
		$this->assertSame(['offered', ['pbuser2', 'pbuser3']], [$no['state'], JobRules::openRecipients($no)]);
		$this->assertFalse(JobRules::activeFor($no, 'pbuser1'));
		$this->assertSame('409 conflict', self::code(fn () => JobFlow::counter($no, ['hours' => 20.0, 'note' => null], 'pbuser1', self::LATER)));
	}

	/** Several counter-proposals: say whose; taking one lets the others lapse. */
	public function testMarketSeveralCounters(): void {
		$m = self::job(['pbuser1', 'pbuser2']);
		$a = JobFlow::counter($m, ['hours' => 30.0, 'note' => null], 'pbuser1', self::NOW);
		$b = JobFlow::counter($a, ['end' => '2026-11-30', 'note' => 'later'], 'pbuser2', self::NOW);
		$this->assertSame(['counter', []], [$b['state'], JobRules::openRecipients($b)]);
		$this->assertCount(2, JobRules::pendingCounters($b));
		$this->assertSame('422 invalid', self::code(fn () => JobFlow::acceptCounter($b, null, 'pblead', self::LATER)));
		$this->assertSame('409 conflict', self::code(fn () => JobFlow::acceptCounter($b, 'pbuser3', 'pblead', self::LATER)));
		// Rejecting one: the other still waits.
		$r = JobFlow::rejectCounter($b, 'pbuser2', 'pblead', self::LATER);
		$this->assertSame(['counter', ['pbuser1']], [$r['state'], array_column(JobRules::pendingCounters($r), 'by')]);
		$ok = JobFlow::acceptCounter($b, 'pbuser2', 'pblead', self::LATER);
		$this->assertSame(['in_progress', 'pbuser2', '2026-11-30', 40.0], [$ok['state'], $ok['assignee'], $ok['end'], $ok['hours']]);
		$this->assertSame(['lapsed', 'accepted'], array_column($ok['counters'], 'answer'));
		$this->assertSame(['pbuser1'], $ok['log'][3]['lapsed']);
		// Both rejected: rejected; one declined and one rejected: rejected too.
		$both = JobFlow::rejectCounter($r, 'pbuser1', 'pblead', self::LATER);
		$this->assertSame('rejected', $both['state']);
		$mixed = JobFlow::rejectCounter(JobFlow::decline($a, 'pbuser2', self::NOW), 'pbuser1', 'pblead', self::LATER);
		$this->assertSame('rejected', $mixed['state']);
		// A counter-proposal changed while it waits.
		$again = JobFlow::counter($a, ['hours' => 32.0, 'note' => null], 'pbuser1', self::LATER);
		$this->assertSame([32.0], array_column($again['counters'], 'hours'));
	}

	public function testDeclineByAll(): void {
		$j = self::job(['pbuser1', 'pbuser2']);
		$one = JobFlow::decline($j, 'pbuser1', self::NOW);
		$this->assertSame(['offered', ['pbuser1']], [$one['state'], $one['declined_by']]);
		$this->assertSame($one, JobFlow::decline($one, 'pbuser1', self::LATER));
		$this->assertSame('409 conflict', self::code(fn () => JobFlow::accept($one, 'pbuser1', self::NOW)));
		$all = JobFlow::decline($one, 'pbuser2', self::NOW);
		$this->assertSame('declined', $all['state']);
		$re = JobFlow::change($all, ['recipients' => ['pbuser1', 'pbuser2']], null, 'pblead', self::LATER);
		$this->assertSame(['offered', []], [$re['state'], $re['declined_by']]);
		// One declines, the other counter-proposes: waits for the sender.
		$wait = JobFlow::counter($one, ['hours' => 20.0, 'note' => null], 'pbuser2', self::NOW);
		$this->assertSame('counter', $wait['state']);
	}

	public function testChange(): void {
		$j = self::job();
		$this->assertSame($j, JobFlow::change($j, ['hours' => 40.0, 'title' => 'Survey'], null, 'pblead', self::NOW), 'nothing changed');
		$c = JobFlow::change($j, ['hours' => 50.0, 'end' => '2026-11-30'], null, 'pblead', self::LATER);
		$this->assertSame(['by' => 'pblead', 'at' => self::LATER, 'fields' => [
			'hours' => ['from' => 40.0, 'to' => 50.0], 'end' => ['from' => '2026-10-31', 'to' => '2026-11-30']]], $c['change']);
		$this->assertSame('422 invalid', self::code(fn () => JobFlow::change($j, ['end' => '2026-09-01'], null, 'pblead', self::NOW)));
		$code = JobFlow::change($j, ['code' => 'D200.2'], ['D200.2'], 'pblead', self::NOW);
		$this->assertSame(['D200.2'], $code['codes']);
		// Running: the person stays, fields may change.
		$run = JobFlow::accept($j, 'pbuser1', self::NOW);
		$this->assertSame('409 conflict', self::code(fn () => JobFlow::change($run, ['recipients' => ['pbuser2']], null, 'pblead', self::NOW)));
		$this->assertSame('in_progress', JobFlow::change($run, ['hours' => 45.0], null, 'pblead', self::NOW)['state']);
		$done = JobFlow::done($run, 'pbuser1', self::NOW);
		$this->assertSame('409 conflict', self::code(fn () => JobFlow::change($done, ['hours' => 45.0], null, 'pblead', self::NOW)));
		// Open offer to someone else: whoever declined and stays keeps it declined.
		$two = JobFlow::decline(self::job(['pbuser1', 'pbuser2']), 'pbuser1', self::NOW);
		$more = JobFlow::change($two, ['recipients' => ['pbuser1', 'pbuser3']], null, 'pblead', self::NOW);
		$this->assertSame(['offered', ['pbuser1']], [$more['state'], $more['declined_by']]);
	}

	public function testReturnDonePaid(): void {
		$run = JobFlow::accept(self::job(), 'pbuser1', self::NOW);
		$this->assertSame('409 conflict', self::code(fn () => JobFlow::giveBack($run, null, 'pbuser2', self::NOW)));
		$ret = JobFlow::giveBack($run, 'no time', 'pbuser1', self::LATER);
		$this->assertSame('returned', $ret['state']);
		$this->assertSame(['at' => self::LATER, 'by' => 'pbuser1', 'action' => 'return', 'note' => 'no time'], $ret['log'][2]);
		$this->assertSame($ret, JobFlow::giveBack($ret, 'no time', 'pbuser1', self::LATER));

		$done = JobFlow::done($run, 'pbuser1', self::LATER);
		$this->assertSame(['by' => 'pbuser1', 'at' => self::LATER, 'reason' => 'declared'], $done['done']);
		$this->assertSame($done, JobFlow::done($done, 'pbuser1', self::LATER));
		$this->assertSame('409 conflict', self::code(fn () => JobFlow::paid($run, true, 'pbadmin', self::NOW)));
		$paid = JobFlow::paid($done, true, 'pbadmin', self::LATER);
		$this->assertSame(['by' => 'pbadmin', 'at' => self::LATER], $paid['paid']);
		$this->assertSame($paid, JobFlow::paid($paid, true, 'pbadmin', self::LATER));
		$this->assertNull(JobFlow::paid($paid, false, 'pbadmin', self::LATER)['paid']);
	}

	public function testProgressAndFulfilled(): void {
		$j = self::job();
		$this->assertNull(JobFlow::progress($j, 5, false, 'pbuser1', self::NOW), 'not accepted yet');
		$run = JobFlow::accept($j, 'pbuser1', self::NOW);
		$this->assertNull(JobFlow::progress($run, 5, false, 'pbuser2', self::NOW), 'not theirs');
		$p = (array)JobFlow::progress($run, 32.004, false, 'pbuser1', self::NOW);
		$this->assertSame(['hours' => 32.004, 'invoiced' => false, 'at' => self::NOW], $p['progress']);
		$this->assertSame($p, JobFlow::progress($p, 32.0, false, 'pbuser1', self::LATER), 'same within a hundredth');
		$full = (array)JobFlow::progress($p, 55.0, false, 'pbuser1', self::LATER);
		$this->assertSame(['done', 40.0, 'fulfilled'], [$full['state'], $full['progress']['hours'], $full['done']['reason']]);
		$inv = (array)JobFlow::progress($full, 40.0, true, 'pbuser1', self::LATER);
		$this->assertTrue($inv['progress']['invoiced']);
		$this->assertSame(40.0, JobRules::hold($inv));
	}

	public function testLogKeepsFirstAndNewest(): void {
		$j = self::job();
		for ($i = 0; $i < JobFlow::LOG_MAX + 5; $i++) {
			$j = JobFlow::change($j, ['hours' => 41.0 + $i], null, 'pblead', self::NOW);
		}
		$this->assertCount(JobFlow::LOG_MAX, $j['log']);
		$this->assertSame('offer', $j['log'][0]['action']);
		$this->assertSame(['from' => 244.0, 'to' => 245.0], $j['log'][JobFlow::LOG_MAX - 1]['fields']['hours']);
	}
}
