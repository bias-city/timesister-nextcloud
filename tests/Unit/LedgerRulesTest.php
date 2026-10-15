<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Capabilities;
use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\InvoiceNumbering;
use OCA\TimeSister\Service\IssueRules;
use OCA\TimeSister\Service\Json;
use OCA\TimeSister\Service\LedgerRules;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * The invoice ledger (0.18.0): canonical JSON, the hash formula with the
 * example values of API.md (the Mac app recomputes them), the chain check
 * with a break, and the pure parts of issuing.
 */
class LedgerRulesTest extends TestCase {
	/** The example of API.md: the payload, its canonical form and hash, two chained entries. */
	private const CANONICAL = '{"customer":{"city":"Basel","name":"Müller AG"},"empty":{},"greeting":null,"key":"inv-1a2b3c4d","net":1875.0,"number":"RE-2026-0007","positions":[{"amount":1875,"hours":12.5,"kind":"hours","rate":150,"text":"Planung / Phase 1"}]}';
	private const PAYLOAD_HASH = 'd8858b9612db6eb93674f78402ffc2876879e675bd4a27d1a1dd1006f4640be5';
	private const HASH_1 = '034409014b2fa66604870366eea807323f9ecbfb759f31f8fb05234756674d55';
	private const PAID_CANONICAL = '{"key":"inv-1a2b3c4d","note":null,"number":"RE-2026-0007","paid_on":"2026-10-20"}';
	private const PAID_HASH = '39e5fa1f3b789d9c5220d4306da211cee579f76d7416eb5710dcdafcce5c7a9a';
	private const HASH_2 = 'e837e0f26f98711b04d672ffd9c3adaf07d7390ed297ca50ba600b080615deac';

	private static function outcome(callable $fn): string {
		try {
			$fn();
		} catch (ApiException $e) {
			return $e->getStatus() . ' ' . $e->getMessage();
		}
		return 'ok';
	}

	public function testCanonicalJson(): void {
		$payload = Json::decode('{"number":"RE-2026-0007","net":1875.0,"positions":[{"kind":"hours","text":"Planung / Phase 1","hours":12.5,"rate":150,"amount":1875}],"customer":{"name":"Müller AG","city":"Basel"},"greeting":null,"key":"inv-1a2b3c4d","empty":{}}');
		$this->assertSame(self::CANONICAL, LedgerRules::canonical($payload), 'keys sorted by byte order, lists kept, no whitespace, unicode and slashes unescaped, 1875.0 keeps its fraction');
		$this->assertSame(self::CANONICAL, LedgerRules::canonical(json_decode(self::CANONICAL, false)), 'the canonical form is a fixed point');
		$this->assertSame(str_replace('"empty":{}', '"empty":[]', self::CANONICAL), LedgerRules::canonical(json_decode(self::CANONICAL, true)), 'from PHP arrays an empty one is a list');
		$this->assertSame('{"B":1,"a":2,"b":[3,{"y":0.5,"z":null}]}', LedgerRules::canonical(['b' => [3, ['z' => null, 'y' => 0.5]], 'a' => 2, 'B' => 1]), 'upper case before lower case, nested objects sorted too');
		$this->assertSame('[]', LedgerRules::canonical([]));
		$this->assertSame('{}', LedgerRules::canonical(new \stdClass()));
		$this->assertSame(self::PAYLOAD_HASH, LedgerRules::payloadHash(self::CANONICAL));
	}

	public function testEntryHashExample(): void {
		$this->assertSame(self::HASH_1, LedgerRules::entryHash(1, '2026-10-15T14:30:00Z', 'pbadmin', 'issued', 'RE-2026-0007', self::PAYLOAD_HASH, ''), 'the first entry: prev_hash empty');
		$this->assertSame(self::HASH_1, hash('sha256', "1\n2026-10-15T14:30:00Z\npbadmin\nissued\nRE-2026-0007\n" . self::PAYLOAD_HASH . "\n"), 'the formula spelled out');
		$this->assertSame(self::PAID_HASH, LedgerRules::payloadHash(self::PAID_CANONICAL));
		$this->assertSame(self::HASH_2, LedgerRules::entryHash(2, '2026-10-15T15:00:00Z', 'pbadmin', 'paid', 'RE-2026-0007', self::PAID_HASH, self::HASH_1), 'the second entry chains to the first');
		$this->assertSame('2026-10-15T14:30:00Z', LedgerRules::at(gmmktime(14, 30, 0, 10, 15, 2026)));
	}

