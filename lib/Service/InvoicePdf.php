<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use Sprain\SwissQrBill\DataGroup\Element\AdditionalInformation;
use Sprain\SwissQrBill\DataGroup\Element\CreditorInformation;
use Sprain\SwissQrBill\DataGroup\Element\PaymentAmountInformation;
use Sprain\SwissQrBill\DataGroup\Element\PaymentReference;
use Sprain\SwissQrBill\DataGroup\Element\StructuredAddress;
use Sprain\SwissQrBill\PaymentPart\Output\DisplayOptions;
use Sprain\SwissQrBill\PaymentPart\Output\TcPdfOutput\TcPdfOutput;
use Sprain\SwissQrBill\QrBill;

/**
 * The PDF of an invoice (0.19.0), A4 portrait: issuer and customer
 * blocks, title, the facts, greeting, the positions, the sums (per rate,
 * pass-through items and total since 0.21.0), the body
 * text, the hours appendix (date, person, code, title, hours – no notes),
 * the receipt copies (0.22.0),
 * the footer with the chain line on every page, a watermark on a preview
 * and the Swiss QR bill as the last page when the issuer has an IBAN in
 * the invoice's currency. TCPDF with DejaVu Sans; no files, no network.
 */
final class InvoicePdf {
	private const LINE = 5.0;
	private const SMALL = 4.0;
	/** Column widths of the positions table (mm), 170 in all. */
	private const COLS = [95.0, 20.0, 25.0, 30.0];
	/** Column widths of the hours appendix. */
	private const APPENDIX = [24.0, 40.0, 24.0, 62.0, 20.0];

	/** The PDF as a string. */
	public function render(InvoiceDocument $d): string {
		$t = $d->texts();
		$pdf = new InvoicePage();
		$pdf->SetCreator('TimeSister');
		$pdf->SetAuthor(self::str($d->issuer['name'] ?? ''));
		$pdf->SetTitle($d->title());
		// The chain line and the watermark also in the metadata: readable without rendering.
		$pdf->SetSubject($d->chainLine);
		$pdf->SetKeywords($d->watermark ?? 'TimeSister');
		$pdf->setWatermark($d->watermark);
		$pdf->setFooterText($d->footer, $d->chainLine, $t['page']);
		$pdf->AddPage();

		$this->addresses($pdf, $d, $t);
		$this->head($pdf, $d, $t);
		if ($d->greeting !== null) {
			$pdf->SetFont(InvoicePage::FONT, '', 10);
			$pdf->MultiCell(0, self::LINE, $d->greeting, 0, 'L', false, 1);
			$pdf->Ln(3);
		}
		$this->positions($pdf, $d, $t);
		$this->sums($pdf, $d, $t);
		if ($d->body !== null) {
			$pdf->Ln(4);
			$pdf->SetFont(InvoicePage::FONT, '', 10);
			$pdf->MultiCell(0, self::LINE, $d->body, 0, 'L', false, 1);
		}
		if ($d->appendix !== []) {
			$this->appendix($pdf, $d, $t);
		}
		// 0.22.0: the copies of the receipts billed, before the payment part.
		if ($d->copies !== []) {
			ReceiptCopyPages::append($pdf, $d->copies, $t);
		}
		if ($d->wantsQrBill()) {
			$this->qrBill($pdf, $d, $t);
		}
		return $pdf->Output('invoice.pdf', 'S');
	}

