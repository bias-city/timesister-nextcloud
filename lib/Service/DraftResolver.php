<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\EntryMapper;
use OCA\TimeSister\Db\InvHourMapper;
use OCA\TimeSister\Db\InvInvoice;
use OCA\TimeSister\Db\InvInvoiceMapper;
use OCA\TimeSister\Db\Record;
use OCA\TimeSister\Db\RecordMapper;

/**
 * Reads everything an invoice draft needs to be issued (0.18.0) – the
 * project, the budget and its currency (both optional since 0.38.0), the customer, the settings'
 * issuer and VAT, the bookings in the book and not billed elsewhere, the
 * positions and the sums – and names what is missing (422/409). Shared by
 * issuing and by the preview (0.19.0), which runs the same checks without
 * taking a number.
 */
final class DraftResolver {
	public function __construct(
		private RecordMapper $records,
		private EntryMapper $entries,
		private InvHourMapper $hours,
		private InvInvoiceMapper $invoices,
	) {
	}

	/** A live record's data, or null. */
	public function live(int $tenantId, string $kind, string $key): ?\stdClass {
		$r = $this->records->findOne($tenantId, $kind, $key);
		return $r === null || $r->isTombstone() ? null : InvoiceRules::decode($r->getData());
	}

	/** The stored invoice record (live) and its data; 404 when there is none. @return array{0:Record,1:\stdClass} */
	public function invoiceRecord(int $tenantId, string $key): array {
		$r = $this->records->findOne($tenantId, InvoiceRules::KIND, $key);
		$d = $r === null || $r->isTombstone() ? null : InvoiceRules::decode($r->getData());
		if ($r === null || $d === null) {
			throw ApiException::notFound('This invoice does not exist.');
		}
		return [$r, $d];
	}

	/**
	 * The draft with everything resolved; throws what issuing would throw.
	 * Since 0.38.0 `project` and `budget` may be null (`projectKey` then "").
	 *
	 * @return array{record:Record,d:\stdClass,project:?\stdClass,projectKey:string,budget:?\stdClass,budgetId:?string,currency:string,customerKey:string,customer:\stdClass,customerBlock:\stdClass,settings:?\stdClass,issuer:\stdClass,vat:float,bookings:list<\stdClass>,positions:list<\stdClass>,sums:VatLines,hours:list<array{uid:string,event_uid:string,start:string,hours:float,rate:float,amount:float,code:?string,level:?string}>,receipts:array<string,\stdClass>}
	 */
	public function resolve(int $tenantId, string $key): array {
		$h = $this->head($tenantId, $key);
		$settings = $this->live($tenantId, 'setting', 'settings');
		$issuer = IssueRules::issuerOf($settings) ?? throw ApiException::invalid('The team has no invoice issuer; set “issuer” in the settings first.')->code(ErrorCode::NO_ISSUER)->field('issuer');
		[$bookings, $positions, $receipts] = $this->billables($tenantId, $key, $h['d'], $h['projectKey'], $h['customerKey']);
		// 0.39.0: advances and their deductions, the expenses invoice's main invoice.
		$this->requireLinks($tenantId, $key, $h['d'], $positions, count($bookings), $h['projectKey'], $h['budgetId'], $h['customerKey']);
		$positions = ReceiptLineRules::prepare($positions, $receipts);
		$cur = $h['record'];
		$d = $h['d'];
		$projectKey = $h['projectKey'];
		$project = $h['project'];
		$budget = $h['budget'];
		$currency = $h['currency'];
		$customerKey = $h['customerKey'];
		$customer = $h['customer'];
		// 0.20.0: the tax case is frozen with the customer; abroad the document carries no VAT.
		$issuerCountry = $issuer->country ?? null;
		$customerBlock = IssueRules::customerBlock($customer, $d, is_string($issuerCountry) ? $issuerCountry : null);
		$taxCase = (string)$customerBlock->{TaxRules::FIELD};
		$vat = TaxRules::rate($taxCase, IssueRules::vatOf($d, $settings));
		// 0.21.0: every line carries its rate and mode; the sums per rate come from VatLines.
		$positions = VatLines::freeze($positions, $vat, $taxCase);
		$sums = VatLines::of($positions, $vat, $taxCase);
		return [
			'record' => $cur,
			'd' => $d,
			'project' => $project,
			'projectKey' => $projectKey,
			'budget' => $budget,
			'budgetId' => $h['budgetId'],
			'currency' => $currency,
			'customerKey' => $customerKey,
			'customer' => $customer,
			'customerBlock' => $customerBlock,
			'settings' => $settings,
			'issuer' => $issuer,
			'vat' => $sums->rate,
			'bookings' => $bookings,
			'positions' => $positions,
			'sums' => $sums,
			'hours' => IssueRules::hoursOf($bookings),
			'receipts' => $receipts,
		];
	}

