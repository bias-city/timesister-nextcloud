<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The invoice (0.16.0): a record of kind `invoice`, a draft of hours sent
 * to accounting with the keys of its bookings, a status set by hand and the
 * number; from issuing on also the hours, amount and currency as issued.
 * Pure: content and the Lead's rule.
 *
 * Team Admins read and write every invoice. A Lead reads the invoices of
 * the active projects they lead and writes only drafts without a number –
 * before and after the write. Members see none.
 */
final class InvoiceRules {
	public const KIND = 'invoice';
	public const DRAFT = 'draft';
	/** 0.24.0: `external`, handed over to the accounting system that issues it (set by the server only). */
	public const STATUSES = [self::DRAFT, 'issued', 'paid', 'cancelled', HandoverRules::STATUS];
	public const MAX_BOOKINGS = 5000;
	/** 5000 bookings with their event UIDs need more than the usual 256 KB. */
	public const MAX_BYTES = 786432;
	public const PERSON_MAX = 64;
	public const UID_MAX = 1024;
	public const NUMBER_MAX = 64;
	public const BUDGET_MAX = 128;
	public const NOTE_MAX = 2000;
	public const BY_MAX = 64;
	/** 0.18.0: the free texts of the document, the positions, the hours per booking. */
	public const TEXT_MAX = 4000;
	public const MAX_POSITIONS = 500;
	/** 0.21.0: expense positions (a percentage of the hours, a flat amount) and cost lines. */
	/** 0.26.0: an advance from the invoice plan and its deduction in a later invoice (`invoice` = the advance's key). */
	public const ADVANCE = 'advance';
	public const ADVANCE_DEDUCTION = 'advance_deduction';
	public const POSITION_KINDS = ['hours', 'flat', 'discount', 'text', 'expense_percent', 'expense_flat', 'costs', ReceiptLineRules::MARKUP, self::ADVANCE, self::ADVANCE_DEDUCTION];
	public const POSITION_TEXT_MAX = 500;
	public const CODE_MAX = 64;
	/** 0.27.0 (controlling): the dunning level and the payments (partial payments allowed). */
	public const DUNNING_MAX = 3;
	public const PAYMENTS_MAX = 200;
	public const PAYMENT_NOTE_MAX = 500;
	/**
	 * 0.27.0: the approval mark on a draft. A Lead submits the draft
	 * (`requested`, with `by`, `at`, `note`); `changed_at` says it changed
	 * after that; a Team Admin returns it (`returned`, with `returned_by`,
	 * `returned_at`, `returned_note`). Issuing stays a Team Admin's call.
	 */
	public const REVIEW = 'review';
	public const REVIEW_REQUESTED = 'requested';
	public const REVIEW_RETURNED = 'returned';
	public const REVIEW_STATES = [self::REVIEW_REQUESTED, self::REVIEW_RETURNED];
	private const REVIEW_BY = ['by', 'returned_by'];
	private const REVIEW_AT = ['at', 'changed_at', 'returned_at'];
	private const REVIEW_NOTE = ['note', 'returned_note'];
	private const REVIEW_RETURN = ['returned_by', 'returned_at', 'returned_note'];

	private const CONTROL = '/[\x00-\x1F\x7F]/';
	private const CONTROL_NOTE = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/';
	/** A local time without zone, as the event's start. */
	private const LOCAL_TIME = '/^(\d{4}-\d{2}-\d{2})T([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/';

