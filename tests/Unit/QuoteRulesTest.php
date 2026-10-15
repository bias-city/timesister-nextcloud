<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Capabilities;
use OCA\TimeSister\Db\Record;
use OCA\TimeSister\Db\RecordMapper;
use OCA\TimeSister\Service\AccessPolicy;
use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\Json;
use OCA\TimeSister\Service\Membership;
use OCA\TimeSister\Service\QuoteRules;
use OCA\TimeSister\Service\RecordRights;
use OCA\TimeSister\Service\RecordValidator;
use OCA\TimeSister\Service\TenantService;
use OCA\TimeSister\Service\ZipRules;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * Quotes (0.16.0, `quotes: 2`): the record's content with its budget, who
 * reads and writes it (a Lead the quotes naming them or of a project they
 * lead, before and after the write), Members nothing.
 */
class QuoteRulesTest extends TestCase {
	private const KEY = 'q-1a2b3c4d';
	private const QUOTE = ['schema' => 1, 'name' => 'Harbour study', 'status' => 'quoted', 'customer' => 'kanton', 'project' => null,
		'number' => 'OF-2026-007', 'date' => '2026-10-14', 'valid_until' => '2026-11-30', 'accepted_on' => null, 'created_on' => '2026-10-14',
		'leads' => ['pblead'], 'contact' => 'Ms. Example', 'note' => null, 'pdf' => 'https://cloud.example.test/s/offerte.pdf',
		'budget' => ['id' => 'b-q1', 'name' => 'Study', 'currency' => 'CHF', 'cap' => 24000,
			'work_packages' => [['no' => 1, 'name' => 'Concept', 'hours' => 40, 'rate' => 180]]],
		'budget_id' => null, 'hours' => null, 'amount' => null, 'currency' => null, 'created_by' => 'pbadmin'];

	private static function data(array $over = [], array $drop = []): \stdClass {
		$d = array_diff_key(array_merge(self::QUOTE, $over), array_flip($drop));
		$v = Json::decode((string)json_encode($d));
		self::assertInstanceOf(\stdClass::class, $v);
		return $v;
	}

	/** Status and message of an ApiException, or `ok`. */
	private static function outcome(callable $fn): string {
		try {
			$fn();
		} catch (ApiException $e) {
			return $e->getStatus() . ' ' . $e->getMessage();
		}
		return 'ok';
	}

	private static function check(array $over = [], array $drop = [], string $key = self::KEY): string {
		return self::outcome(fn () => RecordValidator::validate('quote', $key, self::data($over, $drop)));
	}

	public function testKindAndKey(): void {
		$this->assertContains('quote', RecordValidator::KINDS);
		$this->assertContains('quote', RecordRights::PER_RECORD);
		$this->assertContains('quote', RecordRights::AGAINST_STORED);
		$this->assertSame('ok', self::check([], [], 'q-00ff99aa'));
		foreach (['q-1A2B3C4D', 'q-1a2b3c4', 'q-1a2b3c4d5', 'q-1a2b3c4g', 'Q-1a2b3c4d', 'quote-1a2b3c4d', 'q1a2b3c4d'] as $k) {
			$this->assertSame('422 The key of a quote is “q-” and 8 lowercase hex digits.', self::check([], [], $k), $k);
		}
		$this->assertSame('400 Invalid key. Allowed are 1–128 characters from A–Z, a–z, 0–9 and @ . _ + -', self::check([], [], 'q-1a2b 3c4'), 'the general pattern first');
	}

