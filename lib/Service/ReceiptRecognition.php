<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The fields of a receipt the Mac app fills from its recognition (0.22.0),
 * pure: the mark «please check» with its reasons, the source, the QR
 * reference and the VAT lines of a receipt with several rates. The
 * recognition itself never runs on the server.
 */
final class ReceiptRecognition {
	public const SOURCES = ['zugferd', 'qr', 'model', 'heuristic'];
	public const REASONS_MAX = 10;
	public const REASON_MAX = 200;
	public const QR_MAX = 35;
	public const LINES_MAX = 10;
	/** The values a receipt without these fields has (records from before). */
	public const DEFAULTS = ['review' => false, 'review_reasons' => [], 'recognized_by' => null, 'qr_reference' => null, 'vat_lines' => null];
	private const CENT = 0.0101;
	private const CONTROL = '/[\x00-\x1F\x7F]/';

	/** Content and defaults; `vat_lines` after the amounts are known to be numbers. */
	public static function validate(\stdClass $d): void {
		$review = $d->review ?? false;
		if (!is_bool($review)) {
			throw ApiException::notBool('review');
		}
		$reasons = $d->review_reasons ?? [];
		if (!is_array($reasons) || !array_is_list($reasons) || count($reasons) > self::REASONS_MAX) {
			throw self::reasonsError();
		}
		foreach ($reasons as $r) {
			if (!is_string($r) || trim($r) === '' || mb_strlen($r) > self::REASON_MAX || preg_match(self::CONTROL, $r) || !mb_check_encoding($r, 'UTF-8')) {
				throw self::reasonsError();
			}
		}
		$d->review = $review;
		// Without the mark no reasons: they belong to it.
		$d->review_reasons = $review ? $reasons : [];
		$by = $d->recognized_by ?? null;
		if ($by !== null && !in_array($by, self::SOURCES, true)) {
			throw ApiException::invalid(Message::of('“{field}” must be null or one of {values}.', ['field' => 'recognized_by', 'values' => implode(', ', self::SOURCES)]));
		}
		$d->recognized_by = $by;
		$qr = $d->qr_reference ?? null;
		if ($qr !== null && (!is_string($qr) || $qr === '' || mb_strlen($qr) > self::QR_MAX || preg_match(self::CONTROL, $qr))) {
			throw ApiException::invalid(Message::of('“{field}” must be null or text of at most {max} characters.', ['field' => 'qr_reference', 'max' => self::QR_MAX]));
		}
		$d->qr_reference = $qr;
		self::lines($d);
	}

	/**
	 * `vat_lines`: null or 1–10 lines `{rate, net, vat}` with distinct rates
	 * (`vat_rate` null, checked with the amounts); Σ net = net, Σ vat = vat and each line's
	 * vat = round(net × rate / 100, 2), all within 0.01.
	 */
	private static function lines(\stdClass $d): void {
		$lines = $d->vat_lines ?? null;
		if ($lines === null || $lines === []) {
			$d->vat_lines = null;
			return;
		}
		if (!is_array($lines) || !array_is_list($lines) || count($lines) > self::LINES_MAX) {
			throw self::linesError();
		}
		$seen = [];
		$net = 0.0;
		$vat = 0.0;
		$out = [];
		foreach ($lines as $l) {
			if (!$l instanceof \stdClass || array_diff(array_keys(get_object_vars($l)), ['rate', 'net', 'vat']) !== []) {
				throw self::linesError();
			}
			$rate = $l->rate ?? null;
			if (!(ProjectRules::isRate($rate) && $rate <= 100) || !ReceiptRules::isNumber($l->net ?? null) || !ReceiptRules::isNumber($l->vat ?? null)) {
				throw self::linesError();
			}
			$r = (int)round((float)$rate * 100.0);
			if (isset($seen[$r])) {
				throw ApiException::invalid('Each rate stands once in “vat_lines”.');
			}
			$seen[$r] = true;
			if (abs((float)$l->vat - round((float)$l->net * (float)$rate / 100.0, 2)) > self::CENT) {
				throw ApiException::invalid(Message::of('In “vat_lines” the VAT at {rate} must be the net × rate / 100, rounded to cents.', ['rate' => DocumentRules::percent((float)$rate)]));
			}
			$net += (float)$l->net;
			$vat += (float)$l->vat;
			$out[] = (object)['rate' => $rate, 'net' => $l->net, 'vat' => $l->vat];
		}
		if (abs($net - (float)$d->net) > self::CENT || abs($vat - (float)$d->vat) > self::CENT) {
			throw ApiException::invalid('The “vat_lines” add up to “net” and “vat”.');
		}
		$d->vat_lines = $out;
	}

	/**
	 * Clearing the mark (true before, false after) is for Team Admins, the
	 * project's Leads and who created the receipt.
	 *
	 * @param array<string,true> $led
	 */
	public static function requireClear(bool $manages, string $uid, array $led, ?\stdClass $before, \stdClass $after): void {
		if ($before === null || ($before->review ?? false) !== true || ($after->review ?? false) === true) {
			return;
		}
		if ($manages || ReceiptRules::ofLed($before, $led) || ($before->created_by ?? null) === $uid) {
			return;
		}
		throw ApiException::forbidden('Only Team Admins, the project’s Leads and who recorded the receipt mark it as checked.');
	}

	/** Approval clears the mark. */
	public static function clear(\stdClass $d): void {
		$d->review = false;
		$d->review_reasons = [];
	}

	/** A field's value as it counts (missing = its default). */
	public static function value(\stdClass $d, string $field): mixed {
		return array_key_exists($field, self::DEFAULTS) && !property_exists($d, $field) ? self::DEFAULTS[$field] : ($d->{$field} ?? null);
	}

	/**
	 * The receipt's amounts by rate: its `vat_lines`, else one line of its
	 * rate (none: 0).
	 *
	 * @return list<array{rate:float,net:float,vat:float}>
	 */
	public static function rates(\stdClass $d): array {
		$lines = $d->vat_lines ?? null;
		if (is_array($lines) && $lines !== []) {
			$out = [];
			foreach ($lines as $l) {
				$l = $l instanceof \stdClass ? $l : (object)(is_array($l) ? $l : []);
				$out[] = ['rate' => (float)($l->rate ?? 0), 'net' => (float)($l->net ?? 0), 'vat' => (float)($l->vat ?? 0)];
			}
			return $out;
		}
		$rate = $d->vat_rate ?? null;
		return [['rate' => is_int($rate) || is_float($rate) ? (float)$rate : 0.0, 'net' => (float)($d->net ?? 0), 'vat' => (float)($d->vat ?? 0)]];
	}

	/** Does the receipt carry VAT lines? */
	public static function hasLines(?\stdClass $d): bool {
		$l = $d?->vat_lines ?? null;
		return is_array($l) && $l !== [];
	}

	private static function reasonsError(): ApiException {
		return ApiException::invalid(Message::of('“review_reasons” must be a list of at most {n} texts of at most {max} characters.', ['n' => self::REASONS_MAX, 'max' => self::REASON_MAX]));
	}

	private static function linesError(): ApiException {
		return ApiException::invalid(Message::of('“vat_lines” must be a list of at most {n} lines with “rate” (0 to 100), “net” and “vat”.', ['n' => self::LINES_MAX]));
	}
}
