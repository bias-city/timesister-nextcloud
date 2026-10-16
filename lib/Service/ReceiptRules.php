<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The receipt (0.22.0, record `receipt`, B3), pure: content and defaults,
 * the fields the server owns, the status, who reads and writes it and
 * what may change after approval.
 */
final class ReceiptRules {
	public const KIND = 'receipt';
	public const KEY_PATTERN = '/^rc-[A-Za-z0-9._+@-]{1,125}$/';
	public const RECEIPT = 'receipt';
	public const TRUST = 'trust';
	public const RECURRING = 'recurring';
	public const KINDS = [self::RECEIPT, self::TRUST, self::RECURRING];
	public const PERSON = 'person';
	public const COMPANY = 'company';
	public const CUSTOMER = 'customer';
	public const PAID_BY = [self::PERSON, self::COMPANY, self::CUSTOMER];
	public const CHARGE = 'charge';
	public const SHOW_ONLY = 'show_only';
	public const NONE = 'none';
	public const EFFECTS = [self::CHARGE, self::SHOW_ONLY, self::NONE];
	public const OPEN = 'open';
	public const APPROVED = 'approved';
	public const REJECTED = 'rejected';
	public const INTERNAL = 'internal';
	public const BILLED = 'billed';
	/** Owned by the server: ignored on write, the stored value is kept. */
	public const SERVER = ['status', 'file', 'invoice', 'report', 'created_by', 'created_on', 'approved_by', 'approved_on', 'reject_reason'];
	/** What a Lead or Team Admin may still change after approval; after billing only the note. */
	public const CORRECTABLE = ['project', 'cost_kind', 'budget', 'cost', 'invoice_effect', 'note'];
	/** Set only by Team Admins and the project's Leads; a member's write keeps the stored values (member triage). */
	public const ASSIGNED = ['budget', 'cost', 'invoice_effect'];
	public const SUPPLIER_MAX = 120;
	public const TEXT_MAX = 300;
	public const NOTE_MAX = 300;
	public const UID_MAX = 64;
	public const BUDGET_MAX = 128;
	/** A file: PDF, JPEG or PNG of at most 20 MB. */
	public const FILE_MAX = 20 * 1024 * 1024;
	public const PERIOD_PATTERN = '/^\d{4}(-(0[1-9]|1[0-2])|-Q[1-4])?$/';
	private const CONTROL = '/[\x00-\x1F\x7F]/';
	private const CONTROL_NOTE = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/';

	// ------------------------------------------------------------ content

	/** Content and defaults (`reimburse` false, `invoice_effect` by who paid); the server's fields are not checked here. */
	public static function validate(string $key, \stdClass $d): void {
		if (preg_match(self::KEY_PATTERN, $key) !== 1) {
			throw ApiException::invalid('The key of a receipt is “rc-” and up to 125 characters.');
		}
		self::schema($d);
		if (!in_array($d->kind ?? null, self::KINDS, true)) {
			throw self::oneOf('kind', self::KINDS);
		}
		$date = $d->date ?? null;
		if (!is_string($date) || !Time::isDay($date)) {
			throw ApiException::invalid(Message::of('“{field}” must be a day in the format YYYY-MM-DD.', ['field' => 'date']));
		}
		self::text($d, 'supplier', self::SUPPLIER_MAX, self::CONTROL);
		self::text($d, 'text', self::TEXT_MAX, self::CONTROL_NOTE);
		self::text($d, 'note', self::NOTE_MAX, self::CONTROL_NOTE);
		self::amounts($d);
		ReceiptRecognition::validate($d);
		$c = $d->currency ?? null;
		if ($c !== null) {
			if (!is_string($c) || preg_match(ProjectRules::CURRENCY_PATTERN, $c) !== 1) {
				throw ApiException::invalid('“currency” must be three letters, for example CHF.');
			}
			$d->currency = strtoupper($c);
		}
		ReceiptBooking::validate($d);
		self::position($d);
		self::payer($d);
		self::recurring($d);
	}

