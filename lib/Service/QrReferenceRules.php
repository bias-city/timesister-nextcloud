<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The QR reference (QRR) of a Swiss QR bill (0.20.0), pure: 26 digits –
 * the issuer's BESR id (6 digits, optional), the invoice's counter number
 * zero-padded and the year – plus the modulo 10 recursive check digit
 * (Swiss Payment Standards). Only a QR-IBAN (IID 30000–31999) carries it.
 */
final class QrReferenceRules {
	public const LENGTH = 27;
	public const BESR_LENGTH = 6;
	/** Modulo 10 recursive: the carry table of SIX. */
	private const TABLE = [0, 9, 4, 6, 8, 2, 7, 1, 3, 5];

	/** Is this a QR-IBAN (Swiss or Liechtenstein, IID 30000–31999)? */
	public static function isQrIban(string $iban): bool {
		$iban = strtoupper(str_replace(' ', '', $iban));
		if (!preg_match('/^(CH|LI)\d{19}$/', $iban)) {
			return false;
		}
		$iid = (int)substr($iban, 4, 5);
		return $iid >= 30000 && $iid <= 31999;
	}

	/** The check digit of a digit string, modulo 10 recursive. */
	public static function checkDigit(string $digits): int {
		$carry = 0;
		foreach (str_split($digits) as $d) {
			$carry = self::TABLE[($carry + (int)$d) % 10];
		}
		return (10 - $carry) % 10;
	}

	/** Does the reference (27 digits) carry a valid check digit? */
	public static function isValid(string $reference): bool {
		$r = str_replace(' ', '', $reference);
		return preg_match('/^\d{27}$/', $r) === 1 && self::checkDigit(substr($r, 0, 26)) === (int)$r[26];
	}

	/**
	 * The reference: `<besr><counter, zero-padded><year>` on 26 digits plus
	 * the check digit. Without a BESR id the counter and the year alone
	 * form it. Null when the counter is unknown.
	 */
	public static function reference(?string $besrId, ?int $counterNo, string $issuedOn): ?string {
		if ($counterNo === null || $counterNo < 0) {
			return null;
		}
		$besr = $besrId === null ? '' : preg_replace('/\D/', '', $besrId);
		$year = substr($issuedOn, 0, 4);
		if (!preg_match('/^\d{4}$/', $year)) {
			return null;
		}
		$width = 26 - strlen((string)$besr) - 4;
		$counter = (string)$counterNo;
		if ($width < strlen($counter)) {
			return null;
		}
		$body = $besr . str_pad($counter, $width, '0', STR_PAD_LEFT) . $year;
		return $body . self::checkDigit($body);
	}

	/**
	 * 0.40.0: the creditor reference (ISO 11649) of the counter and the year,
	 * in CHF and EUR – «RF», two check digits (98 − mod 97), the body. Null for
	 * other currencies or without a counter.
	 */
	public static function creditorReference(?int $counterNo, string $issuedOn, string $currency): ?string {
		$year = substr($issuedOn, 0, 4);
		if ($counterNo === null || $counterNo < 0 || !in_array(strtoupper($currency), ['CHF', 'EUR'], true) || !preg_match('/^\d{4}$/', $year)) {
			return null;
		}
		$body = $counterNo . $year;
		return 'RF' . sprintf('%02d', 98 - self::mod97($body . 'RF00')) . $body;
	}

	/** Is this a creditor reference with valid check digits (spaces allowed)? */
	public static function isCreditorReference(string $reference): bool {
		$r = strtoupper(str_replace(' ', '', $reference));
		return preg_match('/^RF\d{2}[A-Z0-9]{1,21}$/', $r) === 1 && self::mod97(substr($r, 4) . substr($r, 0, 4)) === 1;
	}

	/** The remainder modulo 97 of digits and letters (A = 10 … Z = 35). */
	private static function mod97(string $text): int {
		$rest = 0;
		foreach (str_split($text) as $c) {
			$digits = ctype_digit($c) ? $c : (string)(ord($c) - ord('A') + 10);
			foreach (str_split($digits) as $d) {
				$rest = ($rest * 10 + (int)$d) % 97;
			}
		}
		return $rest;
	}

	/** `issuer.besr_id`: null or exactly 6 digits. */
	public static function checkBesrId(mixed $v): ?string {
		if ($v === null || $v === '') {
			return null;
		}
		if (!is_string($v) || !preg_match('/^\d{6}$/', $v)) {
			throw ApiException::invalid('“issuer.besr_id” must be 6 digits.');
		}
		return $v;
	}

	/** `12 34567 89012 34567 89012 34567` – blocks of five from the right, as printed on the bill. */
	public static function display(string $reference): string {
		$r = str_replace(' ', '', $reference);
		return trim(strrev(chunk_split(strrev($r), 5, ' ')));
	}
}