	public function testValidStoredAsSent(): void {
		$r = RecordValidator::validate('quote', self::KEY, self::data(['extra' => ['kept' => true]]));
		$this->assertSame([], $r['accounts']);
		$this->assertStringContainsString('"extra":{"kept":true}', $r['json'], 'other fields pass, as for invoices');
		$this->assertStringContainsString('"work_packages":[{"no":1,"name":"Concept","hours":40,"rate":180}]', $r['json']);
		$this->assertSame('ok', self::check([], ['schema', 'customer', 'project', 'number', 'date', 'valid_until', 'accepted_on', 'created_on',
			'leads', 'contact', 'note', 'pdf', 'budget', 'budget_id', 'hours', 'amount', 'currency', 'created_by']), 'only name and status required');
		$this->assertSame('ok', self::check(['leads' => null, 'customer' => null, 'pdf' => null, 'budget' => null]));
		foreach (['draft', 'quoted', 'declined'] as $s) {
			$this->assertSame('ok', self::check(['status' => $s]), $s);
		}
		$this->assertSame('ok', self::check(['status' => 'accepted', 'project' => 'd200', 'accepted_on' => '2026-10-20', 'budget_id' => 'b7',
			'hours' => 133.5, 'amount' => 24000, 'currency' => 'CHF']), 'accepted: frozen numbers');
		$r = RecordValidator::validate('quote', self::KEY, self::data(['hours' => 0, 'amount' => 0.0, 'currency' => 'chf']));
		$this->assertStringContainsString('"currency":"CHF"', $r['json'], 'currency upper case');
		$this->assertSame('ok', self::check(['project' => 'd200']), 'assigned to a project before acceptance');
		$this->assertSame('ok', self::check(['note' => "Line one\r\nLine two\twith tab"]));
		$this->assertSame('ok', self::check(['leads' => []]));
		$this->assertSame('ok', self::check(['leads' => array_map(static fn (int $i) => 'p' . $i, range(1, 20))]), '20 leads');
		$this->assertSame('ok', self::check(['leads' => [str_repeat('k', 128), 'pblead', 'unknown.account']]), 'person keys up to 128, unknown accounts allowed');
		$this->assertSame('ok', self::check(['name' => str_repeat('n', 200), 'contact' => str_repeat('c', 200), 'note' => str_repeat('x', 2000)]));
		foreach (['http://localhost:8081/s/abc', 'https://cloud.example.test/remote.php/dav/files/a/Offerte%20Hafen.pdf', 'HTTPS://Example.test/x.pdf',
			'https://bücher.example/x.pdf', 'https://e.test/' . str_repeat('p', 2000 - 15)] as $url) {
			$this->assertSame('ok', self::check(['pdf' => $url]), $url);
		}
	}

	public function testInvalidFields(): void {
		foreach ([[], ['name' => ''], ['name' => str_repeat('n', 201)], ['name' => "a\nb"], ['name' => 7]] as $i => $over) {
			$this->assertSame('422 “name” must be text of 1 to 200 characters.', $i === 0 ? self::check([], ['name']) : self::check($over), (string)$i);
		}
		$status = '422 “status” of a quote is one of draft, quoted, accepted, declined.';
		$this->assertSame($status, self::check([], ['status']));
		$this->assertSame($status, self::check(['status' => 'active']));
		$this->assertSame($status, self::check(['status' => 'Accepted']));
		foreach (['a b', 7, '', str_repeat('k', 129)] as $bad) {
			$this->assertSame('422 “customer” must be null or the key of a customer.', self::check(['customer' => $bad]), var_export($bad, true));
			$this->assertSame('422 “project” must be null or the key of a project.', self::check(['project' => $bad]), var_export($bad, true));
		}
		$accepted = '422 An accepted quote needs “project”: the project that took over its budget.';
		$this->assertSame($accepted, self::check(['status' => 'accepted']));
		$this->assertSame($accepted, self::check(['status' => 'accepted'], ['project']));
		foreach (['', str_repeat('x', 65), 12, "OF\n1"] as $n) {
			$this->assertSame('422 “number” must be null or text of 1 to 64 characters.', self::check(['number' => $n]), var_export($n, true));
		}
		foreach (QuoteRules::DAYS as $f) {
			foreach (['14.10.2026', '2026-02-30', 20261014, ''] as $bad) {
				$this->assertSame("422 “{$f}” must be a day in the format YYYY-MM-DD.", self::check([$f => $bad]), $f . ' ' . var_export($bad, true));
			}
		}
		$leads = '422 “leads” must be a list of at most 20 account IDs or person keys.';
		foreach (['pblead', ['a' => 'pblead'], [''], [str_repeat('k', 129)], [7], ["pb\nlead"], array_map(static fn (int $i) => 'p' . $i, range(1, 21))] as $bad) {
			$this->assertSame($leads, self::check(['leads' => $bad]), json_encode($bad));
		}
		$this->assertSame('422 “contact” must be text of at most 200 characters.', self::check(['contact' => str_repeat('c', 201)]));
		$this->assertSame('422 “contact” must be text of at most 200 characters.', self::check(['contact' => "a\nb"]));
		$this->assertSame('422 “note” must be text of at most 2000 characters.', self::check(['note' => str_repeat('x', 2001)]));
		$this->assertSame('422 “note” must be text of at most 2000 characters.', self::check(['note' => "bell\x07"]));
		foreach (['ftp://e.test/x.pdf', 'javascript:alert(1)', '/s/offerte.pdf', 'https://', 'https:///x', 'https://a b', "https://e.test/\x07",
			'file:///etc/passwd', 'e.test/x.pdf', '', 7, 'https://e.test/' . str_repeat('p', 2000 - 14)] as $bad) {
			$this->assertSame('422 “pdf” must be null or an http:// or https:// address of at most 2000 characters.', self::check(['pdf' => $bad]), var_export($bad, true));
		}
		foreach (['422 “budget_id” must be null or text of 1 to 128 characters.' => ['', str_repeat('b', 129), 7, "b\n1"]] as $msg => $bads) {
			foreach ($bads as $bad) {
				$this->assertSame($msg, self::check(['budget_id' => $bad]), var_export($bad, true));
			}
		}
		foreach (['hours', 'amount'] as $f) {
			foreach ([-1, '12', true, [1]] as $bad) {
				$this->assertSame("422 “{$f}” must be null or a number ≥ 0.", self::check([$f => $bad]), $f . ' ' . var_export($bad, true));
			}
		}
		foreach (['CH', 'CHFR', 7] as $bad) {
			$this->assertSame('422 “currency” must be three letters, for example CHF.', self::check(['currency' => $bad]), var_export($bad, true));
		}
		$this->assertSame('422 “created_by” must be text of at most 64 characters.', self::check(['created_by' => str_repeat('x', 65)]));
		$this->assertSame('422 “schema” is invalid.', self::check(['schema' => '1']));
		$this->assertSame('422 “schema” is invalid.', self::check(['schema' => 1001]));
	}