	private static function schema(\stdClass $d): void {
		$s = $d->schema ?? null;
		if ($s !== null && (!is_int($s) || $s < 1 || $s > 1000)) {
			throw ApiException::invalidField('schema');
		}
	}

	/** `net + vat = gross` within 0.01; with a rate also `vat = round(net × rate / 100, 2)` within 0.01. */
	private static function amounts(\stdClass $d): void {
		foreach (['net', 'vat', 'gross'] as $f) {
			if (!self::isNumber($d->{$f} ?? null)) {
				throw ApiException::invalid(Message::of('“{field}” must be a number.', ['field' => $f]));
			}
		}
		$rate = $d->vat_rate ?? null;
		if ($rate !== null && ReceiptRecognition::hasLines($d)) {
			throw ApiException::invalid('A receipt with “vat_lines” has no single “vat_rate”: it is null.');
		}
		if ($rate !== null && !(ProjectRules::isRate($rate) && $rate <= 100)) {
			throw ApiException::invalid(Message::of('“{field}” must be null or a percentage from 0 to 100.', ['field' => 'vat_rate']));
		}
		$net = (float)$d->net;
		$vat = (float)$d->vat;
		if (abs($net + $vat - (float)$d->gross) > 0.0101) {
			throw ApiException::invalid('“net” plus “vat” must equal “gross”.');
		}
		if ($rate !== null && abs($vat - round($net * (float)$rate / 100.0, 2)) > 0.0101) {
			throw ApiException::invalid('“vat” must be “net” × “vat_rate” / 100, rounded to cents.');
		}
	}

	/**
	 * `project`, `cost_kind`, `budget`, `cost`: all null (a general cost),
	 * or a project with a cost kind or none, and budget and cost position
	 * both null (triaged, not yet assigned) or both set.
	 */
	private static function position(\stdClass $d): void {
		$p = $d->project ?? null;
		$b = $d->budget ?? null;
		$c = $d->cost ?? null;
		$k = $d->cost_kind ?? null;
		if ($k !== null && !in_array($k, CostRules::KINDS, true)) {
			throw self::oneOf('cost_kind', CostRules::KINDS);
		}
		if ($p === null) {
			if ($b !== null || $c !== null) {
				throw ApiException::invalid('“project”, “budget” and “cost” are all null or name a project, one of its budgets and a cost position of that budget.');
			}
			if ($k !== null) {
				throw ApiException::invalid('A receipt without a project has no “cost_kind”.');
			}
			return;
		}
		if (!is_string($p) || !RecordValidator::isKey($p)) {
			throw ApiException::invalid('“project” must be the key of a project.');
		}
		if ($b === null && $c === null) {
			return;
		}
		if (!is_string($b) || $b === '' || mb_strlen($b) > self::BUDGET_MAX || preg_match(self::CONTROL, $b) || !is_int($c) || $c < 1) {
			throw ApiException::invalid('“budget” and “cost” are both null or name one of the project’s budgets and a cost position of that budget.');
		}
	}

	/** Who paid, the person, reimbursement and the effect on the invoice with their defaults. */
	private static function payer(\stdClass $d): void {
		$by = $d->paid_by ?? null;
		if (!in_array($by, self::PAID_BY, true)) {
			throw self::oneOf('paid_by', self::PAID_BY);
		}
		$person = $d->person ?? null;
		if ($by === self::PERSON) {
			if (!is_string($person) || $person === '' || mb_strlen($person) > self::UID_MAX || preg_match(self::CONTROL, $person)) {
				throw ApiException::invalid('“person” is required when a person paid: the account’s user ID.');
			}
		} elseif ($person !== null) {
			throw ApiException::invalid('“person” is only set when a person paid.');
		}
		$d->person = $person;
		$re = $d->reimburse ?? false;
		if (!is_bool($re)) {
			throw ApiException::notBool('reimburse');
		}
		if ($re && $by !== self::PERSON) {
			throw ApiException::invalid('Only what a person paid is reimbursed.');
		}
		$d->reimburse = $re;
		$effect = $d->invoice_effect ?? null;
		if ($effect === null) {
			$effect = self::defaultEffect($d);
		}
		if (!in_array($effect, self::EFFECTS, true)) {
			throw self::oneOf('invoice_effect', self::EFFECTS);
		}
		if (($d->project ?? null) === null && $effect !== self::NONE) {
			throw ApiException::invalid('A receipt without a project is never on an invoice: “invoice_effect” is “none”.');
		}
		$d->invoice_effect = $effect;
	}