	/** Content check; other fields pass, as for projects and billing marks. */
	public static function validate(\stdClass $d): void {
		$s = $d->schema ?? null;
		if ($s !== null && (!is_int($s) || $s < 1 || $s > 1000)) {
			throw ApiException::invalidField('schema');
		}
		$project = $d->project ?? null;
		if (!is_string($project) || !RecordValidator::isKey($project)) {
			throw ApiException::invalid('“project” must be the key of a project.');
		}
		// Every invoice belongs to a budget (0.16.0, decided before release).
		$budget = $d->budget ?? null;
		if (!is_string($budget) || trim($budget) === '') {
			throw ApiException::invalid('“budget” is required: every invoice belongs to a budget.');
		}
		self::text($budget, 'budget', self::BUDGET_MAX, self::CONTROL);
		$days = [];
		foreach (['from', 'to'] as $f) {
			$v = $d->{$f} ?? null;
			if (!is_string($v) || !Time::isDay($v)) {
				throw ApiException::invalid(Message::of('“{field}” must be a day in the format YYYY-MM-DD.', ['field' => $f]));
			}
			$days[] = $v;
		}
		if ($days[0] > $days[1]) {
			throw ApiException::invalid('“from” must not be after “to”.');
		}
		if (!in_array($d->status ?? null, self::STATUSES, true)) {
			throw ApiException::invalid(Message::of('“status” of an invoice is one of {statuses}.', ['statuses' => implode(', ', self::STATUSES)]));
		}
		$number = $d->number ?? null;
		if ($number !== null && (!is_string($number) || $number === '' || mb_strlen($number) > self::NUMBER_MAX || preg_match(self::CONTROL, $number))) {
			throw ApiException::invalid(Message::of('“{field}” must be null or text of 1 to {max} characters.', ['field' => 'number', 'max' => self::NUMBER_MAX]));
		}
		self::text($d->note ?? null, 'note', self::NOTE_MAX, self::CONTROL_NOTE);
		self::text($d->created_by ?? null, 'created_by', self::BY_MAX, self::CONTROL);
		foreach (['created_on', 'issued_on', 'paid_on', 'due_on'] as $f) {
			$v = $d->{$f} ?? null;
			if ($v !== null && (!is_string($v) || !Time::isDay($v))) {
				throw ApiException::invalid(Message::of('“{field}” must be a day in the format YYYY-MM-DD.', ['field' => $f]));
			}
		}
		// Frozen when issued: hours, amount and currency as issued (the amount negative on a reversal, 0.18.0).
		$hours = $d->hours ?? null;
		if ($hours !== null && !ProjectRules::isRate($hours)) {
			throw ApiException::invalid(Message::of('“{field}” must be null or a number ≥ 0.', ['field' => 'hours']));
		}
		$amount = $d->amount ?? null;
		if ($amount !== null && !(is_int($amount) || (is_float($amount) && is_finite($amount)))) {
			throw ApiException::invalid(Message::of('“{field}” must be null or a number.', ['field' => 'amount']));
		}
		// VAT in percent, per invoice - the rate can change during a budget.
		$vat = $d->vat ?? null;
		if ($vat !== null && !(ProjectRules::isRate($vat) && $vat <= 100)) {
			throw ApiException::invalid('“vat” must be null or a percentage from 0 to 100.');
		}
		$c = $d->currency ?? null;
		if ($c !== null) {
			if (!is_string($c) || preg_match(ProjectRules::CURRENCY_PATTERN, $c) !== 1) {
				throw ApiException::invalid('“currency” must be three letters, for example CHF.');
			}
			$d->currency = strtoupper($c);
		}
		// 0.18.0: the document's free texts and the positions as the client composed them.
		foreach (['greeting', 'body', 'footer'] as $f) {
			self::text($d->{$f} ?? null, $f, self::TEXT_MAX, self::CONTROL_NOTE);
		}
		if (($d->positions ?? null) !== null) {
			// A reversal or credit note carries the negated lines (0.27.0): the advance signs mirror.
			self::checkPositions($d->positions, ($d->reverses ?? null) !== null || ($d->credits ?? null) !== null);
		}
		// 0.26.0: grouping by level and appended tables.
		if (property_exists($d, InvoiceLayout::FIELD)) {
			$d->{InvoiceLayout::FIELD} = InvoiceLayout::check($d->{InvoiceLayout::FIELD});
		}
		// 0.20.0: the draft may override the tax case.
		TaxRules::checkOverride($d->{TaxRules::FIELD} ?? null);
		// 0.23.1: the hand-over to an accounting system, normalised (Mac plugins; on the server only through POST …/external).
		if (property_exists($d, ExportRules::EXTERNAL_FIELD)) {
			$d->{ExportRules::EXTERNAL_FIELD} = ExportRules::externalBlock($d->{ExportRules::EXTERNAL_FIELD});
		}
		// 0.27.0: the approval mark, normalised to null when absent.
		if (property_exists($d, self::REVIEW)) {
			$d->{self::REVIEW} = self::checkReview($d->{self::REVIEW});
		}
		// 0.27.0 (controlling): dunning level with the day of the last reminder, the payments.
		$dl = $d->dunning_level ?? null;
		if ($dl !== null && (!is_int($dl) || $dl < 0 || $dl > self::DUNNING_MAX)) {
			throw ApiException::invalid(Message::of('“{field}” must be null or an integer from {min} to {max}.', ['field' => 'dunning_level', 'min' => 0, 'max' => self::DUNNING_MAX]));
		}
		$do = $d->dunned_on ?? null;
		if ($do !== null && (!is_string($do) || !Time::isDay($do))) {
			throw ApiException::invalid(Message::of('“{field}” must be a day in the format YYYY-MM-DD.', ['field' => 'dunned_on']));
		}
		if (($d->payments ?? null) !== null) {
			self::checkPayments($d->payments);
		}
		self::bookings($d->bookings ?? null);
	}

