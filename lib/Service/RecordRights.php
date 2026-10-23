<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\Record;
use OCA\TimeSister\Db\RecordMapper;

/**
 * Who reads and writes which record (not Jobs), and in which form – the
 * rules per kind in one place for list, delta, single record and history
 * (0.14.0: members see themselves, Leads their projects, Team Admins
 * everything; 0.16.0 invoices and quotes likewise). The projects a Lead
 * leads are read once per request.
 */
final class RecordRights {
	/** Kinds with rights per record: a non-admin batch may hold only these. */
	public const PER_RECORD = [RecordValidator::BILLING, BookingRules::REPORT, BookingRules::MARK, InvoiceRules::KIND, QuoteRules::KIND,
		ReceiptRules::KIND, ExpenseRuleRules::KIND, ExpenseReportRules::KIND, TemplateRules::KIND, CorrectionRules::KIND];
	/** Kinds whose Lead write is checked against the stored record, and whose tombstone keeps its last data for that. */
	public const AGAINST_STORED = [InvoiceRules::KIND, QuoteRules::KIND, ReceiptRules::KIND, ExpenseRuleRules::KIND, ExpenseReportRules::KIND];
	/** 0.22.0: every member writes these; CostWrites checks each write against the stored record. */
	public const COST_KINDS = [ReceiptRules::KIND, ExpenseRuleRules::KIND, ExpenseReportRules::KIND];

	/** @var array<string,array<string,true>> "tenant:uid" → customer keys */
	private array $customers = [];
	/** @var array<string,array<string,true>> "tenant:uid" → active projects led */
	private array $led = [];

	public function __construct(
		private AccessPolicy $policy,
		private TenantService $team,
		private RecordMapper $records,
	) {
	}

	/** The active projects the caller leads (TenantService::leadOf), by key. @return array<string,true> */
	public function ledProjects(Membership $m): array {
		$id = $m->tenantId . ':' . $m->uid;
		if (!isset($this->led[$id])) {
			$this->led[$id] = [];
			foreach ($this->team->leadOf($m->tenantId, $m->uid) as $p) {
				$this->led[$id][$p['key']] = true;
			}
		}
		return $this->led[$id];
	}

	/**
	 * The customers a Lead reads: named by a project they lead.
	 *
	 * @param list<string> $ownKeys
	 * @return array<string,true>
	 */
	public function customersOf(Membership $m, array $ownKeys): array {
		$id = $m->tenantId . ':' . $m->uid;
		if (!isset($this->customers[$id])) {
			$projects = array_map(static function (Record $r): mixed {
				$raw = $r->getData();
				return $raw === null ? null : Json::decode($raw);
			}, $this->records->findLiveByKind($m->tenantId, 'project'));
			$this->customers[$id] = ProjectAccess::customersLedBy($projects, $ownKeys);
		}
		return $this->customers[$id];
	}

	/**
	 * May the caller read this record (list, delta, single, history of the
	 * per-record kinds)?
	 *
	 * @param list<string> $accounts the record's accounts (only for `person`)
	 * @param list<string> $ownKeys the caller's person keys
	 * @param ?Record $record the stored record (invoices, quotes: its data decides)
	 */
	public function canRead(Membership $m, string $kind, string $key, array $accounts, array $ownKeys, ?Record $record = null): bool {
		if ($kind === QuoteRules::KIND) {
			// Team Admins; Leads those naming them or of a project they lead, also as a tombstone.
			return $m->manages() || ($this->ledProjects($m) !== []
				&& QuoteRules::leadReads(InvoiceRules::decode($record?->getData()), $ownKeys, $this->ledProjects($m)));
		}
		if (in_array($kind, self::COST_KINDS, true)) {
			return $this->canReadCost($m, $kind, InvoiceRules::decode($record?->getData()));
		}
		if ($kind === InvoiceRules::KIND) {
			// Team Admins; Leads those of the active projects they lead, also as a tombstone.
			$project = InvoiceRules::projectOf($record?->getData());
			return $m->manages() || ($project !== null && isset($this->ledProjects($m)[$project]));
		}
		if ($kind === RecordValidator::BILLING) {
			$person = BillingRules::personOf($key);
			return BillingRules::canRead($m->role, $person !== null && in_array($person, $ownKeys, true));
		}
		if ($kind === CorrectionRules::KIND) {
			// 0.27.0: Team Admins; the person (their locked fields); a Lead their own and those of the projects they lead.
			return $this->canReadCorrection($m, $key, $ownKeys, InvoiceRules::decode($record?->getData()));
		}
		if (BookingRules::isKind($kind)) {
			return BookingRules::canRead($m->role, $m->uid, $kind, $key, $m->manages() ? [] : $this->ledProjects($m));
		}
		// 0.27.0: office costs, balances and reserve (cashflow) reach Team Admins only.
		if ($kind === 'setting' && $key === OfficeCostRules::KEY) {
			return $m->manages();
		}
		if (!$this->policy->canRead($m, $kind, $key, $accounts)) {
			return false;
		}
		// Leads: only the customers of their projects (0.14.0).
		if ($kind === 'customer' && !$m->manages()) {
			return isset($this->customersOf($m, $ownKeys)[$key]);
		}
		return true;
	}

