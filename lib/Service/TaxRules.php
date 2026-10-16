<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The tax case of an invoice (0.20.0), pure: domestic, EU reverse charge
 * or export from the countries of issuer and customer and the customer's
 * VAT id; the draft may override it. Frozen at issue into
 * `customer_json.tax_case`; the documents take the VAT category code
 * (EN 16931) and the note from here.
 */
final class TaxRules {
	public const FIELD = 'tax_case';
	public const DOMESTIC = 'domestic';
	public const EU_REVERSE = 'eu_reverse';
	public const EXPORT = 'export';
	public const CASES = [self::DOMESTIC, self::EU_REVERSE, self::EXPORT];
	/** EN 16931 VAT category codes. */
	public const CODE_STANDARD = 'S';
	public const CODE_ZERO = 'Z';
	public const CODE_REVERSE = 'AE';
	public const CODE_EXPORT = 'G';
	public const CODE_EXEMPT = 'E';
	/**
	 * Pass-through items (0.21.0) in the e-invoice: category E with the
	 * exemption of Art. 79(c) VAT directive – `O` (not subject) would forbid
	 * every other category on the invoice (BR-O-11).
	 */
	public const PASS_THROUGH_CODE = 'VATEX-EU-79-C';
	public const PASS_THROUGH_REASON = 'Durchlaufender Posten / disbursement';
	/** The 27 member states (ISO 3166-1 alpha-2), 2026. */
	public const EU = ['AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE'];

	public static function inEu(?string $country): bool {
		return $country !== null && in_array(strtoupper($country), self::EU, true);
	}

	/**
	 * The tax case from the countries and the customer's VAT id; a missing
	 * customer country counts as domestic. An override from the draft
	 * (checked by {@see checkOverride()}) wins.
	 */
	public static function of(?string $issuerCountry, ?string $customerCountry, ?string $customerVatId, ?string $override = null): string {
		if ($override !== null && in_array($override, self::CASES, true)) {
			return $override;
		}
		$i = strtoupper(trim((string)$issuerCountry));
		$c = strtoupper(trim((string)$customerCountry));
		if ($c === '' || $i === '' || $i === $c) {
			return self::DOMESTIC;
		}
		if (self::inEu($i) && self::inEu($c) && is_string($customerVatId) && trim($customerVatId) !== '') {
			return self::EU_REVERSE;
		}
		return self::EXPORT;
	}

	/** `tax_case` of a draft: null, or one of the three; 422 otherwise. */
	public static function checkOverride(mixed $v): ?string {
		if ($v === null) {
			return null;
		}
		if (!is_string($v) || !in_array($v, self::CASES, true)) {
			throw ApiException::invalid('“tax_case” must be “domestic”, “eu_reverse” or “export”.');
		}
		return $v;
	}

	/** The VAT rate on the document: the invoice's rate at home, 0 otherwise. */
	public static function rate(string $taxCase, float $vat): float {
		return $taxCase === self::DOMESTIC ? $vat : 0.0;
	}

	/** The EN 16931 category code: S (Z at 0 %), AE, G outside the EU, E inside. */
	public static function code(string $taxCase, float $vat, ?string $customerCountry): string {
		return match ($taxCase) {
			self::EU_REVERSE => self::CODE_REVERSE,
			self::EXPORT => self::inEu($customerCountry) ? self::CODE_EXEMPT : self::CODE_EXPORT,
			default => $vat > 0 ? self::CODE_STANDARD : self::CODE_ZERO,
		};
	}

	/** The exemption reason code (EN 16931, VATEX list) for AE and G; null otherwise. */
	public static function exemptionCode(string $code): ?string {
		return match ($code) {
			self::CODE_REVERSE => 'VATEX-EU-AE',
			self::CODE_EXPORT => 'VATEX-EU-G',
			default => null,
		};
	}

	/** The note on the document (and in the XML as the exemption reason); null at home. */
	public static function note(string $taxCase, string $lang): ?string {
		return match ($taxCase) {
			self::EU_REVERSE => $lang === DocumentRules::DE
				? 'Steuerschuldnerschaft des Leistungsempfängers (Reverse Charge).'
				: 'Reverse charge: VAT to be accounted for by the recipient.',
			self::EXPORT => $lang === DocumentRules::DE
				? 'Export, nicht der Mehrwertsteuer unterstellt.'
				: 'Export, not subject to VAT.',
			default => null,
		};
	}

	/** The frozen customer block's `tax_case`, domestic when it has none (invoices from before 0.20.0). */
	public static function ofBlock(array $customer): string {
		$v = $customer[self::FIELD] ?? null;
		return is_string($v) && in_array($v, self::CASES, true) ? $v : self::DOMESTIC;
	}

	/**
	 * The category and rate of one line in the e-invoice (0.21.0): a
	 * pass-through item is E at 0, a taxable line the case's code at its
	 * effective rate.
	 *
	 * @param \stdClass|array<string,mixed> $p
	 * @return array{0:string,1:float}
	 */
	public static function lineCategory(\stdClass|array $p, float $default, string $taxCase, ?string $customerCountry): array {
		if (VatLines::isPassThrough($p)) {
			return [self::CODE_EXEMPT, 0.0];
		}
		$rate = VatLines::rateOf($p, $default, $taxCase);
		return [self::code($taxCase, $rate, $customerCountry), $rate];
	}

	/**
	 * The VAT breakdown of the e-invoice (0.21.0): one group per rate of
	 * the taxable lines with the case's code and note, and the pass-through
	 * items as one group E. EN 16931 allows one group per category and
	 * rate (BR-E-01: exactly one E): an export to an EU customer (E) and
	 * pass-through items share it, with both reasons and no reason code.
	 *
	 * @return list<array{code:string,rate:float,basis:float,tax:float,reason:?string,reason_code:?string}>
	 */
	public static function breakdown(VatLines $sums, string $taxCase, ?string $customerCountry, bool $withPassThrough): array {
		$groups = [];
		$note = self::note($taxCase, DocumentRules::EN);
		foreach ($sums->lines as $l) {
			$code = self::code($taxCase, $l['rate'], $customerCountry);
			$groups[self::groupKey($code, $l['rate'])] = ['code' => $code, 'rate' => $l['rate'], 'basis' => $l['net'], 'tax' => $l['vat'], 'reason' => $note, 'reason_code' => self::exemptionCode($code)];
		}
		if ($withPassThrough) {
			$k = self::groupKey(self::CODE_EXEMPT, 0.0);
			if (isset($groups[$k])) {
				$g = $groups[$k];
				$groups[$k] = ['code' => self::CODE_EXEMPT, 'rate' => 0.0, 'basis' => round($g['basis'] + $sums->passThrough, 2), 'tax' => $g['tax'],
					'reason' => trim(($g['reason'] ?? '') . ' ' . self::PASS_THROUGH_REASON), 'reason_code' => null];
			} else {
				$groups[$k] = ['code' => self::CODE_EXEMPT, 'rate' => 0.0, 'basis' => $sums->passThrough, 'tax' => 0.0, 'reason' => self::PASS_THROUGH_REASON, 'reason_code' => self::PASS_THROUGH_CODE];
			}
		}
		return array_values($groups);
	}

	/** The key of a tax group: category and rate. */
	public static function groupKey(string $code, float $rate): string {
		return $code . '|' . number_format($rate, 2, '.', '');
	}
}
