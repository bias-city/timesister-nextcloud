<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * Issuing an invoice (0.18.0), the pure part: what a draft needs, the
 * request fields, the hours per booking, the frozen blocks (issuer,
 * customer) and the payload the ledger hashes; the sums come from
 * {@see VatLines}. The service (IssueService) does the reading and the
 * transaction.
 */
final class IssueRules {
	public const DUE_DAYS = 30;
	public const DUE_DAYS_MAX = 365;
	public const REASON_MAX = 500;
	public const NOTE_MAX = 500;
	public const REVERSAL = 'reversal';
	public const CREDIT = 'credit';
	/** 0.32.0: a reservation without a document is released after this many seconds. */
	public const EXPIRY = 900;
	/** The team's default VAT rate in `setting/settings`, percent. */
	public const VAT_FIELD = 'vat';
	/** Which customer fields are frozen. */
	private const CUSTOMER_FIELDS = ['id', 'name', 'address_extra', 'street', 'postcode', 'city', 'country', 'email', 'phone', 'vat_id', 'leitweg_id', 'datev_account'];
	private const CONTROL_TEXT = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/';

	/** The draft's fitness for issuing: a draft without number and something to bill; since 0.38.0 a budget is optional. */
	public static function requireIssuable(\stdClass $d): void {
		if (($d->status ?? null) !== InvoiceRules::DRAFT) {
			throw ApiException::invalid('Only a draft can be issued.')->code(ErrorCode::INVOICE_NOT_DRAFT);
		}
		if (($d->number ?? null) !== null) {
			throw ApiException::invalid('The draft already has a number.');
		}
		if (self::positionsOf($d) === [] && self::bookingsOf($d) === []) {
			throw ApiException::invalid('The draft has neither positions nor bookings.');
		}
	}

	/**
	 * 0.33.0: an invoice that bills hours (an `hours` line or a booking) of
	 * a budget by role or by category needs a level in the team; every
	 * `hours` line with a `level` needs a rate of that level in the
	 * invoice's currency – on the last day of the period, else on the issue
	 * day ({@see LevelRules::rateOf()}). The rate on the line may differ.
	 *
	 * @throws ApiException 422 `no_levels`, `level_rate_missing`
	 */
	public static function requireLevels(\stdClass $d, ?\stdClass $settings, ?\stdClass $project, ?\stdClass $budget, string $currency, string $issuedOn): void {
		// 0.38.0: levels and fee model only with a budget; without one the rate of each booking stands as sent.
		if ($budget === null) {
			return;
		}
		$positions = self::positionsOf($d);
		$hours = self::bookingsOf($d) !== [] || array_filter($positions, static fn (\stdClass $p): bool => ($p->kind ?? null) === 'hours') !== [];
		if ($hours && FeeModelRules::modelOf($budget) !== FeeModelRules::MEAN_RATE && !LevelRules::hasLevels($settings)) {
			throw self::noLevels((string)($budget->id ?? ''));
		}
		$to = $d->to ?? null;
		$day = is_string($to) && Time::isDay($to) ? $to : $issuedOn;
		foreach ($positions as $i => $p) {
			$level = $p->level ?? null;
			if (($p->kind ?? null) !== 'hours' || !is_string($level) || !LevelRules::isId($level)) {
				continue;
			}
			if (LevelRules::rateOf($settings, $project, $level, $currency, $day) === null) {
				throw (new ApiException(422, ApiException::INVALID, Message::of('Position {n}: the level {level} has no rate in {currency} on {day}; add it to the levels of the team or the project.',
					['n' => $i + 1, 'level' => $level, 'currency' => $currency, 'day' => $day]), ['level' => $level]))
					->code(ErrorCode::LEVEL_RATE_MISSING)->field('level');
			}
		}
	}

	/** 422 `no_levels`: a budget by role or by category, and the team has no level. */
	public static function noLevels(string $budget): ApiException {
		return (new ApiException(422, ApiException::INVALID, Message::of('Budget {budget} computes by level, and the team has no level yet; add one in the team settings.', ['budget' => $budget]),
			['budget' => $budget]))->code(ErrorCode::NO_LEVELS)->field('levels');
	}