	/** A correction record: Team Admins, the person, the Lead who wrote it or who leads a project of its codes. */
	private function canReadCorrection(Membership $m, string $key, array $ownKeys, ?\stdClass $d): bool {
		if ($m->manages()) {
			return true;
		}
		$person = CorrectionRules::personOf($key);
		if ($person !== null && in_array($person, $ownKeys, true)) {
			return true;
		}
		if ($m->role !== Role::LEAD || $d === null) {
			return false;
		}
		if (($d->by ?? null) === $m->uid) {
			return true;
		}
		$rawCodes = $d->codes ?? null;
		$codes = is_array($rawCodes) ? $rawCodes : [];
		$code = $d->code ?? null;
		if (is_string($code)) {
			$codes[] = $code;
		}
		$led = \OCP\Server::get(EntryService::class)->ledCodesOf($m);
		foreach ($codes as $c) {
			$c = mb_strtolower((string)$c);
			foreach ($led as $l) {
				if ($c === $l || str_starts_with($c, $l . '.')) {
					return true;
				}
			}
		}
		return false;
	}

	/** Receipts and expense rules (0.22.0): the person, the project's Leads, Team Admins; expense reports (0.23.0): the person and Team Admins. */
	public function canReadCost(Membership $m, string $kind, ?\stdClass $d): bool {
		$led = $m->manages() ? [] : $this->ledProjects($m);
		return match ($kind) {
			ReceiptRules::KIND => ReceiptRules::canRead($m->manages(), $m->uid, $led, $d),
			ExpenseRuleRules::KIND => ExpenseRuleRules::canRead($m->manages(), $m->uid, $led, $d),
			default => ExpenseReportRules::canRead($m->manages(), $m->uid, $d),
		};
	}

	/** The rights of a write: per record for billing and bookings, everything else Team Admins. */
	public function requireWrite(Membership $m, string $kind, string $key): void {
		if ($kind === RecordValidator::BILLING) {
			BillingRules::requirePerson($key);
			if (!BillingRules::canWrite($m->role)) {
				throw ApiException::forbidden('Only Team Admins mark bookings as billed.');
			}
			return;
		}
		if (BookingRules::isKind($kind)) {
			BookingRules::requireWrite($m->role, $m->uid, $kind, $key, $m->manages() ? [] : $this->ledProjects($m));
			return;
		}
		if ($kind === CorrectionRules::KIND) {
			// 0.27.0: Team Admins and Leads; the project is checked as the record is applied (CorrectionService::prepare).
			if (CorrectionRules::personOf($key) === null) {
				throw ApiException::invalid('The key of a correction is the person’s key, “+” and 32 hex digits of the SHA-256 of the event UID (and recurrence).');
			}
			if (!$m->manages() && $m->role !== Role::LEAD) {
				throw ApiException::forbidden('Only Team Admins and the project’s Leads correct bookings.');
			}
			return;
		}
		if ($kind === InvoiceRules::KIND) {
			// A Lead's write is checked against the stored invoice: requireInvoiceWrite().
			if (!$m->manages() && $this->ledProjects($m) === []) {
				throw ApiException::forbidden('Only Team Admins and the project’s Leads write invoices.');
			}
			return;
		}
		if (in_array($kind, self::COST_KINDS, true)) {
			// Every member: CostWrites checks the write against the stored record.
			return;
		}
		if ($kind === TemplateRules::KIND) {
			// 0.25.0: Team Admins and Leads; a Lead's write is checked against the stored template.
			if (!$m->manages() && $m->role !== Role::LEAD) {
				throw ApiException::forbidden('Only Team Admins and Leads write templates.');
			}
			return;
		}
		if ($kind === QuoteRules::KIND) {
			// Likewise for a quote: requireQuoteWrite(). A Member has none to write.
			if (!$m->manages() && $this->ledProjects($m) === []) {
				throw ApiException::forbidden('Only Team Admins and the quote’s or project’s Leads write quotes.');
			}
			return;
		}
		$this->policy->requireWrite($m);
	}

