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
}