	/** The effect on the invoice when none is given: none without project, show only when the customer paid, else charge. */
	public static function defaultEffect(\stdClass $d): string {
		if (($d->project ?? null) === null) {
			return self::NONE;
		}
		return ($d->paid_by ?? null) === self::CUSTOMER ? self::SHOW_ONLY : self::CHARGE;
	}

	/** `rule` and `period` only on a recurring receipt, and there both. */
	private static function recurring(\stdClass $d): void {
		$rule = $d->rule ?? null;
		$period = $d->period ?? null;
		if (($d->kind ?? null) !== self::RECURRING) {
			if ($rule !== null || $period !== null) {
				throw ApiException::invalid('“rule” and “period” belong only to a recurring receipt.');
			}
			return;
		}
		if (!is_string($rule) || !RecordValidator::isKey($rule) || !is_string($period) || preg_match(self::PERIOD_PATTERN, $period) !== 1) {
			throw ApiException::invalid('A recurring receipt names its “rule” and its “period” (YYYY-MM, YYYY-Qn or YYYY).');
		}
	}

	// ------------------------------------------------------------- status

	/** The status of a new receipt: open; a recurring one of an approved rule approved at once (general: internal). */
	public static function initialStatus(\stdClass $d, bool $ruleApproved): string {
		if (($d->kind ?? null) === self::RECURRING && $ruleApproved) {
			return self::approvedStatus($d);
		}
		return self::OPEN;
	}

	/** Approved with a project, internal without. */
	public static function approvedStatus(\stdClass $d): string {
		return ($d->project ?? null) === null ? self::INTERNAL : self::APPROVED;
	}

	/** Approved in any form: approved, internal, billed. */
	public static function isApproved(?\stdClass $d): bool {
		return in_array($d?->status ?? null, [self::APPROVED, self::INTERNAL, self::BILLED], true);
	}

	/**
	 * The server's fields over a client's write: kept from the stored
	 * record, set on a new one. A write to a rejected receipt opens it
	 * again; a correction of an approved one moves it between approved
	 * (project) and internal (none).
	 */
	public static function mergeServer(?\stdClass $before, \stdClass $after, string $uid, string $today, string $initial): void {
		foreach (self::SERVER as $f) {
			$after->{$f} = $before?->{$f} ?? null;
		}
		if ($before === null) {
			$after->status = $initial;
			$after->created_by = $uid;
			$after->created_on = $today;
			if ($initial !== self::OPEN) {
				$after->approved_by = $uid;
				$after->approved_on = $today;
			}
			return;
		}
		if ($after->status === self::REJECTED) {
			$after->status = self::OPEN;
			$after->reject_reason = null;
		} elseif (in_array($after->status, [self::APPROVED, self::INTERNAL], true)) {
			$after->status = self::approvedStatus($after);
		}
	}

	// ------------------------------------------------------------- triage

