<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\EntryMapper;
use OCA\TimeSister\Db\InvHourMapper;
use OCA\TimeSister\Db\InvInvoice;
use OCA\TimeSister\Db\Record;
use OCA\TimeSister\Db\RecordMapper;

/**
 * Reads everything an invoice draft needs to be issued (0.18.0) – the
 * project, the budget and its currency, the customer, the settings'
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
	 *
	 * @return array{record:Record,d:\stdClass,project:\stdClass,projectKey:string,budget:\stdClass,currency:string,customerKey:string,customer:\stdClass,customerBlock:\stdClass,settings:?\stdClass,issuer:\stdClass,vat:float,bookings:list<\stdClass>,positions:list<\stdClass>,sums:array{net:float,vat_amount:float,gross:float},hours:list<array{uid:string,event_uid:string,start:string,hours:float,rate:float,amount:float,code:?string}>}
	 */
	public function resolve(int $tenantId, string $key): array {
		[$cur, $d] = $this->invoiceRecord($tenantId, $key);
		IssueRules::requireIssuable($d);
		$projectKey = (string)$d->project;
		$project = $this->live($tenantId, 'project', $projectKey) ?? throw ApiException::invalid('The invoice’s project does not exist.');
		$budget = IssueRules::budgetOf($project, (string)$d->budget) ?? throw ApiException::invalid('The invoice’s budget is not in the project.');
		$currency = IssueRules::currencyOf($budget, $d) ?? throw ApiException::invalid('The budget has no currency.');
		$customerKey = IssueRules::customerKeyOf($project) ?? throw ApiException::invalid('The project names no customer.');
		$customer = $this->live($tenantId, 'customer', $customerKey) ?? throw ApiException::invalid('The project’s customer does not exist.');
		$settings = $this->live($tenantId, 'setting', 'settings');
		$issuer = IssueRules::issuerOf($settings) ?? throw ApiException::invalid('The team has no invoice issuer; set “issuer” in the settings first.');
		$vat = IssueRules::vatOf($d, $settings);
		$bookings = IssueRules::bookingsOf($d);
		$this->requireBillable($tenantId, $bookings);
		$positions = IssueRules::positionsOf($d);
		return [
			'record' => $cur,
			'd' => $d,
			'project' => $project,
			'projectKey' => $projectKey,
			'budget' => $budget,
			'currency' => $currency,
			'customerKey' => $customerKey,
			'customer' => $customer,
			'customerBlock' => IssueRules::customerBlock($customer, $d),
			'settings' => $settings,
			'issuer' => $issuer,
			'vat' => $vat,
			'bookings' => $bookings,
			'positions' => $positions,
			'sums' => IssueRules::sums($positions, $vat),
			'hours' => IssueRules::hoursOf($bookings),
		];
	}

	/**
	 * Every booking must be in the book and not billed by another live
	 * invoice: per occurrence through the invoices' hours, and through a
	 * billing mark of the person's event when no server invoice bills an
	 * occurrence of it (a mark set by hand or by a client).
	 *
	 * @param list<\stdClass> $bookings
	 */
	public function requireBillable(int $tenantId, array $bookings): void {
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
					throw ApiException::conflict(Message::of('Booking {n} is billed by invoice {number}.', ['n' => $i + 1, 'number' => $h['number']]));
				}
				$live = true;
			}
			if (!$live) {
				$mark = $this->live($tenantId, BillingRules::KIND, BillingRules::key($person, $uid));
				if ($mark !== null) {
					$doc = $mark->document ?? null;
					throw ApiException::conflict(Message::of('Booking {n} is billed by invoice {number}.', ['n' => $i + 1, 'number' => is_string($doc) && $doc !== '' ? $doc : '?']));
				}
			}
		}
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