	/** @param array<string,string> $t */
	private function addresses(InvoicePage $pdf, InvoiceDocument $d, array $t): void {
		$i = $d->issuer;
		$pdf->SetFont(InvoicePage::FONT, 'B', 9);
		$pdf->Cell(0, self::SMALL, self::str($i['name'] ?? ''), 0, 1);
		$pdf->SetFont(InvoicePage::FONT, '', 9);
		foreach (self::addressLines($i) as $line) {
			$pdf->Cell(0, self::SMALL, $line, 0, 1);
		}
		foreach (['vat_id', 'email', 'phone'] as $f) {
			$v = self::str($i[$f] ?? '');
			if ($v !== '') {
				$pdf->Cell(0, self::SMALL, $t[$f] . ' ' . $v, 0, 1);
			}
		}
		// The customer in the window of a C5 envelope.
		$c = $d->customer;
		$pdf->SetXY(110, 52);
		$pdf->SetFont(InvoicePage::FONT, '', 10);
		$pdf->Cell(0, self::LINE, self::str($c['name'] ?? ''), 0, 1);
		$extra = self::str($c['address_extra'] ?? '');
		if ($extra !== '') {
			$pdf->SetX(110);
			$pdf->Cell(0, self::LINE, $extra, 0, 1);
		}
		foreach (self::addressLines($c, self::str($i['country'] ?? '')) as $line) {
			$pdf->SetX(110);
			$pdf->Cell(0, self::LINE, $line, 0, 1);
		}
		$contact = $c['contact'] ?? null;
		$contactName = is_array($contact) ? self::str($contact['name'] ?? '') : self::str($contact);
		if ($contactName !== '') {
			$pdf->SetX(110);
			$pdf->Cell(0, self::LINE, $contactName, 0, 1);
		}
		$vat = self::str($c['vat_id'] ?? '');
		if ($vat !== '') {
			$pdf->SetX(110);
			$pdf->SetFont(InvoicePage::FONT, '', 8);
			$pdf->Cell(0, self::SMALL, $t['vat_id'] . ' ' . $vat, 0, 1);
		}
	}

	/**
	 * Street, postcode and city, and the country when it differs from the
	 * issuer's (the issuer's own country is never shown).
	 *
	 * @param array<string,mixed> $a
	 * @return list<string>
	 */
	private static function addressLines(array $a, ?string $issuerCountry = null): array {
		$out = [];
		$street = self::str($a['street'] ?? '');
		if ($street !== '') {
			$out[] = $street;
		}
		$place = trim(self::str($a['postcode'] ?? '') . ' ' . self::str($a['city'] ?? ''));
		if ($place !== '') {
			$out[] = $place;
		}
		$country = strtoupper(self::str($a['country'] ?? ''));
		if ($country !== '' && $issuerCountry !== null && $country !== strtoupper($issuerCountry)) {
			$out[] = $country;
		}
		return $out;
	}

	/** Title and the facts: date, due date, period, project, budget. @param array<string,string> $t */
	private function head(InvoicePage $pdf, InvoiceDocument $d, array $t): void {
		$pdf->SetXY(InvoicePage::MARGIN, 95);
		$pdf->SetFont(InvoicePage::FONT, 'B', 14);
		$pdf->Cell(0, 8, $d->title(), 0, 1);
		$pdf->Ln(2);
		$pdf->SetFont(InvoicePage::FONT, '', 9);
		$facts = [
			[$t['date'], DocumentRules::day($d->issuedOn, $d->lang)],
			[$t['due'], DocumentRules::day($d->dueOn, $d->lang)],
			[$t['period'], DocumentRules::day($d->from, $d->lang) . ' – ' . DocumentRules::day($d->to, $d->lang)],
			[$t['project'], $d->project],
			[$t['budget'], $d->budget],
		];
		foreach ($facts as [$label, $value]) {
			if ($value === '' || $value === ' – ') {
				continue;
			}
			$pdf->Cell(40, self::SMALL + 0.5, $label, 0, 0);
			$pdf->Cell(0, self::SMALL + 0.5, $value, 0, 1);
		}
		$pdf->Ln(5);
	}