	/** @return list<array<string,mixed>> */
	private static function chain(): array {
		return [
			['seq' => 1, 'at' => '2026-10-15T14:30:00Z', 'actor' => 'pbadmin', 'action' => 'issued', 'number' => 'RE-2026-0007', 'payload' => self::CANONICAL, 'payload_hash' => self::PAYLOAD_HASH, 'prev_hash' => '', 'hash' => self::HASH_1],
			['seq' => 2, 'at' => '2026-10-15T15:00:00Z', 'actor' => 'pbadmin', 'action' => 'paid', 'number' => 'RE-2026-0007', 'payload' => self::PAID_CANONICAL, 'payload_hash' => self::PAID_HASH, 'prev_hash' => self::HASH_1, 'hash' => self::HASH_2],
		];
	}

	public function testVerify(): void {
		$this->assertSame(['ok' => true, 'entries' => 0, 'head' => null], LedgerRules::verify([]), 'an empty ledger is fine');
		$this->assertSame(['ok' => true, 'entries' => 2, 'head' => self::HASH_2], LedgerRules::verify(self::chain()));
		$noPayload = array_map(static fn (array $e): array => array_diff_key($e, ['payload' => 1]), self::chain());
		$this->assertTrue(LedgerRules::verify($noPayload)['ok'], 'without the payloads only the chain is checked');

		$c = self::chain();
		$c[1]['hash'] = str_repeat('0', 64);
		$r = LedgerRules::verify($c);
		$this->assertSame(['ok' => false, 'entries' => 2, 'first_break' => 2, 'field' => 'hash', 'expected' => self::HASH_2, 'found' => str_repeat('0', 64)], $r, 'a tampered hash');

		$c = self::chain();
		$c[0]['payload'] = str_replace('1875.0', '1875.5', self::CANONICAL);
		$r = LedgerRules::verify($c);
		$this->assertFalse($r['ok']);
		$this->assertSame([1, 'payload_hash', self::PAYLOAD_HASH], [$r['first_break'], $r['field'], $r['found']], 'a changed payload');

		$c = self::chain();
		$c[1]['prev_hash'] = self::HASH_2;
		$r = LedgerRules::verify($c);
		$this->assertSame([2, 'prev_hash', self::HASH_1, self::HASH_2], [$r['first_break'], $r['field'], $r['expected'], $r['found']], 'a broken link');

		$c = self::chain();
		$c[1]['seq'] = 3;
		$r = LedgerRules::verify($c);
		$this->assertSame([3, 'seq', '2', '3'], [$r['first_break'], $r['field'], $r['expected'], $r['found']], 'a gap');

		$c = self::chain();
		$c[0]['actor'] = 'pbverw';
		$r = LedgerRules::verify($c);
		$this->assertSame([1, 'hash'], [$r['first_break'], $r['field']], 'a changed field of the first entry breaks its hash');
	}

	public function testSumsAndNegation(): void {
		$p = static fn (int|float $amount, string $kind = 'flat'): \stdClass => (object)['kind' => $kind, 'text' => 'x', 'amount' => $amount];
		$this->assertSame(['net' => 250.0, 'vat_amount' => 20.25, 'gross' => 270.25], IssueRules::sums([$p(300), $p(-50, 'discount'), $p(0, 'text')], 8.1));
		$this->assertSame(['net' => 0.0, 'vat_amount' => 0.0, 'gross' => 0.0], IssueRules::sums([], 7.7), 'no positions: nothing');
		$this->assertSame(['net' => 10.0, 'vat_amount' => 0.0, 'gross' => 10.0], IssueRules::sums([$p(10)], 0));
		$this->assertSame(['net' => 33.33, 'vat_amount' => 2.7, 'gross' => 36.03], IssueRules::sums([$p(11.11), $p(22.22)], 8.1), 'rounded to cents');
		$neg = IssueRules::negate([$p(300), $p(-50), $p(12.5)]);
		$this->assertSame([-300, 50, -12.5], array_map(static fn (\stdClass $x): int|float => $x->amount, $neg), 'amounts negated, ints stay ints');
		$this->assertSame('300.00', IssueRules::decimal(300.0));
		$this->assertSame('0.10', IssueRules::decimal(0.1));
	}

