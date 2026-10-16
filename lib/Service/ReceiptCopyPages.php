<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use setasign\Fpdi\PdfParser\StreamReader;

/**
 * Receipt copies as appendix pages (0.22.0), shared by the invoice and the
 * expense report: PDF pages imported with FPDI and fitted into the page,
 * images fitted likewise, each page with the small head «Receipt <date>
 * <supplier>»; the page's footer (chain line) stays. A file FPDI cannot
 * read (e.g. a compressed cross-reference) becomes a page that says so,
 * with path and hash.
 */
final class ReceiptCopyPages {
	/** Pages of one receipt at most; the rest is named on the last page. */
	public const MAX_PAGES = 20;
	private const BOX_X = 15.0;
	private const BOX_Y = 18.0;
	private const BOX_W = 180.0;
	private const BOX_H = 245.0;

	/**
	 * @param list<array{head:string,content:?string,mime:string,path:string,sha256:string}> $copies
	 * @param array<string,string> $t the document's labels
	 */
	public static function append(InvoicePage $pdf, array $copies, array $t): void {
		foreach ($copies as $c) {
			$pdf->setPageHead($c['head']);
			try {
				if ($c['content'] === null) {
					throw new \RuntimeException($t['copy_unreadable']);
				}
				if ($c['mime'] === 'application/pdf') {
					self::pdfPages($pdf, $c['content'], $c['head'], $t);
				} else {
					self::image($pdf, $c['content']);
				}
			} catch (\Throwable) {
				self::placeholder($pdf, $c, $t);
			}
		}
		$pdf->setPageHead(null);
	}

	/** @param array<string,string> $t */
	private static function pdfPages(InvoicePage $pdf, string $content, string $head, array $t): void {
		// Parse first: a file FPDI cannot read must not leave a blank page behind.
		$n = $pdf->setSourceFile(StreamReader::createByString($content));
		$shown = min($n, self::MAX_PAGES);
		for ($i = 1; $i <= $shown; $i++) {
			$tpl = $pdf->importPage($i);
			$pdf->setPageHead($n > 1 ? $head . ' (' . $i . '/' . $n . ')' : $head);
			$pdf->AddPage();
			$size = $pdf->getTemplateSize($tpl);
			if (!is_array($size)) {
				throw new \RuntimeException('no page size');
			}
			[$x, $y, $w, $h] = self::fit((float)$size['width'], (float)$size['height']);
			$pdf->useTemplate($tpl, $x, $y, $w, $h);
		}
		if ($n > $shown) {
			$pdf->SetFont(InvoicePage::FONT, '', 8);
			$pdf->SetXY(self::BOX_X, self::BOX_Y + self::BOX_H + 1.0);
			$pdf->Cell(self::BOX_W, 4, str_replace('{n}', (string)($n - $shown), $t['copy_more']), 0, 0, 'L');
		}
	}

	private static function image(InvoicePage $pdf, string $content): void {
		$info = @getimagesizefromstring($content);
		if ($info === false || $info[0] <= 0 || $info[1] <= 0) {
			throw new \RuntimeException('not an image');
		}
		$pdf->AddPage();
		[$x, $y, $w, $h] = self::fit((float)$info[0], (float)$info[1]);
		$pdf->Image('@' . $content, $x, $y, $w, $h, '', '', '', true, 300);
	}

	/**
	 * @param array{head:string,content:?string,mime:string,path:string,sha256:string} $c
	 * @param array<string,string> $t
	 */
	private static function placeholder(InvoicePage $pdf, array $c, array $t): void {
		$pdf->setPageHead($c['head']);
		$pdf->AddPage();
		$pdf->SetFont(InvoicePage::FONT, '', 10);
		$pdf->SetXY(InvoicePage::MARGIN, 40);
		$pdf->MultiCell(0, 5, $t['copy_missing'] . "\n\n" . $c['path'] . "\nSHA-256 " . $c['sha256'], 0, 'L', false, 1);
	}

	/** Fits width × height into the box, centred, keeping the ratio. @return array{0:float,1:float,2:float,3:float} */
	public static function fit(float $width, float $height): array {
		$scale = min(self::BOX_W / $width, self::BOX_H / $height);
		$w = $width * $scale;
		$h = $height * $scale;
		return [self::BOX_X + (self::BOX_W - $w) / 2.0, self::BOX_Y, $w, $h];
	}
}