	/** The positions table; the header row again after a page break. @param array<string,string> $t */
	private function positions(InvoicePage $pdf, InvoiceDocument $d, array $t): void {
		$header = function () use ($pdf, $d, $t): void {
			$pdf->SetFont(InvoicePage::FONT, 'B', 9);
			$pdf->Cell(self::COLS[0], self::LINE, $t['position'], 'B', 0, 'L');
			$pdf->Cell(self::COLS[1], self::LINE, $t['hours'], 'B', 0, 'R');
			$pdf->Cell(self::COLS[2], self::LINE, $t['rate'], 'B', 0, 'R');
			$pdf->Cell(self::COLS[3], self::LINE, $t['amount'] . ' ' . $d->currency, 'B', 1, 'R');
			$pdf->SetFont(InvoicePage::FONT, '', 9);
		};
		$header();
		// 0.21.0: the pass-through items stand in their own block under the sums; with several rates each line names its rate.
		[$taxable] = $d->splitPositions();
		$several = count($d->sums->lines) > 1;
		foreach ($taxable as $p) {
			$kind = self::str($p['kind'] ?? '');
			$text = self::str($p['text'] ?? '');
			if ($several && $kind !== 'text') {
				$text .= ' (' . $t['vat'] . ' ' . DocumentRules::percent(VatLines::rateOf((array)$p, $d->vat, $d->taxCase)) . ')';
			}
			$hours = $kind === 'hours' && is_numeric($p['hours'] ?? null) ? DocumentRules::hours((float)$p['hours']) : '';
			$rate = $kind === 'hours' && is_numeric($p['rate'] ?? null) ? DocumentRules::money((float)$p['rate'], $d->lang) : '';
			$amount = $kind === 'text' ? '' : DocumentRules::money(is_numeric($p['amount'] ?? null) ? (float)$p['amount'] : 0.0, $d->lang);
			$this->row($pdf, self::COLS, [$text, $hours, $rate, $amount], ['L', 'R', 'R', 'R'], $header);
		}
	}

	/**
	 * One table row with a wrapping first column; the other columns are
	 * aligned to its top. A row that does not fit starts a new page with
	 * the header row.
	 *
	 * @param list<float> $widths
	 * @param list<string> $cells
	 * @param list<string> $aligns
	 */
	private function row(InvoicePage $pdf, array $widths, array $cells, array $aligns, ?callable $header, float $line = self::LINE): void {
		$lines = max(1, (int)$pdf->getNumLines($cells[0], $widths[0]));
		$h = (float)$lines * $line;
		if ($pdf->GetY() + $h > (float)$pdf->getPageHeight() - InvoicePage::BOTTOM) {
			$pdf->AddPage();
			if ($header !== null) {
				$header();
			}
		}
		$x = $pdf->GetX();
		$y = $pdf->GetY();
		foreach ($cells as $i => $cell) {
			$pdf->MultiCell($widths[$i], $h, $cell, 0, $aligns[$i], false, 0, $x, $y, true, 0, false, true, $h, 'T');
			$x += $widths[$i];
		}
		$pdf->SetXY(InvoicePage::MARGIN, $y + $h);
	}