	public function testHoursAndChecks(): void {
		$b = static fn (array $over = []): \stdClass => (object)array_merge(['person' => 'alice', 'uid' => 'u1', 'start' => '2026-09-02T08:00:00'], $over);
		$h = IssueRules::hoursOf([$b(['hours' => 2.5, 'rate' => 150, 'code' => 'D196.4']), $b(['uid' => 'u2'])]);
		$this->assertSame(['uid' => 'alice', 'event_uid' => 'u1', 'start' => '2026-09-02T08:00:00', 'hours' => 2.5, 'rate' => 150.0, 'amount' => 375.0, 'code' => 'D196.4'], $h[0]);
		$this->assertSame([0.0, 0.0, 0.0, null], [$h[1]['hours'], $h[1]['rate'], $h[1]['amount'], $h[1]['code']], 'missing hours and rate count 0');
		$this->assertSame(2.5, IssueRules::totalHours($h));

		$d = static fn (array $over = []): \stdClass => (object)array_merge(['status' => 'draft', 'number' => null, 'budget' => 'b1', 'bookings' => [$b()]], $over);
		$this->assertSame('ok', self::outcome(fn () => IssueRules::requireIssuable($d())));
		$this->assertSame('ok', self::outcome(fn () => IssueRules::requireIssuable($d(['bookings' => [], 'positions' => [(object)['kind' => 'flat', 'text' => 'x', 'amount' => 1]]]))));
		$this->assertSame('422 Only a draft can be issued.', self::outcome(fn () => IssueRules::requireIssuable($d(['status' => 'issued']))));
		$this->assertSame('422 The draft already has a number.', self::outcome(fn () => IssueRules::requireIssuable($d(['number' => 'RE-1']))));
		$this->assertSame('422 “budget” is required: every invoice belongs to a budget.', self::outcome(fn () => IssueRules::requireIssuable($d(['budget' => ' ']))));
		$this->assertSame('422 The draft has neither positions nor bookings.', self::outcome(fn () => IssueRules::requireIssuable($d(['bookings' => []]))));

		$this->assertSame('2026-10-15', IssueRules::issuedOn(null, gmmktime(12, 0, 0, 10, 15, 2026)));
		$this->assertSame('2026-10-01', IssueRules::issuedOn('2026-10-01', 0));
		$this->assertSame('422 “issued_on” must be a day in the format YYYY-MM-DD.', self::outcome(fn () => IssueRules::issuedOn('15.10.2026', 0)));
		$this->assertSame(30, IssueRules::dueDays(null));
		$this->assertSame(10, IssueRules::dueDays(10));
		foreach ([0, 366, '10', 1.5] as $bad) {
			$this->assertSame('422 “due_days” must be an integer from 1 to 365.', self::outcome(fn () => IssueRules::dueDays($bad)), var_export($bad, true));
		}
		$this->assertSame('2026-11-14', IssueRules::dueOn('2026-10-15', 30));
		$this->assertSame('2027-01-01', IssueRules::dueOn('2026-12-31', 1));
		$this->assertSame('422 “paid_on” must be a day in the format YYYY-MM-DD.', self::outcome(fn () => IssueRules::paidOn(null)));
		$this->assertSame('reversal', IssueRules::kind('reversal'));
		$this->assertSame('422 “kind” must be “reversal” or “credit”.', self::outcome(fn () => IssueRules::kind('storno')));
		$this->assertNull(IssueRules::shortText(null, 'reason'));
		$this->assertSame('422 “reason” must be text of at most 500 characters.', self::outcome(fn () => IssueRules::shortText(str_repeat('x', 501), 'reason')));

		$project = (object)['customer' => 'kanton', 'budgets' => [(object)['id' => 'b1', 'currency' => 'chf'], (object)['id' => 'b2']]];
		$this->assertSame('kanton', IssueRules::customerKeyOf($project));
		$this->assertSame('alt', IssueRules::customerKeyOf((object)['kunde' => 'alt']), 'older records');
		$this->assertNull(IssueRules::customerKeyOf((object)['customer' => '']));
		$this->assertSame('CHF', IssueRules::currencyOf(IssueRules::budgetOf($project, 'b1') ?? new \stdClass(), (object)[]));
		$this->assertSame('EUR', IssueRules::currencyOf(IssueRules::budgetOf($project, 'b2') ?? new \stdClass(), (object)['currency' => 'EUR']), 'the draft’s when the budget has none');
		$this->assertNull(IssueRules::currencyOf((object)[], (object)[]));
		$this->assertNull(IssueRules::budgetOf($project, 'b3'));
		$settings = (object)['issuer' => (object)['name' => 'Denkstatt'], 'vat' => 8.1];
		$this->assertSame('Denkstatt', IssueRules::issuerOf($settings)?->name);
		$this->assertNull(IssueRules::issuerOf(null));
		$this->assertSame(7.7, IssueRules::vatOf((object)['vat' => 7.7], $settings), 'the draft’s');
		$this->assertSame(8.1, IssueRules::vatOf((object)[], $settings), 'the team’s default');
		$this->assertSame(0.0, IssueRules::vatOf((object)[], null), 'else 0');

		$customer = (object)['id' => 'kanton', 'name' => 'Kanton', 'street' => 'Platz 1', 'city' => 'Basel', 'country' => 'CH', 'vat_id' => 'CHE-1', 'note' => 'secret', 'contacts' => [(object)['id' => 'c1', 'name' => 'Lea']]];
		$block = IssueRules::customerBlock($customer, (object)['contact' => 'c1']);
		$this->assertSame(['id', 'name', 'street', 'city', 'country', 'vat_id', 'contact'], array_keys(get_object_vars($block)), 'address fields, no note');
		$this->assertSame('Lea', $block->contact->name, 'the contact the draft names');
		$this->assertSame('Herr X', IssueRules::customerBlock($customer, (object)['contact' => 'Herr X'])->contact, 'free text when no contact matches');
		$this->assertFalse(property_exists(IssueRules::customerBlock($customer, (object)[]), 'contact'));
		$this->assertMatchesRegularExpression('/^inv-[0-9a-f]{8}$/', IssueRules::newKey());
	}

