<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The document tree (0.22.0), pure: every folder and file name the server
 * writes under the team's root (`documents.root`) in the backup owner's
 * files. Fixed scheme per year and kind; name parts transliterated and
 * made safe; a taken name gets `_2`, `_3` …
 */
final class DocumentTree {
	public const DEFAULT_PARENT = 'TimeSister';
	public const ACCOUNTING = 'Buchhaltung';
	public const QUOTES = 'Offerten';
	public const INVOICES = 'Rechnungen';
	public const RECEIPTS = 'Belege';
	public const EXPENSES = 'Spesen';
	public const CHAIN = 'Kette';
	public const PROJECTS = 'Projekte';
	public const PART_MAX = 40;
	/** The extension of a stored file by its type. */
	public const EXTENSIONS = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'application/xml' => 'xml'];

	private const TRANSLIT = [
		'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss', 'ẞ' => 'SS',
		'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Å' => 'A',
		'æ' => 'ae', 'Æ' => 'Ae', 'ç' => 'c', 'Ç' => 'C', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
		'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I', 'ñ' => 'n', 'Ñ' => 'N',
		'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ø' => 'o', 'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ø' => 'O', 'œ' => 'oe', 'Œ' => 'Oe',
		'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'ý' => 'y', 'ÿ' => 'y', 'Ý' => 'Y',
		'’' => '-', '&' => '-',
	];

	// ---------------------------------------------------------------- root

	/** The team's root: the setting, or `TimeSister/<team name>`. */
	public static function root(?string $root, string $teamName): string {
		return $root ?? self::DEFAULT_PARENT . '/' . DocumentRules::folderName($teamName);
	}

	/** The year of a day (`YYYY-MM-DD`). */
	public static function year(string $day): string {
		return preg_match('/^\d{4}/', $day) === 1 ? substr($day, 0, 4) : '0000';
	}

	private static function accounting(string $root, string $day): string {
		return $root . '/' . self::ACCOUNTING . '/' . self::year($day);
	}

	// ------------------------------------------------------------- folders

	public static function quoteFolder(string $root, string $date): string {
		return self::accounting($root, $date) . '/' . self::QUOTES;
	}

	public static function invoiceFolder(string $root, string $issuedOn): string {
		return self::accounting($root, $issuedOn) . '/' . self::INVOICES;
	}

	public static function receiptFolder(string $root, string $date): string {
		return self::accounting($root, $date) . '/' . self::RECEIPTS;
	}

	/** `Spesen/<person>/` of the year: the person's reimbursed receipts and expense reports. */
	public static function expenseFolder(string $root, string $day, string $personName): string {
		return self::accounting($root, $day) . '/' . self::EXPENSES . '/' . self::folderPart(self::part($personName), 'Person');
	}

	public static function chainFolder(string $root, string $day): string {
		return self::accounting($root, $day) . '/' . self::CHAIN;
	}

	/** `Projekte/<code> <name>/`. */
	public static function projectFolder(string $root, ?string $code, string $name): string {
		$parts = array_filter([$code === null ? '' : self::part($code), self::part($name)], static fn (string $p): bool => $p !== '');
		$segment = implode(' ', $parts);
		return $root . '/' . self::PROJECTS . '/' . self::folderPart($segment, 'Projekt');
	}

	// --------------------------------------------------------------- names

	/** `<date>_<number>_<customer>.pdf`; empty parts are left out. */
	public static function quoteName(string $date, string $number, string $customer): string {
		return self::join([$date, $number, $customer], 'pdf');
	}

	/** `<date>_<number>_<customer>.pdf` or `.xml`. */
	public static function invoiceName(string $date, string $number, string $customer, string $ext = 'pdf'): string {
		return self::join([$date, $number, $customer], $ext);
	}

	/** `<date>_<supplier>_<gross>-<CUR>[_<project code>-K<cost>].<ext>`. */
	public static function receiptName(string $date, string $supplier, float $gross, string $currency, ?string $projectCode, ?int $cost, string $ext): string {
		// No amount yet (0.00): the part is left out.
		$parts = [$date, $supplier, abs($gross) < 0.005 ? '' : self::amount($gross) . '-' . strtoupper($currency)];
		if ($projectCode !== null && $projectCode !== '') {
			$parts[] = $projectCode . ($cost === null ? '' : '-K' . $cost);
		}
		return self::join($parts, $ext);
	}

	/** `<YYYY-MM>_Spesenblatt.pdf` of the month the period ends in. */
	public static function reportName(string $to): string {
		return substr($to, 0, 7) . '_Spesenblatt.pdf';
	}

	public static function chainName(string $day): string {
		return 'chain-' . $day . '.txt';
	}

	public static function dossierName(string $day): string {
		return 'Projektakte-' . $day . '.zip';
	}

	/** An amount with two decimals and a dot: `1340.44`. */
	public static function amount(float $v): string {
		return number_format($v, 2, '.', '');
	}

	/** The extension of a type; `bin` for anything else. */
	public static function extension(string $mime): string {
		return self::EXTENSIONS[$mime] ?? 'bin';
	}

	/** Does the path already carry this name in this folder, also as `_2` … of {@see unique()}? */
	public static function isPlaced(string $path, string $folder, string $name): bool {
		if ($path === $folder . '/' . $name) {
			return true;
		}
		if (!str_starts_with($path, $folder . '/')) {
			return false;
		}
		$base = substr($path, strlen($folder) + 1);
		$dot = strrpos($name, '.');
		$stem = $dot === false ? $name : substr($name, 0, $dot);
		$ext = $dot === false ? '' : substr($name, $dot);
		return preg_match('/^' . preg_quote($stem, '/') . '_[0-9]{1,3}' . preg_quote($ext, '/') . '$/', $base) === 1;
	}

	/**
	 * A name not yet taken: `name.pdf`, else `name_2.pdf`, `name_3.pdf` …
	 *
	 * @param callable(string):bool $taken
	 */
	public static function unique(string $name, callable $taken): string {
		if (!$taken($name)) {
			return $name;
		}
		$dot = strrpos($name, '.');
		$stem = $dot === false ? $name : substr($name, 0, $dot);
		$ext = $dot === false ? '' : substr($name, $dot);
		for ($n = 2; $n < 1000; $n++) {
			$candidate = $stem . '_' . $n . $ext;
			if (!$taken($candidate)) {
				return $candidate;
			}
		}
		throw new \RuntimeException('No free file name for “' . $name . '”.');
	}

	// --------------------------------------------------------------- parts

	/**
	 * A name part: umlauts transliterated, everything but `A–Z a–z 0–9 . -`
	 * becomes `-`, runs collapsed, at most 40 characters, no `-` at the ends.
	 */
	public static function part(string $s, int $max = self::PART_MAX): string {
		$s = strtr($s, self::TRANSLIT);
		$s = (string)preg_replace('/[^A-Za-z0-9.\-]+/', '-', $s);
		$s = (string)preg_replace('/-{2,}/', '-', $s);
		$s = trim($s, '-');
		return trim(substr($s, 0, $max), '-');
	}

	/** A safe part used as a folder: no leading dots, never empty. */
	private static function folderPart(string $safe, string $fallback): string {
		$p = ltrim($safe, '.');
		return trim($p, '-. ') === '' ? $fallback : $p;
	}

	/** @param list<string> $parts */
	private static function join(array $parts, string $ext): string {
		$safe = array_values(array_filter(array_map(static fn (string $p): string => self::part($p), $parts), static fn (string $p): bool => $p !== ''));
		$stem = ltrim(implode('_', $safe), '.');
		return ($stem === '' ? 'document' : $stem) . '.' . $ext;
	}
}