	/**
	 * Net, VAT per rate and gross, right-aligned under the table; then the
	 * pass-through items (no VAT) with their sum and the total to pay.
	 *
	 * @param array<string,string> $t
	 */
	private function sums(InvoicePage $pdf, InvoiceDocument $d, array $t): void {
		$label = self::COLS[0] + self::COLS[1] + self::COLS[2];
		$s = $d->sums;
		[, $pass] = $d->splitPositions();
		$withPass = $pass !== [] || $s->passThrough !== 0.0;
		if ($s->lines !== [] || !$withPass) {
			$pdf->SetFont(InvoicePage::FONT, '', 9);
			$pdf->Cell($label, self::LINE, $t['net'], 'T', 0, 'R');
			$pdf->Cell(self::COLS[3], self::LINE, DocumentRules::money($s->net, $d->lang), 'T', 1, 'R');
			$lines = $s->lines !== [] ? $s->lines : [['rate' => $d->vatRate(), 'net' => $s->net, 'vat' => $s->vatAmount]];
			foreach ($lines as $l) {
				$text = $t['vat'] . ' ' . DocumentRules::percent($l['rate']);
				if (count($lines) > 1) {
					$text .= ' ' . $t['on'] . ' ' . DocumentRules::money($l['net'], $d->lang);
				}
				$pdf->Cell($label, self::LINE, $text, 0, 0, 'R');
				$pdf->Cell(self::COLS[3], self::LINE, DocumentRules::money($l['vat'], $d->lang), 0, 1, 'R');
			}
			$pdf->SetFont(InvoicePage::FONT, 'B', 10);
			$pdf->Cell($label, 6, ($withPass ? $t['gross_vat'] : $t['gross']) . ' ' . $d->currency, 'T', 0, 'R');
			$pdf->Cell(self::COLS[3], 6, DocumentRules::money($s->gross, $d->lang), 'T', 1, 'R');
		}
		// Reverse charge and export (0.20.0): the note under the sums.
		$note = $d->taxNote();
		if ($note !== null) {
			$pdf->SetFont(InvoicePage::FONT, '', 8);
			$pdf->Cell(0, self::SMALL, $note, 0, 1, 'R');
		}
		if ($withPass) {
			$pdf->Ln(4);
			$pdf->SetFont(InvoicePage::FONT, 'B', 9);
			$pdf->Cell(0, self::LINE, $t['pass_through'], 'B', 1, 'L');
			$pdf->SetFont(InvoicePage::FONT, '', 9);
			foreach ($pass as $p) {
				$amount = DocumentRules::money(is_numeric($p['amount'] ?? null) ? (float)$p['amount'] : 0.0, $d->lang);
				$this->row($pdf, [$label, self::COLS[3]], [self::str($p['text'] ?? ''), $amount], ['L', 'R'], null);
			}
			$pdf->Cell($label, self::LINE, $t['pass_through_sum'], 'T', 0, 'R');
			$pdf->Cell(self::COLS[3], self::LINE, DocumentRules::money($s->passThrough, $d->lang), 'T', 1, 'R');
			$pdf->SetFont(InvoicePage::FONT, 'B', 10);
			$pdf->Cell($label, 6, $t['total'] . ' ' . $d->currency, 'T', 0, 'R');
			$pdf->Cell(self::COLS[3], 6, DocumentRules::money($s->total, $d->lang), 'T', 1, 'R');
		}
		$pdf->SetFont(InvoicePage::FONT, '', 10);
	}

	/** The hours appendix on its own pages: date, person, code, title, hours. @param array<string,string> $t */
	private function appendix(InvoicePage $pdf, InvoiceDocument $d, array $t): void {
		$pdf->AddPage();
		$pdf->SetFont(InvoicePage::FONT, 'B', 12);
		$pdf->Cell(0, 7, $t['appendix'] . ' – ' . $d->title(), 0, 1);
		$pdf->Ln(2);
		$header = function () use ($pdf, $t): void {
			$pdf->SetFont(InvoicePage::FONT, 'B', 8);
			$labels = [$t['date'], $t['person'], $t['code'], $t['title'], $t['hours']];
			foreach (self::APPENDIX as $i => $w) {
				$pdf->Cell($w, 4.5, $labels[$i], 'B', $i === 4 ? 1 : 0, $i === 4 ? 'R' : 'L');
			}
			$pdf->SetFont(InvoicePage::FONT, '', 8);
		};
		$header();
		foreach ($d->appendix as $r) {
			$cells = [DocumentRules::day($r['date'], $d->lang), $r['person'], $r['code'], $r['title'], DocumentRules::hours($r['hours'])];
			// The title wraps; the row is as high as it needs.
			$lines = max(1, (int)$pdf->getNumLines($r['title'], self::APPENDIX[3]));
			$h = (float)$lines * 4.0;
			if ($pdf->GetY() + $h > (float)$pdf->getPageHeight() - InvoicePage::BOTTOM) {
				$pdf->AddPage();
				$header();
			}
			$x = $pdf->GetX();
			$y = $pdf->GetY();
			foreach ($cells as $i => $cell) {
				$pdf->MultiCell(self::APPENDIX[$i], $h, $cell, 0, $i === 4 ? 'R' : 'L', false, 0, $x, $y, true, 0, false, true, $h, 'T');
				$x += self::APPENDIX[$i];
			}
			$pdf->SetXY(InvoicePage::MARGIN, $y + $h);
		}
		$pdf->SetFont(InvoicePage::FONT, 'B', 8);
		$pdf->Cell(self::APPENDIX[0] + self::APPENDIX[1] + self::APPENDIX[2] + self::APPENDIX[3], 4.5, $t['total'], 'T', 0, 'L');
		$pdf->Cell(self::APPENDIX[4], 4.5, DocumentRules::hours($d->hoursTotal), 'T', 1, 'R');
	}