	/** @return list<\stdClass> */
	/**
	 * The positions that are on the invoice: a line taken out in the draft (`excluded`, 0.46.0) is not frozen.
	 * @return list<\stdClass>
	 */
	public static function positionsOf(\stdClass $d): array {
		$p = $d->positions ?? null;
		$out = [];
		foreach (is_array($p) ? $p : [] as $x) {
			if ($x instanceof \stdClass && ($x->excluded ?? false) !== true) {
				$out[] = $x;
			}
		}
		return $out;
	}

	/** @return list<\stdClass> */
	public static function bookingsOf(\stdClass $d): array {
		$b = $d->bookings ?? null;
		return is_array($b) ? array_values(array_filter($b, static fn (mixed $x): bool => $x instanceof \stdClass)) : [];
	}

	/** `issued_on` of the request; missing: today (UTC). */
	public static function issuedOn(mixed $v, int $now): string {
		if ($v === null || $v === '') {
			return gmdate('Y-m-d', $now);
		}
		if (!is_string($v) || !Time::isDay($v)) {
			throw ApiException::invalid(Message::of('“{field}” must be a day in the format YYYY-MM-DD.', ['field' => 'issued_on']));
		}
		return $v;
	}

	/** `paid_on` of the request: required day. */
	public static function paidOn(mixed $v): string {
		if (!is_string($v) || !Time::isDay($v)) {
			throw ApiException::invalid(Message::of('“{field}” must be a day in the format YYYY-MM-DD.', ['field' => 'paid_on']));
		}
		return $v;
	}

	/** `due_days` of the request; missing: 30. */
	public static function dueDays(mixed $v): int {
		if ($v === null) {
			return self::DUE_DAYS;
		}
		if (!is_int($v) || $v < 1 || $v > self::DUE_DAYS_MAX) {
			throw ApiException::invalid(Message::of('“{field}” must be an integer from 1 to {max}.', ['field' => 'due_days', 'max' => self::DUE_DAYS_MAX]));
		}
		return $v;
	}

	/** `due_days` of the request, or null when missing - the customer's term or the default decide then. */
	public static function dueDaysGiven(mixed $v): ?int {
		return $v === null ? null : self::dueDays($v);
	}

	/** `amount` of a payment: null (the open rest) or a finite number above 0. */
	public static function paymentAmount(mixed $v, bool $reversal = false): ?float {
		if ($v === null && !$reversal) {
			return null;
		}
		if (!(is_int($v) || (is_float($v) && is_finite($v)))) {
			throw ApiException::invalid('“amount” must be a number above 0.')->field('amount');
		}
		if ($reversal && $v >= 0) {
			throw ApiException::invalid('“amount” of a reversal must be a number below 0.')->field('amount');
		}
		if (!$reversal && $v <= 0) {
			throw ApiException::invalid('“amount” must be a number above 0.')->field('amount');
		}
		return round((float)$v, 2);
	}

	/**
	 * 0.40.0: where a payment comes from – `source` (camt, csv, manual), `key` of the bank
	 * entry (≤ 160), `kind` (payment, writeoff, reversal).
	 *
	 * @return array{source:string,key:?string,kind:string}
	 */
	public static function paymentFrom(\stdClass $body): array {
		$source = $body->source ?? 'manual';
		if (!in_array($source, BankRules::SOURCES, true)) {
			throw ApiException::invalidField('source');
		}
		// On the wire the payment carries `payment_kind` and `bank_key`: `kind` and `key` belong to
		// the path and are refused in any body (BaseController::body). Stored as `kind` and `key`.
		$kind = $body->payment_kind ?? 'payment';
		if (!in_array($kind, InvoiceRules::PAYMENT_KINDS, true)) {
			throw ApiException::invalidField('payment_kind');
		}
		$key = $body->bank_key ?? null;
		if ($key !== null && (!is_string($key) || $key === '' || mb_strlen($key) > 160 || preg_match(self::CONTROL_TEXT, $key))) {
			throw ApiException::invalidField('bank_key');
		}
		return ['source' => $source, 'key' => $key, 'kind' => $kind];
	}

