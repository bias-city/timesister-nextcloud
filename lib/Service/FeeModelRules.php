<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The fee model of a budget (0.33.0): `category`, `mean_rate` or `role`
 * (missing: `role`), the one rate of the mean-rate model, the cap as a
 * ceiling or a price (`cap_mode`) and the tolerance band. Pure: the form,
 * the team's default, which budgets change their model (the lock is
 * {@see RecordService}'s, under the team lock) and the budget a booking
 * falls into (the person's right on its level). Computes no money.
 */
final class FeeModelRules {
	public const FIELD = 'fee_model';
	public const CATEGORY = 'category';
	public const MEAN_RATE = 'mean_rate';
	public const ROLE = 'role';
	public const MODELS = [self::CATEGORY, self::MEAN_RATE, self::ROLE];
	public const CAP_MODES = ['cap', 'flat'];

	/** The model of a budget; null and missing read as `role` (every budget before 0.33.0). */
	public static function modelOf(?\stdClass $b): string {
		$m = $b?->{self::FIELD} ?? null;
		return is_string($m) && in_array($m, self::MODELS, true) ? $m : self::ROLE;
	}

	/**
	 * The budget's fee fields: `fee_model`, `mean_rate` (only in its model),
	 * `cap_mode` (only with a cap), `tolerance` 0–100.
	 *
	 * @throws ApiException 422
	 */
	public static function checkBudget(\stdClass $b): void {
		$model = $b->{self::FIELD} ?? null;
		if ($model !== null && !in_array($model, self::MODELS, true)) {
			throw self::invalidModel();
		}
		$mean = $b->mean_rate ?? null;
		if ($mean !== null) {
			if (!ProjectRules::isRate($mean)) {
				throw ApiException::invalid(Message::of('“{field}” must be null or a number ≥ 0.', ['field' => 'mean_rate']))->field('mean_rate');
			}
			if ($model !== self::MEAN_RATE) {
				throw ApiException::invalid('“mean_rate” is the rate of the model “mean_rate”; leave it null in the other models.')
					->code(ErrorCode::FEE_MODEL_INVALID)->field('mean_rate');
			}
		}
		$mode = $b->cap_mode ?? null;
		if ($mode !== null) {
			if (!in_array($mode, self::CAP_MODES, true)) {
				throw ApiException::invalid('“cap_mode” must be null, “cap” or “flat”.')->code(ErrorCode::CAP_MODE_INVALID)->field('cap_mode');
			}
			if (($b->cap ?? null) === null) {
				throw ApiException::invalid('“cap_mode” needs a “cap”: a ceiling or a price.')->code(ErrorCode::CAP_MODE_INVALID)->field('cap');
			}
		}
		$t = $b->tolerance ?? null;
		if ($t !== null && !(ProjectRules::isRate($t) && $t <= 100)) {
			throw ApiException::invalid(Message::of('“{field}” must be null or a number from 0 to 100.', ['field' => 'tolerance']))->field('tolerance');
		}
	}

	/** The team setting `fee_model`: null or one of the three models. */
	public static function validateSetting(mixed $v): void {
		if ($v !== null && !in_array($v, self::MODELS, true)) {
			throw self::invalidModel();
		}
	}

	private static function invalidModel(): ApiException {
		return ApiException::invalid('“fee_model” must be null, “category”, “mean_rate” or “role”.')->code(ErrorCode::FEE_MODEL_INVALID)->field(self::FIELD);
	}

	/**
	 * The ids of the budgets whose model changes from the stored project to
	 * the incoming one. A new or a removed budget never counts.
	 *
	 * @return list<string>
	 */
	public static function changed(?\stdClass $before, ?\stdClass $after): array {
		if ($before === null || $after === null) {
			return [];
		}
		$old = [];
		$list = $before->budgets ?? null;
		foreach (is_array($list) ? $list : [] as $b) {
			$id = $b instanceof \stdClass ? ($b->id ?? null) : null;
			if (is_string($id)) {
				$old[$id] ??= self::modelOf($b);
			}
		}
		$out = [];
		$list = $after->budgets ?? null;
		foreach (is_array($list) ? $list : [] as $b) {
			$id = $b instanceof \stdClass ? ($b->id ?? null) : null;
			if (is_string($id) && isset($old[$id]) && $old[$id] !== self::modelOf($b) && !in_array($id, $out, true)) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/** 409 `fee_model_locked`: the budget's bookings are billed by this invoice. */
	public static function locked(string $budget, string $invoice): ApiException {
		return ApiException::conflict(Message::of('The fee model of budget {budget} does not change: invoice {number} bills bookings of it. Reverse it first.', ['budget' => $budget, 'number' => $invoice]),
			['budget' => $budget, 'invoice' => $invoice])->code(ErrorCode::FEE_MODEL_LOCKED)->field(self::FIELD);
	}

	// ------------------------------------------ the budget of a booking

	/** Does the code carry the booking's code – equal, or a prefix followed by `.`, case aside? */
	private static function carries(string $code, string $booking): bool {
		$c = mb_strtolower(trim($code));
		return $c !== '' && ($booking === $c || str_starts_with($booking, $c . '.'));
	}

	/**
	 * The project a booking's code belongs to (as for a Lead's right):
	 * the one whose code or subproject code carries it, the most precise
	 * first. Null when none does.
	 *
	 * @param list<\stdClass> $projects the live projects' data
	 */
	public static function projectOf(array $projects, string $code): ?\stdClass {
		$booking = mb_strtolower(trim($code));
		$best = null;
		$len = -1;
		foreach ($projects as $p) {
			$codes = Fields::list($p, 'codes');
			foreach (Fields::list($p, 'subprojects') as $sub) {
				$codes[] = $sub instanceof \stdClass ? ($sub->code ?? null) : null;
			}
			foreach ($codes as $c) {
				if (is_string($c) && self::carries($c, $booking) && mb_strlen(trim($c)) > $len) {
					$best = $p;
					$len = mb_strlen(trim($c));
				}
			}
		}
		return $best;
	}

	/**
	 * The budget a booking's code falls into: among the packages that carry
	 * the code the most precise wins; of equal ones the budget whose
	 * `start`–`end` holds the day (open ends count), else the one that began
	 * last before it, else the earliest; without a package the project's
	 * budget of `kind: aufwand`. Null when there is none.
	 */
	public static function budgetOf(\stdClass $project, string $code, string $day): ?\stdClass {
		$booking = mb_strtolower(trim($code));
		$found = [];
		$len = -1;
		foreach (Fields::list($project, 'budgets') as $b) {
			if (!($b instanceof \stdClass)) {
				continue;
			}
			foreach (Fields::list($b, 'work_packages') as $wp) {
				foreach ($wp instanceof \stdClass ? Fields::list($wp, 'codes') : [] as $c) {
					if (!is_string($c) || !self::carries($c, $booking)) {
						continue;
					}
					$n = mb_strlen(trim($c));
					if ($n > $len) {
						$found = [$b];
						$len = $n;
					} elseif ($n === $len && !in_array($b, $found, true)) {
						$found[] = $b;
					}
				}
			}
		}
		if ($found === []) {
			foreach (Fields::list($project, 'budgets') as $b) {
				if ($b instanceof \stdClass && ($b->kind ?? null) === 'aufwand') {
					return $b;
				}
			}
			return null;
		}
		return self::byDay($found, $day);
	}

	/** @param non-empty-list<\stdClass> $budgets */
	private static function byDay(array $budgets, string $day): \stdClass {
		$start = static function (\stdClass $b): ?string {
			$s = Fields::str($b, 'start');
			return $s !== null && Time::isDay($s) ? $s : null;
		};
		foreach ($budgets as $b) {
			$e = Fields::str($b, 'end');
			$e = $e !== null && Time::isDay($e) ? $e : null;
			if (($start($b) ?? '') <= $day && ($e === null || $e >= $day)) {
				return $b;
			}
		}
		$before = null;
		$earliest = $budgets[0];
		foreach ($budgets as $b) {
			$s = $start($b) ?? '';
			if ($s <= $day && ($before === null || $s > ($start($before) ?? ''))) {
				$before = $b;
			}
			if ($s < ($start($earliest) ?? '')) {
				$earliest = $b;
			}
		}
		return $before ?? $earliest;
	}

	/**
	 * Does a booking fall into a budget in the model `category` – every
	 * one of its codes (at least one), on the booking's day? The person's
	 * right on the level of their own booking.
	 *
	 * @param list<\stdClass> $projects the live projects' data
	 * @param list<string> $codes
	 */
	public static function inCategoryModel(array $projects, array $codes, string $day): bool {
		if ($codes === []) {
			return false;
		}
		foreach ($codes as $code) {
			$p = self::projectOf($projects, $code);
			$b = $p === null ? null : self::budgetOf($p, $code, $day);
			if ($b === null || self::modelOf($b) !== self::CATEGORY) {
				return false;
			}
		}
		return true;
	}
}
