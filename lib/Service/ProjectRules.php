<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The project record's fields of 0.16.0 (quotes): the status, the team,
 * currency and work package rates in the budgets, `from_quote` and the
 * codes (one per project since 0.12.0, none while it is still a quote).
 * Rates per person are not a budget's any more: they are the person's
 * `cost_rates` ({@see PersonRules}). Pure; the team's members come from
 * the caller. Checks only the shape – the app does not compute with it.
 */
final class ProjectRules {
	/** In order: a quote grows (draft → quoted → declined | active), then the project runs. */
	public const STATUSES = ['draft', 'quoted', 'declined', 'active', 'running', 'paused', 'completed', 'archived'];
	/** Before `active`: a quote. It needs no code yet. */
	public const PRELIMINARY = ['draft', 'quoted', 'declined'];
	/** Set once the project began as a quote; it keeps it in every status. */
	public const FROM_QUOTE = 'from_quote';
	/** Older records (the Mac app reads them as the English ones). */
	public const LEGACY_STATUSES = ['aktiv', 'laufend', 'ruhend', 'abgeschlossen', 'archiviert'];
	public const TEAM = 'team';
	/** Roles in a project's team; the first is the default. */
	public const TEAM_ROLES = ['member', 'sparring', 'support', 'qa'];
	public const MAX_TEAM = 100;
	public const CURRENCY_PATTERN = '/^[A-Za-z]{3}$/';
	/** 0.25.0: what the budgets are called («Phasen»), from the template the project came from. */
	public const BUDGET_LABEL = 'budget_label';
	public const BUDGET_LABEL_MAX = 60;
	/** 0.26.0: a work package's level (`parent`: the `no` of the package above) and its tags. */
	public const WP_PARENT_MAX = 32;
	public const WP_TAGS_MAX = 20;
	public const WP_TAG_MAX = 60;
	/** 0.25.1: the planned invoices as `{date, share?, amount?, title?}` beside `invoice_dates`. */
	public const BILLING_PLAN = 'billing_plan';
	public const BILLING_PLAN_MAX = 200;
	public const BILLING_PLAN_KEYS = ['date', 'share', 'amount', 'title'];
	public const BILLING_TITLE_MAX = 200;
	private const CONTROL = '/[\x00-\x1F\x7F]/';

	/**
	 * Status, codes, `from_quote`, team, currency and rate – in place:
	 * `team` as `{user, role}` without duplicates, `currency` upper case.
	 *
	 * @throws ApiException 422
	 */
	public static function validate(\stdClass $data): void {
		self::checkStatus($data->status ?? null);
		self::checkCodes($data);
		if (property_exists($data, self::FROM_QUOTE) && !is_bool($data->{self::FROM_QUOTE}) && $data->{self::FROM_QUOTE} !== null) {
			throw ApiException::invalid('“from_quote” must be true, false or null.');
		}
		if (property_exists($data, self::TEAM) && $data->{self::TEAM} !== null) {
			$data->{self::TEAM} = self::team($data->{self::TEAM});
		}
		$label = $data->{self::BUDGET_LABEL} ?? null;
		if ($label !== null && (!is_string($label) || trim($label) === '' || mb_strlen($label) > self::BUDGET_LABEL_MAX
				|| preg_match('/[\x00-\x1F\x7F]/', $label) === 1 || !mb_check_encoding($label, 'UTF-8'))) {
			throw ApiException::invalid(Message::of('“{field}” must be text of 1 to {max} characters.', ['field' => self::BUDGET_LABEL, 'max' => self::BUDGET_LABEL_MAX]));
		}
		$budgets = $data->budgets ?? null;
		if (!is_array($budgets)) {
			return;
		}
		foreach ($budgets as $b) {
			if ($b instanceof \stdClass) {
				self::checkBudget($b);
			}
		}
	}

	/** Known, or missing (null or empty: the client's default). */
	public static function isStatus(mixed $s): bool {
		if ($s === null) {
			return true;
		}
		if (!is_string($s)) {
			return false;
		}
		$t = mb_strtolower(trim($s));
		return $t === '' || in_array($t, self::STATUSES, true) || in_array($t, self::LEGACY_STATUSES, true);
	}