	/**
	 * A member's write (not a Team Admin, not a Lead of the receipt's
	 * project): budget, cost position and invoice effect stay as stored
	 * while project and cost kind stay; otherwise budget and cost are
	 * cleared and the effect is the default. No error.
	 */
	public static function keepAssigned(?\stdClass $before, \stdClass $after): void {
		$project = $after->project ?? null;
		$same = $before !== null && $project !== null && ($before->project ?? null) === $project;
		$keep = $same && ($before->cost_kind ?? null) === ($after->cost_kind ?? null);
		$after->budget = $keep ? ($before->budget ?? null) : null;
		$after->cost = $keep ? ($before->cost ?? null) : null;
		$effect = $same ? ($before->invoice_effect ?? null) : null;
		$after->invoice_effect = in_array($effect, self::EFFECTS, true) && ($project !== null || $effect === self::NONE) ? $effect : self::defaultEffect($after);
	}

	/** The kind of a budget's cost position, null when the budget has none such. */
	public static function costKind(\stdClass $project, string $budgetId, int $cost): ?string {
		foreach (Fields::list(IssueRules::budgetOf($project, $budgetId), 'costs') as $c) {
			if ($c instanceof \stdClass && ($c->no ?? null) === $cost) {
				return is_string($c->kind ?? null) ? $c->kind : null;
			}
		}
		return null;
	}

	/**
	 * The assignment against the project: the budget carries the cost
	 * position, and its kind is the receipt's cost kind when one is set.
	 */
	public static function requireCost(\stdClass $d, \stdClass $project): void {
		$b = $d->budget ?? null;
		$c = $d->cost ?? null;
		if (!is_string($b) || !is_int($c)) {
			return;
		}
		if (!self::hasCost($project, $b, $c)) {
			throw ApiException::invalid('The budget of the project has no such cost position.');
		}
		$kind = $d->cost_kind ?? null;
		$of = self::costKind($project, $b, $c);
		if ($kind !== null && $of !== null && $of !== $kind) {
			throw ApiException::invalid(Message::of('The cost position {no} is not of the receipt’s kind “{kind}”.', ['no' => $c, 'kind' => (string)$kind]));
		}
	}

	/** Before approval a receipt of a project is assigned to a budget and a cost position (of its kind). */
	public static function requireApprovable(\stdClass $d, ?\stdClass $project): void {
		if (($d->project ?? null) === null) {
			return;
		}
		if ($project === null) {
			throw ApiException::invalid('The receipt’s project does not exist.');
		}
		if (!is_string($d->budget ?? null) || !is_int($d->cost ?? null)) {
			throw ApiException::invalid('Before approval a Lead or Team Admin assigns the receipt to a budget and a cost position.');
		}
		self::requireCost($d, $project);
	}

	// ------------------------------------------------------------- rights

	/** Created by the caller or paid by them. */
	public static function isOwn(?\stdClass $d, string $uid): bool {
		return $d !== null && (($d->created_by ?? null) === $uid || ($d->person ?? null) === $uid);
	}

	/** @param array<string,true> $led */
	public static function ofLed(?\stdClass $d, array $led): bool {
		$p = $d?->project ?? null;
		return is_string($p) && isset($led[$p]);
	}

	/** Team Admins all; a Lead those of the projects they lead and their own; a member their own. @param array<string,true> $led */
	public static function canRead(bool $manages, string $uid, array $led, ?\stdClass $d): bool {
		return $manages || self::isOwn($d, $uid) || self::ofLed($d, $led);
	}