	/** `level` of a dunning request: an integer 0 to 3. */
	public static function dunningLevel(mixed $v): int {
		if (!is_int($v) || $v < 0 || $v > InvoiceRules::DUNNING_MAX) {
			throw ApiException::invalid(Message::of('“{field}” must be an integer from {min} to {max}.', ['field' => 'level', 'min' => 0, 'max' => InvoiceRules::DUNNING_MAX]));
		}
		return $v;
	}

	/** `on` of a dunning request: a day, missing: today. */
	public static function dunnedOn(mixed $v, int $now): string {
		if ($v === null) {
			return gmdate('Y-m-d', $now);
		}
		if (!is_string($v) || !Time::isDay($v)) {
			throw ApiException::invalid(Message::of('“{field}” must be a day in the format YYYY-MM-DD.', ['field' => 'on']));
		}
		return $v;
	}

	/** The day `$days` after `$day`. */
	public static function dueOn(string $day, int $days): string {
		return (new \DateTimeImmutable($day . ' 00:00:00', new \DateTimeZone('UTC')))->modify('+' . $days . ' days')->format('Y-m-d');
	}

	/** `kind` of a reversal request. */
	public static function kind(mixed $v): string {
		if ($v !== self::REVERSAL && $v !== self::CREDIT) {
			throw ApiException::invalid('“kind” must be “reversal” or “credit”.');
		}
		return $v;
	}

	/** An optional short text (`reason`, `note`), at most 500 characters. */
	public static function shortText(mixed $v, string $field): ?string {
		if ($v === null) {
			return null;
		}
		if (!is_string($v) || mb_strlen($v) > self::NOTE_MAX || preg_match(self::CONTROL_TEXT, $v) || !mb_check_encoding($v, 'UTF-8')) {
			throw ApiException::invalid(Message::of('“{field}” must be text of at most {max} characters.', ['field' => $field, 'max' => self::NOTE_MAX]));
		}
		return $v;
	}

	/**
	 * The positions with every amount negated (reversal, credit note);
	 * rate and mode of each line stay.
	 *
	 * @param list<\stdClass> $positions
	 * @return list<\stdClass>
	 */
	public static function negate(array $positions): array {
		$out = [];
		foreach ($positions as $p) {
			$c = clone $p;
			$a = $c->amount ?? 0;
			$c->amount = is_int($a) ? -$a : (is_float($a) ? -$a : 0);
			$out[] = $c;
		}
		return $out;
	}

	/**
	 * The hours per booking: uid, event, start, hours and rate as sent (0
	 * when missing), amount = hours × rate, code, and the level it is
	 * billed in (0.33.0).
	 *
	 * @param list<\stdClass> $bookings
	 * @return list<array{uid:string,event_uid:string,start:string,hours:float,rate:float,amount:float,code:?string,level:?string}>
	 */
	public static function hoursOf(array $bookings): array {
		$out = [];
		foreach ($bookings as $b) {
			$hours = is_int($b->hours ?? null) || is_float($b->hours ?? null) ? (float)$b->hours : 0.0;
			$rate = is_int($b->rate ?? null) || is_float($b->rate ?? null) ? (float)$b->rate : 0.0;
			$code = $b->code ?? null;
			$out[] = [
				'uid' => (string)($b->person ?? ''),
				'event_uid' => (string)($b->uid ?? ''),
				'start' => (string)($b->start ?? ''),
				'hours' => $hours,
				'rate' => $rate,
				'amount' => round($hours * $rate, 2),
				'code' => is_string($code) && $code !== '' ? $code : null,
				'level' => LevelRules::isId($b->level ?? null) ? (string)$b->level : null,
			];
		}
		return $out;
	}

	/** @param list<array{hours:float,...}> $hours */
	public static function totalHours(array $hours): float {
		return round(array_sum(array_map(static fn (array $h): float => $h['hours'], $hours)), 2);
	}

	/** The budget with this id in the project's `budgets`, or null. */
	public static function budgetOf(\stdClass $project, string $id): ?\stdClass {
		foreach ((array)($project->budgets ?? []) as $b) {
			if ($b instanceof \stdClass && ($b->id ?? null) === $id) {
				return $b;
			}
		}
		return null;
	}