	public function testBudgetAsForProjects(): void {
		$b = static fn (array $over = []): array => ['budget' => array_merge(self::QUOTE['budget'], $over)];
		$id = '422 “budget” must be null or an object with an “id” of 1 to 128 characters.';
		foreach (['b1', [1, 2], ['name' => 'no id']] as $bad) {
			$this->assertSame($id, self::check(['budget' => $bad]), json_encode($bad));
		}
		foreach (['', '  ', 7, null, str_repeat('b', 129), "b\n1"] as $bad) {
			$this->assertSame($id, self::check($b(['id' => $bad])), var_export($bad, true));
		}
		$this->assertSame('ok', self::check($b(['id' => str_repeat('b', 128)])));
		$r = RecordValidator::validate('quote', self::KEY, self::data($b(['currency' => 'eur'])));
		$this->assertStringContainsString('"currency":"EUR"', $r['json'], 'the budget’s currency upper case, as in a project');
		foreach (['CH', 'CHFR', 7] as $bad) {
			$this->assertSame('422 “currency” must be three letters, for example CHF.', self::check($b(['currency' => $bad])), var_export($bad, true));
		}
		$this->assertSame('ok', self::check($b(['cap' => null])));
		$this->assertSame('ok', self::check($b(['cap' => 0.5])));
		foreach ([-1, '12000', true] as $bad) {
			$this->assertSame('422 “cap” must be null or a number ≥ 0.', self::check($b(['cap' => $bad])), var_export($bad, true));
		}
		$this->assertSame('ok', self::check($b(['work_packages' => [['no' => 1, 'rate' => null], ['no' => 2], ['no' => 3, 'rate' => 0]]])));
		foreach ([-1, '180'] as $bad) {
			$this->assertSame('422 “rate” must be a number ≥ 0.', self::check($b(['work_packages' => [['no' => 1, 'rate' => $bad]]])), var_export($bad, true));
		}
		$this->assertSame('ok', self::check($b(['rates' => null])));
		$this->assertSame('ok', self::check($b(['rates' => new \stdClass()])), 'an empty object is a client’s default');
		$this->assertSame('422 “rates” is no longer part of a budget; cost rates live on the person.', self::check($b(['rates' => ['pbuser1' => 120]])));
	}

