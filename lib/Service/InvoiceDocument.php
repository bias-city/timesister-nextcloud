<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * What a rendered invoice shows (0.19.0): the frozen content plus the
 * names of project and budget, the hours appendix with person names and
 * titles, the chain line, the language, the watermark of a preview and the
 * IBAN of the QR bill. Filled by DocumentService, drawn by InvoicePdf.
 */
final class InvoiceDocument {
	public string $lang = DocumentRules::EN;
	/** VORABZUG / DRAFT on a preview, null on an issued document. */
	public ?string $watermark = null;
	/** Empty on a preview: the document says “(not yet issued)”. */
	public string $number = '';
	public ?string $reverses = null;
	public ?string $credits = null;
	/** The number of the invoice a reversal reverses. */
	public ?string $originalNumber = null;
	/** @var array<string,mixed> name, street, postcode, city, country, vat_id, email, phone, foreign_note */
	public array $issuer = [];
	/** @var array<string,mixed> name, address_extra, street, postcode, city, country, vat_id, email, phone, contact */
	public array $customer = [];
	public string $issuedOn = '';
	public string $dueOn = '';
	public string $from = '';
	public string $to = '';
	public string $project = '';
	public string $budget = '';
	public ?string $greeting = null;
	public ?string $body = null;
	public ?string $footer = null;
	/** @var list<array<string,mixed>> kind, text, hours, rate, amount */
	public array $positions = [];
	public string $currency = 'CHF';
	public float $vat = 0.0;
	public float $net = 0.0;
	public float $vatAmount = 0.0;
	public float $gross = 0.0;
	/** @var list<array{date:string,person:string,code:string,title:string,hours:float}> */
	public array $appendix = [];
	public float $hoursTotal = 0.0;
	public string $chainLine = '';
	/** The issuer's IBAN in the invoice's currency when a QR bill is wanted; null: no payment part. */
	public ?string $iban = null;

	/** @return array<string,string> the labels in the document's language */
	public function texts(): array {
		return DocumentRules::texts($this->lang);
	}

	public function title(): string {
		$t = $this->texts();
		if ($this->number === '') {
			return $t['invoice'] . ' ' . $t['not_issued'];
		}
		return DocumentRules::title($t, $this->number, $this->reverses, $this->credits, $this->originalNumber);
	}

	/** A QR bill is drawn for a positive amount in CHF or EUR with an IBAN. */
	public function wantsQrBill(): bool {
		return $this->iban !== null && $this->gross > 0 && in_array($this->currency, ['CHF', 'EUR'], true);
	}
}
