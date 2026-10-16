<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\CostRules;
use OCA\TimeSister\Service\ModuleRules;
use OCA\TimeSister\Service\ProjectRules;
use OCA\TimeSister\Service\RecordValidator;
use PHPUnit\Framework\TestCase;

/**
 * The cost positions of a budget (0.21.0, module «costs»): every field,
 * the defaults (third-party costs by receipts, mode rebill), the error
 * per field, the check through the project and the module switch.
 */
class CostRulesTest extends TestCase {
	/** @return list<\stdClass> */
	private static function valid(): array {
		return [
			(object)['no' => 1, 'name' => 'Nebenkosten', 'kind' => 'expense', 'rule' => 'percent', 'percent' => 3, 'mode' => 'rebill', 'vat' => 8.1, 'markup' => null, 'description' => 'Druck, Telefon'],
			(object)['no' => 2, 'name' => 'Pauschale Fahrten', 'kind' => 'expense', 'rule' => 'flat', 'amount' => 250, 'per' => 'invoice'],
			(object)['no' => 3, 'name' => 'Spesen nach Beleg', 'kind' => 'expense', 'rule' => 'receipts', 'cap' => 1500, 'mode' => 'pass_through'],
			(object)['no' => 4, 'name' => 'Umsetzung', 'kind' => 'third_party', 'cap' => 80000, 'markup' => 10, 'vat' => null, 'description' => null],
		];
	}

	private static function message(array $costs): string {
		try {
			CostRules::check($costs);
			return 'ok';
		} catch (ApiException $e) {
			return $e->getStatus() . ' ' . $e->getMessage();
		}
	}

	/** One position of valid() changed. */
	private static function with(int $i, array $over, array $unset = []): array {
		$c = array_map(static fn (\stdClass $x): \stdClass => clone $x, self::valid());
		foreach ($over as $k => $v) {
			$c[$i]->{$k} = $v;
		}
		foreach ($unset as $k) {
			unset($c[$i]->{$k});
		}
		return $c;
	}

	public function testValidPositionsAndDefaults(): void {
		$c = self::valid();
		CostRules::check($c);
		CostRules::check(null);
		CostRules::check([]);
		$this->assertSame(['receipts', 'rebill'], [$c[3]->rule, $c[3]->mode], 'third-party costs: by receipts, re-billed when not said');
		$this->assertSame('rebill', $c[1]->mode);
	}

	public function testErrorsNameTheField(): void {
		foreach ([
			[self::with(0, ['no' => 0]), '422 Cost position 1: The field “no” must be an integer ≥ 1.'],
			[self::with(1, ['no' => 1]), '422 Cost position 2: “no” must be unique within the budget.'],
			[self::with(0, ['name' => '']), '422 Cost position 1: “name” must be text of 1 to 120 characters.'],
			[self::with(0, ['name' => str_repeat('x', 121)]), '422 Cost position 1: “name” must be text of 1 to 120 characters.'],
			[self::with(0, ['kind' => 'travel']), '422 Cost position 1: “kind” must be one of expense, third_party.'],
			[self::with(0, [], ['rule']), '422 Cost position 1: “rule” must be one of percent, flat, receipts.'],
			[self::with(3, ['rule' => 'flat']), '422 Cost position 4: “rule” must be one of receipts.'],
			[self::with(0, ['percent' => 101]), '422 Cost position 1: “percent” must be a percentage from 0 to 100.'],
			[self::with(0, [], ['percent']), '422 Cost position 1: “percent” must be a percentage from 0 to 100.'],
			[self::with(1, ['amount' => -1]), '422 Cost position 2: “amount” must be a number ≥ 0.'],
			[self::with(1, [], ['amount']), '422 Cost position 2: “amount” must be a number ≥ 0.'],
			[self::with(1, ['per' => 'month']), '422 Cost position 2: “per” must be one of invoice, budget.'],
			[self::with(1, [], ['per']), '422 Cost position 2: “per” must be one of invoice, budget.'],
			[self::with(2, [], ['cap']), '422 Cost position 3: “cap” must be a number ≥ 0.'],
			[self::with(3, ['cap' => -5]), '422 Cost position 4: “cap” must be a number ≥ 0.'],
			[self::with(0, ['mode' => 'resell']), '422 Cost position 1: “mode” must be one of rebill, pass_through.'],
			[self::with(2, ['markup' => 10]), '422 Cost position 3: “markup” applies only when “mode” is “rebill”.'],
			[self::with(2, ['vat' => 8.1]), '422 Cost position 3: “vat” applies only when “mode” is “rebill”.'],
			[self::with(3, ['markup' => 120]), '422 Cost position 4: “markup” must be a percentage from 0 to 100.'],
			[self::with(0, ['vat' => '8.1']), '422 Cost position 1: “vat” must be a percentage from 0 to 100.'],
			[self::with(0, ['description' => str_repeat('x', 301)]), '422 Cost position 1: “description” must be text of at most 300 characters.'],
			[[1], '422 Cost position 1: Each cost position must be a JSON object.'],
		] as $i => [$costs, $want]) {
			$this->assertSame($want, self::message($costs), (string)$i);
		}
		try {
			CostRules::check((object)['no' => 1]);
			$this->fail('an object');
		} catch (ApiException $e) {
			$this->assertSame('“costs” must be a list of at most 200 entries.', $e->getMessage());
		}
	}

	public function testThroughTheProjectAndTheModule(): void {
		$project = (object)['id' => 'p1', 'codes' => ['D196'], 'budgets' => [(object)['id' => 'b1', 'currency' => 'chf', 'costs' => self::valid()]]];
		ProjectRules::validate($project);
		$this->assertSame('rebill', $project->budgets[0]->costs[3]->mode);
		$project->budgets[0]->costs[0]->kind = 'other';
		try {
			RecordValidator::validate('project', 'p1', $project);
			$this->fail('kind');
		} catch (ApiException $e) {
			$this->assertSame([422, 'Cost position 1: “kind” must be one of expense, third_party.'], [$e->getStatus(), $e->getMessage()]);
		}
		ModuleRules::validate((object)['jobs' => true, 'costs' => false, 'later' => 'x']);
		ModuleRules::validate(null);
		RecordValidator::validate('setting', 'settings', (object)['modules' => (object)['costs' => true]]);
		try {
			RecordValidator::validate('setting', 'settings', (object)['modules' => (object)['costs' => 'yes']]);
			$this->fail('costs');
		} catch (ApiException $e) {
			$this->assertSame('“modules.costs” must be true or false.', $e->getMessage());
		}
	}
}