	/**
	 * A client's write against the stored receipt (the server's fields
	 * already merged into `$after`). Open or rejected: whoever reads it
	 * before and after. Approved: Team Admins, or a Lead of the project
	 * before and after, and only the correctable fields; billed: only the
	 * note. Deleting only while open or rejected.
	 *
	 * @param array<string,true> $led
	 */
	public static function requireWrite(bool $manages, string $uid, array $led, ?\stdClass $before, ?\stdClass $after): void {
		if ($before !== null && self::isApproved($before)) {
			if ($after === null) {
				throw ApiException::conflict('An approved receipt is not deleted.');
			}
			if (!$manages && !(self::ofLed($before, $led) && self::ofLed($after, $led))) {
				throw ApiException::forbidden('After approval only Team Admins and the project’s Leads change a receipt.');
			}
			$allowed = ($before->status ?? null) === self::BILLED ? ['note'] : self::CORRECTABLE;
			foreach (self::changed($before, $after) as $f) {
				if (in_array($f, $allowed, true)) {
					continue;
				}
				if (($before->status ?? null) === self::BILLED) {
					throw ApiException::conflict(Message::of('A billed receipt changes only in “note”; “{field}” stays.', ['field' => $f]));
				}
				throw ApiException::conflict(Message::of('An approved receipt changes only in project, cost kind, budget, cost, invoice effect and note; “{field}” stays.', ['field' => $f]));
			}
			if (($after->project ?? null) !== null && (!is_string($after->budget ?? null) || !is_int($after->cost ?? null))) {
				throw ApiException::invalid('An approved receipt of a project keeps a budget and a cost position.');
			}
			return;
		}
		if ($after !== null) {
			ReceiptRecognition::requireClear($manages, $uid, $led, $before, $after);
		}
		if ($manages) {
			return;
		}
		foreach ([$before, $after] as $d) {
			if ($d !== null && !self::canRead(false, $uid, $led, $d)) {
				throw ApiException::forbidden('Members write only the receipts they created or paid; Leads also those of their projects.');
			}
		}
	}

	/**
	 * The client's fields that differ (the server's are not compared).
	 *
	 * @return list<string>
	 */
	public static function changed(\stdClass $before, \stdClass $after): array {
		$out = [];
		$keys = array_unique([...array_keys(get_object_vars($before)), ...array_keys(get_object_vars($after))]);
		foreach ($keys as $k) {
			if (in_array($k, self::SERVER, true)) {
				continue;
			}
			if (Json::encode([ReceiptRecognition::value($before, $k)]) !== Json::encode([ReceiptRecognition::value($after, $k)])) {
				$out[] = $k;
			}
		}
		return $out;
	}

	// ------------------------------------------------------------ helpers

	/** The receipt's project code for its file name: the project's first code, else its key. */
	public static function projectCode(?\stdClass $project, string $key): string {
		$codes = $project?->codes ?? null;
		if (is_array($codes) && is_string($codes[0] ?? null) && $codes[0] !== '') {
			return $codes[0];
		}
		return $key;
	}

	/** Does the budget of the project carry this cost position? */
	public static function hasCost(\stdClass $project, string $budgetId, int $cost): bool {
		foreach (Fields::list(IssueRules::budgetOf($project, $budgetId), 'costs') as $c) {
			if ($c instanceof \stdClass && ($c->no ?? null) === $cost) {
				return true;
			}
		}
		return false;
	}

	/** PDF, JPEG or PNG by the file's first bytes; null for anything else. */
	public static function sniff(string $content): ?string {
		return match (true) {
			str_starts_with($content, '%PDF-') => 'application/pdf',
			str_starts_with($content, "\xFF\xD8\xFF") => 'image/jpeg',
			str_starts_with($content, "\x89PNG\r\n\x1A\n") => 'image/png',
			default => null,
		};
	}

	public static function isNumber(mixed $v): bool {
		return is_int($v) || (is_float($v) && is_finite($v));
	}

	/** @param non-empty-string $control */
	private static function text(\stdClass $d, string $field, int $max, string $control): void {
		$v = $d->{$field} ?? null;
		if ($v !== null && (!is_string($v) || mb_strlen($v) > $max || preg_match($control, $v) || !mb_check_encoding($v, 'UTF-8'))) {
			throw ApiException::invalid(Message::of('“{field}” must be text of at most {max} characters.', ['field' => $field, 'max' => $max]));
		}
	}

	/** @param list<string> $values */
	public static function oneOf(string $field, array $values): ApiException {
		return ApiException::invalid(Message::of('“{field}” must be one of {values}.', ['field' => $field, 'values' => implode(', ', $values)]));
	}

	public static function decode(?string $raw): ?\stdClass {
		return InvoiceRules::decode($raw);
	}
}
