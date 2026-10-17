<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * Invoice documents (0.19.0), the pure part: the team setting `documents`
 * (since 0.22.0 the root of the document tree and the chain mail; the
 * paths below it come from DocumentTree), the chain line on every page,
 * the watermark and the labels of the document in German and English.
 * The services (DocumentService, DocumentFolder, InvoicePdf) do the
 * reading, the files and the PDF.
 */
final class DocumentRules {
	public const FIELD = 'documents';
	public const PATH_MAX = 255;
	public const MAIL_MAX = 254;
	public const MAX_TRIES = 10;
	/** Ledger action of a stored document. */
	public const ACTION = 'document';
	public const KIND_PDF = 'pdf';
	public const KIND_XML = 'xml';
	/** Ledger `kind` of the stored files (0.20.0): the PDF/A-3 with the XML inside, the XRechnung file. */
	public const KIND_ZUGFERD = 'zugferd';
	public const KIND_XRECHNUNG = 'xrechnung';
	public const DE = 'de';
	public const EN = 'en';
	/** The settings with their defaults (0.22.0: the tree's root; null = `TimeSister/<team>`). */
	public const DEFAULTS = ['root' => null, 'chain_mail' => null];
	/** The folders of 0.19.0: ignored on write, never returned (0.22.0, no transition). */
	public const GONE = ['invoices', 'quotes', 'per_year'];
	private const CONTROL = '/[\x00-\x1F\x7F]/';

	// ----------------------------------------------------------- settings

	/**
	 * The stored setting (JSON, null = defaults) as the API hands it out.
	 *
	 * @return array{root:?string,chain_mail:?string}
	 */
	public static function settings(?string $json): array {
		$out = self::DEFAULTS;
		$v = $json === null || $json === '' ? null : json_decode($json, true);
		if (is_array($v)) {
			foreach (['root', 'chain_mail'] as $k) {
				$out[$k] = isset($v[$k]) && is_string($v[$k]) && $v[$k] !== '' ? $v[$k] : null;
			}
		}
		return $out;
	}

	/**
	 * `documents` from a body: an object with `root` and `chain_mail`; a
	 * missing key keeps the stored value, the folders of 0.19.0 are
	 * ignored, other keys, a malformed path or address: 422.
	 *
	 * @param array{root:?string,chain_mail:?string} $current
	 * @return array{root:?string,chain_mail:?string}
	 */
	public static function validate(mixed $v, array $current): array {
		if ($v instanceof \stdClass) {
			$v = get_object_vars($v);
		}
		if (!is_array($v) || array_is_list($v) && $v !== []) {
			throw ApiException::invalid(Message::of('“{field}” must be an object.', ['field' => self::FIELD]));
		}
		$out = $current;
		foreach ($v as $key => $value) {
			if (in_array($key, self::GONE, true)) {
				continue;
			}
			switch ($key) {
				case 'root':
					$out['root'] = self::path($value, self::FIELD . '.root');
					break;
				case 'chain_mail':
					$out['chain_mail'] = self::mail($value);
					break;
				default:
					throw ApiException::invalid(Message::of('Unknown team setting “{key}”.', ['key' => self::FIELD . '.' . (string)$key]));
			}
		}
		return $out;
	}

	/** A folder path: 1–255 characters, no `..`, no leading `/`, no control characters; null or empty: the default. */
	public static function path(mixed $v, string $field): ?string {
		if ($v === null || $v === '') {
			return null;
		}
		// A trailing slash is tolerated; a leading one is not (the path is relative to the owner's files).
		$v = is_string($v) ? rtrim($v, '/') : null;
		if ($v === null || !self::isPath($v)) {
			throw ApiException::invalid(Message::of('“{field}” must be a folder path of at most 255 characters, without “..” and without a leading slash.', ['field' => $field]));
		}
		return $v;
	}

	/** Is this a folder path the setting takes? */
	public static function isPath(string $v): bool {
		if ($v === '' || mb_strlen($v) > self::PATH_MAX || !mb_check_encoding($v, 'UTF-8') || preg_match(self::CONTROL, $v) || str_starts_with($v, '/') || str_contains($v, '\\')) {
			return false;
		}
		foreach (explode('/', $v) as $segment) {
			if ($segment === '' || $segment === '.' || $segment === '..') {
				return false;
			}
		}
		return true;
	}

