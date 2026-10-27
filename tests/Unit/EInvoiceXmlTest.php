<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use horstoeko\zugferd\ZugferdDocumentPdfReader;
use horstoeko\zugferd\ZugferdDocumentReader;
use horstoeko\zugferd\ZugferdProfiles;
use horstoeko\zugferd\ZugferdXsdValidator;
use OCA\TimeSister\Service\DocumentRules;
use OCA\TimeSister\Service\EInvoiceXml;
use OCA\TimeSister\Service\InvoiceDocument;
use OCA\TimeSister\Service\InvoicePdf;
use OCA\TimeSister\Service\VatLines;
use OCA\TimeSister\Service\QrReferenceRules;
use PHPUnit\Framework\TestCase;

/**
 * The e-invoice (0.20.0) without Nextcloud: the rendered PDF gets
 * `factur-x.xml` embedded (PDF/A-3) and the library reads it back; the
 * XML passes the XSD of its profile (EN 16931, XRechnung 3.0); the tax
 * cases give their category codes; the Leitweg-ID is the buyer reference;
 * the QR reference and the tax note reach the PDF.
 */
class EInvoiceXmlTest extends TestCase {
	private static function document(): InvoiceDocument {
		$d = new InvoiceDocument();
		$d->lang = 'de';
		$d->number = 'RE-2026-0007';
		$d->issuer = ['name' => 'Muster sàrl', 'street' => 'Musterstrasse 12', 'postcode' => '4000', 'city' => 'Basel', 'country' => 'CH', 'vat_id' => 'CHE-123.456.789 MWST', 'email' => 'mail@example.test', 'phone' => '+41 61 000 00 00'];
		$d->customer = ['id' => 'k1', 'name' => 'Müller AG', 'street' => 'Platz 1', 'postcode' => '4001', 'city' => 'Basel', 'country' => 'CH', 'email' => 'buchhaltung@example.test', 'tax_case' => 'domestic'];
		$d->customerKey = 'k1';
		$d->issuedOn = '2026-10-15';
		$d->dueOn = '2026-11-14';
		$d->from = '2026-10-01';
		$d->to = '2026-10-31';
		$d->project = 'Umbau Rheingasse';
		$d->budget = 'Phase 1';
		$d->positions = [
			['kind' => 'hours', 'text' => 'Planung', 'hours' => 12.5, 'rate' => 150, 'amount' => 1875],
			['kind' => 'flat', 'text' => 'Spesen', 'amount' => 100],
			['kind' => 'discount', 'text' => 'Rabatt', 'amount' => -150],
			['kind' => 'text', 'text' => 'Nur ein Hinweis', 'amount' => 0],
		];
		$d->currency = 'CHF';
		$d->vat = 8.1;
		$d->sums = VatLines::of($d->positions, $d->vat, $d->taxCase);
		$d->accountIban = 'CH9300762011623852957';
		$d->iban = 'CH9300762011623852957';
		$d->chainLine = DocumentRules::chainLine('RE-2026-0007', 3, '3f9c0123abcdef0123', 'a1b2c3d4e5f6a7b8c9');
		return $d;
	}

	private static function xsd(InvoiceDocument $d, int $profile): void {
		$v = (new ZugferdXsdValidator((new EInvoiceXml())->builder($d, $profile)))->validate();
		self::assertTrue($v->validationPased(), json_encode($v->validationErrors()));
	}