	/** `payments`: a list of at most 200 `{ date, amount, note? }` - a day, a number above 0, a note of at most 500 characters. */
	public static function checkPayments(mixed $list): void {
		$bad = fn (): ApiException => ApiException::invalid(Message::of('“payments” must be null or a list of at most {max} payments, each with a day “date”, an “amount” above 0 and an optional “note”.', ['max' => self::PAYMENTS_MAX]));
		if (!is_array($list) || !array_is_list($list) || count($list) > self::PAYMENTS_MAX) {
			throw $bad();
		}
		foreach ($list as $p) {
			if (!($p instanceof \stdClass)) {
				throw $bad();
			}
			$date = $p->date ?? null;
			$amount = $p->amount ?? null;
			if (!is_string($date) || !Time::isDay($date) || !(is_int($amount) || (is_float($amount) && is_finite($amount))) || $amount <= 0) {
				throw $bad();
			}
			$note = $p->note ?? null;
			if ($note !== null && (!is_string($note) || mb_strlen($note) > self::PAYMENT_NOTE_MAX || preg_match(self::CONTROL_NOTE, $note) || !mb_check_encoding($note, 'UTF-8'))) {
				throw $bad();
			}
		}
	}

	/** The sum of the payments of a record (none: 0). */
	public static function paymentsSum(\stdClass $d): float {
		$sum = 0.0;
		$list = $d->payments ?? null;
		foreach (is_array($list) ? $list : [] as $p) {
			$a = $p->amount ?? null;
			if (is_int($a) || is_float($a)) {
				$sum += (float)$a;
			}
		}
		return round($sum, 2);
	}

	/**
	 * The approval mark (0.27.0): null, or an object with `state`
	 * (`requested` or `returned`), `by`/`returned_by` (null or up to 64
	 * characters), `at`/`changed_at`/`returned_at` (null, a day or a local
	 * time as a booking's `start`), `note`/`returned_note` (null or up to
	 * 2000 characters). Other members are dropped.
	 */
	public static function checkReview(mixed $v): ?\stdClass {
		if ($v === null) {
			return null;
		}
		if (!($v instanceof \stdClass)) {
			throw ApiException::invalid('“review” must be null or a JSON object.');
		}
		if (!in_array($v->state ?? null, self::REVIEW_STATES, true)) {
			throw ApiException::invalid(Message::of('“review.state” is one of {states}.', ['states' => implode(', ', self::REVIEW_STATES)]));
		}
		$out = new \stdClass();
		$out->state = $v->state;
		foreach (self::REVIEW_BY as $f) {
			self::text($v->{$f} ?? null, 'review.' . $f, self::BY_MAX, self::CONTROL);
			$out->{$f} = $v->{$f} ?? null;
		}
		foreach (self::REVIEW_AT as $f) {
			$t = $v->{$f} ?? null;
			if ($t !== null && (!is_string($t) || !self::isStart($t))) {
				throw ApiException::invalid(Message::of('“{field}” must be null, a day (YYYY-MM-DD) or a local time (YYYY-MM-DDTHH:MM:SS).', ['field' => 'review.' . $f]));
			}
			$out->{$f} = $t;
		}
		foreach (self::REVIEW_NOTE as $f) {
			self::text($v->{$f} ?? null, 'review.' . $f, self::NOTE_MAX, self::CONTROL_NOTE);
			$out->{$f} = $v->{$f} ?? null;
		}
		return $out;
	}

