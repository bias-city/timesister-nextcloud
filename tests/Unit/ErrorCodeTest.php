<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\ErrorCode;
use PHPUnit\Framework\TestCase;

/**
 * The error-code contract of 0.31.0 (API.md «Error codes»): every rule
 * violation (409/403) of receipts, expenses, invoices and hand-over names
 * its code, the constants of ErrorCode and the table are the same set,
 * and the body carries `code` and `field` beside `error` and `message`.
 */
class ErrorCodeTest extends TestCase {
	private const ROOT = __DIR__ . '/../..';
	/** The services whose 409/403 all carry a code (PLAN-ROBUST R1.1); others follow release by release. */
	private const RULED = [
		'ReceiptRules', 'ReceiptService', 'ReceiptBooking', 'ExpenseFields', 'ExpenseReportRules', 'ExpenseReportService',
		'ExpenseRuleRules', 'ExpenseExportService', 'CostWrites', 'HandoverRules', 'HandoverService', 'InvoiceRules',
		'InvoiceService', 'IssueRules', 'IssueService', 'DraftResolver',
		// 0.33.0: fee models and the person's level.
		'FeeModelRules', 'LevelRules', 'CorrectionRules',
	];

	public function testBodyCarriesCodeAndField(): void {
		$e = ApiException::conflict('x')->code(ErrorCode::EXPENSE_SETTLED)->field('file');
		$this->assertSame(['error' => 'conflict', 'message' => 'x', 'code' => 'expense_settled', 'field' => 'file'], $e->toData());
		$this->assertSame(['error' => 'conflict', 'message' => 'y'], ApiException::conflict('y')->toData());
		$this->assertSame(['error' => 'invalid', 'message' => '“a” is invalid.', 'field' => 'a'], ApiException::invalidField('a')->toData());
		$this->assertSame('invoice_issued', ApiException::issued()->getRuleCode());
		$g = ApiException::gone();
		$this->assertSame(410, $g->getStatus());
		$this->assertSame(['error' => 'gone', 'code' => 'gone'], array_intersect_key($g->toData(), ['error' => 1, 'code' => 1]));
		// `like` copies code and field onto a reworded wrapper, the extra stays the wrapper's.
		$w = (new ApiException(409, ApiException::CONFLICT, 'wrapped', ['index' => 3]))->like($e);
		$this->assertSame(['error' => 'conflict', 'message' => 'wrapped', 'code' => 'expense_settled', 'field' => 'file', 'index' => 3], $w->toData());
	}

	/** Every `conflict(`, `forbidden(` and `duplicate(` in the ruled services ends in `->code(ErrorCode::…)`. */
	public function testEveryRuleViolationHasACode(): void {
		$missing = [];
		foreach (self::RULED as $name) {
			$src = (string)file_get_contents(self::ROOT . "/lib/Service/$name.php");
			$offset = 0;
			while (preg_match('/ApiException::(conflict|forbidden|duplicate)\(/', $src, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
				$open = $m[0][1] + strlen($m[0][0]) - 1;
				$close = self::closing($src, $open);
				$tail = substr($src, $close + 1, 40);
				if (!str_starts_with($tail, '->code(ErrorCode::')) {
					$missing[] = $name . ':' . (substr_count(substr($src, 0, $open), "\n") + 1);
				}
				$offset = $close + 1;
			}
		}
		$this->assertSame([], $missing, 'rule violations without a code');
	}

	/** The constants of ErrorCode and the rows of the table in API.md are one set; both copies of API.md are equal. */
	public function testConstantsMatchTheTable(): void {
		$constants = array_values((new \ReflectionClass(ErrorCode::class))->getConstants());
		sort($constants);
		$this->assertSame(array_unique($constants), $constants, 'every code once');
		foreach ($constants as $c) {
			$this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', (string)$c);
		}
		// docs/API.md ships with the app; the original nc-app/API.md exists only in the monorepo.
		$api = (string)file_get_contents(self::ROOT . '/docs/API.md');
		$this->assertSame(1, preg_match('/\n## Error codes[^\n]*\n(.*?)\n## /s', $api, $m), 'API.md has the section «Error codes»');
		preg_match_all('/^\| `([a-z0-9_]+)` \|/m', $m[1], $rows);
		$table = array_values(array_diff($rows[1], ["code"]));
		sort($table);
		$this->assertSame($table, $constants, 'ErrorCode and the table in API.md name the same codes');
		if (is_file(self::ROOT . '/../API.md')) {
			$this->assertSame(file_get_contents(self::ROOT . '/../API.md'), $api, 'docs/API.md is a copy of nc-app/API.md');
		}
	}

	/** The index of the `)` closing the `(` at `$open`, skipping quoted strings. */
	private static function closing(string $src, int $open): int {
		$depth = 0;
		$quote = null;
		for ($i = $open, $n = strlen($src); $i < $n; $i++) {
			$ch = $src[$i];
			if ($quote !== null) {
				if ($ch === '\\') {
					$i++;
				} elseif ($ch === $quote) {
					$quote = null;
				}
				continue;
			}
			if ($ch === "'" || $ch === '"') {
				$quote = $ch;
			} elseif ($ch === '(') {
				$depth++;
			} elseif ($ch === ')' && --$depth === 0) {
				return $i;
			}
		}
		self::fail('unbalanced parentheses');
	}
}