	public function testEmbedsAndReadsBack(): void {
		$d = self::document();
		$pdf = (new InvoicePdf())->render($d);
		$out = (new EInvoiceXml())->embed($d, $pdf);
		$this->assertStringStartsWith('%PDF-', $out);
		$this->assertStringContainsString('factur-x.xml', $out);
		$this->assertStringContainsString('pdfaid:part>3<', $out, 'PDF/A-3');
		$this->assertStringContainsString('Beleg RE-2026-0007 · Kette 3 3f9c0123abcd', $out, 'the chain line stays the subject');
		$this->assertStringContainsString('Rechnung RE-2026-0007', $out);
		$xml = ZugferdDocumentPdfReader::getXmlFromContent($out);
		$this->assertStringContainsString('urn:cen.eu:en16931:2017', $xml, 'profile EN 16931');
		$this->assertStringContainsString('<ram:ID>RE-2026-0007</ram:ID>', $xml);
		$this->assertStringContainsString('<ram:TypeCode>380</ram:TypeCode>', $xml);
		$this->assertStringContainsString('<ram:Content>Beleg RE-2026-0007 · Kette 3 3f9c0123abcd ← a1b2c3d4e5f6</ram:Content>', $xml, 'the chain line as a note');
		$reader = ZugferdDocumentPdfReader::readAndGuessFromContent($out);
		$this->assertInstanceOf(ZugferdDocumentReader::class, $reader);
		$this->assertSame(ZugferdProfiles::PROFILE_EN16931, $reader->getProfileId());
		$reader->getDocumentInformation($no, $type, $date, $currency, $taxCurrency, $name, $language, $period);
		$this->assertSame(['RE-2026-0007', '380', 'CHF'], [$no, $type, $currency]);
		$reader->getDocumentSummation($grand, $due, $lines, $charges, $allowances, $basis, $tax, $rounding, $prepaid);
		$this->assertSame([1972.83, 1972.83, 1825.0, 1825.0, 147.83], [$grand, $due, $lines, $basis, $tax]);
		$n = 0;
		if ($reader->firstDocumentPosition()) {
			do {
				$n++;
			} while ($reader->nextDocumentPosition());
		}
		$this->assertSame(4, $n, 'four lines');
		self::xsd($d, ZugferdProfiles::PROFILE_EN16931);
		$this->assertStringContainsString('<ram:CategoryCode>S</ram:CategoryCode>', $xml);
		$this->assertStringContainsString('<ram:RateApplicablePercent>8.10</ram:RateApplicablePercent>', $xml);
		$this->assertStringContainsString('<ram:PaymentReference>RE-2026-0007</ram:PaymentReference>', $xml, 'the number as remittance without a QR reference');
		$this->assertStringContainsString('<ram:IBANID>CH9300762011623852957</ram:IBANID>', $xml);
		$this->assertStringContainsString('<ram:BilledQuantity unitCode="HUR">12.50</ram:BilledQuantity>', $xml);
		$this->assertStringContainsString('<ram:BilledQuantity unitCode="C62">-1.00</ram:BilledQuantity>', $xml, 'a discount: quantity -1 at the absolute price');
	}

	public function testTaxCasesAndXRechnung(): void {
		$eu = self::document();
		$eu->issuer['country'] = 'DE';
		$eu->issuer['vat_id'] = 'DE123456789';
		$eu->customer = ['id' => 'fr', 'name' => 'SARL Exemple', 'street' => 'Rue 1', 'postcode' => '75001', 'city' => 'Paris', 'country' => 'FR', 'vat_id' => 'FR12345678901', 'email' => 'c@example.test', 'tax_case' => 'eu_reverse'];
		$eu->taxCase = 'eu_reverse';
		$eu->vat = 0.0;
		$eu->sums = VatLines::of($eu->positions, $eu->vat, $eu->taxCase);
		$eu->currency = 'EUR';
		$eu->accountIban = 'DE89370400440532013000';
		$eu->iban = null;
		$xml = (new EInvoiceXml())->xml($eu);
		$this->assertStringContainsString('<ram:CategoryCode>AE</ram:CategoryCode>', $xml);
		$this->assertStringContainsString('<ram:ExemptionReasonCode>VATEX-EU-AE</ram:ExemptionReasonCode>', $xml);
		$this->assertStringContainsString('Reverse charge', $xml);
		self::xsd($eu, ZugferdProfiles::PROFILE_EN16931);

		$export = self::document();
		$export->customer = ['id' => 'us', 'name' => 'Inc', 'city' => 'Boston', 'postcode' => '02101', 'country' => 'US', 'tax_case' => 'export'];
		$export->taxCase = 'export';
		$export->vat = 0.0;
		$export->sums = VatLines::of($export->positions, $export->vat, $export->taxCase);
		$xml = (new EInvoiceXml())->xml($export);
		$this->assertStringContainsString('<ram:CategoryCode>G</ram:CategoryCode>', $xml);
		$this->assertStringContainsString('VATEX-EU-G', $xml);
		$exportEu = self::document();
		$exportEu->customer = ['id' => 'de', 'name' => 'GmbH', 'city' => 'Berlin', 'postcode' => '10115', 'country' => 'DE', 'tax_case' => 'export'];
		$exportEu->taxCase = 'export';
		$this->assertStringContainsString('<ram:CategoryCode>E</ram:CategoryCode>', (new EInvoiceXml())->xml($exportEu), 'export to an EU customer without reverse charge: E');

		// XRechnung for a German public customer: profile 3.0, the Leitweg-ID as the buyer reference.
		$xr = self::document();
		$xr->issuer['country'] = 'DE';
		$xr->customer = ['id' => 'stadt', 'name' => 'Stadt Beispiel', 'street' => 'Rathausplatz 1', 'postcode' => '10115', 'city' => 'Berlin', 'country' => 'DE', 'email' => 'rechnung@example.test', 'leitweg_id' => '04011000-1234512345-06', 'tax_case' => 'domestic'];
		$xr->leitwegId = '04011000-1234512345-06';
		$xr->eInvoice = true;
		$xr->currency = 'EUR';
		$xr->vat = 19.0;
		$xr->sums = VatLines::of($xr->positions, $xr->vat, $xr->taxCase);
		$xr->accountIban = 'DE89370400440532013000';
		$this->assertTrue($xr->wantsXRechnung());
		$xml = (new EInvoiceXml())->xrechnung($xr);
		$this->assertStringContainsString('urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0', $xml, 'profile XRechnung 3.0');
		$this->assertStringContainsString('<ram:BuyerReference>04011000-1234512345-06</ram:BuyerReference>', $xml);
		$this->assertStringContainsString('<ram:URIID schemeID="EM">mail@example.test</ram:URIID>', $xml, 'the seller’s electronic address');
		$this->assertStringContainsString('<ram:CategoryCode>S</ram:CategoryCode>', $xml);
		self::xsd($xr, ZugferdProfiles::PROFILE_XRECHNUNG_3);
		$plain = self::document();
		$this->assertFalse($plain->wantsXRechnung());
		$plain->eInvoice = true;
		$this->assertStringContainsString('<ram:BuyerReference>k1</ram:BuyerReference>', (new EInvoiceXml())->xrechnung($plain), 'without Leitweg-ID the customer key');
	}

