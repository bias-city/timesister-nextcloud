<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * Team setting `invoicing_mode` (0.24.0): how invoices come about. Null is
 * TimeSister issuing without a copy; `{mode: timesister, system, copy:
 * auto|manual}` sends a copy of every issued invoice to an accounting
 * system (the Mac app's plugins); `{mode: external, system}` hands the draft
 * over and the accounting system issues it. Pure.
 */
final class InvoicingModeRules {
	public const FIELD = 'invoicing_mode';
	public const TIMESISTER = 'timesister';
	public const EXTERNAL = 'external';
	public const MODES = [self::TIMESISTER, self::EXTERNAL];
	public const COPY_OFF = 'off';
	public const COPIES = [self::COPY_OFF, 'auto', 'manual'];
	public const KEYS = ['mode', 'system', 'copy', 'archive_pdf'];
	/** 0.24.1: store the accounting system's PDF of a handed-over invoice in the document tree (mode external, default on). */
	public const ARCHIVE_PDF = 'archive_pdf';

	/**
	 * The setting from a body: null, or an object checked by the rules and
	 * returned complete (missing `system` null, missing `copy` off; in the
	 * mode external `archive_pdf`, missing true – kept only there).
	 *
	 * @return ?array{mode:string,system:?string,copy:string,archive_pdf?:bool}
	 */
	public static function validate(mixed $v): ?array {
		if ($v === null) {
			return null;
		}
		if ($v instanceof \stdClass) {
			$v = get_object_vars($v);
		}
		if (!is_array($v) || array_is_list($v)) {
			throw ApiException::invalid('“invoicing_mode” must be null or an object with “mode”.');
		}
		foreach (array_keys($v) as $k) {
			if (!in_array($k, self::KEYS, true)) {
				throw ApiException::invalid(Message::of('Unknown team setting “{key}”.', ['key' => self::FIELD . '.' . (string)$k]));
			}
		}
		$mode = $v['mode'] ?? null;
		if (!is_string($mode) || !in_array($mode, self::MODES, true)) {
			throw ApiException::invalid(Message::of('“{field}” must be “timesister” or “external”.', ['field' => self::FIELD . '.mode']));
		}
		$system = $v['system'] ?? null;
		$system = $system === null ? null : ExportRules::system($system, self::FIELD . '.system');
		$copy = $v['copy'] ?? null;
		if ($copy === null) {
			$copy = self::COPY_OFF;
		} elseif (!is_string($copy) || !in_array($copy, self::COPIES, true)) {
			throw ApiException::invalid(Message::of('“{field}” must be “off”, “auto” or “manual”.', ['field' => self::FIELD . '.copy']));
		}
		if ($mode === self::EXTERNAL && $system === null) {
			throw ApiException::invalid('“invoicing_mode.system” is required for the mode “external”: name the accounting system that issues the invoices.');
		}
		if ($mode === self::EXTERNAL && $copy !== self::COPY_OFF) {
			throw ApiException::invalid('“invoicing_mode.copy” must be “off” for the mode “external”: the accounting system issues the invoices itself.');
		}
		if ($mode === self::TIMESISTER && $system === null && $copy !== self::COPY_OFF) {
			throw ApiException::invalid('“invoicing_mode.copy” needs “invoicing_mode.system”: name the accounting system that gets the copies.');
		}
		$archive = $v[self::ARCHIVE_PDF] ?? null;
		if ($archive !== null && !is_bool($archive)) {
			throw ApiException::invalid(Message::of('“{field}” must be true, false or null.', ['field' => self::FIELD . '.' . self::ARCHIVE_PDF]));
		}
		$out = ['mode' => $mode, 'system' => $system, 'copy' => $copy];
		if ($mode === self::EXTERNAL) {
			$out[self::ARCHIVE_PDF] = $archive ?? true;
		}
		return $out;
	}

	/**
	 * The stored setting (JSON, null = TimeSister without a copy); anything
	 * unreadable counts as null.
	 *
	 * @return ?array{mode:string,system:?string,copy:string,archive_pdf?:bool}
	 */
	public static function decode(?string $json): ?array {
		if ($json === null || $json === '') {
			return null;
		}
		try {
			return self::validate(json_decode($json, true));
		} catch (ApiException) {
			return null;
		}
	}

	/** @param ?array{mode:string,system:?string,copy:string,archive_pdf?:bool} $m */
	public static function encode(?array $m): ?string {
		return $m === null ? null : json_encode($m, Json::FLAGS);
	}

	/** The system that issues the team's invoices (mode external), else null. @param ?array{mode:string,system:?string,copy:string,archive_pdf?:bool} $m */
	public static function externalSystem(?array $m): ?string {
		return $m !== null && $m['mode'] === self::EXTERNAL ? $m['system'] : null;
	}

	/** Does the team store the accounting system's PDF (mode external, `archive_pdf` not false)? @param ?array{mode:string,system:?string,copy:string,archive_pdf?:bool} $m */
	public static function archivesPdf(?array $m): bool {
		return self::externalSystem($m) !== null && ($m['archive_pdf'] ?? true) !== false;
	}
}
