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
	/** 0.27.0: the project's currency – one per project, its budgets inherit it. */
	public const CURRENCY = 'currency';
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
		// 0.27.0: the rates of the levels in this project ({@see LevelRules}).
		LevelRules::validateProject($data->{LevelRules::PROJECT_FIELD} ?? null);
		// 0.27.0: the project's currency (null or three letters, stored upper case);
		// a budget's `currency` stays for older clients and follows the project's.
		$currency = $data->{self::CURRENCY} ?? null;
		if ($currency !== null) {
			if (!is_string($currency) || preg_match(self::CURRENCY_PATTERN, $currency) !== 1) {
				throw ApiException::invalid('“currency” must be three letters, for example CHF.');
			}
			$currency = strtoupper($currency);
			$data->{self::CURRENCY} = $currency;
		}
		$budgets = $data->budgets ?? null;
		if (!is_array($budgets)) {
			return;
		}
		foreach ($budgets as $b) {
			if ($b instanceof \stdClass) {
				self::checkBudget($b);
				if ($currency !== null) {
					$b->currency = $currency;
				}
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
		self::checkAddenda($b);
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
		// 0.26.3: a package with packages below has no hours of its own – only the sum.
		$parents = [];
		foreach ($wps as $wp) {
			$parent = $wp instanceof \stdClass ? ($wp->parent ?? null) : null;
			if (is_string($parent)) {
				$parents[] = mb_strtolower(trim($parent));
			}
		}
		foreach ($wps as $wp) {
			if ($wp instanceof \stdClass && ($wp->rate ?? null) !== null && !self::isRate($wp->rate)) {
				throw ApiException::invalid('“rate” must be a number ≥ 0.');
			}
			$hours = $wp instanceof \stdClass ? ($wp->hours ?? null) : null;
			if ($wp instanceof \stdClass && is_array($hours)
				&& in_array(mb_strtolower(trim((string)(is_string($wp->no ?? null) || is_int($wp->no ?? null) ? $wp->no : ''))), $parents, true)) {
				foreach ($hours as $h) {
					if (is_numeric($h) && (float)$h > 0) {
						throw ApiException::invalid('A work package with packages below has no hours of its own – move them to a package below.');
					}
				}
			}
			// 0.27.0: how far the package is, a whole percent set by the Lead; null: estimated by the client.
			if ($wp instanceof \stdClass && ($wp->progress ?? null) !== null && (!is_int($wp->progress) || $wp->progress < 0 || $wp->progress > 100)) {
				throw ApiException::invalid('Work package “progress” must be null or a whole number from 0 to 100.');
			}
			// 0.27.0: the log of done and remaining effort per day and person ({@see ProgressLog}).
			if ($wp instanceof \stdClass) {
				ProgressLog::check($wp);
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

	/** 0.27.0: at most this many addenda per budget. */
	public const ADDENDA_MAX = 200;
	public const ADDENDUM_STATUS = ['open', 'approved', 'rejected'];

	/**
	 * 0.27.0: `addenda` null or a list of at most 200 objects – an order after
	 * the quote. Each has an integer `no` ≥ 1 (unique), a day `date`, a
	 * `status` from the list, a `title` (≤ 200), and exactly one target:
	 * `package` (the `no` of a work package of this budget, with `hours` ≥ 0)
	 * or `cost` (the `no` of a cost position, with `amount` ≥ 0). `reference`
	 * ≤ 200 and `description` ≤ 1000 characters, both optional.
	 *
	 * @throws ApiException 422
	 */
	private static function checkAddenda(\stdClass $b): void {
		$list = $b->addenda ?? null;
		if ($list === null) {
			return;
		}
		if (!is_array($list) || !array_is_list($list) || count($list) > self::ADDENDA_MAX) {
			throw ApiException::invalid(Message::of('“{field}” must be a list of at most {limit} entries.', ['field' => 'addenda', 'limit' => self::ADDENDA_MAX]));
		}
		$packages = [];
		foreach ($b->work_packages ?? [] as $wp) {
			if ($wp instanceof \stdClass && (is_string($wp->no ?? null) || is_int($wp->no ?? null))) {
				$packages[] = mb_strtolower(trim((string)$wp->no));
			}
		}
		$costs = [];
		foreach ($b->{CostRules::FIELD} ?? [] as $c) {
			if ($c instanceof \stdClass && is_int($c->no ?? null)) {
				$costs[] = $c->no;
			}
		}
		$seen = [];
		foreach ($list as $i => $a) {
			$n = $i + 1;
			if (!($a instanceof \stdClass)) {
				throw ApiException::invalid(Message::of('Addendum {n}: must be a JSON object.', ['n' => $n]));
			}
			$no = $a->no ?? null;
			if (!is_int($no) || $no < 1 || isset($seen[$no])) {
				throw ApiException::invalid(Message::of('Addendum {n}: “no” must be an integer ≥ 1, unique within the budget.', ['n' => $n]));
			}
			$seen[$no] = true;
			$date = $a->date ?? null;
			if (!is_string($date) || !Time::isDay($date)) {
				throw ApiException::invalid(Message::of('Addendum {n}: “date” must be a day (YYYY-MM-DD).', ['n' => $n]));
			}
			if (!in_array($a->status ?? 'open', self::ADDENDUM_STATUS, true)) {
				throw ApiException::invalid(Message::of('Addendum {n}: “status” must be open, approved or rejected.', ['n' => $n]));
			}
			foreach ([['title', 200], ['reference', 200], ['description', 1000]] as [$f, $max]) {
				$v = $a->{$f} ?? null;
				if ($v !== null && (!is_string($v) || mb_strlen($v) > $max || preg_match(self::CONTROL, $v) || !mb_check_encoding($v, 'UTF-8'))) {
					throw ApiException::invalid(Message::of('Addendum {n}: “{field}” must be null or text of at most {max} characters.', ['n' => $n, 'field' => $f, 'max' => $max]));
				}
			}
			$package = $a->package ?? null;
			$cost = $a->cost ?? null;
			if (($package === null) === ($cost === null)) {
				throw ApiException::invalid(Message::of('Addendum {n}: exactly one of “package” and “cost”.', ['n' => $n]));
			}
			if ($package !== null) {
				if ((!is_string($package) && !is_int($package)) || !in_array(mb_strtolower(trim((string)$package)), $packages, true)) {
					throw ApiException::invalid(Message::of('Addendum {n}: “package” must be the “no” of a work package of this budget.', ['n' => $n]));
				}
				if (!self::isRate($a->hours ?? null) || ($a->amount ?? null) !== null) {
					throw ApiException::invalid(Message::of('Addendum {n}: a work package addendum needs “hours” ≥ 0 and no “amount”.', ['n' => $n]));
				}
			} else {
				if (!is_int($cost) || !in_array($cost, $costs, true)) {
					throw ApiException::invalid(Message::of('Addendum {n}: “cost” must be the “no” of a cost position of this budget.', ['n' => $n]));
				}
				if (!self::isRate($a->amount ?? null) || ($a->hours ?? null) !== null) {
					throw ApiException::invalid(Message::of('Addendum {n}: a cost position addendum needs “amount” ≥ 0 and no “hours”.', ['n' => $n]));
				}
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
