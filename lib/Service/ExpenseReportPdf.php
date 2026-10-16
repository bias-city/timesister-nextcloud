<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The PDF of an expense report (0.22.0, B4a), A4 portrait like the
 * invoice: head with team, person and period; the receipts (date,
 * supplier, text, project, net, VAT, gross, «without receipt» for trust
 * and recurring ones); the sums per rate; signature lines for the person
 * and the approval; then the receipt copies. No ledger entry.
 */
final class ExpenseReportPdf {
	private const LINE = 4.5;
	/** Date, supplier, text, project, net, VAT, gross: 170 mm. */
	private const COLS = [20.0, 30.0, 45.0, 22.0, 18.0, 17.0, 18.0];

	/**
	 * @param array{lang:string,team:string,person:string,from:string,to:string,currency:string,key:string,
	 *   rows:list<array{date:string,supplier:string,text:string,project:string,net:float,vat_rate:?float,vat:float,gross:float,without:bool}>,
	 *   sums:array{net:float,vat_lines:list<array{rate:float,net:float,vat:float}>,gross:float,currency?:string},
	 *   approved_by:?string,approved_on:?string,
	 *   copies:list<array{head:string,content:?string,mime:string,path:string,sha256:string}>} $r
	 */
	public function render(array $r): string {
		$lang = $r['lang'];
		$t = DocumentRules::texts($lang);
		$title = $t['report'] . ' ' . $r['person'] . ' · ' . DocumentRules::day($r['from'], $lang) . ' – ' . DocumentRules::day($r['to'], $lang);
		$pdf = new InvoicePage();
		$pdf->SetCreator('TimeSister');
		$pdf->SetAuthor($r['team']);
		$pdf->SetTitle($title);
		$pdf->SetSubject($r['key']);
		$pdf->setFooterText(null, $title, $t['page']);
		$pdf->AddPage();

		$pdf->SetFont(InvoicePage::FONT, 'B', 9);
		$pdf->Cell(0, 4, $r['team'], 0, 1);
		$pdf->Ln(8);
		$pdf->SetFont(InvoicePage::FONT, 'B', 14);
		$pdf->Cell(0, 8, $t['report'], 0, 1);
		$pdf->SetFont(InvoicePage::FONT, '', 9);
		foreach ([[$t['person'], $r['person']], [$t['period'], DocumentRules::day($r['from'], $lang) . ' – ' . DocumentRules::day($r['to'], $lang)], [$t['team'], $r['team']]] as [$label, $value]) {
			$pdf->Cell(40, 5, $label, 0, 0);
			$pdf->Cell(0, 5, $value, 0, 1);
		}
		$pdf->Ln(5);
		$this->table($pdf, $r, $t);
		$this->sums($pdf, $r, $t);
		$this->signatures($pdf, $r, $t);
		if ($r['copies'] !== []) {
			ReceiptCopyPages::append($pdf, $r['copies'], $t);
		}
		return $pdf->Output('expenses.pdf', 'S');
	}

