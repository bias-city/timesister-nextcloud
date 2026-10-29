<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Capabilities;
use OCA\TimeSister\Db\Record;
use OCA\TimeSister\Db\RecordMapper;
use OCA\TimeSister\Service\AccessPolicy;
use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\BillingRules;
use OCA\TimeSister\Service\BookingRules;
use OCA\TimeSister\Service\JobRules;
use OCA\TimeSister\Service\JobWeeks;
use OCA\TimeSister\Service\Json;
use OCA\TimeSister\Service\Membership;
use OCA\TimeSister\Service\PersonAccess;
use OCA\TimeSister\Service\ProjectAccess;
use OCA\TimeSister\Service\RecordRights;
use OCA\TimeSister\Service\TenantService;
use OCA\TimeSister\Service\ZipRules;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * Visibility per role (0.14.0): members see themselves, Leads their
 * projects, Team Admins everything – person cards, customers of the
 * projects led, booking records, billing marks, fits without numbers.
 */
class RoleScopeTest extends TestCase {
	private const PERSON = '{"schema":1,"login":"mia","first_name":"Mia","last_name":"Muster","initials":"MM","accounts":["mia"],'
		. '"aliases":["mia@example.org"],"workload":[{"from":"2026-01-01","percent":80}],"regions":["BS"],"vacation":[{"year":2026,"days":25}],'
		. '"entry_date":"2020-01-01","exit_date":null,"calendar":"https://cloud.example/x","calendar_name":"TimeSister – Mia","feed":"https://secret.example/feed.ics",'
		. '"corrections":[{"kind":"balance","value":3}],"cost_rates":[{"from":"2026-01-01","rate":95}]}';

	private static function code(callable $fn): string {
		try {
			$fn();
		} catch (ApiException $e) {
			return $e->getStatus() . ' ' . $e->getErrorCode();
		}
		return 'ok';
	}

	private static function record(string $kind, string $key, string $json, array $accounts = []): Record {
		$r = new Record();
		$r->setKind($kind);
		$r->setRkey($key);
		$r->setData($json);
		$r->setAccounts($accounts === [] ? null : json_encode($accounts));
		return $r;
	}

	/**
	 * RecordRights with the per-request answers set: which active projects
	 * each account leads, which customers each Lead reads.
	 *
	 * @param array<string,list<string>> $led uid → project keys
	 * @param array<string,list<string>> $customers uid → customer keys
	 */
	private static function rights(array $led, array $customers = []): RecordRights {
		$team = (new \ReflectionClass(TenantService::class))->newInstanceWithoutConstructor();
		$byUid = [];
		foreach ($led as $uid => $keys) {
			$byUid[$uid] = array_map(static fn (string $k) => ['kind' => 'project', 'key' => $k, 'code' => null, 'name' => $k], $keys);
		}
		(new \ReflectionProperty(TenantService::class, 'leads'))->setValue($team, [1 => $byUid]);
		$records = (new \ReflectionClass(RecordMapper::class))->newInstanceWithoutConstructor();
		$rights = new RecordRights(new AccessPolicy(), $team, $records);
		$cache = [];
		foreach ($customers as $uid => $keys) {
			$cache['1:' . $uid] = array_fill_keys($keys, true);
		}
		(new \ReflectionProperty(RecordRights::class, 'customers'))->setValue($rights, $cache);
		return $rights;
	}

	public function testPersonCardOnlyNames(): void {
		$card = PersonAccess::card(Json::decode(self::PERSON));
		$this->assertSame('{"schema":1,"login":"mia","first_name":"Mia","last_name":"Muster","initials":"MM","accounts":["mia"]}', Json::encode($card));
		$this->assertSame('{"login":"x"}', Json::encode(PersonAccess::card((object)['login' => 'x', 'workload' => []])));
	}

