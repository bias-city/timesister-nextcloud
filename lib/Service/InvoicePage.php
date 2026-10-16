<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The page of an invoice document (0.19.0): TCPDF with the watermark of a
 * preview drawn first on every page (so it lies under the content) and the
 * footer – the team's footer text, the chain line and the page number –
 * drawn on every page. On the page with the QR bill the footer sits above
 * the payment part. Since 0.22.0 FPDI underneath: receipt copies import
 * PDF pages, and a page may carry a small head of its own.
 */
final class InvoicePage extends \setasign\Fpdi\Tcpdf\Fpdi {
	public const FONT = 'dejavusans';
	public const MARGIN = 20.0;
	/** Room for the footer text (up to three lines), the chain line and the page number. */
	public const BOTTOM = 32.0;
	/** The Swiss QR bill takes the bottom 105 mm; the footer of that page moves above it. */
	public const QR_TOP = 192.0;

	private ?string $watermark = null;
	private ?string $footerText = null;
	private string $chainLine = '';
	private string $pageLabel = 'Page {n} of {total}';
	private int $qrPage = 0;
	private ?string $pageHead = null;

	public function __construct() {
		parent::__construct('P', 'mm', 'A4', true, 'UTF-8', false);
		$this->setPrintHeader(true);
		$this->setPrintFooter(true);
		$this->SetMargins(self::MARGIN, self::MARGIN, self::MARGIN);
		$this->SetHeaderMargin(0);
		$this->SetFooterMargin(self::BOTTOM);
		$this->SetAutoPageBreak(true, self::BOTTOM);
		$this->setFontSubsetting(true);
		$this->SetFont(self::FONT, '', 10);
	}

	public function setWatermark(?string $text): void {
		$this->watermark = $text;
	}

	public function setFooterText(?string $text, string $chainLine, string $pageLabel): void {
		$this->footerText = $text;
		$this->chainLine = $chainLine;
		$this->pageLabel = $pageLabel;
	}

	/** A small head on the pages added from now on (a receipt copy); null: none. */
	public function setPageHead(?string $text): void {
		$this->pageHead = $text;
	}

	/** The current page carries the QR bill at its bottom. */
	public function markQrPage(): void {
		$this->qrPage = $this->getPage();
	}

	/** The watermark, diagonally across the page, light grey, under the content. */
	public function Header(): void {
		if ($this->pageHead !== null) {
			$this->SetAutoPageBreak(false, 0);
			$this->SetTextColor(60, 60, 60);
			$this->SetFont(self::FONT, 'B', 8);
			$this->SetXY(self::MARGIN, 10);
			$this->Cell(170, 4, $this->pageHead, 0, 0, 'L');
			$this->SetTextColor(0, 0, 0);
			$this->SetAutoPageBreak(true, self::BOTTOM);
		}
		if ($this->watermark === null) {
			return;
		}
		$this->StartTransform();
		$this->SetTextColor(200, 200, 200);
		$this->SetFont(self::FONT, 'B', 90);
		$w = $this->GetStringWidth($this->watermark);
		$this->Rotate(45, 105, 148.5);
		$this->Text(105.0 - $w / 2.0, 132.5, $this->watermark);
		$this->StopTransform();
		$this->SetTextColor(0, 0, 0);
	}

	/** Footer text, chain line and page number on every page. */
	public function Footer(): void {
		$qr = $this->getPage() === $this->qrPage;
		$height = (float)$this->getPageHeight();
		$y = $qr ? self::QR_TOP - 14.0 : $height - self::BOTTOM + 4.0;
		$this->SetAutoPageBreak(false, 0);
		$this->SetTextColor(90, 90, 90);
		if ($this->footerText !== null && !$qr) {
			$this->SetFont(self::FONT, '', 7.5);
			$this->SetXY(self::MARGIN, $y);
			$this->MultiCell(0, 3.5, $this->footerText, 0, 'L', false, 1, null, null, true, 0, false, true, 14, 'T');
		}
		$y = $qr ? self::QR_TOP - 12.0 : $height - 12.0;
		$this->SetFont(self::FONT, '', 7);
		$this->SetXY(self::MARGIN, $y);
		$this->Cell(120, 4, $this->chainLine, 0, 0, 'L');
		$this->Cell(50, 4, str_replace(['{n}', '{total}'], [$this->getAliasNumPage(), $this->getAliasNbPages()], $this->pageLabel), 0, 0, 'R');
		$this->SetTextColor(0, 0, 0);
		$this->SetAutoPageBreak(true, self::BOTTOM);
	}
}