	/**
	 * The draft's record, project, budget, currency and customer, as
	 * issuing needs them (422 naming what is missing); shared with the
	 * hand-over (0.24.0). Since 0.38.0 project and budget are optional and
	 * the customer is the invoice's own, else the budget's quote's, else the
	 * project's – none is `422 invoice_no_customer`.
	 *
	 * @return array{record:Record,d:\stdClass,project:?\stdClass,projectKey:string,budget:?\stdClass,budgetId:?string,currency:string,customerKey:string,customer:\stdClass}
	 */
	public function head(int $tenantId, string $key): array {
		[$cur, $d] = $this->invoiceRecord($tenantId, $key);
		IssueRules::requireIssuable($d);
		$projectKey = InvoiceRules::keyOf($d->project ?? null) ?? '';
		$budgetId = InvoiceRules::keyOf($d->budget ?? null);
		$project = null;
		$budget = null;
		if ($projectKey !== '') {
			$project = $this->live($tenantId, 'project', $projectKey) ?? throw ApiException::invalid('The invoice’s project does not exist.');
		}
		if ($budgetId !== null) {
			if ($project === null) {
				throw ApiException::invalid('A budget belongs to a project: “budget” needs “project”.')->code(ErrorCode::INVOICE_BUDGET_WITHOUT_PROJECT)->field('budget');
			}
			$budget = IssueRules::budgetOf($project, $budgetId) ?? throw ApiException::invalid('The invoice’s budget is not in the project.');
		}
		$currency = IssueRules::currencyOf($budget, $d, $project, $this->live($tenantId, 'setting', 'settings'));
		$customerKey = IssueRules::customerOf($d, $project, $projectKey, $budgetId, $this->quotes($tenantId, $d, $budgetId)) ?? throw IssueRules::noCustomer();
		$customer = $this->live($tenantId, 'customer', $customerKey) ?? throw ApiException::invalid('The invoice’s customer does not exist.')->field('customer');
		return ['record' => $cur, 'd' => $d, 'project' => $project, 'projectKey' => $projectKey, 'budget' => $budget, 'budgetId' => $budgetId,
			'currency' => $currency, 'customerKey' => $customerKey, 'customer' => $customer];
	}

	/**
	 * The checks of 0.39.0 against the team's other invoices: an advance
	 * invoice has one advance line and no hours; a deduction names an
	 * issued advance of the same budget (project, customer) not deducted
	 * elsewhere, by its amount; an expenses invoice names its main invoice
	 * of the same customer in `companion`.
	 *
	 * @param list<\stdClass> $positions
	 */
	private function requireLinks(int $tenantId, string $key, \stdClass $d, array $positions, int $bookings, string $projectKey, ?string $budgetId, string $customerKey): void {
		AdvanceRules::checkKind($d, $positions, $bookings);
		$deducts = AdvanceRules::deducts($positions);
		if (!$deducts && ($d->kind ?? null) !== AdvanceRules::EXPENSES) {
			return;
		}
		$invoices = [];
		foreach ($this->records->findLiveByKind($tenantId, InvoiceRules::KIND) as $r) {
			$o = InvoiceRules::decode($r->getData());
			if ($o !== null) {
				$invoices[$r->getRkey()] = $o;
			}
		}
		if ($deducts) {
			AdvanceRules::checkDeductions($key, $positions, $projectKey === '' ? null : $projectKey, $budgetId, $customerKey, $invoices);
		}
		AdvanceRules::checkCompanion($key, $d, $customerKey, $invoices, fn (\stdClass $o): ?string => $this->customerKeyOf($tenantId, $o));
	}

