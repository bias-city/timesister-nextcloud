<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * Issuing an invoice (0.18.0), the pure part: what a draft needs, the
 * request fields, the sums the server computes from the positions, the
 * hours per booking, the frozen blocks (issuer, customer) and the payload
 * the ledger hashes. The service (IssueService) does the reading and the
 * transaction.
 */
final class IssueRules {
	public const DUE_DAYS = 30;
	public const DUE_DAYS_MAX = 365;
	public const REASON_MAX = 500;
	public const NOTE_MAX = 500;
	public const REVERSAL = 'reversal';
	public const CREDIT = 'credit';
	/** The team's default VAT rate in `setting/settings`, percent. */
	public const VAT_FIELD = 'vat';
	/** Which customer fields are frozen. */
	private const CUSTOMER_FIELDS = ['id', 'name', 'address_extra', 'street', 'postcode', 'city', 'country', 'email', 'phone', 'vat_id'];
	private const CONTROL_TEXT = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/';

	/** The draft's fitness for issuing: a draft without number, with a budget and something to bill. */
	public static function requireIssuable(\stdClass $d): void {
		if (($d->status ?? null) !== InvoiceRules::DRAFT) {
			throw ApiException::invalid('Only a draft can be issued.');
		}
		if (($d->number ?? null) !== null) {
			throw ApiException::invalid('The draft already has a number.');
		}
		$budget = $d->budget ?? null;
		if (!is_string($budget) || trim($budget) === '') {
			throw ApiException::invalid('“budget” is required: every invoice belongs to a budget.');
		}
		if (self::positionsOf($d) === [] && self::bookingsOf($d) === []) {
			throw ApiException::invalid('The draft has neither positions nor bookings.');
		}
	}

	/** @return list<\stdClass> */
	public static function positionsOf(\stdClass $d): array {
		$p = $d->positions ?? null;
		return is_array($p) ? array_values(array_filter($p, static fn (mixed $x): bool => $x instanceof \stdClass)) : [];
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
	 * The sums from the positions: net = Σ amount, VAT rounded to cents,
	 * gross. The client's amounts are taken as sent.
	 *
	 * @param list<\stdClass> $positions
	 * @return array{net:float,vat_amount:float,gross:float}
	 */
	public static function sums(array $positions, float $vat): array {
		$net = 0.0;
		foreach ($positions as $p) {
			$a = $p->amount ?? 0;
			$net += is_int($a) || is_float($a) ? (float)$a : 0.0;
		}
		$net = round($net, 2);
		$vatAmount = round($net * $vat / 100.0, 2);
		return ['net' => $net, 'vat_amount' => $vatAmount, 'gross' => round($net + $vatAmount, 2)];
	}

	/**
	 * The positions with every amount negated (reversal, credit note).
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
	 * when missing), amount = hours × rate, code.
	 *
	 * @param list<\stdClass> $bookings
	 * @return list<array{uid:string,event_uid:string,start:string,hours:float,rate:float,amount:float,code:?string}>
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

	/** The budget's currency, else the draft's; null when neither names one. */
	public static function currencyOf(\stdClass $budget, \stdClass $d): ?string {
		foreach ([$budget->currency ?? null, $d->currency ?? null] as $c) {
			if (is_string($c) && preg_match(ProjectRules::CURRENCY_PATTERN, $c) === 1) {
				return strtoupper($c);
			}
		}
		return null;
	}

	/** The project's customer key (`customer`, older records `kunde`). */
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
	 * and the contact the draft names (`contact`: the id of one of the
	 * customer's `contacts`, or free text).
	 */
	public static function customerBlock(\stdClass $customer, \stdClass $d): \stdClass {
		$out = new \stdClass();
		foreach (self::CUSTOMER_FIELDS as $f) {
			$v = $customer->{$f} ?? null;
			if (is_string($v) && $v !== '') {
				$out->{$f} = $v;
			}
		}
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
	 * (status, paid, reversed_by, documents).
	 *
	 * @param list<\stdClass> $positions
	 * @param list<array{uid:string,event_uid:string,start:string,hours:float,rate:float,amount:float,code:?string}> $hours
	 * @return array<string,mixed>
	 */
	public static function payload(string $key, string $number, string $issuedOn, string $issuedBy, string $dueOn, string $project, string $budget, ?string $customerKey, string $from, string $to, string $currency, float $vat, array $sums, \stdClass $issuer, \stdClass $customer, array $positions, array $hours, ?string $greeting, ?string $body, ?string $footer, ?string $reverses, ?string $credits): array {
		return [
			'key' => $key,
			'number' => $number,
			'issued_on' => $issuedOn,
			'issued_by' => $issuedBy,
			'due_on' => $dueOn,
			'project' => $project,
			'budget' => $budget,
			'customer_key' => $customerKey,
			'period' => ['from' => $from, 'to' => $to],
			'currency' => $currency,
			'vat' => $vat,
			'net' => $sums['net'],
			'vat_amount' => $sums['vat_amount'],
			'gross' => $sums['gross'],
			'issuer' => $issuer,
			'customer' => $customer,
			'positions' => $positions,
			'hours' => $hours,
			'greeting' => $greeting,
			'body' => $body,
			'footer' => $footer,
			'reverses' => $reverses,
			'credits' => $credits,
		];
	}

	/** A text field of the draft, or null. */
	public static function textOf(\stdClass $d, string $field): ?string {
		$v = $d->{$field} ?? null;
		return is_string($v) && $v !== '' ? $v : null;
	}

	/** Money and hours as the DECIMAL columns take them. */
	public static function decimal(float $v): string {
		return number_format($v, 2, '.', '');
	}

	/** A fresh record key for a reversal or credit note. */
	public static function newKey(): string {
		return 'inv-' . bin2hex(random_bytes(4));
	}
}
