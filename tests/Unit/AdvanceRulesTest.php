<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Capabilities;
use OCA\TimeSister\Service\AdvanceRules;
use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\IssueRules;
use OCA\TimeSister\Service\Json;
use OCA\TimeSister\Service\ProjectRules;
use OCA\TimeSister\Service\RecordValidator;
use OCA\TimeSister\Service\VatLines;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * Advances and expenses invoices (0.39.0, PLAN-RECHNUNGEN R5/R6): the
 * advance invoice's one line, the deductions against issued advances, the
 * companion of an expenses invoice, `plan_kind`, `invoicing.expenses_separate`.
 */
class AdvanceRulesTest extends TestCase {
	private static function o(array $a): \stdClass {
		$v = Json::decode((string)json_encode($a));
		self::assertInstanceOf(\stdClass::class, $v);
		return $v;
	}

	/** @return list<\stdClass> */
	private static function lines(array ...$l): array {
		return array_map(static fn (array $x): \stdClass => self::o($x), $l);
	}

	private static function advance(string $number, float $amount, array $over = []): \stdClass {
		return self::o(array_merge(['status' => 'issued', 'number' => $number, 'project' => 'd200', 'budget' => 'b1', 'customer' => 'k1', 'kind' => 'advance',
			'positions' => [['kind' => 'advance', 'text' => 'Akontorechnung', 'amount' => $amount]]], $over));
	}

	/** @return array<string,\stdClass> */
	private static function invoices(): array {
		return [
			'inv-a1' => self::advance('RE-1', 3000),
			'inv-a2' => self::advance('RE-2', 2000, ['status' => 'paid']),
			'inv-a3' => self::advance('RE-3', 1000, ['status' => 'draft', 'number' => null]),
			'inv-a4' => self::advance('RE-4', 1000, ['budget' => 'b2']),
			'inv-a5' => self::advance('RE-5', 1000, ['status' => 'cancelled']),
			'inv-h' => self::o(['status' => 'issued', 'number' => 'RE-6', 'project' => 'd200', 'budget' => 'b1', 'positions' => [['kind' => 'flat', 'text' => 'x', 'amount' => 10]]]),
		];
	}

	private static function outcome(callable $fn): string {
		try {
			$fn();
		} catch (ApiException $e) {
			return $e->getStatus() . ' ' . ($e->getRuleCode() ?? $e->getMessage());
		}
		return 'ok';
	}

	private static function deduct(array $invoices, array ...$lines): string {
		return self::outcome(fn () => AdvanceRules::checkDeductions('inv-final', self::lines(['kind' => 'hours', 'text' => 'Planung', 'amount' => 9000], ...$lines), 'd200', 'b1', 'k1', $invoices));
	}

	public function testAdvanceInvoiceHasOneLine(): void {
		$adv = self::o(['kind' => 'advance']);
		$one = self::lines(['kind' => 'advance', 'text' => 'Akonto', 'amount' => 3000]);
		$this->assertSame('ok', self::outcome(fn () => AdvanceRules::checkKind($adv, $one, 0)));
		$this->assertSame('ok', self::outcome(fn () => AdvanceRules::checkKind($adv, [...$one, ...self::lines(['kind' => 'text', 'text' => 'Danke', 'amount' => 0])], 0)), 'a text line beside');
		$this->assertSame('422 advance_position_count', self::outcome(fn () => AdvanceRules::checkKind($adv, [], 0)));
		$this->assertSame('422 advance_position_count', self::outcome(fn () => AdvanceRules::checkKind($adv, [...$one, ...$one], 0)));
		$this->assertSame('422 advance_position_count', self::outcome(fn () => AdvanceRules::checkKind($adv, [...$one, ...self::lines(['kind' => 'hours', 'text' => 'h', 'amount' => 1])], 0)));
		$this->assertSame('422 advance_position_count', self::outcome(fn () => AdvanceRules::checkKind($adv, $one, 2)), 'no bookings');
		$this->assertSame('ok', self::outcome(fn () => AdvanceRules::checkKind(self::o(['kind' => 'final']), [], 2)), 'other kinds are free');
	}

