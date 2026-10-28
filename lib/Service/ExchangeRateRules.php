<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * Exchange rates of the ECB (0.30.0, PLAN-SPESEN 9 H), pure: the team
 * setting `settings.exchange_rates`, which days a team needs, the request
 * to Frankfurter and its answer turned into the record `exchange_rate`
 * (key `<team currency>-<day>`, team currency per one unit, six places).
 * Only the server writes these records; every member reads them.
 */
final class ExchangeRateRules {
	public const KIND = 'exchange_rate';
	/** The team setting, a switch: off unless true. */
	public const SETTING = 'exchange_rates';
	/** The app config with the service's address; an admin may point it to an own Frankfurter. */
	public const CONFIG_KEY = 'exchange_rates_url';
	public const DEFAULT_URL = 'https://api.frankfurter.dev';
	/** How far back a receipt's day is fetched, and how many days one run fetches at most. */
	public const BACK_DAYS = 400;
	public const DAYS_PER_RUN = 50;
	/** The ECB's reference currencies (Frankfurter); a currency of `UNTIL` only up to that day. */
	public const ECB = ['AUD', 'BRL', 'CAD', 'CHF', 'CNY', 'CZK', 'DKK', 'EUR', 'GBP', 'HKD', 'HUF', 'IDR', 'ILS', 'INR', 'ISK',
		'JPY', 'KRW', 'MXN', 'MYR', 'NOK', 'NZD', 'PHP', 'PLN', 'RON', 'SEK', 'SGD', 'THB', 'TRY', 'USD', 'ZAR'];
	public const UNTIL = ['BGN' => '2025-12-31'];
	public const RATES_MAX = 40;
	private const CODE = '/^[A-Z]{3}$/';

	/** Is the switch on? */
	public static function on(?\stdClass $settings): bool {
		return ($settings?->{self::SETTING} ?? null) === true;
	}

	/** `settings.exchange_rates`: null or a boolean. */
	public static function validateSetting(mixed $v): void {
		if ($v !== null && !is_bool($v)) {
			throw ApiException::notBool(self::SETTING);
		}
	}

	public static function key(string $base, string $day): string {
		return $base . '-' . $day;
	}

	/** Does the ECB publish this currency on that day? */
	public static function supported(string $currency, string $day): bool {
		return in_array($currency, self::ECB, true) || (isset(self::UNTIL[$currency]) && $day <= self::UNTIL[$currency]);
	}

	/** The configured address without a trailing slash; null unless http(s). */
	public static function baseUrl(string $configured): ?string {
		$url = rtrim(trim($configured), '/');
		$scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
		return in_array($scheme, ['http', 'https'], true) && is_string(parse_url($url, PHP_URL_HOST)) ? $url : null;
	}

	/** `GET {base}/v1/{day}?base={team}&symbols={…}`. @param list<string> $currencies */
	public static function url(string $base, string $day, string $team, array $currencies): string {
		return $base . '/v1/' . $day . '?base=' . $team . '&symbols=' . implode(',', $currencies);
	}

	/**
	 * The days to fetch, newest first, at most `$limit`: days before today
	 * and at most 400 days back on which a reimbursed receipt in a foreign
	 * currency of the ECB has neither `booked` nor `rate`, is not rejected
	 * and not settled – unless the day's record names that currency. Each
	 * day with every currency it needs, the stored ones included.
	 *
	 * @param array<array-key,?\stdClass> $receipts live receipts
	 * @param array<string,?\stdClass> $reports live expense reports by key
	 * @param array<string,?\stdClass> $stored live `exchange_rate` records by key
	 * @return array<string,list<string>> day → currencies
	 */
	public static function due(array $receipts, array $reports, string $team, array $stored, string $today, int $limit): array {
		$oldest = gmdate('Y-m-d', (int)strtotime($today . ' 00:00:00 UTC') - self::BACK_DAYS * 86400);
		$need = [];
		foreach ($receipts as $r) {
			if ($r === null || ($r->reimburse ?? false) !== true || ($r->status ?? null) === ReceiptRules::REJECTED
				|| ($r->booked ?? null) !== null || ($r->rate ?? null) !== null || ExpenseReportRules::heldBy($r, $reports) !== null) {
				continue;
			}
			$cur = $r->currency ?? null;
			$c = is_string($cur) ? strtoupper(trim($cur)) : '';
			$day = $r->date ?? null;
			if ($c === '' || $c === $team || !is_string($day) || !Time::isDay($day) || $day >= $today || $day < $oldest || !self::supported($c, $day)) {
				continue;
			}
			$need[$day][$c] = true;
		}
		krsort($need, SORT_STRING);
		$out = [];
		foreach ($need as $day => $currencies) {
			$rates = Fields::obj($stored[self::key($team, $day)] ?? null, 'rates');
			$have = $rates === null ? [] : array_map('strval', array_keys(get_object_vars($rates)));
			$wanted = array_map('strval', array_keys($currencies));
			if (array_diff($wanted, $have) === []) {
				continue;
			}
			$all = array_values(array_filter(array_unique([...$have, ...$wanted]), static fn (string $c): bool => self::supported($c, $day)));
			sort($all, SORT_STRING);
			$out[$day] = $all;
			if (count($out) >= $limit) {
				break;
			}
		}
		return $out;
	}

