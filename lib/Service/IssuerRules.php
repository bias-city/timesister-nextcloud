<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The invoice issuer (0.16.0): `issuer` in `setting/settings` - who issues
 * the team's invoices: name, address, contact, VAT ID, the note on invoices
 * abroad (VAT-free), one account per currency and whether invoices carry a
 * QR code (the client picks Swiss QR for CHF and the EPC GiroCode for EUR).
 * Pure: the shape only.
 */
final class IssuerRules {
	public const FIELD = 'issuer';
	public const ACCOUNTS_MAX = 10;
	private const CONTROL = '/[\x00-\x1F\x7F]/';

	/** `payment` of the settings record when it is written; null or missing passes. */
	public static function validate(mixed $v): void {
		if ($v === null) {
			return;
		}
		if (!($v instanceof \stdClass)) {
			throw ApiException::invalid(Message::of('“{field}” must be an object.', ['field' => self::FIELD]));
		}
		foreach (['name' => 70, 'street' => 70, 'postcode' => 16, 'city' => 35, 'country' => 2, 'email' => 254, 'phone' => 40, 'vat_id' => 32] as $f => $max) {
			self::text($v->{$f} ?? null, $f, $max);
		}
		$note = $v->foreign_note ?? null;
		if ($note !== null && (!is_string($note) || mb_strlen($note) > 500 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $note))) {
			throw ApiException::invalid(Message::of('“{field}” must be text of at most {max} characters.', ['field' => self::FIELD . '.foreign_note', 'max' => 500]));
		}
		$qr = $v->qr ?? null;
		if ($qr !== null && !is_bool($qr)) {
			throw ApiException::notBool(self::FIELD . '.qr');
		}
		$accounts = $v->accounts ?? null;
		if ($accounts === null) {
			return;
		}
		if (!is_array($accounts) || !array_is_list($accounts) || count($accounts) > self::ACCOUNTS_MAX) {
			throw ApiException::invalid(Message::of('“issuer.accounts” must be a list of at most {max} accounts.', ['max' => self::ACCOUNTS_MAX]));
		}
		$seen = [];
		foreach ($accounts as $a) {
			$currency = $a instanceof \stdClass ? ($a->currency ?? null) : null;
			$iban = $a instanceof \stdClass ? ($a->iban ?? null) : null;
			$bic = $a instanceof \stdClass ? ($a->bic ?? null) : null;
			if (!is_string($currency) || preg_match(ProjectRules::CURRENCY_PATTERN, $currency) !== 1
				|| !is_string($iban) || preg_match('/^[A-Za-z]{2}[0-9]{2}[A-Za-z0-9]{11,30}$/', $iban) !== 1
				|| ($bic !== null && $bic !== '' && (!is_string($bic) || preg_match('/^[A-Za-z0-9]{8}([A-Za-z0-9]{3})?$/', $bic) !== 1))) {
				throw ApiException::invalid('Each account needs a currency (three letters), an IBAN without spaces and, if any, a BIC of 8 or 11 characters.');
			}
			$key = strtoupper($currency);
			if (isset($seen[$key])) {
				throw ApiException::invalid('One account per currency.');
			}
			$seen[$key] = true;
		}
	}

	private static function text(mixed $v, string $field, int $max): void {
		if ($v !== null && (!is_string($v) || mb_strlen($v) > $max || preg_match(self::CONTROL, $v) || !mb_check_encoding($v, 'UTF-8'))) {
			throw ApiException::invalid(Message::of('“{field}” must be text of at most {max} characters.', ['field' => self::FIELD . '.' . $field, 'max' => $max]));
		}
	}
}