	/**
	 * An invoice write against the stored state (inside the write's
	 * transaction): Team Admins anything, a Lead only drafts without a
	 * number of projects they lead ({@see InvoiceRules::requireLeadWrite()}).
	 *
	 * @param ?Record $cur the stored record; a tombstone keeps its last data
	 * @param ?string $json the new data, null when deleting
	 */
	public function requireInvoiceWrite(Membership $m, ?Record $cur, ?string $json): void {
		if ($m->manages()) {
			return;
		}
		InvoiceRules::requireLeadWrite(InvoiceRules::decode($cur?->getData()), InvoiceRules::decode($json), $this->ledProjects($m));
	}

	/**
	 * A quote write against the stored state, likewise: a Lead only quotes
	 * they read before and after ({@see QuoteRules::requireLeadWrite()}).
	 *
	 * @param list<string> $ownKeys the caller's person keys
	 */
	public function requireQuoteWrite(Membership $m, ?Record $cur, ?string $json, array $ownKeys): void {
		if ($m->manages()) {
			return;
		}
		QuoteRules::requireLeadWrite(InvoiceRules::decode($cur?->getData()), InvoiceRules::decode($json), $ownKeys, $this->ledProjects($m));
	}

	/** A template write against the stored record: Team Admins anything, a Lead their own ({@see TemplateRules::requireLeadWrite()}). */
	public function requireTemplateWrite(Membership $m, ?Record $cur): void {
		if ($m->manages()) {
			return;
		}
		TemplateRules::requireLeadWrite($cur, $m->uid);
	}

	/** Is the caller's batch limited to per-record kinds (then it is checked write by write)? */
	public static function perRecordOnly(mixed $writes): bool {
		if (!is_array($writes) || $writes === []) {
			return false;
		}
		foreach ($writes as $w) {
			if (!($w instanceof \stdClass) || !in_array($w->kind ?? null, self::PER_RECORD, true)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The data as the caller may read it: a project full only for Team
	 * Admins and its leads (otherwise the booking catalog), a person full
	 * only for Team Admins – themselves without the admin-only fields
	 * (`cost_rates`), others as the name card without the feed.
	 *
	 * @param list<string> $ownKeys
	 */
	public function shape(Membership $m, Record $r, mixed $data, array $ownKeys): mixed {
		if (!($data instanceof \stdClass) || $m->manages()) {
			return $data;
		}
		if ($r->getKind() === 'project' && !$this->policy->canSeeFullProject($m, $data, $ownKeys)) {
			return ProjectAccess::catalog($data);
		}
		// 0.27.0: rates (the levels' defaults, a person's level) reach Leads, never members.
		$lead = $this->ledProjects($m) !== [];
		if ($r->getKind() === 'person') {
			return AccessPolicy::isOwnPerson($m->uid, $r->getRkey(), $r->accountList())
				? PersonAccess::own($data, $lead) : PersonAccess::card($data, $lead);
		}
		if ($r->getKind() === 'setting' && $r->getRkey() === 'settings' && !$lead) {
			return LevelRules::withoutRates($data);
		}
		return $data;
	}
}