	public function testDeductions(): void {
		$inv = self::invoices();
		$this->assertSame('ok', self::deduct($inv, ['kind' => 'advance_deduction', 'text' => 'Abzug Akonto RE-1', 'amount' => -3000, 'invoice' => 'inv-a1'],
			['kind' => 'advance_deduction', 'text' => 'Abzug Akonto RE-2', 'amount' => -2000.004, 'invoice' => 'inv-a2']));
		foreach (['inv-a3' => 'a draft', 'inv-a4' => 'another budget', 'inv-a5' => 'cancelled', 'inv-h' => 'no advance', 'inv-x' => 'missing', 'inv-final' => 'itself'] as $k => $why) {
			$this->assertSame('422 advance_unknown', self::deduct($inv, ['kind' => 'advance_deduction', 'text' => 'Abzug', 'amount' => -1000, 'invoice' => $k]), $why);
		}
		$this->assertSame('422 advance_amount', self::deduct($inv, ['kind' => 'advance_deduction', 'text' => 'Abzug', 'amount' => -2500, 'invoice' => 'inv-a1']));
		$twice = ['kind' => 'advance_deduction', 'text' => 'Abzug', 'amount' => -3000, 'invoice' => 'inv-a1'];
		$this->assertSame('422 advance_deducted_twice', self::deduct($inv, $twice, $twice), 'twice here');
		$other = self::o(['status' => 'draft', 'number' => null, 'project' => 'd200', 'budget' => 'b1', 'positions' => [$twice]]);
		$this->assertSame('422 advance_deducted_twice', self::deduct($inv + ['inv-other' => $other], $twice), 'a draft elsewhere');
		$other->status = 'cancelled';
		$this->assertSame('ok', self::deduct($inv + ['inv-other' => $other], $twice), 'a cancelled one released it');
		$other->status = 'issued';
		$other->reverses = 'inv-old';
		$this->assertSame('ok', self::deduct($inv + ['inv-other' => $other], $twice), 'a reversal deducts nothing');
		// Without a budget: the same project; without a project: the same customer.
		$a = ['inv-a1' => self::advance('RE-1', 3000, ['budget' => null])];
		$line = self::lines(['kind' => 'advance_deduction', 'text' => 'Abzug', 'amount' => -3000, 'invoice' => 'inv-a1']);
		$this->assertSame('ok', self::outcome(fn () => AdvanceRules::checkDeductions('inv-f', $line, 'd200', null, 'k1', $a)));
		$this->assertSame('422 advance_unknown', self::outcome(fn () => AdvanceRules::checkDeductions('inv-f', $line, 'd300', null, 'k1', $a)));
		$this->assertSame('ok', self::outcome(fn () => AdvanceRules::checkDeductions('inv-f', $line, null, null, 'k1', $a)));
		$this->assertSame('422 advance_unknown', self::outcome(fn () => AdvanceRules::checkDeductions('inv-f', $line, null, null, 'k2', $a)));
		$this->assertTrue(AdvanceRules::deducts($line));
		$this->assertFalse(AdvanceRules::deducts(self::lines(['kind' => 'advance', 'text' => 'x', 'amount' => 1])));
	}

	public function testCompanion(): void {
		$inv = ['inv-main' => self::o(['status' => 'draft', 'customer' => 'k1']), 'inv-k2' => self::o(['status' => 'issued', 'customer' => 'k2']),
			'inv-gone' => self::o(['status' => 'cancelled', 'customer' => 'k1']), 'inv-ext' => self::o(['status' => 'external', 'customer' => 'k1'])];
		$of = static fn (\stdClass $o): ?string => is_string($o->customer ?? null) ? $o->customer : null;
		$c = fn (array $d): string => self::outcome(fn () => AdvanceRules::checkCompanion('inv-exp', self::o($d), 'k1', $inv, $of));
		$this->assertSame('ok', $c(['kind' => 'expenses', 'companion' => 'inv-main']));
		$this->assertSame('ok', $c(['kind' => 'hours', 'companion' => 'inv-x']), 'only an expenses invoice is checked');
		foreach ([null, 'inv-x', 'inv-k2', 'inv-gone', 'inv-ext', 'inv-exp'] as $k) {
			$this->assertSame('422 companion_unknown', $c(['kind' => 'expenses', 'companion' => $k]), (string)$k);
		}
		$p = IssueRules::payload('inv-exp', 'RE-9', '2026-11-05', 'pbadmin', '2026-12-05', 'd200', 'k1', '2026-10-01', '2026-10-31', 'CHF',
			VatLines::of([], 8.1, 'domestic'), new \stdClass(), new \stdClass(), [], [], null, null, null, null, null, null, null, 'inv-main');
		$this->assertSame('inv-main', $p['companion']);
		$q = IssueRules::payload('inv-1', 'RE-1', '2026-11-05', 'pbadmin', '2026-12-05', 'd200', 'k1', '2026-10-01', '2026-10-31', 'CHF',
			VatLines::of([], 8.1, 'domestic'), new \stdClass(), new \stdClass(), [], [], null, null, null, null, null);
		$this->assertArrayNotHasKey('companion', $q, 'older payloads stay exact');
	}

	public function testPlanKindAndSetting(): void {
		foreach ([null, 'hours', 'advance'] as $k) {
			$this->assertSame('ok', self::outcome(fn () => ProjectRules::checkBudget(self::o(['plan_kind' => $k]))), (string)$k);
		}
		$this->assertSame('422 “plan_kind” must be one of hours, advance.', self::outcome(fn () => ProjectRules::checkBudget(self::o(['plan_kind' => 'percent']))));
		$this->assertTrue(AdvanceRules::expensesSeparate(null), 'default on');
		$this->assertTrue(AdvanceRules::expensesSeparate(self::o(['invoicing' => ['expenses_separate' => true]])));
		$this->assertFalse(AdvanceRules::expensesSeparate(self::o(['invoicing' => ['expenses_separate' => false]])));
		$s = static fn (mixed $v): string => self::outcome(fn () => RecordValidator::validate('setting', 'settings', self::o(['invoicing' => $v])));
		$this->assertSame('ok', $s(['expenses_separate' => false]));
		$this->assertSame('ok', $s(null));
		$this->assertSame('ok', $s(new \stdClass()));
		$this->assertStringStartsWith('422', $s(['expenses_separate' => 'yes']));
		$this->assertStringStartsWith('422', $s(true));
		$cap = (new Capabilities($this->createMock(IAppManager::class)))->getCapabilities()['timesister'];
		$this->assertSame([1, 1, 1], [$cap['advance_rules'], $cap['expenses_separate'], $cap['receipts_by_customer']]);
	}
}
