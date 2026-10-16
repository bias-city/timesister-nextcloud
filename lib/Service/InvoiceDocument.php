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
	/** The invoice's single rate (0.20.0 readers); the sums per rate are in `sums`. */
	public float $vat = 0.0;
	/** 0.21.0: net and VAT per rate, gross, pass-through items, total – frozen, or the preview's. */
	public VatLines $sums;
	/** @var list<array{date:string,person:string,code:string,title:string,hours:float}> */
	public array $appendix = [];
	public float $hoursTotal = 0.0;
	/** @var list<array{head:string,content:?string,mime:string,path:string,sha256:string}> 0.22.0: the receipt copies after the appendix */
	public array $copies = [];
	public string $chainLine = '';
	/** The issuer's IBAN in the invoice's currency when a QR bill is wanted; null: no payment part. */
	public ?string $iban = null;
	/** 0.20.0: the issuer's account in the currency for the e-invoice (regardless of `issuer.qr`). */
	public ?string $accountIban = null;
	public ?string $accountBic = null;
	/** 0.20.0: the frozen tax case, the QR reference of a QR-IBAN, the customer's key and Leitweg-ID, whether an XRechnung is wanted. */
	public string $taxCase = TaxRules::DOMESTIC;
	public ?string $qrReference = null;
	public ?string $customerKey = null;
	public ?string $leitwegId = null;
	public bool $eInvoice = false;

	public function __construct() {
		$this->sums = VatLines::of([], 0.0, TaxRules::DOMESTIC);
	}

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

	/** The single VAT rate (one line): the invoice's at home, 0 for reverse charge and export. */
	public function vatRate(): float {
		return TaxRules::rate($this->taxCase, $this->vat);
	}

	/** The note under the sums for reverse charge and export; null at home. */
	public function taxNote(): ?string {
		return TaxRules::note($this->taxCase, $this->lang);
	}

	/** An XRechnung file is written when the customer asks for e-invoices or has a Leitweg-ID. */
	public function wantsXRechnung(): bool {
		return $this->eInvoice || ($this->leitwegId !== null && $this->leitwegId !== '');
	}

	/** A QR bill is drawn for a positive total in CHF or EUR with an IBAN. */
	public function wantsQrBill(): bool {
		return $this->iban !== null && $this->sums->total > 0 && in_array($this->currency, ['CHF', 'EUR'], true);
	}

	/** The taxable lines (the positions table) and the pass-through items (their own block). @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>} */
	public function splitPositions(): array {
		$taxable = [];
		$pass = [];
		foreach ($this->positions as $p) {
			if (VatLines::isPassThrough($p)) {
				$pass[] = $p;
			} else {
				$taxable[] = $p;
			}
		}
		return [$taxable, $pass];
	}
}
