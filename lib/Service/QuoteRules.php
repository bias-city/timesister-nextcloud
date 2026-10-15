<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The quote (0.16.0, capability `quotes: 2`): a record of kind `quote`
 * holding a budget before anyone accepted it, optionally assigned to a
 * project. On acceptance the client writes the budget into the project and
 * sets the quote `accepted` – two ordinary record writes. Pure: content and
 * the Lead's rule.
 *
 * Team Admins read and write every quote. A Lead reads a quote whose
 * `leads` name them (as a project's `leads` do) or that belongs to a project
 * they lead, and writes only such quotes – before and after the write.
 * Members see none.
 */
final class QuoteRules {
	public const KIND = 'quote';
	public const ACCEPTED = 'accepted';
	public const STATUSES = ['draft', 'quoted', self::ACCEPTED, 'declined'];
	public const KEY_PATTERN = '/^q-[0-9a-f]{8}$/';
	/** A budget with many work packages may need more than the usual 256 KB. */
	public const MAX_BYTES = 786432;
	public const NAME_MAX = 200;
	public const NUMBER_MAX = 64;
	public const MAX_LEADS = 20;
	/** An account ID or a person key, as in a project's `leads`. */
	public const LEAD_MAX = 128;
	public const CONTACT_MAX = 200;
	public const NOTE_MAX = 2000;
	public const PDF_MAX = 2000;
	public const BUDGET_ID_MAX = 128;
	public const BY_MAX = 64;
	public const DAYS = ['date', 'valid_until', 'accepted_on', 'created_on'];

	private const CONTROL = '/[\x00-\x1F\x7F]/';
	private const CONTROL_NOTE = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/';
	/** http(s), a host part, no spaces or control characters. */
	private const PDF_URL = '~^https?://[^\s/\x00-\x1F\x7F][^\s\x00-\x1F\x7F]*$~iu';

	/** Key and content; other fields pass and are stored as sent, as for invoices. */
	public static function validate(string $key, \stdClass $d): void {
		if (preg_match(self::KEY_PATTERN, $key) !== 1) {
			throw ApiException::invalid('The key of a quote is “q-” and 8 lowercase hex digits.');
		}
		$s = $d->schema ?? null;
		if ($s !== null && (!is_int($s) || $s < 1 || $s > 1000)) {
			throw ApiException::invalidField('schema');
		}
		$name = $d->name ?? null;
		if (!is_string($name) || $name === '' || mb_strlen($name) > self::NAME_MAX || preg_match(self::CONTROL, $name) || !mb_check_encoding($name, 'UTF-8')) {
			throw ApiException::invalid(Message::of('“{field}” must be text of 1 to {max} characters.', ['field' => 'name', 'max' => self::NAME_MAX]));
		}
		$status = $d->status ?? null;
		if (!in_array($status, self::STATUSES, true)) {
			throw ApiException::invalid(Message::of('“status” of a quote is one of {statuses}.', ['statuses' => implode(', ', self::STATUSES)]));
		}
		$customer = $d->customer ?? null;
		if ($customer !== null && (!is_string($customer) || !RecordValidator::isKey($customer))) {
			throw ApiException::invalid('“customer” must be null or the key of a customer.');
		}
		$project = $d->project ?? null;
		if ($project !== null && (!is_string($project) || !RecordValidator::isKey($project))) {
			throw ApiException::invalid('“project” must be null or the key of a project.');
		}
		if ($status === self::ACCEPTED && $project === null) {
			throw ApiException::invalid('An accepted quote needs “project”: the project that took over its budget.');
		}
		self::nullableText($d->number ?? null, 'number', self::NUMBER_MAX);
		foreach (self::DAYS as $f) {
			$v = $d->{$f} ?? null;
			if ($v !== null && (!is_string($v) || !Time::isDay($v))) {
				throw ApiException::invalid(Message::of('“{field}” must be a day in the format YYYY-MM-DD.', ['field' => $f]));
			}
		}
		self::leads($d->leads ?? null);
		self::text($d->contact ?? null, 'contact', self::CONTACT_MAX, self::CONTROL);
		self::text($d->note ?? null, 'note', self::NOTE_MAX, self::CONTROL_NOTE);
		$pdf = $d->pdf ?? null;
		if ($pdf !== null && (!is_string($pdf) || mb_strlen($pdf) > self::PDF_MAX || preg_match(self::PDF_URL, $pdf) !== 1)) {
			throw ApiException::invalid(Message::of('“pdf” must be null or an http:// or https:// address of at most {max} characters.', ['max' => self::PDF_MAX]));
		}
		$budget = $d->budget ?? null;
		if ($budget !== null) {
			$id = $budget instanceof \stdClass ? ($budget->id ?? null) : null;
			if (!is_string($id) || trim($id) === '' || mb_strlen($id) > self::BUDGET_ID_MAX || preg_match(self::CONTROL, $id) || !mb_check_encoding($id, 'UTF-8')) {
				throw ApiException::invalid(Message::of('“budget” must be null or an object with an “id” of 1 to {max} characters.', ['max' => self::BUDGET_ID_MAX]));
			}
			// The same rules as a project's budget: currency, cap, rates.
			ProjectRules::checkBudget($budget);
		}
		self::nullableText($d->budget_id ?? null, 'budget_id', self::BUDGET_ID_MAX);
		// Frozen at acceptance: hours, amount and currency.
		foreach (['hours', 'amount'] as $f) {
			$v = $d->{$f} ?? null;
			if ($v !== null && !ProjectRules::isRate($v)) {
				throw ApiException::invalid(Message::of('“{field}” must be null or a number ≥ 0.', ['field' => $f]));
			}
		}
		$c = $d->currency ?? null;
		if ($c !== null) {
			if (!is_string($c) || preg_match(ProjectRules::CURRENCY_PATTERN, $c) !== 1) {
				throw ApiException::invalid('“currency” must be three letters, for example CHF.');
			}
			$d->currency = strtoupper($c);
		}
		self::text($d->created_by ?? null, 'created_by', self::BY_MAX, self::CONTROL);
	}

	/** Missing, null or a list of at most 20 accounts or person keys. */
	private static function leads(mixed $v): void {
		if ($v === null) {
			return;
		}
		$ok = is_array($v) && array_is_list($v) && count($v) <= self::MAX_LEADS;
		foreach ($ok ? $v : [] as $a) {
			if (!is_string($a) || $a === '' || mb_strlen($a) > self::LEAD_MAX || preg_match(self::CONTROL, $a) || !mb_check_encoding($a, 'UTF-8')) {
				$ok = false;
				break;
			}
		}
		if (!$ok) {
			throw ApiException::invalid(Message::of('“leads” must be a list of at most {max} account IDs or person keys.', ['max' => self::MAX_LEADS]));
		}
	}

	private static function nullableText(mixed $v, string $field, int $max): void {
		if ($v !== null && (!is_string($v) || $v === '' || mb_strlen($v) > $max || preg_match(self::CONTROL, $v) || !mb_check_encoding($v, 'UTF-8'))) {
			throw ApiException::invalid(Message::of('“{field}” must be null or text of 1 to {max} characters.', ['field' => $field, 'max' => $max]));
		}
	}

	/** @param non-empty-string $control */
	private static function text(mixed $v, string $field, int $max, string $control): void {
		if ($v !== null && (!is_string($v) || mb_strlen($v) > $max || preg_match($control, $v) || !mb_check_encoding($v, 'UTF-8'))) {
			throw ApiException::invalid(Message::of('“{field}” must be text of at most {max} characters.', ['field' => $field, 'max' => $max]));
		}
	}

	/**
	 * May this Lead read the quote: `leads` names them the way a project's
	 * `leads` does ({@see ProjectAccess::isLead()}: the account, or a person
	 * with it in `accounts`), or its `project` is one they lead. Null data
	 * (none stored): no.
	 *
	 * @param list<string> $ownKeys the caller's person keys
	 * @param array<string,true> $led the active projects the caller leads
	 */
	public static function leadReads(?\stdClass $d, array $ownKeys, array $led): bool {
		if ($d === null) {
			return false;
		}
		if (ProjectAccess::isLead($d, $ownKeys)) {
			return true;
		}
		$p = $d->project ?? null;
		return is_string($p) && isset($led[$p]);
	}

	/**
	 * Does the stored quote name one of these keys in `leads`?
	 *
	 * @param array<string,true> $keys
	 */
	public static function namesAny(?string $raw, array $keys): bool {
		return ProjectAccess::isLead(InvoiceRules::decode($raw), array_map('strval', array_keys($keys)));
	}

	/**
	 * A Lead's write: the stored quote (`$before`, null when new) and the
	 * new one (`$after`, null when deleted) must both be readable by them
	 * ({@see leadReads()}). Otherwise 403.
	 *
	 * @param list<string> $ownKeys the caller's person keys
	 * @param array<string,true> $led the active projects the caller leads
	 */
	public static function requireLeadWrite(?\stdClass $before, ?\stdClass $after, array $ownKeys, array $led): void {
		foreach ([$before, $after] as $d) {
			if ($d !== null && !self::leadReads($d, $ownKeys, $led)) {
				throw ApiException::forbidden('Only Team Admins and the quote’s or project’s Leads write quotes.');
			}
		}
	}
}