	public function testNumberFill(): void {
		$this->assertSame('RE-2026-0007', InvoiceNumbering::fill('RE-{year}-{no:4}', 7, 2026, 'd200', 'kanton'));
		$this->assertSame('2026-0007', InvoiceNumbering::fill(null, 7, 2026, 'd200', 'kanton'), 'no pattern: the default');
		$this->assertSame('kanton/d200/26-7', InvoiceNumbering::fill('{kunde}/{projekt}/{jj}-{nr}', 7, 2026, 'd200', 'kanton'));
		$this->assertSame('R00000123', InvoiceNumbering::fill('R{nr:8}', 123, 2026, 'd200', null));
		$this->assertSame('-7', InvoiceNumbering::fill('{customer}-{no}', 7, 2026, 'd200', null), 'no customer: empty');
		$this->assertSame('RE-{year}-{no:4}', InvoiceNumbering::patternOf(Json::decode('{"invoice_number":{"pattern":"RE-{year}-{no:4}"}}')));
		$this->assertNull(InvoiceNumbering::patternOf(Json::decode('{"invoice_number":null}')));
		$this->assertNull(InvoiceNumbering::patternOf(null));
		$this->assertSame('422 The invoice number ' . str_repeat('p', 60) . '-1234567 is empty or longer than 64 characters; change the pattern.', self::outcome(fn () => InvoiceNumbering::fill('{project}-{no}', 1234567, 2026, str_repeat('p', 60), null)));
	}

	public function testCapability(): void {
		$cap = (new Capabilities($this->createMock(IAppManager::class)))->getCapabilities()['timesister'];
		$this->assertSame(1, $cap['invoicing']);
	}
}