	/**
	 * The invoice's currency, the same chain as the Mac app: since 0.38.0
	 * the invoice's own first, then the budget's (0.36.0: budget, draft),
	 * the project's, the team's (`settings.currency`), else CHF.
	 */
	public static function currencyOf(?\stdClass $budget, \stdClass $d, ?\stdClass $project = null, ?\stdClass $settings = null): string {
		foreach ([$d->currency ?? null, $budget?->currency ?? null, $project?->currency ?? null] as $c) {
			if (is_string($c) && preg_match(ProjectRules::CURRENCY_PATTERN, $c) === 1) {
				return strtoupper($c);
			}
		}
		return ReceiptBooking::teamCurrency($settings);
	}

	/**
	 * The customer of an invoice when it is issued (0.38.0): the invoice's
	 * own `customer`, else the accepted quote of its budget, else its
	 * project's customer; null when none names one.
	 *
	 * @param list<\stdClass> $quotes data of the team's live quote records
	 */
	public static function customerOf(\stdClass $d, ?\stdClass $project, string $projectKey, ?string $budgetId, array $quotes): ?string {
		$own = InvoiceRules::keyOf($d->customer ?? null);
		if ($own !== null) {
			return $own;
		}
		if ($project === null) {
			return null;
		}
		return $budgetId === null ? self::customerKeyOf($project) : self::customerKeyFor($project, $projectKey, $budgetId, $quotes);
	}

	/** 422 `invoice_no_customer`: issuing needs a customer. */
	public static function noCustomer(): ApiException {
		return ApiException::invalid('The invoice names no customer, nor do the quote of its budget or its project; set “customer”.')->code(ErrorCode::INVOICE_NO_CUSTOMER)->field('customer');
	}

	/**
	 * The period of an issued invoice (0.38.0, `from` and `to` optional): a
	 * missing day comes from the bookings' days (and the other day given),
	 * else the issue day.
	 *
	 * @return array{0:string,1:string}
	 */
	public static function periodOf(\stdClass $d, string $issuedOn): array {
		$from = $d->from ?? null;
		$to = $d->to ?? null;
		$from = is_string($from) && Time::isDay($from) ? $from : null;
		$to = is_string($to) && Time::isDay($to) ? $to : null;
		$days = [];
		foreach (self::bookingsOf($d) as $b) {
			$start = $b->start ?? null;
			if (is_string($start) && Time::isDay(substr($start, 0, 10))) {
				$days[] = substr($start, 0, 10);
			}
		}
		$low = $days === [] ? ($to ?? $issuedOn) : min([...$days, ...($to === null ? [] : [$to])]);
		$high = $days === [] ? ($from ?? $issuedOn) : max([...$days, ...($from === null ? [] : [$from])]);
		return [$from ?? $low, $to ?? $high];
	}

	/** The project's customer key (`customer`, older records `kunde`). */
	/**
	 * The invoice's customer, as the Mac app finds it: the customer of the
	 * accepted quote this budget came from (same project), else the
	 * project's customer.
	 *
	 * @param list<\stdClass> $quotes data of the team's live quote records
	 */
	public static function customerKeyFor(\stdClass $project, string $projectKey, string $budgetId, array $quotes): ?string {
		foreach ($quotes as $q) {
			$c = $q->customer ?? null;
			// 0.27.0: a quote holds `budget_ids`; older records one `budget_id`.
			$rawIds = $q->budget_ids ?? null;
			$ids = is_array($rawIds) ? $rawIds : [];
			if (($q->status ?? null) === 'accepted' && (($q->budget_id ?? null) === $budgetId || in_array($budgetId, $ids, true))
				&& is_string($q->project ?? null) && strcasecmp((string)$q->project, $projectKey) === 0
				&& is_string($c) && trim($c) !== '') {
				return $c;
			}
		}
		return self::customerKeyOf($project);
	}

	public static function customerKeyOf(\stdClass $project): ?string {
		foreach ([$project->customer ?? null, $project->kunde ?? null] as $c) {
			if (is_string($c) && $c !== '') {
				return $c;
			}
		}
		return null;
	}