	/** draft, quoted or declined – still a quote. Missing or empty is not. */
	public static function isPreliminary(mixed $s): bool {
		return is_string($s) && in_array(mb_strtolower(trim($s)), self::PRELIMINARY, true);
	}

	/**
	 * One project, one code (0.12.0); parts go into “subprojects”. A quote
	 * (draft, quoted, declined) may have none yet; from `active` on –
	 * also with no status – the code is required.
	 */
	private static function checkCodes(\stdClass $data): void {
		$codes = $data->codes ?? null;
		$n = is_array($codes) ? count(array_filter($codes, static fn ($c) => is_string($c) && trim($c) !== '')) : 0;
		if ($n > 1) {
			throw ApiException::invalid('A project has exactly one code; parts go into “subprojects”.');
		}
		if ($n === 0 && !self::isPreliminary($data->status ?? null)) {
			throw ApiException::invalid('An active project needs a code.');
		}
	}

	private static function checkStatus(mixed $s): void {
		if (!self::isStatus($s)) {
			throw ApiException::invalid(Message::of('Unknown project status. Allowed: {statuses}.', ['statuses' => implode(', ', self::STATUSES)]));
		}
	}

	/**
	 * The team as `{user, role}` (0.16.0): a string (older format) becomes
	 * a member, a missing role `member`; other fields are dropped. The first
	 * entry of a user wins, later ones are dropped.
	 *
	 * @return list<object{user: string, role: string}>
	 */
	private static function team(mixed $v): array {
		if (!is_array($v) || !array_is_list($v)) {
			throw ApiException::invalid('“team” must be a list of entries with “user” and “role”.');
		}
		$out = [];
		foreach ($v as $e) {
			if (is_string($e)) {
				$user = $e;
				$role = null;
			} elseif ($e instanceof \stdClass) {
				$user = $e->user ?? null;
				$role = $e->role ?? null;
			} else {
				throw ApiException::invalid('“team” must be a list of entries with “user” and “role”.');
			}
			if (!is_string($user) || $user === '' || strlen($user) > 64) {
				throw ApiException::invalid('“user” in “team” must be a user ID.');
			}
			if ($role !== null && !in_array($role, self::TEAM_ROLES, true)) {
				throw ApiException::invalid('“role” in “team” is member, sparring, support or qa.');
			}
			if (!isset($out[$user])) {
				$out[$user] = (object)['user' => $user, 'role' => $role ?? self::TEAM_ROLES[0]];
			}
		}
		if (count($out) > self::MAX_TEAM) {
			throw ApiException::invalid(Message::of('A team of a project holds at most {max} people.', ['max' => self::MAX_TEAM]));
		}
		return array_values($out);
	}

	/**
	 * Everyone in `team` is a member of the team (also one who left – an
	 * old record keeps them), otherwise 422 naming the unknown ones. Reads
	 * strings and `{user}` alike; a wrong shape is left to {@see validate}.
	 *
	 * @param list<string> $members the team's accounts
	 */
	public static function requireTeamMembers(mixed $data, array $members): void {
		$team = $data instanceof \stdClass ? ($data->{self::TEAM} ?? null) : null;
		if (!is_array($team)) {
			return;
		}
		$unknown = [];
		foreach ($team as $e) {
			$u = $e instanceof \stdClass ? ($e->user ?? null) : $e;
			if (is_string($u) && $u !== '' && !in_array($u, $members, true)) {
				$unknown[$u] = true;
			}
		}
		if ($unknown !== []) {
			throw ApiException::invalid(Message::of('Not members of this team: {uids}.', ['uids' => implode(', ', array_keys($unknown))]));
		}
	}