	/** An e-mail address or null. */
	private static function mail(mixed $v): ?string {
		if ($v === null || $v === '') {
			return null;
		}
		if (!is_string($v) || mb_strlen($v) > self::MAIL_MAX || !preg_match('/^[^\s@\x00-\x1F]+@[^\s@\x00-\x1F]+\.[^\s@\x00-\x1F]+$/u', $v)) {
			throw ApiException::invalid(Message::of('“{field}” must be an e-mail address.', ['field' => self::FIELD . '.chain_mail']));
		}
		return $v;
	}

	/** The settings as stored (JSON), null when everything is default. */
	public static function encode(array $settings): ?string {
		$s = array_intersect_key($settings + self::DEFAULTS, self::DEFAULTS);
		return $s === self::DEFAULTS ? null : json_encode($s, Json::FLAGS);
	}

	/** The team's root folder: the setting or `TimeSister/<team>`. @param array{root:?string,...} $s */
	public static function rootOf(array $s, string $teamName): string {
		return DocumentTree::root($s['root'], $teamName);
	}

	// ------------------------------------------------------------- folders

	/** A team name as a folder name: no slashes, no control characters. */
	public static function folderName(string $teamName): string {
		$n = trim((string)preg_replace(self::CONTROL, '', str_replace(['/', '\\'], '-', $teamName)));
		return $n === '' || $n === '.' || $n === '..' ? 'Team' : $n;
	}

	/** `<number>.pdf` – the number without slashes and control characters. */
	public static function fileName(string $number, string $kind = self::KIND_PDF): string {
		$n = trim((string)preg_replace(self::CONTROL, '', str_replace(['/', '\\'], '-', $number)));
		return ($n === '' ? 'invoice' : $n) . '.' . $kind;
	}

	/** The text of the chain head file and mail. */
	public static function chainHeadText(string $team, string $day, int $entries, int $seq, string $hash): string {
		return "team: $team\ndate: $day\nentries: $entries\nhead seq: $seq\nhead hash: $hash\n";
	}

	// ------------------------------------------------------------ document

	/** `Beleg <number> · Kette <seq> <hash[0:12]> ← <prev_hash[0:12]>` – on every page; a first entry has no previous hash. */
	public static function chainLine(string $number, ?int $seq, ?string $hash, ?string $prevHash): string {
		$prev = $prevHash === null || $prevHash === '' ? '–' : substr($prevHash, 0, 12);
		return 'Beleg ' . $number . ' · Kette ' . (string)($seq ?? 0) . ' ' . substr((string)$hash, 0, 12) . ' ← ' . $prev;
	}

	/** The watermark of a preview: VORABZUG in German, DRAFT otherwise. */
	public static function watermark(string $lang): string {
		return $lang === self::DE ? 'VORABZUG' : 'DRAFT';
	}

	/**
	 * The team's document language as written (0.22.0): null (the server's
	 * default decides) or `de` / `en`.
	 *
	 * @throws ApiException 422 otherwise
	 */
	public static function validateLanguage(mixed $v): void {
		if ($v !== null && $v !== self::DE && $v !== self::EN) {
			throw ApiException::invalid('The team’s “language” is null, “de” or “en”.');
		}
	}

	/**
	 * The document's language: the team's `settings.language` when it is
	 * German or English, else the server's default language (German when
	 * it starts with `de`, English otherwise).
	 */
	public static function language(?\stdClass $settings, string $serverDefault): string {
		$v = $settings?->language ?? null;
		foreach ([is_string($v) ? $v : '', $serverDefault] as $code) {
			$code = strtolower(substr($code, 0, 2));
			if ($code === self::DE) {
				return self::DE;
			}
			if ($code === self::EN) {
				return self::EN;
			}
		}
		return self::EN;
	}