	/**
	 * The Swiss QR bill on the last page (Swiss Payment Standards):
	 * creditor = issuer, debtor = customer when the address is complete,
	 * amount = the total (0.21.0: gross plus pass-through items), the QR reference with a QR-IBAN. When the data fails the standard's
	 * checks (e.g. a QR-IBAN without reference), the page is left out.
	 *
	 * @param array<string,string> $t
	 */
	private function qrBill(InvoicePage $pdf, InvoiceDocument $d, array $t): void {
		$bill = $this->buildQrBill($d);
		if ($bill === null) {
			return;
		}
		$pdf->AddPage();
		$pdf->markQrPage();
		$pdf->SetFont(InvoicePage::FONT, 'B', 12);
		$pdf->Cell(0, 7, $t['payment_part'] . ' – ' . $d->title(), 0, 1);
		$output = new TcPdfOutput($bill, $d->lang, $pdf);
		$options = new DisplayOptions();
		$options->setPrintable(false)->setDisplayScissors(false)->setDisplayTextDownArrows(false);
		$output->setDisplayOptions($options)->getPaymentPart();
	}

	/** The bill's data; null when the standard's checks fail. */
	private function buildQrBill(InvoiceDocument $d): ?QrBill {
		$creditor = self::address($d->issuer);
		if ($creditor === null || $d->iban === null) {
			return null;
		}
		try {
			$bill = QrBill::create();
			$bill->setCreditor($creditor);
			$bill->setCreditorInformation(CreditorInformation::create($d->iban));
			$debtor = self::address($d->customer);
			if ($debtor !== null) {
				$bill->setUltimateDebtor($debtor);
			}
			$bill->setPaymentAmountInformation(PaymentAmountInformation::create($d->currency, round($d->sums->total, 2)));
			// A QR-IBAN carries the QR reference (0.20.0); a plain IBAN none.
			$bill->setPaymentReference($d->qrReference !== null && QrReferenceRules::isQrIban($d->iban)
				? PaymentReference::create(PaymentReference::TYPE_QR, $d->qrReference)
				: PaymentReference::create(PaymentReference::TYPE_NON));
			$bill->setAdditionalInformation(AdditionalInformation::create(mb_substr($d->title(), 0, 140)));
			return $bill->getViolations()->count() === 0 ? $bill : null;
		} catch (\Throwable) {
			return null;
		}
	}

	/** A structured address for the QR bill; null when name, postcode, city or country is missing. @param array<string,mixed> $a */
	private static function address(array $a): ?StructuredAddress {
		$name = mb_substr(self::str($a['name'] ?? ''), 0, 70);
		$postcode = mb_substr(self::str($a['postcode'] ?? ''), 0, 16);
		$city = mb_substr(self::str($a['city'] ?? ''), 0, 35);
		$country = strtoupper(self::str($a['country'] ?? ''));
		if ($name === '' || $postcode === '' || $city === '' || strlen($country) !== 2) {
			return null;
		}
		$street = self::str($a['street'] ?? '');
		if ($street === '') {
			return StructuredAddress::createWithoutStreet($name, $postcode, $city, $country);
		}
		// “Dornacherstrasse 192” → street and building number.
		$number = null;
		if (preg_match('/^(.*\S)\s+(\d+[a-zA-Z]?)$/u', $street, $m)) {
			$street = $m[1];
			$number = $m[2];
		}
		return StructuredAddress::createWithStreet($name, mb_substr($street, 0, 70), $number, $postcode, $city, $country);
	}

	private static function str(mixed $v): string {
		return is_string($v) ? $v : (is_int($v) || is_float($v) ? (string)$v : '');
	}
}