	/** The issuer block of the settings record, or null. */
	public static function issuerOf(?\stdClass $settings): ?\stdClass {
		$i = $settings?->{IssuerRules::FIELD} ?? null;
		return $i instanceof \stdClass ? $i : null;
	}

	/** The VAT rate: the draft's, else the team's default (`settings.vat`), else 0. */
	public static function vatOf(\stdClass $d, ?\stdClass $settings): float {
		foreach ([$d->vat ?? null, $settings?->{self::VAT_FIELD} ?? null] as $v) {
			if (ProjectRules::isRate($v) && $v <= 100) {
				return (float)$v;
			}
		}
		return 0.0;
	}

	/**
	 * The frozen customer block: name and address lines, country, VAT id,
	 * the contact the draft names (`contact`: the id of one of the
	 * customer's `contacts`, or free text), and since 0.20.0 the Leitweg-ID,
	 * the DATEV account, `e_invoice` and the tax case.
	 */
	public static function customerBlock(\stdClass $customer, \stdClass $d, ?string $issuerCountry = null): \stdClass {
		$out = new \stdClass();
		foreach (self::CUSTOMER_FIELDS as $f) {
			$v = $customer->{$f} ?? null;
			if (is_string($v) && $v !== '') {
				$out->{$f} = $v;
			}
		}
		// 0.20.0: whether an XRechnung is wanted, and the tax case – from the countries, or the draft's override.
		if (($customer->e_invoice ?? null) === true) {
			$out->e_invoice = true;
		}
		$override = $d->{TaxRules::FIELD} ?? null;
		$out->{TaxRules::FIELD} = TaxRules::of($issuerCountry, $out->country ?? null, $out->vat_id ?? null, is_string($override) ? $override : null);
		$contact = $d->contact ?? null;
		if (is_string($contact) && $contact !== '') {
			$out->contact = $contact;
			foreach ((array)($customer->contacts ?? []) as $c) {
				if ($c instanceof \stdClass && ($c->id ?? null) === $contact) {
					$out->contact = $c;
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * The payload the ledger hashes when an invoice is issued, reversed or
	 * credited: the whole frozen content, nothing that changes later
	 * (status, paid, reversed_by, documents; since 0.38.0 the budget).
	 *
	 * @param list<\stdClass> $positions
	 * @param list<array{uid:string,event_uid:string,start:string,hours:float,rate:float,amount:float,code:?string,level?:?string}> $hours
	 * @param list<array{kind:string,title:string,value:string}>|null $references
	 * @return array<string,mixed>
	 */
	public static function payload(string $key, string $number, string $issuedOn, string $issuedBy, string $dueOn, ?string $project, ?string $customerKey, string $from, string $to, string $currency, VatLines $sums, \stdClass $issuer, \stdClass $customer, array $positions, array $hours, ?string $greeting, ?string $body, ?string $footer, ?string $reverses, ?string $credits, ?string $qrReference = null, ?\stdClass $layout = null, ?string $companion = null, ?string $costCenter = null, ?array $references = null): array {
		// 0.26.0: the layout only when set - older entries keep their exact payload; 0.39.0 likewise the companion invoice.
		// 0.44.0 likewise the cost center, 0.47.0 the reference lines: only when set, so older entries keep their exact payload.
		return ($layout === null ? [] : ['layout' => $layout]) + ($companion === null ? [] : ['companion' => $companion])
			+ ($costCenter === null ? [] : ['cost_center' => $costCenter])
			+ ($references === null || $references === [] ? [] : ['references' => $references]) + [
			'key' => $key,
			'number' => $number,
			'issued_on' => $issuedOn,
			'issued_by' => $issuedBy,
			'due_on' => $dueOn,
			'project' => $project,
			// 0.38.0: no `budget` – it is an assignment for controlling, changed through `assign`.
			'customer_key' => $customerKey,
			'period' => ['from' => $from, 'to' => $to],
			'currency' => $currency,
			'vat' => $sums->rate,
			'net' => $sums->net,
			'vat_amount' => $sums->vatAmount,
			'gross' => $sums->gross,
			// 0.21.0: net and VAT per rate, the pass-through items, the amount to pay.
			'vat_lines' => $sums->lines,
			'pass_through' => $sums->passThrough,
			'total' => $sums->total,
			'issuer' => $issuer,
			'customer' => $customer,
			'positions' => $positions,
			'hours' => $hours,
			'greeting' => $greeting,
			'body' => $body,
			'footer' => $footer,
			'reverses' => $reverses,
			'credits' => $credits,
			// 0.20.0: the QR reference of a QR-IBAN (null otherwise), printed on the bill.
			'qr_reference' => $qrReference,
		];
	}

	/** The issuer's account in the currency: `[iban, bic]` or null (0.20.0). @return ?array{0:string,1:?string} */
	public static function accountOf(\stdClass $issuer, string $currency): ?array {
		foreach ((array)($issuer->accounts ?? []) as $a) {
			if (!($a instanceof \stdClass)) {
				continue;
			}
			$iban = $a->iban ?? null;
			if (strtoupper((string)($a->currency ?? '')) === $currency && is_string($iban) && $iban !== '') {
				$bic = $a->bic ?? null;
				return [strtoupper(str_replace(' ', '', $iban)), is_string($bic) && $bic !== '' ? $bic : null];
			}
		}
		return null;
	}

	/** A text field of the draft, or null. */
	public static function textOf(\stdClass $d, string $field): ?string {
		$v = $d->{$field} ?? null;
		return is_string($v) && $v !== '' ? $v : null;
	}

	/** 0.47.0: the draft's reference lines as the column freezes them – JSON, or null when there are none. */
	public static function referencesJsonOf(\stdClass $d): ?string {
		$lines = ReferenceRules::frozen($d->{ReferenceRules::LINES} ?? null);
		return $lines === null ? null : json_encode($lines, Json::FLAGS);
	}

	/**
	 * 0.47.0: the frozen reference lines of the column, or null.
	 *
	 * @return list<array{kind:string,title:string,value:string}>|null
	 */
	public static function referencesOf(?string $json): ?array {
		if ($json === null || $json === '') {
			return null;
		}
		try {
			return ReferenceRules::frozen(json_decode($json, true, 8, JSON_THROW_ON_ERROR));
		} catch (\JsonException) {
			return null;
		}
	}

	/** Money and hours as the DECIMAL columns take them. */
	public static function decimal(float $v): string {
		return number_format($v, 2, '.', '');
	}

	/** A fresh record key for a reversal or credit note. */
	public static function newKey(): string {
		return 'inv-' . bin2hex(random_bytes(4));
	}

	// ------------------------------------------------------ the reservation

	/**
	 * The footer sentence of the document (0.32.0): «Beleg <number> · Kette
	 * <seq> ← <prev_hash[0:12]>» in German, «Document … · Chain … ← …» in
	 * English; `← –` for the first entry of a team. The app prints it on
	 * every page and puts it into the PDF's XMP; the close searches for it.
	 */
	public static function chainLine(string $lang, string $number, int $seq, string $prevHash): string {
		$prev = $prevHash === '' ? '–' : substr($prevHash, 0, 12);
		return $lang === DocumentRules::DE
			? 'Beleg ' . $number . ' · Kette ' . $seq . ' ← ' . $prev
			: 'Document ' . $number . ' · Chain ' . $seq . ' ← ' . $prev;
	}

	/** `409 issue_pending` with the open reservation of the team. */
	public static function pending(string $key, string $number, int $expiresAt): ApiException {
		return ApiException::conflict('An issue of this team is open; close it with its PDF, cancel it or wait for its expiry.',
			['pending' => ['key' => $key, 'number' => $number, 'expires_at' => LedgerRules::at($expiresAt)]])->code(ErrorCode::ISSUE_PENDING);
	}

	/** The document block of the ledger payload and the record: kind, name, size, hash. @return array{kind:string,name:string,size:int,sha256:string} */
	public static function documentBlock(string $kind, string $name, int $size, string $sha256): array {
		return ['kind' => $kind, 'name' => $name, 'size' => $size, 'sha256' => $sha256];
	}
}