	/** The customer key of an invoice record (its customer, the quote, the project), null when none names one. */
	public function customerKeyOf(int $tenantId, \stdClass $d): ?string {
		$projectKey = InvoiceRules::keyOf($d->project ?? null) ?? '';
		$project = $projectKey === '' ? null : $this->live($tenantId, 'project', $projectKey);
		$budgetId = InvoiceRules::keyOf($d->budget ?? null);
		return IssueRules::customerOf($d, $project, $projectKey, $budgetId, $this->quotes($tenantId, $d, $budgetId));
	}

	/**
	 * The team's live quotes, read only when the customer may come from the budget's quote.
	 *
	 * @return list<\stdClass>
	 */
	private function quotes(int $tenantId, \stdClass $d, ?string $budgetId): array {
		if ($budgetId === null || InvoiceRules::keyOf($d->customer ?? null) !== null) {
			return [];
		}
		$out = [];
		foreach ($this->records->findLiveByKind($tenantId, 'quote') as $r) {
			$q = InvoiceRules::decode($r->getData());
			if ($q !== null) {
				$out[] = $q;
			}
		}
		return $out;
	}

	/**
	 * The bookings (in the book, not billed elsewhere), the positions and
	 * the receipts they name (0.22.0: not billed by another invoice).
	 *
	 * @return array{0:list<\stdClass>,1:list<\stdClass>,2:array<string,\stdClass>}
	 */
	public function billables(int $tenantId, string $key, \stdClass $d, string $projectKey, ?string $customerKey = null): array {
		$bookings = IssueRules::bookingsOf($d);
		$this->requireBillable($tenantId, $bookings);
		$positions = IssueRules::positionsOf($d);
		$receipts = [];
		foreach (ReceiptFiles::keysOf($positions) as $k) {
			$rd = $this->live($tenantId, ReceiptRules::KIND, $k);
			if ($rd !== null) {
				$receipts[$k] = $rd;
			}
		}
		ReceiptLineRules::check($positions, $receipts, $projectKey, InvoiceRules::keyOf($d->budget ?? null) ?? '', $customerKey ?? $this->customerKeyOf($tenantId, $d), $key,
			fn (string $k): string => $this->invoices->findByKey($tenantId, $k)?->getNumber() ?? $this->externalNumber($tenantId, $k) ?? $k,
			fn (string $p): ?string => ($pd = $this->live($tenantId, 'project', $p)) === null ? null : IssueRules::customerKeyOf($pd));
		return [$bookings, $positions, $receipts];
	}

	/** The customer's name of an invoice record (its customer, the quote, the project), '' when it cannot be told (0.24.1, file names). */
	public function customerName(int $tenantId, \stdClass $d): string {
		$key = $this->customerKeyOf($tenantId, $d);
		$name = $key === null ? null : ($this->live($tenantId, 'customer', $key)?->name ?? null);
		return is_string($name) ? $name : '';
	}

	/** The accounting system's number of a handed-over invoice (0.24.0), else null. */
	private function externalNumber(int $tenantId, string $key): ?string {
		$d = $this->live($tenantId, InvoiceRules::KIND, $key);
		$n = HandoverRules::handedOverTo($d) === null ? null : ($d?->external->number ?? null);
		return is_string($n) ? $n : null;
	}