	/**
	 * The record of a day from Frankfurter's answer (units of each currency
	 * per one of the team's): inverted to the team's currency per unit, on
	 * six places; `ecb_date` is the day the ECB published (the last before a
	 * weekend or holiday). Currencies the answer lacks are left out; none
	 * at all is an error.
	 *
	 * @param list<string> $currencies
	 */
	public static function fromAnswer(mixed $answer, string $team, string $day, array $currencies): \stdClass {
		$ecb = $answer instanceof \stdClass ? ($answer->date ?? null) : null;
		$given = $answer instanceof \stdClass ? ($answer->rates ?? null) : null;
		if (!$answer instanceof \stdClass || !is_string($answer->base ?? null) || strtoupper($answer->base) !== $team
			|| !is_string($ecb) || !Time::isDay($ecb) || $ecb > $day || !$given instanceof \stdClass) {
			throw new \UnexpectedValueException('The answer is no rate table of ' . $team . ' for ' . $day . '.');
		}
		$rates = [];
		foreach ($currencies as $c) {
			$r = $given->{$c} ?? null;
			$v = ReceiptRules::isNumber($r) && (float)$r > 0.0 ? ExpenseTable::round6(1.0 / (float)$r) : 0.0;
			if ($v > 0.0) {
				$rates[$c] = $v;
			}
		}
		if ($rates === []) {
			throw new \UnexpectedValueException('The answer holds none of ' . implode(', ', $currencies) . ' for ' . $day . '.');
		}
		ksort($rates, SORT_STRING);
		$d = new \stdClass();
		$d->base = $team;
		$d->date = $day;
		$d->ecb_date = $ecb;
		$d->rates = (object)$rates;
		return $d;
	}

	/** The stored record: `{ base, date, ecb_date, rates }` under `<base>-<date>`; 422 otherwise. */
	public static function validate(string $key, \stdClass $d): void {
		$base = $d->base ?? null;
		$day = $d->date ?? null;
		$ecb = $d->ecb_date ?? null;
		$rates = $d->rates ?? null;
		$ok = is_string($base) && preg_match(self::CODE, $base) === 1 && is_string($day) && Time::isDay($day) && $key === self::key($base, $day)
			&& is_string($ecb) && Time::isDay($ecb) && $ecb <= $day && $rates instanceof \stdClass && count(get_object_vars($rates)) <= self::RATES_MAX
			&& array_diff(array_keys(get_object_vars($d)), ['schema', 'base', 'date', 'ecb_date', 'rates']) === [];
		foreach ($ok && $rates instanceof \stdClass ? array_map('strval', array_keys(get_object_vars($rates))) : [] as $c) {
			$v = $rates->{$c} ?? null;
			$ok = $ok && preg_match(self::CODE, $c) === 1 && ReceiptRules::isNumber($v) && $v > 0 && $v < ExpenseFields::RATE_MAX;
		}
		if (!$ok) {
			throw ApiException::invalid('An exchange rate is { “base”, “date”, “ecb_date”, “rates” } under the key “<base>-<date>”.');
		}
	}
}