	/** One budget: currency (upper case), `rates` refused, `cap` and work package `rate` ≥ 0, `invoice_dates` days, `costs` ({@see CostRules}), `progress` 0–100, work package `addendum` bool. Also a quote's budget. */
	public static function checkBudget(\stdClass $b): void {
		// 0.24.1: the progress the Lead sets, a whole percent or null.
		$p = $b->progress ?? null;
		if ($p !== null && (!is_int($p) || $p < 0 || $p > 100)) {
			throw ApiException::invalid('“progress” must be null or a whole number from 0 to 100.');
		}
		$c = $b->currency ?? null;
		if ($c !== null) {
			if (!is_string($c) || preg_match(self::CURRENCY_PATTERN, $c) !== 1) {
				throw ApiException::invalid('“currency” must be three letters, for example CHF.');
			}
			$b->currency = strtoupper($c);
		}
		// Rates per person moved to the person (cost_rates). A client's
		// empty default (null or {}) passes; anything with content is refused.
		$rates = $b->rates ?? null;
		if ($rates !== null && !($rates instanceof \stdClass && get_object_vars($rates) === [])) {
			throw ApiException::invalid('“rates” is no longer part of a budget; cost rates live on the person.');
		}
		// The cost cap in the budget's currency; null means the sum of the rates.
		$cap = $b->cap ?? null;
		if ($cap !== null && !self::isRate($cap)) {
			throw ApiException::invalid(Message::of('“{field}” must be null or a number ≥ 0.', ['field' => 'cap']));
		}
		// The planned invoice dates (forecast invoices): days, each once, at most 200.
		$dates = $b->invoice_dates ?? null;
		if ($dates !== null) {
			$ok = is_array($dates) && array_is_list($dates) && count($dates) <= 200;
			foreach ($ok ? $dates : [] as $d) {
				if (!is_string($d) || !Time::isDay($d)) {
					$ok = false;
				}
			}
			if (!$ok || count(array_unique($dates)) !== count($dates)) {
				throw ApiException::invalid('“invoice_dates” must be a list of at most 200 days (YYYY-MM-DD), each once.');
			}
		}
		self::checkBillingPlan($b->{self::BILLING_PLAN} ?? null);
		// 0.21.0: the cost positions beside the work packages (module «costs»).
		CostRules::check($b->{CostRules::FIELD} ?? null);
		$wps = $b->work_packages ?? null;
		if (!is_array($wps)) {
			return;
		}
		// The numbers of the packages, for `parent`.
		$nos = [];
		foreach ($wps as $wp) {
			if ($wp instanceof \stdClass && (is_string($wp->no ?? null) || is_int($wp->no ?? null))) {
				$nos[] = mb_strtolower(trim((string)$wp->no));
			}
		}
		foreach ($wps as $wp) {
			if ($wp instanceof \stdClass && ($wp->rate ?? null) !== null && !self::isRate($wp->rate)) {
				throw ApiException::invalid('“rate” must be a number ≥ 0.');
			}
			// 0.24.1: a work package added later (addendum), shown and counted as such.
			if ($wp instanceof \stdClass && ($wp->addendum ?? null) !== null && !is_bool($wp->addendum)) {
				throw ApiException::invalid('“addendum” must be true, false or null.');
			}
			if ($wp instanceof \stdClass) {
				self::checkParentAndTags($wp, $nos);
			}
		}
	}

	/**
	 * 0.26.0: `parent` null or the `no` of another work package of the same
	 * budget (stored trimmed); `tags` null or at most 20 texts of 1–60
	 * characters without control characters (stored trimmed). Replace the
	 * `group` and `role` of 0.25.1.
	 *
	 * @param list<string> $nos the packages' numbers, lower case
	 */
	private static function checkParentAndTags(\stdClass $wp, array $nos): void {
		$p = $wp->parent ?? null;
		if ($p !== null) {
			$t = is_string($p) && mb_check_encoding($p, 'UTF-8') ? trim($p) : '';
			$self = mb_strtolower(trim((string)(is_string($wp->no ?? null) || is_int($wp->no ?? null) ? $wp->no : '')));
			if ($t === '' || mb_strlen($t) > self::WP_PARENT_MAX || preg_match(self::CONTROL, $t) === 1
				|| mb_strtolower($t) === $self || !in_array(mb_strtolower($t), $nos, true)) {
				throw ApiException::invalid('“parent” must be null or the “no” of another work package of the budget.');
			}
			$wp->parent = $t;
		}
		$tags = $wp->tags ?? null;
		if ($tags === null) {
			return;
		}
		$ok = is_array($tags) && array_is_list($tags) && count($tags) <= self::WP_TAGS_MAX;
		$out = [];
		foreach ($ok ? $tags : [] as $x) {
			$t = is_string($x) && mb_check_encoding($x, 'UTF-8') ? trim($x) : '';
			if ($t === '' || mb_strlen($t) > self::WP_TAG_MAX || preg_match(self::CONTROL, $t) === 1) {
				$ok = false;
				break;
			}
			$out[] = $t;
		}
		if (!$ok) {
			throw ApiException::invalid(Message::of('“tags” must be null or a list of at most {n} texts of 1 to {max} characters.', ['n' => self::WP_TAGS_MAX, 'max' => self::WP_TAG_MAX]));
		}
		$wp->tags = $out;
	}