	/**
	 * Every booking must be in the book and not billed by another live
	 * invoice: per occurrence through the invoices' hours, and through a
	 * billing mark of the person's event when no server invoice bills an
	 * occurrence of it (a mark set by hand or by a client); since 0.24.0
	 * also per occurrence through the handed-over invoices.
	 *
	 * @param list<\stdClass> $bookings
	 */
	public function requireBillable(int $tenantId, array $bookings): void {
		// The handed-over invoices, loaded once when a mark needs them.
		$handed = [];
		$loaded = false;
		foreach ($bookings as $i => $b) {
			$person = (string)($b->person ?? '');
			$uid = (string)($b->uid ?? '');
			$start = (string)($b->start ?? '');
			if ($this->entries->findByEvent($tenantId, $person, $uid) === null) {
				throw ApiException::invalid(Message::of('Booking {n} is not in the book.', ['n' => $i + 1]));
			}
			$live = false;
			foreach ($this->hours->billingsOf($tenantId, $person, $uid) as $h) {
				if ($h['status'] !== InvInvoice::ISSUED && $h['status'] !== InvInvoice::PAID) {
					continue;
				}
				if ($h['start'] === $start) {
					throw ApiException::conflict(Message::of('Booking {n} is billed by invoice {number}.', ['n' => $i + 1, 'number' => $h['number']]))->code(ErrorCode::BOOKING_BILLED);
				}
				$live = true;
			}
			$mark = $this->live($tenantId, BillingRules::KIND, BillingRules::key($person, $uid));
			if ($mark === null) {
				continue;
			}
			// 0.24.0: handed-over invoices bill per occurrence through their records.
			if (!$loaded) {
				$handed = $this->handedOver($tenantId);
				$loaded = true;
			}
			$ext = $handed[$person . "\n" . $uid] ?? [];
			foreach ($ext as $x) {
				if ($x['start'] === $start) {
					throw ApiException::conflict(Message::of('Booking {n} is billed by invoice {number}.', ['n' => $i + 1, 'number' => $x['number']]))->code(ErrorCode::BOOKING_BILLED);
				}
			}
			if (!$live && $ext === []) {
				$doc = $mark->document ?? null;
				throw ApiException::conflict(Message::of('Booking {n} is billed by invoice {number}.', ['n' => $i + 1, 'number' => is_string($doc) && $doc !== '' ? $doc : '?']))->code(ErrorCode::BOOKING_BILLED);
			}
		}
	}

	/**
	 * The bookings of the handed-over invoices (0.24.0), per person and
	 * event UID (`person\nuid`): the invoice key, the accounting system's
	 * number and the occurrence's start. `$except`: an invoice left out.
	 *
	 * @return array<string,list<array{key:string,number:string,start:string}>>
	 */
	public function handedOver(int $tenantId, ?string $except = null): array {
		$out = [];
		foreach ($this->records->findLiveByKind($tenantId, InvoiceRules::KIND) as $r) {
			if ($r->getRkey() === $except) {
				continue;
			}
			$d = InvoiceRules::decode($r->getData());
			if ($d === null || HandoverRules::handedOverTo($d) === null) {
				continue;
			}
			$number = $d->external->number ?? null;
			foreach (IssueRules::bookingsOf($d) as $b) {
				$out[(string)($b->person ?? '') . "\n" . (string)($b->uid ?? '')][] = ['key' => $r->getRkey(), 'number' => is_string($number) ? $number : '?', 'start' => (string)($b->start ?? '')];
			}
		}
		return $out;
	}

	/** The name of a project or budget for the document; the key when it has none. */
	public static function nameOf(?\stdClass $o, string $fallback): string {
		$n = $o?->name ?? null;
		return is_string($n) && trim($n) !== '' ? trim($n) : $fallback;
	}

	/** The title of a booking: the event's summary in the book, or empty. */
	public function titleOf(int $tenantId, string $uid, string $eventUid): string {
		$e = $this->entries->findByEvent($tenantId, $uid, $eventUid);
		return $e === null ? '' : $e->getSummary();
	}
}
