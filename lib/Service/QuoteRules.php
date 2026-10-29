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
 * Since 0.27.0 (`quotes: 3`) a quote is a subtree: `budgets` (1–n, each as
 * the old `budget`), a target – `project` or a `project_draft` (code
 * proposal and name of the project the acceptance creates; it lives only
 * in the quote) – and after acceptance `budget_ids`. The statuses
 * `negotiation` and `verbal` count as open like `quoted`. The old
 * `budget` and `budget_id` stay accepted; the client reads them as one.
 *
 * Team Admins read and write every quote. A Lead reads a quote whose
 * `leads` name them (as a project's `leads` do) or that belongs to a project
 * they lead, and writes only such quotes – before and after the write.
 * Members see none.
 *
 * Since 0.32.0 (`quotes: 4`) the client writes `texts` and `attachments`;
 * `POST /quotes/{key}/issue` sets the number from the team's counter and
 * freezes the offered part as the server field `issued`; `documents[]`
 * (`quote_documents: 1`) and `document` are the server's too.
 */
final class QuoteRules {
	public const KIND = 'quote';
	public const ACCEPTED = 'accepted';
	public const DRAFT = 'draft';
	public const STATUSES = [self::DRAFT, 'quoted', 'negotiation', 'verbal', self::ACCEPTED, 'declined'];
	/** 0.27.0: at most this many budgets in one quote. */
	public const BUDGETS_MAX = 50;
	public const DRAFT_CODE_MAX = 64;
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
	public const PROBABILITY_MAX = 100;
	public const LOST_REASON_MAX = 500;
	/** 0.32.0: the document's texts, the attachments line, the number pattern, the three document kinds. */
	public const TEXT_FIELDS = ['greeting', 'intro', 'closing', 'footer'];
	public const TEXT_MAX = 4000;
	public const ATTACHMENTS_MAX = 2000;
	public const NUMBER_FIELD = 'quote_number';
	public const NUMBER_COUNTER = 'quote';
	public const NUMBER_DEFAULT = 'OF-{year}-{no}';
	public const KIND_TIMESISTER = 'timesister';
	public const KIND_SENT = 'sent';
	public const KIND_SIGNED = 'signed';
	public const DOCUMENT_KINDS = [self::KIND_TIMESISTER, self::KIND_SENT, self::KIND_SIGNED];
	public const DOCUMENTS_MAX = 10;
	/** Fields the server writes; a client's copy is replaced by the stored one. */
	public const SERVER_FIELDS = ['document', 'documents', 'issued'];

	private const CONTROL = '/[\x00-\x1F\x7F]/';
	private const CONTROL_NOTE = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/';
	/** http(s), a host part, no spaces or control characters. */
	private const PDF_URL = '~^https?://[^\s/\x00-\x1F\x7F][^\s\x00-\x1F\x7F]*$~iu';

	/** `422 quote_name_required`: only a draft may be unnamed; the issue and every other status need a name. */
	public static function nameRequired(): ApiException {
		return ApiException::invalid(Message::of('A quote needs a name of 1 to {max} characters from the issue on; only a draft may be unnamed.', ['max' => self::NAME_MAX]))
			->code(ErrorCode::QUOTE_NAME_REQUIRED)->field('name');
	}

	/** Key and content; other fields pass and are stored as sent, as for invoices. */
	public static function validate(string $key, \stdClass $d): void {
		if (preg_match(self::KEY_PATTERN, $key) !== 1) {
			throw ApiException::invalid('The key of a quote is “q-” and 8 lowercase hex digits.');
		}
		$s = $d->schema ?? null;
		if ($s !== null && (!is_int($s) || $s < 1 || $s > 1000)) {
			throw ApiException::invalidField('schema');
		}
		$status = $d->status ?? null;
		if (!in_array($status, self::STATUSES, true)) {
			throw ApiException::invalid(Message::of('“status” of a quote is one of {statuses}.', ['statuses' => implode(', ', self::STATUSES)]));
		}
		// 0.32.0 (E1): a draft is made first and named later; from the issue on the name is required.
		$name = $d->name ?? null;
		if ($name === null || $name === '') {
			if ($status !== self::DRAFT) {
				throw self::nameRequired();
			}
		} elseif (!is_string($name) || mb_strlen($name) > self::NAME_MAX || preg_match(self::CONTROL, $name) || !mb_check_encoding($name, 'UTF-8')) {
			throw ApiException::invalid(Message::of('“{field}” must be text of 1 to {max} characters.', ['field' => 'name', 'max' => self::NAME_MAX]));
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
		self::budget($d->budget ?? null, 'budget');
		// 0.27.0: the budgets of the subtree, each as `budget`, ids unique.
		$budgets = $d->budgets ?? null;
		if ($budgets !== null) {
			if (!is_array($budgets) || !array_is_list($budgets) || count($budgets) > self::BUDGETS_MAX) {
				throw ApiException::invalid(Message::of('“budgets” must be null or a list of at most {max} budgets.', ['max' => self::BUDGETS_MAX]));
			}
			$ids = [];
			foreach ($budgets as $b) {
				self::budget($b, 'budgets');
				$ids[] = (string)$b->id;
			}
			if (count($ids) !== count(array_unique($ids))) {
				throw ApiException::invalid('“budgets” must not hold the same “id” twice.');
			}
		}
		self::nullableText($d->budget_id ?? null, 'budget_id', self::BUDGET_ID_MAX);
		$ids = $d->budget_ids ?? null;
		if ($ids !== null) {
			$ok = is_array($ids) && array_is_list($ids) && count($ids) <= self::BUDGETS_MAX;
			foreach ($ok ? $ids : [] as $id) {
				if (!is_string($id) || trim($id) === '' || mb_strlen($id) > self::BUDGET_ID_MAX || preg_match(self::CONTROL, $id) || !mb_check_encoding($id, 'UTF-8')) {
					$ok = false;
					break;
				}
			}
			if (!$ok) {
				throw ApiException::invalid(Message::of('“budget_ids” must be null or a list of at most {max} budget ids of 1 to {chars} characters.', ['max' => self::BUDGETS_MAX, 'chars' => self::BUDGET_ID_MAX]));
			}
		}
		// 0.27.0: the project draft – the target when there is no project yet.
		$draft = $d->project_draft ?? null;
		if ($draft !== null) {
			$name = $draft instanceof \stdClass ? ($draft->name ?? null) : null;
			if (!is_string($name) || $name === '' || mb_strlen($name) > self::NAME_MAX || preg_match(self::CONTROL, $name) || !mb_check_encoding($name, 'UTF-8')) {
				throw ApiException::invalid(Message::of('“project_draft” must be null or an object with a “name” of 1 to {max} characters.', ['max' => self::NAME_MAX]));
			}
			$code = $draft->code ?? null;
			if ($code !== null && (!is_string($code) || $code === '' || mb_strlen($code) > self::DRAFT_CODE_MAX || preg_match('/[\s#.\x00-\x1F\x7F]/', $code) || !mb_check_encoding($code, 'UTF-8'))) {
				throw ApiException::invalid(Message::of('“project_draft.code” must be null or a code of 1 to {max} characters without spaces, # or dots.', ['max' => self::DRAFT_CODE_MAX]));
			}
			if ($project !== null) {
				throw ApiException::invalid('A quote has either “project” or “project_draft”, not both.');
			}
		}
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
		// 0.27.0 (controlling): the probability to win in percent and the reason when lost.
		$pr = $d->probability ?? null;
		if ($pr !== null && (!is_int($pr) || $pr < 0 || $pr > self::PROBABILITY_MAX)) {
			throw ApiException::invalid(Message::of('“{field}” must be null or an integer from {min} to {max}.', ['field' => 'probability', 'min' => 0, 'max' => self::PROBABILITY_MAX]));
		}
		self::text($d->lost_reason ?? null, 'lost_reason', self::LOST_REASON_MAX, self::CONTROL_NOTE);
		// 0.32.0: the document's texts and the attachments line.
		$texts = $d->texts ?? null;
		if ($texts !== null) {
			if (!($texts instanceof \stdClass)) {
				throw ApiException::invalid('“texts” must be null or an object with greeting, intro, closing, footer.');
			}
			foreach (array_keys(get_object_vars($texts)) as $name) {
				if (!in_array($name, self::TEXT_FIELDS, true)) {
					throw ApiException::invalid(Message::of('“texts” has an unknown field “{name}”; allowed are greeting, intro, closing, footer.', ['name' => $name]));
				}
				self::text($texts->{$name}, 'texts.' . $name, self::TEXT_MAX, self::CONTROL_NOTE);
			}
		}
		self::text($d->attachments ?? null, 'attachments', self::ATTACHMENTS_MAX, self::CONTROL_NOTE);
		$issued = $d->issued ?? null;
		if ($issued !== null && !($issued instanceof \stdClass)) {
			throw ApiException::invalid('“issued” is set by the server when the quote is issued.');
		}
	}

	// ------------------------------------------------------------- issuing

	/** Team Admins and the quote's Leads issue it and store its documents; `403 quote_lead_only` otherwise. @param list<string> $ownKeys @param array<string,true> $led */
	public static function requireLead(bool $manages, ?\stdClass $d, array $ownKeys, array $led): void {
		/** @var list<string> $ownKeys */
		if (!$manages && !self::leadReads($d, $ownKeys, $led)) {
			throw ApiException::forbidden('Only Team Admins and the quote’s or project’s Leads issue a quote and store its documents.')->code(ErrorCode::QUOTE_LEAD_ONLY);
		}
	}

	/** Is the quote issued (it carries a number and the frozen copy)? */
	public static function isIssued(?\stdClass $d): bool {
		return $d !== null && ($d->issued ?? null) instanceof \stdClass && is_string($d->number ?? null);
	}

	/**
	 * A client's write against the stored quote (0.32.0): the server's
	 * fields stay as stored – `documents` and `document` silently, `issued`
	 * and the `number` of an issued quote with `409 quote_issued_frozen`
	 * when the client changes them (also `issued` set on a draft).
	 */
	public static function keepServerFields(?\stdClass $before, \stdClass $after): void {
		$storedIssued = $before?->issued ?? null;
		$sent = $after->issued ?? null;
		if ($sent !== null && ($storedIssued === null || self::differs($sent, $storedIssued))) {
			throw ApiException::conflict('“issued” of a quote is written by the server when the quote is issued and does not change.')->code(ErrorCode::QUOTE_ISSUED_FROZEN)->field('issued');
		}
		if ($before !== null && self::isIssued($before) && ($after->number ?? null) !== $before->number) {
			throw ApiException::conflict('The number of an issued quote does not change; a changed offer is a new quote.')->code(ErrorCode::QUOTE_ISSUED_FROZEN)->field('number');
		}
		foreach (self::SERVER_FIELDS as $f) {
			if ($before !== null && property_exists($before, $f)) {
				$after->{$f} = $before->{$f};
			} else {
				unset($after->{$f});
			}
		}
	}

	/** Do two values differ as JSON, keys sorted, `184.0` and `184` the same (a JavaScript client drops the fraction)? */
	private static function differs(mixed $a, mixed $b): bool {
		return json_encode(self::sorted($a), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !== json_encode(self::sorted($b), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

	/** The offered part, frozen on issue: what later negotiation never alters. @param array{key:?string,name:?string} $customer */
	public static function issuedCopy(\stdClass $d, string $at, string $by, string $number, string $date, ?string $validUntil, ?float $hours, ?float $amount, array $customer): \stdClass {
		$budgets = $d->budgets ?? null;
		if (!is_array($budgets)) {
			$budgets = ($d->budget ?? null) instanceof \stdClass ? [$d->budget] : [];
		}
		return Json::object([
			'at' => $at, 'by' => $by, 'number' => $number, 'date' => $date, 'valid_until' => $validUntil,
			'currency' => is_string($d->currency ?? null) ? $d->currency : null,
			'hours' => $hours, 'amount' => $amount,
			'customer' => $customer['key'] === null ? null : Json::object($customer),
			'project' => is_string($d->project ?? null) ? $d->project : null,
			'project_draft' => ($d->project_draft ?? null) instanceof \stdClass ? $d->project_draft : null,
			'budgets' => array_values($budgets),
			'texts' => ($d->texts ?? null) instanceof \stdClass ? $d->texts : null,
			'attachments' => is_string($d->attachments ?? null) ? $d->attachments : null,
		]);
	}

	/** The body of `POST /quotes/{key}/issue`: a day, an optional day, two optional sums ≥ 0. @return array{date:string,valid_until:?string,hours:?float,amount:?float} */
	public static function issueBody(\stdClass $body, int $now): array {
		$out = ['date' => gmdate('Y-m-d', $now), 'valid_until' => null, 'hours' => null, 'amount' => null];
		foreach (['date', 'valid_until'] as $f) {
			$v = $body->{$f} ?? null;
			if ($v === null) {
				continue;
			}
			if (!is_string($v) || !Time::isDay($v)) {
				throw ApiException::invalid(Message::of('“{field}” must be a day in the format YYYY-MM-DD.', ['field' => $f]));
			}
			$out[$f] = $v;
		}
		foreach (['hours', 'amount'] as $f) {
			$v = $body->{$f} ?? null;
			if ($v !== null && !ProjectRules::isRate($v)) {
				throw ApiException::invalid(Message::of('“{field}” must be null or a number ≥ 0.', ['field' => $f]));
			}
			$out[$f] = $v === null ? null : round((float)$v, 2);
		}
		return $out;
	}

	/** `kind` of `POST /quotes/{key}/documents`: sent or signed; `422` with field `kind` otherwise. */
	public static function addedKind(mixed $kind): string {
		if ($kind !== self::KIND_SENT && $kind !== self::KIND_SIGNED) {
			throw ApiException::invalid('“kind” must be “sent” or “signed”.')->field('kind');
		}
		return $kind;
	}

	/** `kind` of `GET /quotes/{key}/document`: one of the three, default timesister. */
	public static function readKind(mixed $kind): string {
		if ($kind === null || $kind === '') {
			return self::KIND_TIMESISTER;
		}
		if (!in_array($kind, self::DOCUMENT_KINDS, true)) {
			throw ApiException::invalid('“kind” must be “timesister”, “sent” or “signed”.')->field('kind');
		}
		return $kind;
	}

	/** The file name in the tree: `<date>_<number>_<customer>[_gesendet|_unterschrieben].pdf`. */
	public static function documentName(string $date, string $number, string $customer, string $kind): string {
		$name = DocumentTree::quoteName($date, $number, $customer);
		$suffix = match ($kind) {
			self::KIND_SENT => '_gesendet',
			self::KIND_SIGNED => '_unterschrieben',
			default => '',
		};
		return $suffix === '' ? $name : substr($name, 0, -4) . $suffix . '.pdf';
	}

	/** The stored `documents[]` of a quote. @return list<\stdClass> */
	public static function documents(?\stdClass $d): array {
		$list = $d?->documents ?? null;
		return is_array($list) ? array_values(array_filter($list, static fn (mixed $x): bool => $x instanceof \stdClass)) : [];
	}

	/** Null or a budget object with an `id`, checked as a project's budget (currency, cap, rates). */
	private static function budget(mixed $budget, string $field): void {
		if ($budget === null) {
			return;
		}
		$id = null;
		if ($budget instanceof \stdClass) {
			$id = $budget->id ?? null;
		}
		if (!is_string($id) || trim($id) === '' || mb_strlen($id) > self::BUDGET_ID_MAX || preg_match(self::CONTROL, $id) || !mb_check_encoding($id, 'UTF-8')) {
			throw ApiException::invalid(Message::of('“{field}” must be null or an object with an “id” of 1 to {max} characters.', ['field' => $field, 'max' => self::BUDGET_ID_MAX]));
		}
		ProjectRules::checkBudget($budget);
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
