<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\BankRules;
use OCA\TimeSister\Service\InvoiceRules;
use OCA\TimeSister\Service\IssueRules;
use OCA\TimeSister\Service\Membership;
use OCA\TimeSister\Service\OfficeCostRules;
use OCA\TimeSister\Service\QrReferenceRules;
use OCA\TimeSister\Service\ReceiptRules;
use OCA\TimeSister\Service\RecordValidator;
use PHPUnit\Framework\TestCase;

/** The record kind `bank` (0.40.0): keys, entries, rules, plan, the upsert per key; schema 2 of the office costs; payments and the reference. */
class BankRulesTest extends TestCase {
	private static function code(callable $fn): string {
		try {
			$fn();
			return 'ok';
		} catch (ApiException $e) {
			return $e->getStatus() . ' ' . ($e->getRuleCode() ?? $e->getErrorCode()) . ($e->toData()['field'] ?? null ? ' ' . $e->toData()['field'] : '');
		}
	}

	private static function obj(string $json): mixed {
		return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
	}

	private static function entry(string $ref, string $date, float $amount, array $more = []): \stdClass {
		return (object)(['key' => "main/$ref", 'account' => 'main', 'source' => 'camt', 'date' => $date, 'amount' => $amount, 'currency' => 'CHF'] + $more);
	}

	private static function year(array $entries): \stdClass {
		return (object)['schema' => 1, 'account' => 'main', 'year' => 2026, 'entries' => $entries];
	}

	public function testKeys(): void {
		$this->assertContains('bank', RecordValidator::KINDS);
		$this->assertSame(['main', 2026], BankRules::entriesKey('main.2026'));
		$this->assertNull(BankRules::entriesKey('rules'));
		$this->assertNull(BankRules::entriesKey('plan.2026-11'));
		$this->assertNull(BankRules::entriesKey('Main.2026'));
		$this->assertNull(BankRules::entriesKey('plan.2026'));
		$this->assertSame('422 invalid', self::code(fn () => RecordValidator::validate('bank', 'other', self::obj('{}'))));
		$this->assertSame('409 bank_entries_only', self::code(fn () => BankRules::requireClientWrite('bank', 'main.2026')));
		$this->assertSame('ok', self::code(fn () => BankRules::requireClientWrite('bank', 'rules')));
		$this->assertSame('403 bank_admin_only', self::code(fn () => BankRules::requireAdmin(new Membership('lead1', 1, 'lead'))));
		$this->assertSame('ok', self::code(fn () => BankRules::requireAdmin(new Membership('admin', 1, 'admin'))));
	}

	public function testEntries(): void {
		$ok = self::entry('R1', '2026-10-14', 12450, ['category' => 'receivables', 'party' => 'Muster AG', 'text' => 'RN-2026-017',
			'links' => [(object)['kind' => 'invoice', 'id' => 'inv-17', 'amount' => 12450]], 'charges' => 2.5, 'code' => 'PMNT/RCDT/ESCT',
			'party_fp' => str_repeat('a', 64), 'confirmed' => true, 'by' => 'admin', 'parts' => 1]);
		$this->assertSame('ok', self::code(fn () => RecordValidator::validate('bank', 'main.2026', self::year([$ok]))));
		$bad = static fn (array $more): string => self::code(fn () => BankRules::validateEntry(self::entry('R2', '2026-10-14', 1, []) == null ?: (object)($more + (array)self::entry('R2', '2026-10-14', 1)), 'main'));
		$this->assertSame('422 invalid key', $bad(['key' => 'eur/R2']));
		$this->assertSame('422 invalid source', $bad(['source' => 'mt940']));
		$this->assertSame('422 invalid category', $bad(['category' => 'coffee']));
		$this->assertSame('422 invalid ignored', $bad(['ignored' => true, 'category' => 'rent']));
		$this->assertSame('422 invalid orig_amount', $bad(['orig_amount' => 100]));
		$this->assertSame('422 invalid links', $bad(['links' => [(object)['kind' => 'invoice', 'id' => 'inv-1', 'amount' => 0]]]));
		$this->assertSame('422 invalid party_fp', $bad(['party_fp' => 'abc']));
		$this->assertSame('422 invalid iban', $bad(['iban' => 'CH93']));
		// 0.41.2: the reference and the parts of a collective entry.
		$ref = (object)['type' => 'QRR', 'value' => '210000000003139471430009017'];
		$part = (object)['party' => 'Spalen Druck AG', 'amount' => 16026.2, 'text' => 'RN-2026-017', 'reference' => $ref];
		$this->assertSame('ok', self::code(fn () => BankRules::validateEntry((object)(['reference' => $ref, 'details' => [$part, $part], 'parts' => 2] + (array)self::entry('R3', '2026-10-14', 32052.4)), 'main')));
		$this->assertSame('422 invalid reference', $bad(['reference' => (object)['type' => 'ESR', 'value' => '1']]));
		$this->assertSame('422 invalid reference', $bad(['reference' => (object)['type' => 'QRR', 'value' => '']]));
		$this->assertSame('422 invalid details', $bad(['details' => [(object)['party' => 'X', 'amount' => 'ten']]]));
		$this->assertSame('422 invalid details', $bad(['details' => array_fill(0, 201, $part)]));
		// A confirmed entry takes reference and parts from a new import, nothing else.
		$stored = (object)(['confirmed' => true, 'category' => 'receivables'] + (array)self::entry('R4', '2026-10-14', 5));
		$incoming = (object)(['reference' => $ref, 'details' => [$part], 'category' => 'rent'] + (array)self::entry('R4', '2026-10-14', 5));
		[$kept, $what] = BankRules::merge($stored, $incoming);
		$this->assertSame('changed', $what);
		$this->assertSame(['receivables', 'QRR', 1], [$kept->category, $kept->reference->type, count($kept->details)]);
		$this->assertSame('kept', BankRules::merge($kept, $incoming)[1]);
		// The year of the key, each key once.
		$this->assertSame('422 invalid date', self::code(fn () => RecordValidator::validate('bank', 'main.2026', self::year([self::entry('A', '2025-12-31', 1)]))));
		$this->assertSame('422 invalid key', self::code(fn () => RecordValidator::validate('bank', 'main.2026', self::year([self::entry('A', '2026-01-01', 1), self::entry('A', '2026-01-02', 2)]))));
	}