	public function testShapeOfPersonsAndProjects(): void {
		$rights = self::rights(['lea' => ['JB1']]);
		$mia = self::record('person', 'mia', self::PERSON, ['mia']);
		$data = Json::decode(self::PERSON);
		$lead = new Membership('lea', 1, 'lead');
		$shaped = $rights->shape($lead, $mia, $data, ['lea']);
		// `company` (0.27.0) is on the card only when the record has it.
		$this->assertSame(array_values(array_diff(PersonAccess::CARD, ['company'])), array_keys(get_object_vars($shaped)), 'a Lead reads the name card of others');
		$this->assertObjectNotHasProperty('feed', $shaped);
		// The own person: everything but the cost rates (0.16.0: personnel cost, Team Admins only).
		$own = clone $data;
		unset($own->cost_rates);
		$this->assertEquals($own, $rights->shape(new Membership('mia', 1, 'user'), $mia, $data, ['mia']), 'the person reads all of it but cost_rates');
		$this->assertObjectHasProperty('cost_rates', $data, 'the stored data keeps them');
		$this->assertSame($data, $rights->shape(new Membership('petra', 1, 'admin'), $mia, $data, ['petra']), 'Team Admins read all of it');
		// A person record carrying the caller in accounts is their own.
		$this->assertEquals($own, $rights->shape(new Membership('mia2', 1, 'lead'), self::record('person', 'mia', self::PERSON, ['mia', 'mia2']), $data, ['mia2', 'mia']));
		$this->assertObjectNotHasProperty('cost_rates', $rights->shape($lead, $mia, $data, ['lea']), 'nor in the name card');

		$p = Json::decode('{"id":"JB1","name":"Jobs","codes":["JB1"],"leads":["lea"],"customer":"K1","budgets":[{"hours":20}]}');
		$proj = self::record('project', 'JB1', Json::encode($p));
		$this->assertSame($p, $rights->shape($lead, $proj, $p, ['lea']), 'the Lead reads their project full');
		$this->assertSame('{"id":"JB1","name":"Jobs","codes":["JB1"],"led":true}', Json::encode($rights->shape(new Membership('luca', 1, 'lead'), $proj, $p, ['luca'])));
		$this->assertNull($rights->shape($lead, $mia, null, ['lea']), 'a tombstone stays null');
	}

	public function testCustomersOfTheProjectsLed(): void {
		$projects = [
			Json::decode('{"id":"JB1","leads":["lea"],"customer":"K1"}'),
			Json::decode('{"id":"OLD","leads":["lea.person"],"kunde":"K2","status":"completed"}'),
			Json::decode('{"id":"TK1","leads":["luca"],"customer":"K3"}'),
			Json::decode('{"id":"X","leads":["lea"]}'),
			null,
		];
		$this->assertSame(['K1' => true, 'K2' => true], ProjectAccess::customersLedBy($projects, ['lea', 'lea.person']));
		$this->assertSame([], ProjectAccess::customersLedBy($projects, ['mia']));
		$this->assertNull(ProjectAccess::customerOf((object)['customer' => '']));
		$this->assertNull(ProjectAccess::customerOf(null));

		$rights = self::rights(['lea' => ['JB1']], ['lea' => ['K1']]);
		$lead = new Membership('lea', 1, 'lead');
		$this->assertTrue($rights->canRead($lead, 'customer', 'K1', [], ['lea']));
		$this->assertFalse($rights->canRead($lead, 'customer', 'K3', [], ['lea']), 'a customer of another project: 403');
		$this->assertFalse($rights->canRead(new Membership('mia', 1, 'user'), 'customer', 'K1', [], ['mia']));
		$this->assertTrue($rights->canRead(new Membership('petra', 1, 'admin'), 'customer', 'K3', [], ['petra']));
		$this->assertSame('403 forbidden', self::code(fn () => $rights->requireWrite($lead, 'customer', 'K1')), 'reading only');
	}

	/** A project write that changes leads, customer or the active state delivers the affected records again. */
	public function testScopeChange(): void {
		$d = static fn (string $json): \stdClass => Json::decode($json);
		$this->assertSame(['customers' => [], 'bookings' => false],
			ProjectAccess::scopeChange($d('{"leads":["lea"],"customer":"K1","name":"A"}'), $d('{"leads":["lea"],"customer":"K1","name":"B"}')), 'only the name');
		$this->assertSame(['customers' => [], 'bookings' => false],
			ProjectAccess::scopeChange($d('{"leads":["a","b"]}'), $d('{"leads":["b","a","a"]}')), 'same leads, other order');
		$this->assertSame(['customers' => ['K1'], 'bookings' => true],
			ProjectAccess::scopeChange($d('{"leads":["lea"],"customer":"K1"}'), $d('{"leads":["lea","noah"],"customer":"K1"}')), 'a new Lead');
		$this->assertSame(['customers' => ['K1', 'K2'], 'bookings' => false],
			ProjectAccess::scopeChange($d('{"leads":["lea"],"customer":"K1"}'), $d('{"leads":["lea"],"customer":"K2"}')), 'another customer');
		$this->assertSame(['customers' => ['K1'], 'bookings' => true],
			ProjectAccess::scopeChange($d('{"leads":["lea"],"customer":"K1","status":"completed"}'), $d('{"leads":["lea"],"customer":"K1","status":"active"}')), 'active again');
		$this->assertSame(['customers' => ['K1'], 'bookings' => true], ProjectAccess::scopeChange(null, $d('{"leads":["lea"],"customer":"K1"}')), 'new');
		$this->assertSame(['customers' => [], 'bookings' => true], ProjectAccess::scopeChange(null, $d('{"id":"P"}')), 'new: active, reports of an earlier project with this key go out again');
		$this->assertSame(['customers' => ['K1'], 'bookings' => true], ProjectAccess::scopeChange($d('{"leads":["lea"],"customer":"K1"}'), null), 'deleted');
	}

