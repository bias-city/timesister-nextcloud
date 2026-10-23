<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The invoice ledger's arithmetic (0.18.0). Pure, so the clients can
 * recompute it: the canonical JSON of a payload, its SHA-256, the hash of
 * an entry over its fields and the previous hash, and the check of a whole
 * chain. Hex, lower case.
 *
 * Canonical JSON: object keys sorted by byte order (recursively), lists as
 * they are, no whitespace, `/` and non-ASCII unescaped, numbers as given
 * (a float keeps `.0`). An empty object stays `{}`.
 */
final class LedgerRules {
	public const ISSUED = 'issued';
	public const REVERSAL = 'reversal';
	public const CREDIT = 'credit';
	public const CANCELLED = 'cancelled';
	public const PAID = 'paid';
	public const UNPAID = 'unpaid';
	/** 0.27.0: a partial payment that does not settle the invoice. */
	public const PAYMENT = 'payment';
	/** Expense reports (0.23.0): fixed, its PDF, paid, reversed – in the same chain; `invoice` is 0. */
	public const EXPENSE_FIXED = 'expense_fixed';
	public const EXPENSE_DOCUMENT = 'expense_document';
	public const EXPENSE_PAID = 'expense_paid';
	public const EXPENSE_REVERSED = 'expense_reversed';
	/** Hand-over to an accounting system that issues the invoice (0.24.0) and its revocation; `invoice` is 0. */
	public const EXTERNAL_ISSUED = 'external_issued';
	public const EXTERNAL_REVOKED = 'external_revoked';
	/** 0.24.1: the accounting system's PDF stored in the document tree, and its cancellation there; `invoice` is 0. */
	public const EXTERNAL_DOCUMENT = 'external_document';
	public const EXTERNAL_CANCELLED = 'external_cancelled';
	public const HASH_PATTERN = '/^[0-9a-f]{64}$/';

	/** `at` of an entry: UTC, `YYYY-MM-DDTHH:MM:SSZ`. */
	public static function at(int $ts): string {
		return gmdate('Y-m-d\TH:i:s\Z', $ts);
	}

	/** The canonical JSON of a value (see the class comment). */
	public static function canonical(mixed $value): string {
		return json_encode(self::sorted($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
	}

	private static function sorted(mixed $v): mixed {
		if ($v instanceof \stdClass) {
			$v = get_object_vars($v);
			if ($v === []) {
				return new \stdClass();
			}
		} elseif (!is_array($v)) {
			return $v;
		} elseif (array_is_list($v)) {
			return array_map(static fn (mixed $e): mixed => self::sorted($e), $v);
		}
		ksort($v, SORT_STRING);
		$o = new \stdClass();
		foreach ($v as $k => $e) {
			$o->{(string)$k} = self::sorted($e);
		}
		return $o;
	}

	/** SHA-256 of the canonical payload. */
	public static function payloadHash(string $canonical): string {
		return hash('sha256', $canonical);
	}

	/**
	 * The entry's hash: SHA-256 of
	 * `seq "\n" at "\n" actor "\n" action "\n" number "\n" payload_hash "\n" prev_hash`
	 * (`prev_hash` empty for the first entry of a team).
	 */
	public static function entryHash(int $seq, string $at, string $actor, string $action, string $number, string $payloadHash, string $prevHash): string {
		return hash('sha256', implode("\n", [(string)$seq, $at, $actor, $action, $number, $payloadHash, $prevHash]));
	}

	/**
	 * Recomputes a team's chain in order. Every entry's `prev_hash` must be
	 * the previous entry's `hash`, `seq` must go on without a gap, the
	 * payload (when given) must hash to `payload_hash`, and the entry hash
	 * must match. The first break names the field and both values.
	 *
	 * @param iterable<array{seq:int,at:string,actor:string,action:string,number:string,payload?:?string,payload_hash:string,prev_hash:string,hash:string,...}> $entries
	 * @return array{ok:true,entries:int,head:?string}|array{ok:false,entries:int,first_break:int,field:string,expected:string,found:string}
	 */
	public static function verify(iterable $entries): array {
		$n = 0;
		$prev = '';
		$head = null;
		$next = null;
		foreach ($entries as $e) {
			$n++;
			$seq = $e['seq'];
			if ($next !== null && $seq !== $next) {
				return self::broken($n, $seq, 'seq', (string)$next, (string)$seq);
			}
			if ($e['prev_hash'] !== $prev) {
				return self::broken($n, $seq, 'prev_hash', $prev, $e['prev_hash']);
			}
			$payload = $e['payload'] ?? null;
			if (is_string($payload)) {
				$ph = self::payloadHash($payload);
				if ($ph !== $e['payload_hash']) {
					return self::broken($n, $seq, 'payload_hash', $ph, $e['payload_hash']);
				}
			}
			$h = self::entryHash($seq, $e['at'], $e['actor'], $e['action'], $e['number'], $e['payload_hash'], $prev);
			if ($h !== $e['hash']) {
				return self::broken($n, $seq, 'hash', $h, $e['hash']);
			}
			$prev = $h;
			$head = $h;
			$next = $seq + 1;
		}
		return ['ok' => true, 'entries' => $n, 'head' => $head];
	}

	/** @return array{ok:false,entries:int,first_break:int,field:string,expected:string,found:string} */
	private static function broken(int $n, int $seq, string $field, string $expected, string $found): array {
		return ['ok' => false, 'entries' => $n, 'first_break' => $seq, 'field' => $field, 'expected' => $expected, 'found' => $found];
	}
}