	/** @param array<string,mixed> $r @param array<string,string> $t */
	private function table(InvoicePage $pdf, array $r, array $t): void {
		$lang = (string)$r['lang'];
		$header = function () use ($pdf, $t, $r): void {
			$pdf->SetFont(InvoicePage::FONT, 'B', 8);
			$labels = [$t['date'], $t['supplier'], $t['text'], $t['project'], $t['net'], $t['vat'], $t['gross_col'] . ' ' . $r['currency']];
			foreach (self::COLS as $i => $w) {
				$pdf->Cell($w, self::LINE, $labels[$i], 'B', $i === 6 ? 1 : 0, $i >= 4 ? 'R' : 'L');
			}
			$pdf->SetFont(InvoicePage::FONT, '', 8);
		};
		$header();
		foreach ($r['rows'] as $row) {
			$text = $row['text'] . ($row['without'] ? ' (' . $t['without_receipt'] . ')' : '');
			$vat = DocumentRules::money($row['vat'], $lang) . ($row['vat_rate'] === null ? '' : "\n" . DocumentRules::percent($row['vat_rate']));
			$cells = [DocumentRules::day($row['date'], $lang), $row['supplier'], $text, $row['project'],
				DocumentRules::money($row['net'], $lang), $vat, DocumentRules::money($row['gross'], $lang)];
			$lines = 1;
			foreach ([1, 2, 3, 5] as $i) {
				$lines = max($lines, (int)$pdf->getNumLines($cells[$i], self::COLS[$i]));
			}
			$h = (float)$lines * 4.0;
			if ($pdf->GetY() + $h > (float)$pdf->getPageHeight() - InvoicePage::BOTTOM) {
				$pdf->AddPage();
				$header();
			}
			$x = $pdf->GetX();
			$y = $pdf->GetY();
			foreach ($cells as $i => $cell) {
				$pdf->MultiCell(self::COLS[$i], $h, $cell, 0, $i >= 4 ? 'R' : 'L', false, 0, $x, $y, true, 0, false, true, $h, 'T');
				$x += self::COLS[$i];
			}
			$pdf->SetXY(InvoicePage::MARGIN, $y + $h);
		}
	}

	/** Net, VAT per rate and gross, right-aligned. @param array<string,mixed> $r @param array<string,string> $t */
	private function sums(InvoicePage $pdf, array $r, array $t): void {
		$lang = (string)$r['lang'];
		$label = 170.0 - self::COLS[6];
		$s = $r['sums'];
		$pdf->SetFont(InvoicePage::FONT, '', 9);
		$pdf->Cell($label, 5, $t['net'], 'T', 0, 'R');
		$pdf->Cell(self::COLS[6], 5, DocumentRules::money($s['net'], $lang), 'T', 1, 'R');
		foreach ($s['vat_lines'] as $l) {
			$text = $t['vat'] . ' ' . DocumentRules::percent($l['rate']) . (count($s['vat_lines']) > 1 ? ' ' . $t['on'] . ' ' . DocumentRules::money($l['net'], $lang) : '');
			$pdf->Cell($label, 5, $text, 0, 0, 'R');
			$pdf->Cell(self::COLS[6], 5, DocumentRules::money($l['vat'], $lang), 0, 1, 'R');
		}
		$pdf->SetFont(InvoicePage::FONT, 'B', 10);
		$pdf->Cell($label, 6, $t['total'] . ' ' . $r['currency'], 'T', 0, 'R');
		$pdf->Cell(self::COLS[6], 6, DocumentRules::money($s['gross'], $lang), 'T', 1, 'R');
	}

	/** Two signature lines: the person, and the approval with name and date. @param array<string,mixed> $r @param array<string,string> $t */
	private function signatures(InvoicePage $pdf, array $r, array $t): void {
		if ($pdf->GetY() + 30.0 > (float)$pdf->getPageHeight() - InvoicePage::BOTTOM) {
			$pdf->AddPage();
		}
		$pdf->Ln(18);
		$y = $pdf->GetY();
		$pdf->Line(InvoicePage::MARGIN, $y, InvoicePage::MARGIN + 75.0, $y);
		$pdf->Line(InvoicePage::MARGIN + 95.0, $y, InvoicePage::MARGIN + 170.0, $y);
		$pdf->SetFont(InvoicePage::FONT, '', 8);
		$pdf->SetXY(InvoicePage::MARGIN, $y + 1.0);
		$pdf->Cell(75, 4, $t['sign_person'] . ': ' . $r['person'], 0, 0);
		$approval = $t['sign_approval'];
		if (is_string($r['approved_by'] ?? null)) {
			$approval .= ': ' . $r['approved_by'] . (is_string($r['approved_on'] ?? null) ? ', ' . DocumentRules::day((string)$r['approved_on'], (string)$r['lang']) : '');
		}
		$pdf->SetXY(InvoicePage::MARGIN + 95.0, $y + 1.0);
		$pdf->Cell(75, 4, $approval, 0, 1);
	}
}