	public function testUpsertPerKeyConfirmedWins(): void {
		$stored = self::year([self::entry('A', '2026-03-01', 100, ['category' => 'other_in', 'confirmed' => true]), self::entry('B', '2026-01-01', 5)]);
		$out = BankRules::mergeYear($stored, 'main', 2026, [self::entry('A', '2026-03-01', 100), self::entry('B', '2026-01-01', 5), self::entry('C', '2026-02-01', 7)]);
		$this->assertSame([1, 1, ['main/A']], [$out['saved'], $out['unchanged'], $out['kept']]);
		$this->assertSame(['main/B', 'main/C', 'main/A'], array_map(static fn ($e) => $e->key, $out['record']->entries), 'by date');
		$this->assertSame('other_in', $out['record']->entries[2]->category, 'the confirmed entry stays');
		// A confirmed entry replaces a confirmed one.
		$again = BankRules::mergeYear($out['record'], 'main', 2026, [self::entry('A', '2026-03-01', 100, ['category' => 'costs', 'confirmed' => true])]);
		$this->assertSame('costs', $again['record']->entries[2]->category);
	}

	public function testRulesAndPlan(): void {
		$rules = self::obj('{"schema":1,"salt":"' . str_repeat('ab', 32) . '","rules":[{"fp":"' . str_repeat('c', 64) . '","direction":"debit","category":"rent","cost_line":"c-1","since":"2026-11-05"},
			{"fp":"' . str_repeat('c', 64) . '","direction":"credit","ignored":true,"since":"2026-11-05"}]}');
		$this->assertSame('ok', self::code(fn () => RecordValidator::validate('bank', 'rules', $rules)));
		$rules->rules[1]->direction = 'debit';
		$this->assertSame('422 invalid rules', self::code(fn () => RecordValidator::validate('bank', 'rules', $rules)), 'one rule per counterparty and direction');
		$this->assertSame('422 invalid salt', self::code(fn () => RecordValidator::validate('bank', 'rules', self::obj('{"salt":"zz"}'))));
		$this->assertSame('422 invalid rules', self::code(fn () => RecordValidator::validate('bank', 'rules', self::obj('{"salt":"' . str_repeat('a', 32) . '","rules":[{"fp":"' . str_repeat('c', 64) . '","direction":"debit","category":"rent","ignored":true}]}'))));
		$plan = self::obj('{"schema":1,"month":"2026-11","made_on":"2026-11-01","currencies":{"CHF":{"salaries":61200,"rent":3000}}}');
		$this->assertSame('ok', self::code(fn () => RecordValidator::validate('bank', 'plan.2026-11', $plan)));
		$this->assertSame('422 invalid month', self::code(fn () => RecordValidator::validate('bank', 'plan.2026-12', $plan)));
		$plan->currencies->CHF->coffee = 1;
		$this->assertSame('422 invalid currencies', self::code(fn () => RecordValidator::validate('bank', 'plan.2026-11', $plan)));
	}

	public function testOfficeCostsSchema2(): void {
		$two = self::obj('{"schema":2,"reserve":5000,"experience_off":["bank"],
			"costs":[{"id":"c-1","name":"Rent","amount":3000,"rhythm":"monthly","from":"2026-01-01","kind":"rent","account":"main"}],
			"accounts":[{"id":"main","name":"Main","role":"main","credit_limit":50000,"balances":[{"date":"2026-10-31","amount":1000,"source":"camt"}]},
			            {"id":"eur","name":"EUR","currency":"EUR","iban_fp":"' . str_repeat('f', 64) . '","role":"savings","balances":[]}]}');
		$this->assertSame('ok', self::code(fn () => RecordValidator::validate('setting', 'office_costs', $two)));
		$bad = static function (callable $f) use ($two): string {
			$x = json_decode(json_encode($two), false);
			$f($x);
			return self::code(fn () => OfficeCostRules::validate($x));
		};
		$this->assertSame('422 invalid accounts[1].role', $bad(fn ($x) => $x->accounts[1]->role = 'cash'));
		$this->assertSame('422 invalid accounts[1].id', $bad(fn ($x) => $x->accounts[1]->id = 'rules'));
		$this->assertSame('422 invalid accounts', $bad(fn ($x) => $x->accounts[1]->id = 'main'));
		$this->assertSame('422 invalid accounts[0].balances[0].source', $bad(fn ($x) => $x->accounts[0]->balances[0]->source = 'fax'));
		$this->assertSame('422 invalid experience_off', $bad(fn ($x) => $x->experience_off = ['coffee']));
		$this->assertSame('422 invalid', $bad(fn ($x) => $x->accounts[0]->iban = 'CH93'));
		// Schema 1 without accounts stays fine – until accounts are stored.
		$one = '{"schema":1,"costs":[],"balances":[{"date":"2026-10-01","amount":5}]}';
		$this->assertSame('ok', self::code(fn () => OfficeCostRules::checkSchema(self::obj($one), $one)));
		$this->assertSame('409 office_costs_schema', self::code(fn () => OfficeCostRules::checkSchema($two, $one)));
		$this->assertSame('ok', self::code(fn () => OfficeCostRules::checkSchema($two, json_encode($two))));
	}

	public function testPaymentsReferenceAndReceipts(): void {
		$d = self::obj('{"payments":[{"date":"2026-10-20","amount":700,"source":"camt","key":"main/R9"},{"date":"2026-10-21","amount":-700,"source":"camt","key":"main/R9","kind":"reversal"},{"date":"2026-10-22","amount":5,"kind":"writeoff"}]}');
		$this->assertSame('ok', self::code(fn () => InvoiceRules::checkPayments($d->payments)));
		$this->assertSame(5.0, InvoiceRules::paymentsSum($d));
		$this->assertTrue(InvoiceRules::fromBank($d));
		foreach (['[{"date":"2026-10-20","amount":-1}]', '[{"date":"2026-10-20","amount":1,"kind":"reversal"}]', '[{"date":"2026-10-20","amount":1,"source":"fax"}]'] as $p) {
			$this->assertSame('422 invalid', self::code(fn () => InvoiceRules::checkPayments(self::obj($p))), $p);
		}
		$this->assertSame(['source' => 'csv', 'key' => 'main/R1', 'kind' => 'reversal'], IssueRules::paymentFrom(self::obj('{"source":"csv","bank_key":"main/R1","payment_kind":"reversal"}')));
		$this->assertSame('422 invalid amount', self::code(fn () => IssueRules::paymentAmount(5, true)));
		$this->assertSame(-5.0, IssueRules::paymentAmount(-5, true));
		// RF: the examples of the norm, and as the Mac app computes it from counter and year.
		$this->assertTrue(QrReferenceRules::isCreditorReference('RF18 5390 0754 7034'));
		$this->assertFalse(QrReferenceRules::isCreditorReference('RF19539007547034'));
		$rf = QrReferenceRules::creditorReference(7, '2026-10-15', 'EUR');
		$this->assertTrue(QrReferenceRules::isCreditorReference((string)$rf));
		$this->assertStringEndsWith('72026', (string)$rf);
		$this->assertNull(QrReferenceRules::creditorReference(7, '2026-10-15', 'USD'));
		$this->assertSame('422 invalid reference', self::code(fn () => InvoiceRules::validate(self::obj('{"project":"d200","budget":"b1","status":"draft","reference":"RN-7"}'))));
		// A receipt's payment: the form, and only Team Admins change it.
		$before = self::obj('{"paid_on":"2026-10-20","bank_key":"main/D1","note":"x"}');
		$after = self::obj('{"paid_on":null,"note":"x"}');
		$this->assertSame([], ReceiptRules::changed($before, $after), 'the payment is no change of the receipt');
		ReceiptRules::keepPayment($before, $after);
		$this->assertSame(['2026-10-20', 'main/D1'], [$after->paid_on, $after->bank_key]);
	}
}
