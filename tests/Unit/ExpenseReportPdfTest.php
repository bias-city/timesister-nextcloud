<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\DocumentRules;
use OCA\TimeSister\Service\ExpenseReportPdf;
use OCA\TimeSister\Service\InvoiceDocument;
use OCA\TimeSister\Service\InvoicePdf;
use OCA\TimeSister\Service\ReceiptCopyPages;
use OCA\TimeSister\Service\VatLines;
use PHPUnit\Framework\TestCase;
use Smalot\PdfParser\Parser;

/**
 * Receipt copies and the expense report PDF (0.22.0): a PDF receipt is
 * imported page by page, an image fitted, an unreadable file named on a
 * page of its own; the report shows its rows, sums and signatures.
 */
class ExpenseReportPdfTest extends TestCase {
	private static function text(string $pdf): string {
		return (new Parser())->parseContent($pdf)->getText();
	}

	private static function pages(string $pdf): int {
		return count((new Parser())->parseContent($pdf)->getPages());
	}

	/** A two-page PDF as a receipt, made with TCPDF (classic cross-reference). */
	private static function receiptPdf(): string {
		$p = new \TCPDF('P', 'mm', 'A5', true, 'UTF-8', false);
		$p->setPrintHeader(false);
		$p->setPrintFooter(false);
		foreach (['Quittung Seite eins', 'Quittung Seite zwei'] as $line) {
			$p->AddPage();
			$p->SetFont('helvetica', '', 12);
			$p->Cell(0, 10, $line);
		}
		return $p->Output('r.pdf', 'S');
	}

	private static function png(): string {
		$img = imagecreatetruecolor(40, 80);
		imagefill($img, 0, 0, (int)imagecolorallocate($img, 200, 30, 30));
		ob_start();
		imagepng($img);
		return (string)ob_get_clean();
	}

	/** A PDF whose cross-reference is a stream: the free FPDI parser refuses it. */
	private static function compressedXref(): string {
		$obj = "1 0 obj\n<< /Type /XRef /Size 2 /W [1 1 1] /Length 0 >>\nstream\n\nendstream\nendobj\n";
		return "%PDF-1.5\n" . $obj . "startxref\n9\n%%EOF\n";
	}

	/** @return list<array{head:string,content:?string,mime:string,path:string,sha256:string}> */
	private static function copies(): array {
		return [
			['head' => 'Beleg 02.10.2026 Bahn', 'content' => self::receiptPdf(), 'mime' => 'application/pdf', 'path' => 'R/a.pdf', 'sha256' => 'aa'],
			['head' => 'Beleg 03.10.2026 Kiosk', 'content' => self::png(), 'mime' => 'image/png', 'path' => 'R/b.png', 'sha256' => 'bb'],
			['head' => 'Beleg 04.10.2026 Scanner', 'content' => self::compressedXref(), 'mime' => 'application/pdf', 'path' => 'R/c.pdf', 'sha256' => 'cc'],
			['head' => 'Beleg 05.10.2026 Missing', 'content' => null, 'mime' => 'application/pdf', 'path' => 'R/d.pdf', 'sha256' => 'dd'],
		];
	}

	public function testFit(): void {
		[$x, $y, $w, $h] = ReceiptCopyPages::fit(210.0, 297.0);
		$this->assertEqualsWithDelta(245.0, $h, 0.001, 'A4 portrait fills the height');
		$this->assertEqualsWithDelta(105.0, $x + $w / 2, 0.001, 'centred');
		[, , $w2] = ReceiptCopyPages::fit(400.0, 100.0);
		$this->assertEqualsWithDelta(180.0, $w2, 0.001, 'a wide image fills the width');
	}

	public function testReportWithCopies(): void {
		$pdf = (new ExpenseReportPdf())->render([
			'lang' => 'de', 'team' => 'Example Team', 'person' => 'Alice Example', 'from' => '2026-10-01', 'to' => '2026-10-31', 'currency' => 'CHF', 'key' => 'sb-1',
			'rows' => [
				['date' => '2026-10-02', 'supplier' => 'Bahn', 'text' => 'Basel–Bern', 'project' => 'D196', 'net' => 100.0, 'vat_rate' => 8.1, 'vat' => 8.1, 'gross' => 108.1, 'without' => false],
				['date' => '2026-10-31', 'supplier' => '', 'text' => 'Mobile', 'project' => '', 'net' => 50.0, 'vat_rate' => null, 'vat' => 0.0, 'gross' => 50.0, 'without' => true],
			],
			'sums' => ['net' => 150.0, 'vat_lines' => [['rate' => 0.0, 'net' => 50.0, 'vat' => 0.0], ['rate' => 8.1, 'net' => 100.0, 'vat' => 8.1]], 'gross' => 158.1],
			'approved_by' => 'Bob Example', 'approved_on' => '2026-11-02',
			'copies' => self::copies(),
		]);
		$this->assertStringStartsWith('%PDF-', $pdf);
		$text = self::text($pdf);
		foreach (['Spesenblatt', 'Alice Example', 'ohne Beleg', 'MWST 8.1 %', '158.10', 'Unterschrift Person', 'Freigabe: Bob Example, 02.11.2026',
			'Beleg 02.10.2026 Bahn (1/2)', 'Beleg 02.10.2026 Bahn (2/2)', 'Beleg 03.10.2026 Kiosk', 'Quittung Seite zwei', 'R/c.pdf', 'SHA-256 dd'] as $s) {
			$this->assertStringContainsString($s, $text, $s);
		}
		// One report page, two imported pages, the image, two pages naming unreadable files.
		$this->assertSame(6, self::pages($pdf));
	}

	public function testInvoiceCarriesCopiesBeforeTheBill(): void {
		$d = new InvoiceDocument();
		$d->lang = 'en';
		$d->number = 'RE-1';
		$d->issuer = ['name' => 'Example Ltd', 'postcode' => '4000', 'city' => 'Basel', 'country' => 'CH'];
		$d->positions = [['kind' => 'costs', 'text' => 'Travel', 'amount' => 100, 'receipts' => ['rc-1']]];
		$d->sums = VatLines::of($d->positions, 8.1, 'domestic');
		$d->chainLine = DocumentRules::chainLine('RE-1', 1, 'abc', null);
		$d->copies = [self::copies()[1]];
		$pdf = (new InvoicePdf())->render($d);
		$this->assertSame(2, self::pages($pdf));
		$this->assertStringContainsString('Beleg 03.10.2026 Kiosk', self::text($pdf));
	}
}