	public function testLimits(): void {
		$wp = static fn (int $n): array => ['budget' => array_merge(self::QUOTE['budget'],
			['work_packages' => array_map(static fn (int $i) => ['no' => $i, 'name' => str_repeat('w', 1000), 'rate' => 100], range(1, $n))])];
		$this->assertSame('ok', self::check($wp(400)), 'a large budget fits');
		$this->assertGreaterThan(RecordValidator::MAX_DATA_BYTES, strlen((string)json_encode($wp(400))), '… more than other kinds may hold');
		$this->assertSame('413 A quote may be at most 768 KB.', self::check($wp(800)));
	}

	public function testLeadRule(): void {
		$own = ['lea', 'p-lea'];
		$led = ['d200' => true];
		$named = self::data(['leads' => ['lea']]);
		$viaPerson = self::data(['leads' => ['p-lea']]);
		$ofProject = self::data(['leads' => [], 'project' => 'd200']);
		$accepted = self::data(['leads' => null, 'status' => 'accepted', 'project' => 'd200']);
		$other = self::data(['leads' => ['luca'], 'project' => 'x9']);
		$nobody = self::data(['leads' => null]);
		$this->assertTrue(QuoteRules::leadReads($named, $own, $led));
		$this->assertTrue(QuoteRules::leadReads($viaPerson, $own, $led), 'a person key in leads, as for a project');
		$this->assertTrue(QuoteRules::leadReads($ofProject, $own, $led));
		$this->assertFalse(QuoteRules::leadReads($other, $own, $led));
		$this->assertFalse(QuoteRules::leadReads($nobody, $own, $led));
		$this->assertFalse(QuoteRules::leadReads(null, $own, $led));

		$w = static fn (?\stdClass $before, ?\stdClass $after): string => self::outcome(fn () => QuoteRules::requireLeadWrite($before, $after, $own, $led));
		$this->assertSame('ok', $w(null, $named), 'create a quote naming themselves');
		$this->assertSame('ok', $w(null, $viaPerson));
		$this->assertSame('ok', $w($named, $named), 'change it');
		$this->assertSame('ok', $w($named, null), 'delete it');
		$this->assertSame('ok', $w($named, $ofProject), 'hand it to a project they lead');
		$this->assertSame('ok', $w($ofProject, $accepted), 'accept it on their project');
		$msg = '403 Only Team Admins and the quote’s or project’s Leads write quotes.';
		$this->assertSame($msg, $w(null, $other), 'create one for others');
		$this->assertSame($msg, $w(null, $nobody), 'create one naming nobody');
		$this->assertSame($msg, $w($named, $other), 'give it away: they would no longer read it');
		$this->assertSame($msg, $w($other, $named), 'take over one they do not read');
		$this->assertSame($msg, $w($other, null), 'delete one they do not read');
	}

	/**
	 * RecordRights with the active projects each account leads (as
	 * TenantService::leadOf would give them).
	 *
	 * @param array<string,list<string>> $led
	 */
	private static function rights(array $led): RecordRights {
		$team = (new \ReflectionClass(TenantService::class))->newInstanceWithoutConstructor();
		$byUid = [];
		foreach ($led as $uid => $keys) {
			$byUid[$uid] = array_map(static fn (string $k) => ['kind' => 'project', 'key' => $k, 'code' => null, 'name' => $k], $keys);
		}
		(new \ReflectionProperty(TenantService::class, 'leads'))->setValue($team, [1 => $byUid]);
		$records = (new \ReflectionClass(RecordMapper::class))->newInstanceWithoutConstructor();
		return new RecordRights(new AccessPolicy(), $team, $records);
	}

	private static function record(\stdClass $d, bool $tombstone = false): Record {
		$r = new Record();
		$r->setKind('quote');
		$r->setRkey(self::KEY);
		$r->setData(Json::encode($d));
		$r->setDeleted($tombstone ? 1 : 0);
		return $r;
	}