	public function testRightsPerKind(): void {
		$rights = self::rights(['lea' => ['JB1'], 'luca' => ['TK1']]);
		$lead = new Membership('lea', 1, 'lead');
		$luca = new Membership('luca', 1, 'lead');
		$mia = new Membership('mia', 1, 'user');
		$admin = new Membership('petra', 1, 'admin');
		$report = BookingRules::reportKey('mia', 'JB1', '2026-10');
		$mark = BookingRules::markKey('mia', 'JB1', 'b1');

		foreach ([BookingRules::REPORT => $report, BookingRules::MARK => $mark] as $kind => $key) {
			$this->assertTrue($rights->canRead($lead, $kind, $key, [], ['lea']), "$kind: Lead of JB1");
			$this->assertFalse($rights->canRead($luca, $kind, $key, [], ['luca']), "$kind: Lead of TK1");
			$this->assertTrue($rights->canRead($mia, $kind, $key, [], ['mia']), "$kind: the person");
			$this->assertFalse($rights->canRead(new Membership('noah', 1, 'user'), $kind, $key, [], ['noah']), "$kind: another User");
			$this->assertTrue($rights->canRead($admin, $kind, $key, [], ['petra']), "$kind: Team Admin");
		}
		$this->assertSame('ok', self::code(fn () => $rights->requireWrite($mia, BookingRules::REPORT, $report)));
		$this->assertSame('403 forbidden', self::code(fn () => $rights->requireWrite($admin, BookingRules::REPORT, $report)), 'only the person reports');
		$this->assertSame('ok', self::code(fn () => $rights->requireWrite($lead, BookingRules::MARK, $mark)));
		$this->assertSame('403 forbidden', self::code(fn () => $rights->requireWrite($luca, BookingRules::MARK, $mark)));
		$this->assertSame('403 forbidden', self::code(fn () => $rights->requireWrite($mia, BookingRules::MARK, $mark)));

		// Billing marks: Team Admins write; Leads no more (0.14.0); the person reads their own.
		$billing = BillingRules::key('mia', 'b1');
		$this->assertSame('ok', self::code(fn () => $rights->requireWrite($admin, 'billing', $billing)));
		$this->assertSame('403 forbidden', self::code(fn () => $rights->requireWrite($lead, 'billing', $billing)));
		$this->assertSame('422 invalid', self::code(fn () => $rights->requireWrite($admin, 'billing', 'mia')));
		$this->assertFalse($rights->canRead($lead, 'billing', $billing, [], ['lea']));
		$this->assertTrue($rights->canRead($mia, 'billing', $billing, [], ['mia']));

		// Persons: Leads read the cards of others, Users only themselves.
		$this->assertTrue($rights->canRead($lead, 'person', 'mia', ['mia'], ['lea']));
		$this->assertFalse($rights->canRead(new Membership('noah', 1, 'user'), 'person', 'mia', ['mia'], ['noah']));
		$this->assertTrue($rights->canRead($mia, 'person', 'mia', ['mia'], ['mia']));
		$this->assertSame('403 forbidden', self::code(fn () => $rights->requireWrite($lead, 'person', 'mia')));
	}

	public function testBatchOfOthersOnlyPerRecordKinds(): void {
		$w = static fn (string $kind): \stdClass => (object)['kind' => $kind, 'key' => 'k'];
		$this->assertTrue(RecordRights::perRecordOnly([$w('billable'), $w('booking-report'), $w('billing')]));
		$this->assertFalse(RecordRights::perRecordOnly([$w('billable'), $w('project')]));
		$this->assertFalse(RecordRights::perRecordOnly([]));
		$this->assertFalse(RecordRights::perRecordOnly('x'));
		$this->assertFalse(RecordRights::perRecordOnly([['kind' => 'billable']]));
	}

