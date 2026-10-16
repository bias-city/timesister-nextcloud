<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use horstoeko\zugferd\ZugferdDocumentBuilder;
use horstoeko\zugferd\ZugferdDocumentPdfBuilder;
use horstoeko\zugferd\ZugferdProfiles;
use horstoeko\zugferd\ZugferdSettings;

/**
 * The e-invoice of a document (0.20.0): the CII XML (EN 16931) from the
 * frozen content through `horstoeko/zugferd`, embedded into the rendered
 * PDF as `factur-x.xml` (PDF/A-3, ZUGFeRD), and the same content in the
 * XRechnung 3.0 profile as its own file for German public customers. No
 * files, no network; the library validates nothing here.
 */
final class EInvoiceXml {
	public const FILE = 'factur-x.xml';
	/** UN/ECE 1001: invoice, corrected invoice (reversal and credit note with negated amounts). */
	public const TYPE_INVOICE = '380';
	public const TYPE_CORRECTION = '384';
	/** UN/ECE 4461: credit transfer (58 SEPA, 30 other). */
	private const MEANS_SEPA = '58';
	private const MEANS_TRANSFER = '30';
	private const UNIT_HOUR = 'HUR';
	private const UNIT_ONE = 'C62';

	/** The PDF with the EN 16931 XML embedded (PDF/A-3); the chain line stays the subject. */
	public function embed(InvoiceDocument $d, string $pdf): string {
		$builder = $this->builder($d, ZugferdProfiles::PROFILE_EN16931);
		$out = new ZugferdDocumentPdfBuilder($builder, $pdf);
		$out->setAdditionalCreatorTool('TimeSister');
		$chainLine = $d->chainLine;
		$title = $d->title();
		$out->setMetaInformationCallback(static function (string $which) use ($chainLine, $title): string {
			return match ($which) {
				'subject' => $chainLine,
				'title' => $title,
				'keywords' => 'TimeSister, FacturX/ZUGFeRD',
				default => '',
			};
		});
		// Nextcloud's entity loader answers every file load with null, which also stops the
		// library's simplexml_load_file() of its XMP template: when that is the case, allow
		// that one file while embedding and deny everything again afterwards (as Nextcloud does).
		$asset = (string)realpath(ZugferdSettings::getFullXmpMetaDataFilename());
		$blocked = $asset === '' || !self::loads($asset);
		if ($blocked) {
			libxml_set_external_entity_loader(static function (?string $public, string $system) use ($asset): ?string {
				$path = str_starts_with($system, 'file://') ? substr($system, 7) : $system;
				return realpath($path) === $asset ? $asset : null;
			});
		}
		try {
			return $out->generateDocument()->downloadString();
		} finally {
			if ($blocked) {
				libxml_set_external_entity_loader(static fn (): ?string => null);
			}
		}
	}

	/** Can libxml load this file through the current entity loader? */
	private static function loads(string $file): bool {
		$before = libxml_use_internal_errors(true);
		$ok = simplexml_load_file($file) !== false;
		libxml_clear_errors();
		libxml_use_internal_errors($before);
		return $ok;
	}

	/** The XRechnung 3.0 XML (CII) as a string. */
	public function xrechnung(InvoiceDocument $d): string {
		return $this->builder($d, ZugferdProfiles::PROFILE_XRECHNUNG_3)->getContent();
	}

	/** The EN 16931 XML alone (tests and checks). */
	public function xml(InvoiceDocument $d): string {
		return $this->builder($d, ZugferdProfiles::PROFILE_EN16931)->getContent();
	}

