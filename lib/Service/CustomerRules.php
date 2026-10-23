<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The customer record (0.20.0), pure: the optional fields the e-invoices
 * and exports read – `e_invoice` (an XRechnung is wanted), `leitweg_id`
 * (German public customers, up to 46 characters) and `datev_account`
 * (the debtor account, digits). Other fields pass as before.
 */
final class CustomerRules {
	public const LEITWEG_MAX = 46;
	public const ACCOUNT_MAX = 9;
	/** 0.27.0 (controlling): the customer's type and payment term in days. */
	public const TYPES = ['public', 'cooperative', 'private', 'company'];
	public const PAYMENT_DAYS_MAX = 365;

	public static function validate(\stdClass $d): void {
		$t = $d->type ?? null;
		if ($t !== null && !in_array($t, self::TYPES, true)) {
			throw ApiException::invalid(Message::of('“type” of a customer is one of {types}.', ['types' => implode(', ', self::TYPES)]));
		}
		$p = $d->payment_days ?? null;
		if ($p !== null && (!is_int($p) || $p < 1 || $p > self::PAYMENT_DAYS_MAX)) {
			throw ApiException::invalid(Message::of('“{field}” must be null or an integer from {min} to {max}.', ['field' => 'payment_days', 'min' => 1, 'max' => self::PAYMENT_DAYS_MAX]));
		}
		$e = $d->e_invoice ?? null;
		if ($e !== null && !is_bool($e)) {
			throw ApiException::notBool('e_invoice');
		}
		$l = $d->leitweg_id ?? null;
		if ($l !== null && (!is_string($l) || $l === '' || strlen($l) > self::LEITWEG_MAX || !preg_match('/^[A-Za-z0-9\-]+$/', $l))) {
			throw ApiException::invalid(Message::of('“{field}” must be text of at most {max} characters.', ['field' => 'leitweg_id', 'max' => self::LEITWEG_MAX]));
		}
		$a = $d->datev_account ?? null;
		if ($a !== null && (!is_string($a) || !preg_match('/^\d{1,' . self::ACCOUNT_MAX . '}$/', $a))) {
			throw ApiException::invalid(Message::of('“{field}” must be up to {max} digits.', ['field' => 'datev_account', 'max' => self::ACCOUNT_MAX]));
		}
	}

	/** The customer's payment term in days, when set and valid; else null (the request or the default decides). */
	public static function paymentDays(?\stdClass $d): ?int {
		$p = $d?->payment_days ?? null;
		return is_int($p) && $p >= 1 && $p <= self::PAYMENT_DAYS_MAX ? $p : null;
	}
}