	/**
	 * The labels of the document in its language.
	 *
	 * @return array<string,string>
	 */
	public static function texts(string $lang): array {
		if ($lang === self::DE) {
			return [
				'invoice' => 'Rechnung', 'reversal' => 'Storno zu', 'credit' => 'Gutschrift', 'not_issued' => '(noch nicht gestellt)',
				'date' => 'Datum', 'due' => 'Zahlbar bis', 'period' => 'Leistungszeitraum', 'project' => 'Projekt', 'budget' => 'Budget',
				'vat_id' => 'MWST-Nr.', 'email' => 'E-Mail', 'phone' => 'Telefon', 'contact' => 'Kontakt',
				'position' => 'Position', 'hours' => 'Stunden', 'rate' => 'Ansatz', 'amount' => 'Betrag',
				'net' => 'Netto', 'vat' => 'MWST', 'gross' => 'Total', 'appendix' => 'Stundenliste', 'person' => 'Person',
				'code' => 'Code', 'title' => 'Titel', 'total' => 'Total', 'page' => 'Seite {n} von {total}', 'payment_part' => 'Zahlteil',
				'on' => 'auf', 'gross_vat' => 'Total inkl. MWST', 'pass_through' => 'Durchlaufende Posten (ohne MWST)',
				'pass_through_sum' => 'Summe durchlaufende Posten',
				'receipt' => 'Beleg', 'copy_missing' => 'Die Kopie dieses Belegs liess sich nicht einbinden. Die Datei liegt hier:',
				'copy_more' => '… und {n} weitere Seiten in der Datei des Belegs.', 'copy_unreadable' => 'Die Datei fehlt.',
				'report' => 'Spesenblatt', 'supplier' => 'Lieferant', 'text' => 'Text', 'gross_col' => 'Brutto', 'without_receipt' => 'ohne Beleg',
				'sign_person' => 'Unterschrift Person', 'sign_fixed' => 'Fixiert', 'team' => 'Team', 'status' => 'Stand',
			];
		}
		return [
			'invoice' => 'Invoice', 'reversal' => 'Reversal of', 'credit' => 'Credit note', 'not_issued' => '(not yet issued)',
			'date' => 'Date', 'due' => 'Due', 'period' => 'Period', 'project' => 'Project', 'budget' => 'Budget',
			'vat_id' => 'VAT ID', 'email' => 'E-mail', 'phone' => 'Phone', 'contact' => 'Contact',
			'position' => 'Position', 'hours' => 'Hours', 'rate' => 'Rate', 'amount' => 'Amount',
			'net' => 'Net', 'vat' => 'VAT', 'gross' => 'Total', 'appendix' => 'Hours', 'person' => 'Person',
			'code' => 'Code', 'title' => 'Title', 'total' => 'Total', 'page' => 'Page {n} of {total}', 'payment_part' => 'Payment part',
			'on' => 'on', 'gross_vat' => 'Total incl. VAT', 'pass_through' => 'Pass-through items (no VAT)',
			'pass_through_sum' => 'Pass-through items',
			'receipt' => 'Receipt', 'copy_missing' => 'The copy of this receipt could not be embedded. The file is here:',
			'copy_more' => '… and {n} more pages in the receipt’s file.', 'copy_unreadable' => 'The file is missing.',
			'report' => 'Expense report', 'supplier' => 'Supplier', 'text' => 'Text', 'gross_col' => 'Gross', 'without_receipt' => 'without receipt',
			'sign_person' => 'Signature person', 'sign_fixed' => 'Fixed', 'team' => 'Team', 'status' => 'Status',
		];
	}

	/** The document's title: “Invoice RE-2026-0007”, “Reversal of RE-2026-0007”, “Credit note RE-2026-0008”. @param array<string,string> $t */
	public static function title(array $t, string $number, ?string $reverses, ?string $credits, ?string $originalNumber): string {
		if ($reverses !== null) {
			return $t['reversal'] . ' ' . ($originalNumber ?? $reverses) . ' – ' . $number;
		}
		if ($credits !== null) {
			return $t['credit'] . ' ' . $number;
		}
		return $t['invoice'] . ' ' . $number;
	}

	/** An amount: `1’234.50` in German (Swiss style), `1,234.50` in English. */
	public static function money(float $v, string $lang): string {
		return number_format($v, 2, '.', $lang === self::DE ? '’' : ',');
	}

	/** A rate: `8.1 %`, `19 %`. */
	public static function percent(float $v): string {
		return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') . ' %';
	}

	/** Hours: `12.5` → `12.50`. */
	public static function hours(float $v): string {
		return number_format($v, 2, '.', '');
	}

	/** A day: `15.10.2026` in German, `2026-10-15` in English. */
	public static function day(string $day, string $lang): string {
		if ($lang === self::DE && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $day, $m)) {
			return $m[3] . '.' . $m[2] . '.' . $m[1];
		}
		return substr($day, 0, 10);
	}
}