	public function testReversalAndPdfWithQrReference(): void {
		$rev = self::document();
		$rev->number = 'RE-2026-0008';
		$rev->reverses = 'inv-1';
		$rev->originalNumber = 'RE-2026-0007';
		$rev->positions = [['kind' => 'hours', 'text' => 'Planung', 'hours' => 12.5, 'rate' => 150, 'amount' => -1875]];
		$rev->sums = VatLines::of($rev->positions, $rev->vat, $rev->taxCase);
		$xml = (new EInvoiceXml())->xml($rev);
		$this->assertStringContainsString('<ram:TypeCode>384</ram:TypeCode>', $xml, 'a reversal is a corrected invoice');
		$this->assertStringContainsString('<ram:IssuerAssignedID>RE-2026-0007</ram:IssuerAssignedID>', $xml, 'referring to the original');
		$this->assertStringContainsString('<ram:BilledQuantity unitCode="HUR">-12.50</ram:BilledQuantity>', $xml, 'negative quantity, positive price');
		self::xsd($rev, ZugferdProfiles::PROFILE_EN16931);

		// 0.26.0: an advance invoice is titled so and is a partial invoice (326); its reversal stays 384.
		$adv = self::document();
		$adv->positions = [['kind' => 'advance', 'text' => 'Akonto 1 · Phase 1 · 30 %', 'amount' => 13500]];
		$adv->sums = VatLines::of($adv->positions, $adv->vat, $adv->taxCase);
		$this->assertSame('Akontorechnung RE-2026-0007', $adv->title());
		$this->assertStringContainsString('<ram:TypeCode>326</ram:TypeCode>', (new EInvoiceXml())->xml($adv));
		self::xsd($adv, ZugferdProfiles::PROFILE_XRECHNUNG_3);
		$adv->reverses = 'inv-1';
		$adv->originalNumber = 'RE-2026-0006';
		$this->assertStringContainsString('<ram:TypeCode>384</ram:TypeCode>', (new EInvoiceXml())->xml($adv));
		$this->assertStringStartsWith('Storno zu RE-2026-0006', $adv->title());

		// A QR-IBAN with the QR reference: the payment part is drawn (0.19.0 left it out) and the reference is in the PDF and the XML.
		$qr = self::document();
		$qr->iban = 'CH4431999123000889012';
		$qr->accountIban = 'CH4431999123000889012';
		$qr->qrReference = QrReferenceRules::reference('123456', 7, '2026-10-15');
		$this->assertSame(27, strlen((string)$qr->qrReference));
		$pdf = (new InvoicePdf())->render($qr);
		$this->assertSame(2, preg_match_all('/\/Type \/Page\b/', $pdf), 'invoice page and payment part');
		$this->assertStringContainsString('<ram:PaymentReference>' . $qr->qrReference . '</ram:PaymentReference>', (new EInvoiceXml())->xml($qr));
		$noRef = self::document();
		$noRef->iban = 'CH4431999123000889012';
		$this->assertSame(1, preg_match_all('/\/Type \/Page\b/', (new InvoicePdf())->render($noRef)), 'a QR-IBAN without reference: no payment part');

		// The tax note under the sums (the page content is compressed: the note is checked through the document, the PDF only renders).
		$eu = self::document();
		$eu->taxCase = 'eu_reverse';
		$eu->lang = 'en';
		$this->assertSame('Reverse charge: VAT to be accounted for by the recipient.', $eu->taxNote());
		$this->assertSame(0.0, $eu->vatRate());
		$this->assertStringStartsWith('%PDF-', (new InvoicePdf())->render($eu));
	}