	/**
	 * A Lead's part of the mark: submit (`requested`) and withdraw (null)
	 * freely; never set `returned` nor change the return block – that is
	 * the Team Admin's answer.
	 */
	public static function leadMayReview(?\stdClass $before, ?\stdClass $after): bool {
		$b = $before?->{self::REVIEW} ?? null;
		$a = $after?->{self::REVIEW} ?? null;
		if ($a === null || $after === null) {
			return true;
		}
		if (($a->state ?? null) === self::REVIEW_RETURNED && ($b->state ?? null) !== self::REVIEW_RETURNED) {
			return false;
		}
		foreach (self::REVIEW_RETURN as $f) {
			if (($a->{$f} ?? null) !== ($b->{$f} ?? null)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The positions (0.18.0): at most 500 of `hours`, `flat`, `discount` or
	 * `text` (0.21.0 also `expense_percent`, `expense_flat`, `costs`), each
	 * with a text and an amount (negative for a discount, 0 for a text
	 * line); work package, person, hours and rate optional; since 0.21.0
	 * `vat`, `vat_mode` and `cost`. `$negated`: the lines of a reversal or
	 * credit note (0.27.0) – an advance is then ≤ 0, its deduction ≥ 0.
	 */
	public static function checkPositions(mixed $list, bool $negated = false): void {
		if (!is_array($list) || !array_is_list($list) || count($list) > self::MAX_POSITIONS) {
			throw ApiException::invalid(Message::of('“positions” must be a list of at most {max} positions.', ['max' => self::MAX_POSITIONS]));
		}
		foreach ($list as $i => $p) {
			try {
				if (!($p instanceof \stdClass)) {
					throw ApiException::invalid('Each position must be a JSON object.');
				}
				if (!in_array($p->kind ?? null, self::POSITION_KINDS, true)) {
					throw ApiException::invalid(Message::of('“kind” of a position is one of {kinds}.', ['kinds' => implode(', ', self::POSITION_KINDS)]));
				}
				$t = $p->text ?? null;
				if (!is_string($t) || $t === '' || mb_strlen($t) > self::POSITION_TEXT_MAX || preg_match(self::CONTROL_NOTE, $t) || !mb_check_encoding($t, 'UTF-8')) {
					throw ApiException::invalid(Message::of('“{field}” must be text of 1 to {max} characters.', ['field' => 'text', 'max' => self::POSITION_TEXT_MAX]));
				}
				$wp = $p->work_package ?? null;
				if ($wp !== null && (!is_int($wp) || $wp < 0)) {
					throw ApiException::invalid('“work_package” must be null or an integer ≥ 0.');
				}
				self::text($p->person ?? null, 'person', self::PERSON_MAX, self::CONTROL);
				foreach (['hours', 'rate'] as $f) {
					$v = $p->{$f} ?? null;
					if ($v !== null && !ProjectRules::isRate($v)) {
						throw ApiException::invalid(Message::of('“{field}” must be null or a number ≥ 0.', ['field' => $f]));
					}
				}
				$a = $p->amount ?? null;
				if (!(is_int($a) || (is_float($a) && is_finite($a)))) {
					throw ApiException::invalid('“amount” must be a number.');
				}
				self::checkLineTax($p);
				self::checkAdvance($p, $negated);
				InvoiceLayout::checkPosition($p);
			} catch (ApiException $e) {
				throw ApiException::invalid(Message::of('Position {n}: {message}', ['n' => $i + 1, 'message' => $e->getText()]));
			}
		}
	}

	/**
	 * VAT and mode of a line (0.21.0): `vat` null or 0–100, `vat_mode`
	 * taxable or pass_through, `cost` null or the number of a cost
	 * position; `rate` of an expense percentage is a percentage.
	 */
	private static function checkLineTax(\stdClass $p): void {
		$vat = $p->vat ?? null;
		if ($vat !== null && !(ProjectRules::isRate($vat) && $vat <= 100)) {
			throw ApiException::invalid('“vat” must be null or a percentage from 0 to 100.');
		}
		$mode = $p->vat_mode ?? null;
		if ($mode !== null && !in_array($mode, VatLines::MODES, true)) {
			throw ApiException::invalid(Message::of('“{field}” must be one of {values}.', ['field' => 'vat_mode', 'values' => implode(', ', VatLines::MODES)]));
		}
		$cost = $p->cost ?? null;
		if ($cost !== null && (!is_int($cost) || $cost < 1)) {
			throw ApiException::invalid(Message::of('“{field}” must be null or an integer ≥ 1.', ['field' => 'cost']));
		}
		$rate = $p->rate ?? null;
		if (in_array($p->kind ?? null, ['expense_percent', ReceiptLineRules::MARKUP], true) && $rate !== null && !(ProjectRules::isRate($rate) && $rate <= 100)) {
			throw ApiException::invalid(Message::of('“{field}” must be null or a percentage from 0 to 100.', ['field' => 'rate']));
		}
		// 0.22.0: a costs line may name its receipts.
		ReceiptLineRules::checkList($p);
	}

	/**
	 * An advance (0.26.0) is a fee line ≥ 0; its deduction is ≤ 0 and names
	 * the advance invoice's key in `invoice`, no other line does. Neither is
	 * a cost line or a pass-through item. On a reversal or credit note
	 * (`$negated`) the signs mirror: advance ≤ 0, deduction ≥ 0.
	 */
	private static function checkAdvance(\stdClass $p, bool $negated = false): void {
		$kind = $p->kind ?? null;
		$ref = $p->invoice ?? null;
		if ($kind === self::ADVANCE_DEDUCTION) {
			if (!is_string($ref) || !RecordValidator::isKey($ref)) {
				throw ApiException::invalid('“invoice” of an advance deduction must be the key of the advance invoice.');
			}
		} elseif ($ref !== null) {
			throw ApiException::invalid('Only an advance deduction names an “invoice”.');
		}
		if (!in_array($kind, [self::ADVANCE, self::ADVANCE_DEDUCTION], true)) {
			return;
		}
		$a = $negated ? -$p->amount : $p->amount;
		if ($kind === self::ADVANCE && $a < 0) {
			if ($negated) {
				throw ApiException::invalid('A reversed advance has an amount ≤ 0.');
			}
			throw ApiException::invalid('An advance has an amount ≥ 0.');
		}
		if ($kind === self::ADVANCE_DEDUCTION && $a > 0) {
			if ($negated) {
				throw ApiException::invalid('A reversed advance deduction has an amount ≥ 0.');
			}
			throw ApiException::invalid('An advance deduction has an amount ≤ 0.');
		}
		if (($p->cost ?? null) !== null || ($p->vat_mode ?? null) === VatLines::PASS_THROUGH) {
			throw ApiException::invalid('An advance belongs to the fee: no cost position, not pass-through.');
		}
	}

	/** An advance invoice (0.26.0): it has an advance line (positions as arrays or objects). */
	public static function isAdvance(mixed $positions): bool {
		foreach (is_array($positions) ? $positions : [] as $p) {
			if (is_object($p)) {
				$p = get_object_vars($p);
			}
			if (is_array($p) && ($p['kind'] ?? null) === self::ADVANCE) {
				return true;
			}
		}
		return false;
	}

	private static function bookings(mixed $list): void {
		if (!is_array($list) || !array_is_list($list) || count($list) > self::MAX_BOOKINGS) {
			throw ApiException::invalid(Message::of('“bookings” must be a list of at most {max} bookings.', ['max' => self::MAX_BOOKINGS]));
		}
		$seen = [];
		foreach ($list as $i => $b) {
			try {
				if (!($b instanceof \stdClass)) {
					throw ApiException::invalid('Each booking must be a JSON object.');
				}
				$person = $b->person ?? null;
				$uid = $b->uid ?? null;
				$start = $b->start ?? null;
				self::required($person, 'person', self::PERSON_MAX);
				self::required($uid, 'uid', self::UID_MAX);
				if (!is_string($start) || !self::isStart($start)) {
					throw ApiException::invalid('“start” must be a day (YYYY-MM-DD) or a local time (YYYY-MM-DDTHH:MM:SS).');
				}
				$id = Json::encode([(string)$person, (string)$uid, $start]);
				if (isset($seen[$id])) {
					throw ApiException::invalid('The same booking (person, uid, start) is there twice.');
				}
				$seen[$id] = true;
				// 0.18.0: the hours, the rate and the code of the occurrence, stored with the invoice when issuing.
				foreach (['hours', 'rate'] as $f) {
					$v = $b->{$f} ?? null;
					if ($v !== null && !ProjectRules::isRate($v)) {
						throw ApiException::invalid(Message::of('“{field}” must be null or a number ≥ 0.', ['field' => $f]));
					}
				}
				self::text($b->code ?? null, 'code', self::CODE_MAX, self::CONTROL);
			} catch (ApiException $e) {
				throw ApiException::invalid(Message::of('Booking {n}: {message}', ['n' => $i + 1, 'message' => $e->getText()]));
			}
		}
	}

	/** `YYYY-MM-DD` (all day) or `YYYY-MM-DDTHH:MM:SS` (local time), a real day. */
	public static function isStart(string $s): bool {
		if (Time::isDay($s)) {
			return true;
		}
		return preg_match(self::LOCAL_TIME, $s, $m) === 1 && Time::isDay($m[1]);
	}

	private static function required(mixed $v, string $field, int $max): void {
		if (!is_string($v) || $v === '' || mb_strlen($v) > $max || preg_match(self::CONTROL, $v) || !mb_check_encoding($v, 'UTF-8')) {
			throw ApiException::invalid(Message::of('“{field}” must be text of 1 to {max} characters.', ['field' => $field, 'max' => $max]));
		}
	}

	/** @param non-empty-string $control */
	private static function text(mixed $v, string $field, int $max, string $control): void {
		if ($v !== null && (!is_string($v) || mb_strlen($v) > $max || preg_match($control, $v) || !mb_check_encoding($v, 'UTF-8'))) {
			throw ApiException::invalid(Message::of('“{field}” must be text of at most {max} characters.', ['field' => $field, 'max' => $max]));
		}
	}

	/** The project of a stored invoice (a tombstone keeps its last data); null if none. */
	public static function projectOf(?string $raw): ?string {
		$d = self::decode($raw);
		$p = $d?->project ?? null;
		return is_string($p) ? $p : null;
	}

	public static function decode(?string $raw): ?\stdClass {
		if ($raw === null) {
			return null;
		}
		try {
			$d = Json::decode($raw);
		} catch (\JsonException) {
			return null;
		}
		return $d instanceof \stdClass ? $d : null;
	}

	/**
	 * A Lead's write: the stored invoice (`$before`, null when new) and the
	 * new one (`$after`, null when deleted) both of a project they lead,
	 * both drafts, both without a number. Otherwise 403.
	 *
	 * @param array<string,true> $led the active projects the caller leads
	 */
	public static function requireLeadWrite(?\stdClass $before, ?\stdClass $after, array $led): void {
		foreach ([$before, $after] as $d) {
			$p = $d?->project ?? null;
			if ($d !== null && (!is_string($p) || !isset($led[$p]))) {
				throw ApiException::forbidden('Only Team Admins and the project’s Leads write invoices.');
			}
		}
		foreach ([$before, $after] as $d) {
			if ($d !== null && (($d->status ?? null) !== self::DRAFT || ($d->number ?? null) !== null)) {
				throw ApiException::forbidden('A Lead writes only drafts without a number; issuing, payment and cancelling are for Team Admins.');
			}
		}
		if (!self::leadMayReview($before, $after)) {
			throw ApiException::forbidden('Only Team Admins return a draft submitted for approval; a Lead submits or withdraws it.');
		}
	}
}
