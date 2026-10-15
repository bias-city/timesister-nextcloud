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

	public static function validate(\stdClass $d): void {
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
}
