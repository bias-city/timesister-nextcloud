<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\DocumentRules;
use OCA\TimeSister\Service\InvoiceDocument;
use OCA\TimeSister\Service\InvoicePdf;
use OCA\TimeSister\Service\VatLines;
use PHPUnit\Framework\TestCase;

/**
 * The invoice PDF (0.19.0) without Nextcloud: a document renders to a
 * PDF, the chain line and the watermark are in its metadata (XMP, plain
 * text), the hours appendix and the QR bill add pages, a QR-IBAN without
 * reference or a negative amount leaves the payment part out.
 */
class InvoicePdfTest extends TestCase {
	private static function document(): InvoiceDocument {
		$d = new InvoiceDocument();
		$d->lang = 'de';
		$d->number = 'RE-2026-0007';
		$d->issuer = ['name' => 'Muster sàrl', 'street' => 'Musterstrasse 12', 'postcode' => '4000', 'city' => 'Basel', 'country' => 'CH', 'vat_id' => 'CHE-123.456.789 MWST', 'email' => 'mail@example.test'];
		$d->customer = ['name' => 'Müller AG', 'street' => 'Platz 1', 'postcode' => '4001', 'city' => 'Basel', 'country' => 'CH', 'contact' => ['id' => 'c1', 'name' => 'Lea Kontakt']];
		$d->issuedOn = '2026-10-15';
		$d->dueOn = '2026-11-14';
		$d->from = '2026-10-01';
		$d->to = '2026-10-31';
		$d->project = 'Umbau Rheingasse';
		$d->budget = 'Phase 1';
		$d->greeting = 'Sehr geehrte Damen und Herren';
		$d->body = 'Zahlbar innert 30 Tagen.';
		$d->footer = 'Muster sàrl · Basel';
		$d->positions = [['kind' => 'hours', 'text' => 'Planung', 'hours' => 12.5, 'rate' => 150, 'amount' => 1875], ['kind' => 'discount', 'text' => 'Rabatt', 'amount' => -50]];
		$d->currency = 'CHF';
		$d->vat = 8.1;
		$d->sums = VatLines::of($d->positions, $d->vat, $d->taxCase);
		$d->chainLine = DocumentRules::chainLine('RE-2026-0007', 3, '3f9c0123abcdef0123', 'a1b2c3d4e5f6a7b8c9');
		return $d;
	}

	private static function pages(string $pdf): int {
		return preg_match_all('/\/Type \/Page\b/', $pdf);
	}

	public function testRendersWithChainLineInMetadata(): void {
		$pdf = (new InvoicePdf())->render(self::document());
		$this->assertStringStartsWith('%PDF-', $pdf);
		$this->assertStringContainsString('Beleg RE-2026-0007 · Kette 3 3f9c0123abcd ← a1b2c3d4e5f6', $pdf);
		$this->assertStringContainsString('Rechnung RE-2026-0007', $pdf);
		$this->assertStringNotContainsString('VORABZUG', $pdf);
		$this->assertSame(1, self::pages($pdf));
	}

	public function testPreviewCarriesTheWatermark(): void {
		$d = self::document();
		$d->number = '';
		$d->watermark = DocumentRules::watermark('de');
		$d->chainLine = 'VORABZUG – (noch nicht gestellt)';
		$pdf = (new InvoicePdf())->render($d);
		$this->assertStringStartsWith('%PDF-', $pdf);
		$this->assertStringContainsString('VORABZUG', $pdf);
		$this->assertStringContainsString('Rechnung (noch nicht gestellt)', $pdf);
		$en = self::document();
		$en->lang = 'en';
		$en->watermark = DocumentRules::watermark('en');
		$this->assertStringContainsString('DRAFT', (new InvoicePdf())->render($en));
	}

	public function testAppendixAndQrBillAddPages(): void {
		$d = self::document();
		$d->iban = 'CH9300762011623852957';
		for ($i = 0; $i < 70; $i++) {
			$d->appendix[] = ['date' => '2026-10-0' . (1 + $i % 9), 'person' => 'Mia Muster', 'code' => 'D196', 'title' => 'Besprechung ' . $i, 'hours' => 1.0];
		}
		$d->hoursTotal = 70.0;
		$pdf = (new InvoicePdf())->render($d);
		// Invoice, two appendix pages, the QR bill.
		$this->assertSame(4, self::pages($pdf));
		$this->assertStringContainsString('Beleg RE-2026-0007', $pdf);
	}

	public function testNoQrBillWithoutValidData(): void {
		$d = self::document();
		$d->iban = 'CH4431999123000889012';
		// A QR-IBAN needs a QRR reference; the standard refuses it without one – no payment part.
		$this->assertSame(1, self::pages((new InvoicePdf())->render($d)));
		$d = self::document();
		$d->iban = 'CH9300762011623852957';
		$d->sums = VatLines::of([['kind' => 'flat', 'text' => 'x', 'amount' => -10]], 0.0, 'domestic');
		$this->assertFalse($d->wantsQrBill());
		$this->assertSame(1, self::pages((new InvoicePdf())->render($d)));
		$d = self::document();
		$d->iban = 'CH9300762011623852957';
		$d->currency = 'USD';
		$this->assertFalse($d->wantsQrBill());
	}
}