	public function testFitsBody(): void {
		$b = static fn (array $v): \stdClass => json_decode((string)json_encode($v));
		$ok = ['hours' => 12, 'start' => '2026-10-12', 'end' => '2026-10-23', 'recipients' => ['mia', 'noah', 'mia']];
		$this->assertSame(['hours' => 12.0, 'start' => '2026-10-12', 'end' => '2026-10-23', 'recipients' => ['mia', 'noah'], 'job' => null], JobRules::fits($b($ok)));
		$this->assertSame('j-1', JobRules::fits($b($ok + ['job' => 'j-1']))['job']);
		$this->assertSame('422 invalid', self::code(fn () => JobRules::fits($b(['hours' => 1, 'start' => '2026-10-12', 'end' => '2026-10-13']))), 'no recipients');
		$this->assertSame('422 invalid', self::code(fn () => JobRules::fits($b(['recipients' => []] + $ok))));
		$this->assertSame('422 invalid', self::code(fn () => JobRules::fits($b(['hours' => 0] + $ok))));
		$this->assertSame('422 invalid', self::code(fn () => JobRules::fits($b(['end' => '2026-10-01'] + $ok))), 'end before start');
		$this->assertSame('422 invalid', self::code(fn () => JobRules::fits($b(['job' => 7] + $ok))));
		$this->assertSame('400 invalid', self::code(fn () => JobRules::fits($b(['job' => 'a/b'] + $ok))));
	}

	/** Leads learn fits or not and which weeks – never load or capacity. */
	public function testNoNumbersForLeads(): void {
		$this->assertNull(JobWeeks::fitsOf(null), 'never reported');
		$this->assertTrue(JobWeeks::fitsOf(['reported_at' => null, 'weeks' => []]));
		$over = [
			['start' => '2026-10-12', 'week' => 42, 'load' => 45.5, 'available' => 33.6, 'over' => 11.9],
			['start' => '2026-10-19', 'week' => 43, 'load' => 9.0, 'available' => 6.72, 'over' => 2.28, 'day' => '2026-10-19'],
		];
		$this->assertFalse(JobWeeks::fitsOf(['reported_at' => null, 'weeks' => $over]));
		$this->assertSame([['start' => '2026-10-12', 'week' => 42], ['start' => '2026-10-19', 'week' => 43, 'day' => '2026-10-19']], JobWeeks::withoutNumbers($over));
		$this->assertSame('42, 43 (2026-10-19)', JobWeeks::weeksText($over, false));
		$this->assertSame('42 (+11.9 h), 43 (+2.28 h, 2026-10-19)', JobWeeks::weeksText($over));
	}

	public function testCapabilities(): void {
		$cap = (new Capabilities($this->createMock(IAppManager::class)))->getCapabilities()['timesister'];
		$this->assertSame(1, $cap['lead_scope']);
		$this->assertSame(1, $cap['booking_reports']);
		$this->assertSame(1, $cap['job_fits']);
		$this->assertSame(4, $cap['quotes'], 'the kind quote (0.16.0), as a subtree with budgets (0.27.0), issued with texts (0.32.0)');
		$this->assertSame(1, $cap['cost_rates']);
		$this->assertSame(1, $cap['derived_lead']);
		$this->assertArrayNotHasKey('lead_reach', $cap);
	}

	/** A team ZIP moved to other accounts: the account in front of booking keys follows. */
	public function testZipMapsBookingKeys(): void {
		$map = ['mia' => 'mia.new', 'gone' => null];
		$this->assertSame('mia.new+JB1+2026-10', ZipRules::mapKey(BookingRules::REPORT, 'mia+JB1+2026-10', $map));
		$mark = BookingRules::markKey('mia', 'JB1', 'b1');
		$this->assertSame(BookingRules::markKey('mia.new', 'JB1', 'b1'), ZipRules::mapKey(BookingRules::MARK, $mark, $map));
		$this->assertSame('gone+JB1+2026-10', ZipRules::mapKey(BookingRules::REPORT, 'gone+JB1+2026-10', $map), 'a dropped account keeps its name');
		$this->assertSame('noah+JB1+2026-10', ZipRules::mapKey(BookingRules::REPORT, 'noah+JB1+2026-10', $map));
	}
}