	/** The document in a profile: parties, period, positions, taxes, sums, payment, notes. */
	public function builder(InvoiceDocument $d, int $profile): ZugferdDocumentBuilder {
		$b = ZugferdDocumentBuilder::createNew($profile);
		$correction = $d->reverses !== null || $d->credits !== null;
		$b->setDocumentInformation($d->number, $correction ? self::TYPE_CORRECTION : self::TYPE_INVOICE, self::date($d->issuedOn), $d->currency);
		if ($d->chainLine !== '') {
			$b->addDocumentNote($d->chainLine);
		}
		if ($correction && $d->originalNumber !== null) {
			$b->setDocumentInvoiceReferencedDocument($d->originalNumber);
		}
		// XRechnung needs a buyer reference (BT-10): the Leitweg-ID, else the customer's key.
		$reference = $d->leitwegId ?? ($profile === ZugferdProfiles::PROFILE_XRECHNUNG_3 ? $d->customerKey : null);
		if ($reference !== null && $reference !== '') {
			$b->setDocumentBuyerReference($reference);
		}
		$this->seller($b, $d);
		$this->buyer($b, $d);
		if ($d->from !== '' && $d->to !== '') {
			$b->setDocumentBillingPeriod(self::date($d->from), self::date($d->to), null);
		}
		// 0.21.0: one tax group per rate and category, the pass-through items as E (TaxRules::breakdown).
		$s = $d->sums;
		[, $pass] = $d->splitPositions();
		$withPass = $pass !== [] || $s->passThrough !== 0.0;
		$groups = TaxRules::breakdown($s, $d->taxCase, self::str($d->customer['country'] ?? null), $withPass);
		if ($groups === []) {
			$rate = $d->vatRate();
			$code = TaxRules::code($d->taxCase, $rate, self::str($d->customer['country'] ?? null));
			$groups[] = ['code' => $code, 'rate' => $rate, 'basis' => 0.0, 'tax' => 0.0, 'reason' => TaxRules::note($d->taxCase, DocumentRules::EN), 'reason_code' => TaxRules::exemptionCode($code)];
		}
		$this->positions($b, $d, $groups);
		foreach ($groups as $g) {
			$b->addDocumentTax($g['code'], 'VAT', $g['basis'], $g['tax'], $g['rate'], $g['reason'], $g['reason_code']);
		}
		$this->payment($b, $d);
		// The line total and the tax basis include the pass-through items (BR-CO-10, BR-CO-13); grand total and due = total.
		$basis = round($s->net + $s->passThrough, 2);
		$b->setDocumentSummation($s->total, $s->total, $basis, 0.0, 0.0, $basis, $s->vatAmount, null, 0.0);
		return $b;
	}

	private function seller(ZugferdDocumentBuilder $b, InvoiceDocument $d): void {
		$i = $d->issuer;
		$name = self::str($i['name'] ?? null);
		$b->setDocumentSeller($name);
		$b->setDocumentSellerAddress(self::nullable($i['street'] ?? null), null, null, self::nullable($i['postcode'] ?? null), self::nullable($i['city'] ?? null), self::country($i['country'] ?? null));
		$vat = self::nullable($i['vat_id'] ?? null);
		if ($vat !== null) {
			$b->addDocumentSellerVATRegistrationNumber($vat);
		}
		$email = self::nullable($i['email'] ?? null);
		$phone = self::nullable($i['phone'] ?? null);
		// The contact (BT-41–43) and the electronic address (BT-34) are required by XRechnung.
		$b->setDocumentSellerContact($name, null, $phone, null, $email);
		if ($email !== null) {
			$b->setDocumentSellerCommunication('EM', $email);
		}
	}

	private function buyer(ZugferdDocumentBuilder $b, InvoiceDocument $d): void {
		$c = $d->customer;
		$b->setDocumentBuyer(self::str($c['name'] ?? null), self::nullable($c['id'] ?? null));
		$b->setDocumentBuyerAddress(self::nullable($c['street'] ?? null), self::nullable($c['address_extra'] ?? null), null, self::nullable($c['postcode'] ?? null), self::nullable($c['city'] ?? null), self::country($c['country'] ?? null));
		$vat = self::nullable($c['vat_id'] ?? null);
		if ($vat !== null) {
			$b->addDocumentBuyerVATRegistrationNumber($vat);
		}
		$email = self::nullable($c['email'] ?? null);
		if ($email !== null) {
			$b->setDocumentBuyerCommunication('EM', $email);
		}
		$contact = $c['contact'] ?? null;
		$contactName = is_array($contact) ? self::nullable($contact['name'] ?? null) : self::nullable($contact);
		if ($contactName !== null) {
			$b->setDocumentBuyerContact($contactName, null, null, null, null);
		}
	}