	/**
	 * 0.21.0: two rates and a pass-through item – one tax group per rate,
	 * the pass-through item as E with VATEX-EU-79-C, totals including it;
	 * the XML passes the XSD, reads back from the PDF, and the PDF text
	 * shows both rates and the block.
	 */
	public function testTwoRatesAndPassThrough(): void {
		$d = self::document();
		$d->positions = [
			['kind' => 'hours', 'text' => 'Planung', 'hours' => 10, 'rate' => 150, 'amount' => 1500, 'vat' => 8.1, 'vat_mode' => 'taxable'],
			['kind' => 'expense_flat', 'text' => 'Druckkosten', 'amount' => 200, 'vat' => 2.6, 'vat_mode' => 'taxable', 'cost' => 2],
			['kind' => 'text', 'text' => 'Hinweis', 'amount' => 0, 'vat' => 3.8, 'vat_mode' => 'taxable'],
			['kind' => 'costs', 'text' => 'Gebühr Baubewilligung', 'amount' => 350, 'vat' => null, 'vat_mode' => 'pass_through', 'cost' => 4],
		];
		$d->sums = VatLines::of($d->positions, 8.1, 'domestic');
		$d->vat = $d->sums->rate;
		$this->assertSame([1700.0, 126.7, 1826.7, 350.0, 2176.7], [$d->sums->net, $d->sums->vatAmount, $d->sums->gross, $d->sums->passThrough, $d->sums->total]);
		$xml = (new EInvoiceXml())->xml($d);
		self::xsd($d, ZugferdProfiles::PROFILE_EN16931);
		self::xsd($d, ZugferdProfiles::PROFILE_XRECHNUNG_3);
		$this->assertSame(3, substr_count($xml, '<ram:ApplicableTradeTax>') - 4, 'three tax groups besides the four lines');
		$this->assertStringContainsString('<ram:RateApplicablePercent>2.60</ram:RateApplicablePercent>', $xml);
		$this->assertStringContainsString('<ram:ExemptionReasonCode>VATEX-EU-79-C</ram:ExemptionReasonCode>', $xml);
		$this->assertStringContainsString('<ram:ExemptionReason>Durchlaufender Posten / disbursement</ram:ExemptionReason>', $xml);
		$this->assertStringContainsString('<ram:TaxBasisTotalAmount>2050.00</ram:TaxBasisTotalAmount>', $xml, 'the tax basis includes the pass-through item');
		$this->assertStringContainsString('<ram:GrandTotalAmount>2176.70</ram:GrandTotalAmount>', $xml);
		$this->assertStringContainsString('<ram:DuePayableAmount>2176.70</ram:DuePayableAmount>', $xml);

		$pdf = (new InvoicePdf())->render($d);
		$out = (new EInvoiceXml())->embed($d, $pdf);
		$reader = ZugferdDocumentPdfReader::readAndGuessFromContent($out);
		$this->assertInstanceOf(ZugferdDocumentReader::class, $reader);
		$reader->getDocumentSummation($grand, $due, $lines, $charges, $allowances, $basis, $tax, $rounding, $prepaid);
		$this->assertSame([2176.7, 2176.7, 2050.0, 2050.0, 126.7], [$grand, $due, $lines, $basis, $tax]);
		$groups = [];
		if ($reader->firstDocumentTax()) {
			do {
				$reader->getDocumentTax($category, $type, $taxBasis, $calculated, $rate, $reason, $reasonCode, $lineBasis, $chargeBasis, $taxPoint, $dueType);
				$groups[] = [$category, $rate, $taxBasis, $calculated];
			} while ($reader->nextDocumentTax());
		}
		$this->assertSame([['S', 2.6, 200.0, 5.2], ['S', 8.1, 1500.0, 121.5], ['E', 0.0, 350.0, 0.0]], $groups);

		$text = (new \Smalot\PdfParser\Parser())->parseContent($pdf)->getText();
		foreach (['MWST 2.6 % auf 200.00', 'MWST 8.1 % auf 1’500.00', 'Total inkl. MWST CHF', 'Durchlaufende Posten (ohne MWST)', 'Gebühr Baubewilligung', 'Summe durchlaufende Posten', '2’176.70'] as $want) {
			$this->assertStringContainsString($want, $text);
		}
	}
}
