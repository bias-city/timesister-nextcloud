<?php

declare(strict_types=1);

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\Record;

/**
 * The progress log of a work package (0.27.0, `progress_log`): per day and
 * person how far the package is (`done`, a whole percent) and the remaining
 * effort in hours (`remaining`). Entries are never overwritten – the newest
 * up to a reference date counts. The server checks the list on write and,
 * for a project record, joins the stored entries into the incoming ones:
 * what one client wrote, another cannot drop. An old client that sends only
 * `progress` gets its change logged as an entry of its own.
 */
final class ProgressLog {
	public const FIELD = 'progress_log';
	public const MAX = 500;
	public const BY_MAX = 100;

	/**
	 * `progress_log` null or a list of at most 500 objects, each a day `date`,
	 * `done` null or a whole percent 0–100, `remaining` null or a number ≥ 0
	 * (at least one of both) and `by` a text of at most 100 characters.
	 * Stored trimmed and ordered by date.
	 *
	 * @throws ApiException 422
	 */
	public static function check(\stdClass $wp): void {
		if (!property_exists($wp, self::FIELD) || $wp->{self::FIELD} === null) {
			return;
		}
		$log = $wp->{self::FIELD};
		if (!is_array($log) || !array_is_list($log) || count($log) > self::MAX) {
			throw ApiException::invalid(Message::of('“progress_log” must be null or a list of at most {max} entries.', ['max' => self::MAX]));
		}
		$out = [];
		foreach ($log as $e) {
			if (!($e instanceof \stdClass) || !is_string($e->date ?? null) || !Time::isDay(trim($e->date))) {
				throw ApiException::invalid('Each “progress_log” entry needs a day “date” (YYYY-MM-DD).');
			}
			$done = $e->done ?? null;
			if ($done !== null && (!is_int($done) || $done < 0 || $done > 100)) {
				throw ApiException::invalid('“done” must be null or a whole number from 0 to 100.');
			}
			$rem = $e->remaining ?? null;
			if ($rem !== null && (!is_int($rem) && !is_float($rem) || !is_finite((float)$rem) || (float)$rem < 0)) {
				throw ApiException::invalid('“remaining” must be null or a number ≥ 0.');
			}
			if ($done === null && $rem === null) {
				throw ApiException::invalid('A “progress_log” entry needs “done” or “remaining”.');
			}
			$by = $e->by ?? '';
			if (!is_string($by) || mb_strlen($by) > self::BY_MAX || preg_match('/[\x00-\x1F\x7F]/', $by) === 1) {
				throw ApiException::invalid(Message::of('“by” must be a text of at most {max} characters.', ['max' => self::BY_MAX]));
			}
			$n = new \stdClass();
			$n->date = trim($e->date);
			if ($done !== null) {
				$n->done = $done;
			}
			if ($rem !== null) {
				$n->remaining = $rem;
			}
			$n->by = trim($by);
			$out[] = $n;
		}
		usort($out, static fn (\stdClass $a, \stdClass $b): int => strcmp($a->date, $b->date));
		$wp->{self::FIELD} = $out;
	}

	/** The newest `done` of a log, or null. */
	private static function latestDone(array $log): ?int {
		$best = null;
		foreach ($log as $e) {
			if ($e instanceof \stdClass && isset($e->done) && ($best === null || strcmp((string)$e->date, (string)$best->date) >= 0)) {
				$best = $e;
			}
		}
		return $best?->done;
	}

	/**
	 * Join the stored logs of a project's work packages into the incoming
	 * record (budgets by `id`, packages by `no`): stored entries the incoming
	 * list lacks (same `date` and `by`) are added back; an incoming package
	 * without the field keeps the stored log, and a changed bare `progress`
	 * becomes an entry of today by the caller.
	 */
	public static function merge(?Record $cur, ?string $json, string $uid, string $today): ?string {
		if ($json === null || $cur === null || $cur->isTombstone()) {
			return $json;
		}
		$raw = $cur->getData();
		$old = $raw === null ? null : Json::decode($raw);
		$new = Json::decode($json);
		if (!($old instanceof \stdClass) || !($new instanceof \stdClass) || !is_array($new->budgets ?? null) || !is_array($old->budgets ?? null)) {
			return $json;
		}
		$changed = false;
		foreach ($new->budgets as $nb) {
			if (!($nb instanceof \stdClass) || !is_array($nb->work_packages ?? null)) {
				continue;
			}
			$ob = self::find($old->budgets, 'id', (string)($nb->id ?? ''));
			if ($ob === null || !is_array($ob->work_packages ?? null)) {
				continue;
			}
			foreach ($nb->work_packages as $nw) {
				if (!($nw instanceof \stdClass)) {
					continue;
				}
				$ow = self::find($ob->work_packages, 'no', trim((string)($nw->no ?? '')));
				$rawStored = $ow?->{self::FIELD} ?? null;
				$stored = is_array($rawStored) ? array_values($rawStored) : [];
				$rawLog = property_exists($nw, self::FIELD) ? $nw->{self::FIELD} : null;
				if (is_array($rawLog)) {
					$log = array_values($rawLog);
					foreach ($stored as $e) {
						if ($e instanceof \stdClass && !self::has($log, $e)) {
							$log[] = $e;
							$changed = true;
						}
					}
				} else {
					// An old client: its bare `progress` is logged when it differs from the newest entry.
					$log = $stored;
					$p = $nw->progress ?? null;
					if (is_int($p) && self::latestDone($log) !== $p) {
						$e = new \stdClass();
						$e->date = $today;
						$e->done = $p;
						$e->by = $uid;
						$log[] = $e;
					}
					if ($log !== []) {
						$changed = true;
					}
				}
				if ($log !== []) {
					usort($log, static fn (\stdClass $a, \stdClass $b): int => strcmp((string)$a->date, (string)$b->date));
					$nw->{self::FIELD} = $log;
				}
			}
		}
		return $changed ? Json::encode($new) : $json;
	}

	/** @param list<mixed> $list */
	private static function find(array $list, string $key, string $value): ?\stdClass {
		foreach ($list as $x) {
			if ($x instanceof \stdClass && trim((string)($x->{$key} ?? '')) === $value) {
				return $x;
			}
		}
		return null;
	}

	/** @param list<mixed> $log */
	private static function has(array $log, \stdClass $e): bool {
		foreach ($log as $x) {
			if ($x instanceof \stdClass && (string)($x->date ?? '') === (string)($e->date ?? '') && (string)($x->by ?? '') === (string)($e->by ?? '')) {
				return true;
			}
		}
		return false;
	}
}