	/**
	 * One line per position: hours as quantity (HUR) × rate, everything
	 * else as one piece (C62) at the amount; a negative amount makes the
	 * quantity negative (the price is never negative, BR-27); a text line
	 * is a line at 0. Each line carries its own category and rate (0.21.0).
	 */
	/** @param non-empty-list<array{code:string,rate:float,basis:float,tax:float,reason:?string,reason_code:?string}> $groups */
	private function positions(ZugferdDocumentBuilder $b, InvoiceDocument $d, array $groups): void {
		$country = self::str($d->customer['country'] ?? null);
		$known = [];
		foreach ($groups as $g) {
			$known[TaxRules::groupKey($g['code'], $g['rate'])] = true;
		}
		$n = 0;
		foreach ($d->positions as $p) {
			$n++;
			$kind = self::str($p['kind'] ?? null);
			$amount = round(self::float($p['amount'] ?? 0), 2);
			$hours = self::float($p['hours'] ?? 0);
			$price = self::float($p['rate'] ?? 0);
			if ($kind === 'hours' && $hours > 0 && $price > 0) {
				$quantity = $amount < 0 ? -$hours : $hours;
				$unit = self::UNIT_HOUR;
			} else {
				$quantity = $amount < 0 ? -1.0 : ($amount > 0 ? 1.0 : 0.0);
				$price = abs($amount);
				$unit = self::UNIT_ONE;
			}
			$b->addNewPosition((string)$n);
			$b->setDocumentPositionProductDetails(mb_substr(self::str($p['text'] ?? null) === '' ? '–' : self::str($p['text'] ?? null), 0, 500));
			$b->setDocumentPositionNetPrice(abs($price));
			$b->setDocumentPositionQuantity($quantity, $unit);
			[$code, $rate] = TaxRules::lineCategory($p, $d->vat, $d->taxCase, $country);
			// A line of amount 0 (a text) at a rate no other line has: the first group's, so every line has its group.
			if (!isset($known[TaxRules::groupKey($code, $rate)])) {
				[$code, $rate] = [$groups[0]['code'], $groups[0]['rate']];
			}
			$b->addDocumentPositionTax($code, 'VAT', $rate);
			$b->setDocumentPositionLineSummation($amount);
		}
	}

	/** Credit transfer to the issuer's account in the currency, the QR reference (or the number) as remittance; due date as the term. */
	private function payment(ZugferdDocumentBuilder $b, InvoiceDocument $d): void {
		$iban = $d->accountIban;
		if ($iban !== null) {
			$b->addDocumentPaymentMeanToCreditTransfer($iban, self::nullable($d->issuer['name'] ?? null), null, $d->accountBic, $d->qrReference ?? $d->number);
		} else {
			$b->addDocumentPaymentMean(self::MEANS_TRANSFER, null);
		}
		if ($d->dueOn !== '') {
			$b->addDocumentPaymentTerm($d->texts()['due'] . ' ' . DocumentRules::day($d->dueOn, $d->lang), self::date($d->dueOn));
		}
	}

	private static function date(string $day): \DateTimeImmutable {
		return new \DateTimeImmutable(substr($day, 0, 10) . ' 00:00:00', new \DateTimeZone('UTC'));
	}

	private static function country(mixed $v): ?string {
		$c = strtoupper(trim(self::str($v)));
		return strlen($c) === 2 ? $c : null;
	}

	private static function str(mixed $v): string {
		return is_string($v) ? $v : (is_int($v) || is_float($v) ? (string)$v : '');
	}

	private static function nullable(mixed $v): ?string {
		$s = trim(self::str($v));
		return $s === '' ? null : $s;
	}

	private static function float(mixed $v): float {
		return is_int($v) || is_float($v) ? (float)$v : (is_numeric($v) ? (float)$v : 0.0);
	}
}