	public function testRightsPerRole(): void {
		$rights = self::rights(['lea' => ['d200'], 'luca' => ['x9']]);
		$admin = new Membership('petra', 1, 'admin');
		$lea = new Membership('lea', 1, 'lead');
		$luca = new Membership('luca', 1, 'lead');
		$mia = new Membership('mia', 1, 'user');
		$own = ['petra' => ['petra'], 'lea' => ['lea', 'p-lea'], 'luca' => ['luca'], 'mia' => ['mia']];
		$read = static fn (Membership $m, ?Record $r): bool => $rights->canRead($m, 'quote', self::KEY, [], $own[$m->uid], $r);
		$named = self::record(self::data(['leads' => ['lea', 'mia']]));

		$this->assertTrue($read($admin, $named));
		$this->assertTrue($read($admin, null), 'Team Admin: not there is 404, not 403');
		$this->assertTrue($read($lea, $named), 'named in leads');
		$this->assertTrue($read($lea, self::record(self::data(['leads' => ['p-lea']]))), 'named by their person key');
		$this->assertTrue($read($lea, self::record(self::data(['leads' => [], 'project' => 'd200']))), 'Lead of the assigned project');
		$this->assertTrue($read($lea, self::record(self::data(['leads' => ['lea']]), true)), 'also the tombstone, by its kept data');
		$this->assertFalse($read($lea, null), 'unknown key: 403, existence not revealed');
		$this->assertFalse($read($luca, $named), 'another Lead');
		$this->assertTrue($read($luca, self::record(self::data(['project' => 'x9']))), 'until it is assigned to their project');
		$this->assertFalse($read($mia, $named), 'a Member sees no quotes, even named in leads');

		$code = static function (callable $fn): string {
			try {
				$fn();
			} catch (ApiException $e) {
				return $e->getStatus() . ' ' . $e->getErrorCode();
			}
			return 'ok';
		};
		$this->assertSame('ok', $code(fn () => $rights->requireWrite($admin, 'quote', self::KEY)));
		$this->assertSame('ok', $code(fn () => $rights->requireWrite($lea, 'quote', self::KEY)), 'a Lead passes the first check');
		$this->assertSame('403 Only Team Admins and the quote’s or project’s Leads write quotes.', self::outcome(fn () => $rights->requireWrite($mia, 'quote', self::KEY)));

		$mine = Json::encode(self::data(['leads' => ['lea']]));
		$others = Json::encode(self::data(['leads' => ['luca']]));
		$this->assertSame('ok', $code(fn () => $rights->requireQuoteWrite($admin, null, $others, $own['petra'])), 'Team Admin writes any');
		$this->assertSame('ok', $code(fn () => $rights->requireQuoteWrite($admin, self::record(self::data(['leads' => ['luca']])), null, $own['petra'])));
		$this->assertSame('ok', $code(fn () => $rights->requireQuoteWrite($lea, null, $mine, $own['lea'])));
		$this->assertSame('ok', $code(fn () => $rights->requireQuoteWrite($lea, $named, null, $own['lea'])));
		$this->assertSame('403 forbidden', $code(fn () => $rights->requireQuoteWrite($lea, $named, $others, $own['lea'])), 'before yes, after no');
		$this->assertSame('403 forbidden', $code(fn () => $rights->requireQuoteWrite($luca, $named, $others, $own['luca'])), 'before no, after yes');
		$this->assertSame('403 forbidden', $code(fn () => $rights->requireQuoteWrite($lea, self::record(self::data(['leads' => ['luca']]), true), $mine, $own['lea'])), 'the tombstone of another’s quote');

		$w = static fn (string $kind): \stdClass => (object)['kind' => $kind, 'key' => 'k'];
		$this->assertTrue(RecordRights::perRecordOnly([$w('quote'), $w('invoice')]), 'a Lead batches quotes');
		$this->assertFalse(RecordRights::perRecordOnly([$w('quote'), $w('project')]));
	}

	public function testNamesAny(): void {
		$raw = Json::encode(self::data(['leads' => ['p-lea', 'luca']]));
		$this->assertTrue(QuoteRules::namesAny($raw, ['p-lea' => true]));
		$this->assertFalse(QuoteRules::namesAny($raw, ['lea' => true]));
		$this->assertFalse(QuoteRules::namesAny(Json::encode(self::data(['leads' => null])), ['lea' => true]));
		$this->assertFalse(QuoteRules::namesAny(null, ['lea' => true]));
	}

	public function testCapabilityAndZip(): void {
		$cap = (new Capabilities($this->createMock(IAppManager::class)))->getCapabilities()['timesister'];
		$this->assertSame(2, $cap['quotes']);
		$mapped = ZipRules::mapData(self::data(), ['pblead' => 'lea', 'pbadmin' => 'petra']);
		$this->assertInstanceOf(\stdClass::class, $mapped);
		$this->assertSame(['lea'], $mapped->leads, 'a team ZIP moved to other accounts: the leads follow');
		$this->assertSame('petra', $mapped->created_by);
	}
}
