<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * Invoice documents, the pure part: the team setting `documents` (since
 * 0.22.0 the root of the document tree and the chain mail; the paths below
 * it come from DocumentTree), the document language, and since 0.32.0 the
 * checks of the app's uploads – PDF and XML magic, size, the search for
 * number and chain line – and which files a chain verification re-hashes.
 * The server renders nothing (one renderer, the Mac app).
 */
final class DocumentRules {
	public const FIELD = 'documents';
	public const PATH_MAX = 255;
	public const MAIL_MAX = 254;
	/** Ledger action of a file that arrives after the issue's entry (the XML, older chains). */
	public const ACTION = 'document';
	public const KIND_PDF = 'pdf';
	public const KIND_XML = 'xml';
	/** Ledger `kind` of the XRechnung file (0.20.0). */
	public const KIND_XRECHNUNG = 'xrechnung';
	/** An upload is at most 20 MB, as the quote documents and the accounting system's PDF. */
	public const FILE_MAX = 20 * 1024 * 1024;
	/** The ledger actions whose payload carries the issue's own document (0.32.0). */
	public const ISSUE_ACTIONS = [LedgerRules::ISSUED, LedgerRules::REVERSAL, LedgerRules::CREDIT];
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

	// ------------------------------------------------------------ language

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

	// ------------------------------------------------------------- uploads

	/** The PDF the app sends: at most 20 MB (`413`), starting with `%PDF-` (`422`, field `content`). */
	public static function requirePdf(string $bytes): void {
		if (strlen($bytes) > self::FILE_MAX) {
			throw ApiException::tooLarge('A file may be at most 20 MB.');
		}
		if (!str_starts_with($bytes, '%PDF-')) {
			throw ApiException::invalid('The document must be a PDF.')->field('content');
		}
	}

	/**
	 * The PDF must name this invoice: the number and the chain line as
	 * answered by `issue` (the XMP is uncompressed, so a plain search
	 * finds them); `422 document_mismatch` otherwise. A PDF of another
	 * invoice or an older render stays out of the file.
	 *
	 * @param list<string> $needles
	 */
	public static function requireMentions(string $bytes, array $needles): void {
		foreach ($needles as $n) {
			if ($n !== '' && !str_contains($bytes, $n)) {
				throw ApiException::invalid('The uploaded file does not name this invoice; render it from the reservation and send it again.')->code(ErrorCode::DOCUMENT_MISMATCH)->field('content');
			}
		}
	}

	/**
	 * The e-invoice XML the app sends: at most 20 MB, UTF-8, well-formed
	 * (no network, no DTD, no external entities), root `CrossIndustryInvoice`
	 * (CII) or `Invoice` (UBL); `422` with field `content` otherwise.
	 */
	public static function requireXml(string $bytes): void {
		if (strlen($bytes) > self::FILE_MAX) {
			throw ApiException::tooLarge('A file may be at most 20 MB.');
		}
		$bad = static fn (): ApiException => ApiException::invalid('The file must be a well-formed e-invoice XML (CII or UBL).')->field('content');
		if ($bytes === '' || !mb_check_encoding($bytes, 'UTF-8') || preg_match('/<!DOCTYPE/i', $bytes) === 1) {
			throw $bad();
		}
		$previous = libxml_use_internal_errors(true);
		try {
			$doc = new \DOMDocument();
			$ok = $doc->loadXML($bytes, LIBXML_NONET | LIBXML_NOCDATA);
			$root = $doc->documentElement;
			if ($ok === false || $root === null || !in_array($root->localName, ['CrossIndustryInvoice', 'Invoice'], true)) {
				throw $bad();
			}
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}
	}

	/**
	 * The files a chain verification re-hashes (`?files=true`): the issue's
	 * own document of 0.32.0 entries (`payload.document.sha256`, the path
	 * from the frozen row) and the `document` entries (`payload.path`,
	 * `payload.sha256`). Entries without a file are skipped.
	 *
	 * @param iterable<array{seq:int,action:string,invoice:int,payload?:?string,...}> $entries
	 * @return list<array{seq:int,invoice:int,path:?string,sha256:string}>
	 */
	public static function hashedFiles(iterable $entries): array {
		$out = [];
		foreach ($entries as $e) {
			$raw = $e['payload'] ?? null;
			$p = is_string($raw) ? json_decode($raw, true) : null;
			if (!is_array($p)) {
				continue;
			}
			if ($e['action'] === self::ACTION && is_string($p['sha256'] ?? null)) {
				$out[] = ['seq' => $e['seq'], 'invoice' => $e['invoice'], 'path' => is_string($p['path'] ?? null) ? $p['path'] : null, 'sha256' => $p['sha256']];
			} elseif (in_array($e['action'], self::ISSUE_ACTIONS, true) && is_array($p['document'] ?? null) && is_string($p['document']['sha256'] ?? null)) {
				$out[] = ['seq' => $e['seq'], 'invoice' => $e['invoice'], 'path' => null, 'sha256' => $p['document']['sha256']];
			}
		}
		return $out;
	}

	/** The break of a chain verification at a file. @return array{ok:false,first_break:int,field:string,expected:string,found:string} */
	public static function fileBreak(int $seq, string $expected, ?string $found): array {
		return ['ok' => false, 'first_break' => $seq, 'field' => 'document', 'expected' => $expected, 'found' => $found ?? 'missing'];
	}

	// ------------------------------------------------------------- numbers

	/** A rate: `8.1 %`, `19 %`. */
	public static function percent(float $v): string {
		return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') . ' %';
	}


	/** A day: `15.10.2026` in German, `2026-10-15` in English. */
	public static function day(string $day, string $lang): string {
		if ($lang === self::DE && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $day, $m)) {
			return $m[3] . '.' . $m[2] . '.' . $m[1];
		}
		return substr($day, 0, 10);
	}
}