	/**
	 * 0.25.1: null or a list of at most 200 `{date, share?, amount?, title?}`;
	 * `date` a day, each once; `share` 0–100 or `amount` ≥ 0, not both.
	 *
	 * @throws ApiException 422
	 */
	public static function checkBillingPlan(mixed $plan): void {
		if ($plan === null) {
			return;
		}
		if (!is_array($plan) || !array_is_list($plan) || count($plan) > self::BILLING_PLAN_MAX) {
			throw ApiException::invalid(Message::of('“billing_plan” must be null or a list of at most {max} entries.', ['max' => self::BILLING_PLAN_MAX]));
		}
		$days = [];
		foreach ($plan as $i => $e) {
			$n = $i + 1;
			if (!($e instanceof \stdClass)) {
				throw ApiException::invalid(Message::of('Entry {n} of “billing_plan” must be an object with “date”.', ['n' => $n]));
			}
			foreach (array_keys(get_object_vars($e)) as $k) {
				if (!in_array($k, self::BILLING_PLAN_KEYS, true)) {
					throw ApiException::invalid(Message::of('Entry {n} of “billing_plan” has an unknown field “{key}”; allowed are date, share, amount and title.', ['n' => $n, 'key' => $k]));
				}
			}
			$date = $e->date ?? null;
			if (!is_string($date) || !Time::isDay($date)) {
				throw ApiException::invalid(Message::of('Entry {n} of “billing_plan”: “date” must be a day (YYYY-MM-DD).', ['n' => $n]));
			}
			if (isset($days[$date])) {
				throw ApiException::invalid(Message::of('“billing_plan” has the date {date} twice.', ['date' => $date]));
			}
			$days[$date] = true;
			$share = $e->share ?? null;
			if ($share !== null && (!self::isRate($share) || $share > 100)) {
				throw ApiException::invalid(Message::of('Entry {n} of “billing_plan”: “share” must be null or a number from 0 to 100.', ['n' => $n]));
			}
			$amount = $e->amount ?? null;
			if ($amount !== null && !self::isRate($amount)) {
				throw ApiException::invalid(Message::of('Entry {n} of “billing_plan”: “amount” must be null or a number ≥ 0.', ['n' => $n]));
			}
			if ($share !== null && $amount !== null) {
				throw ApiException::invalid(Message::of('Entry {n} of “billing_plan”: set “share” or “amount”, not both.', ['n' => $n]));
			}
			$title = $e->title ?? null;
			if ($title !== null && (!is_string($title) || !mb_check_encoding($title, 'UTF-8') || mb_strlen($title) > self::BILLING_TITLE_MAX || preg_match(self::CONTROL, $title) === 1)) {
				throw ApiException::invalid(Message::of('Entry {n} of “billing_plan”: “title” must be null or text of at most {max} characters without control characters.', ['n' => $n, 'max' => self::BILLING_TITLE_MAX]));
			}
		}
	}

	/** A number ≥ 0 (int or finite float). */
	public static function isRate(mixed $v): bool {
		return (is_int($v) || (is_float($v) && is_finite($v))) && $v >= 0;
	}
}
