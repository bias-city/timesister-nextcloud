<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Capabilities;
use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\HandoverRules;
use OCA\TimeSister\Service\InvoicingModeRules;
use OCA\TimeSister\Service\ManualInvoiceRules;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * 0.48.0: the invoicing mode manual – the setting, issuing refused, the
 * kinds and bytes of the filed documents, which records are filed and
 * under which fixed name.
 */
class ManualInvoiceRulesTest extends TestCase {
	/** "ok" or "<status> <code|message>". */
	private static function outcome(callable $fn): string {
		try {
			$fn();
			return 'ok';
		} catch (ApiException $e) {
			return $e->getStatus() . ' ' . ($e->getRuleCode() ?? $e->getMessage());
		}
	}

	public function testModeManual(): void {
		$this->assertSame(['mode' => 'manual', 'system' => null, 'copy' => 'off'], InvoicingModeRules::validate(json_decode('{"mode":"manual"}')));
		$this->assertSame(['mode' => 'manual', 'system' => 'bexio', 'copy' => 'auto'], InvoicingModeRules::validate(json_decode('{"mode":"manual","system":"bexio","copy":"auto"}')), 'a copy as in the mode timesister');
		$this->assertSame(['mode' => 'manual', 'system' => null, 'copy' => 'off'], InvoicingModeRules::validate(json_decode('{"mode":"manual","archive_pdf":true}')), 'archive_pdf only in the mode external');
		$this->assertSame('422 “invoicing_mode.copy” needs “invoicing_mode.system”: name the accounting system that gets the copies.',
			self::outcome(static fn () => InvoicingModeRules::validate(json_decode('{"mode":"manual","copy":"manual"}'))));
		$this->assertSame('422 “invoicing_mode.mode” must be “timesister”, “manual” or “external”.', self::outcome(static fn () => InvoicingModeRules::validate(json_decode('{"mode":"mac"}'))));
		$m = InvoicingModeRules::validate(json_decode('{"mode":"manual"}'));
		$this->assertSame($m, InvoicingModeRules::decode(InvoicingModeRules::encode($m)), 'round trip');
		$this->assertTrue(InvoicingModeRules::isManual($m));
		$this->assertFalse(InvoicingModeRules::isManual(null));
		$this->assertFalse(InvoicingModeRules::isManual(['mode' => 'timesister', 'system' => null, 'copy' => 'off']));
		$this->assertNull(InvoicingModeRules::externalSystem($m), 'not handed over');
		$this->assertFalse(InvoicingModeRules::archivesPdf($m));
	}

	public function testIssueRefused(): void {
		$manual = ['mode' => 'manual', 'system' => null, 'copy' => 'off'];
		$this->assertSame('409 invoicing_manual', self::outcome(static fn () => ManualInvoiceRules::refuseIssue($manual)));
		$this->assertSame('ok', self::outcome(static fn () => ManualInvoiceRules::refuseIssue(null)));
		$this->assertSame('ok', self::outcome(static fn () => ManualInvoiceRules::refuseIssue(['mode' => 'timesister', 'system' => 'bexio', 'copy' => 'auto'])));
		$this->assertSame('ok', self::outcome(static fn () => HandoverRules::refuseIssue($manual)), 'the external refusal leaves it to the manual one');
		$this->assertSame('409 handover_mode_not_external', self::outcome(static fn () => HandoverRules::requireMode($manual)), 'hand-over needs external');
		$this->assertSame('409 handover_no_archive', self::outcome(static fn () => HandoverRules::requireArchive($manual)));
		try {
			ManualInvoiceRules::refuseIssue($manual);
		} catch (ApiException $e) {
			$this->assertSame('In the mode manual the app issues invoices; the server keeps no ledger.', $e->getMessage());
		}
	}

	public function testKindsAndBytes(): void {
		$this->assertSame('pdf', ManualInvoiceRules::kind(null));
		$this->assertSame('pdf', ManualInvoiceRules::kind(''));
		foreach (['pdf', 'docx', 'xml'] as $k) {
			$this->assertSame($k, ManualInvoiceRules::kind($k));
		}
		$this->assertSame('422 “kind” must be “pdf”, “docx” or “xml”.', self::outcome(static fn () => ManualInvoiceRules::kind('zip')));
		$this->assertSame('ok', self::outcome(static fn () => ManualInvoiceRules::requireBytes('pdf', "%PDF-1.4\n%%EOF\n")));
		$this->assertSame('422 The document must be a PDF.', self::outcome(static fn () => ManualInvoiceRules::requireBytes('pdf', 'PK..')));
		$this->assertSame('ok', self::outcome(static fn () => ManualInvoiceRules::requireBytes('docx', "PK\x03\x04rest")));
		$this->assertSame('422 The document must be a Word file (.docx).', self::outcome(static fn () => ManualInvoiceRules::requireBytes('docx', '')));
		$this->assertSame('422 The document must be a Word file (.docx).', self::outcome(static fn () => ManualInvoiceRules::requireBytes('docx', '%PDF-1.4')));
		$this->assertSame('413 A file may be at most 20 MB.', self::outcome(static fn () => ManualInvoiceRules::requireBytes('docx', 'PK' . str_repeat('x', 20 * 1024 * 1024))));
		$xml = '<?xml version="1.0"?><rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100"/>';
		$this->assertSame('ok', self::outcome(static fn () => ManualInvoiceRules::requireBytes('xml', $xml)));
		$this->assertSame('422 The file must be a well-formed e-invoice XML (CII or UBL).', self::outcome(static fn () => ManualInvoiceRules::requireBytes('xml', '<other/>')));
		$this->assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', ManualInvoiceRules::TYPES['docx']);
	}

	public function testWhatIsFiled(): void {
		$d = static fn (array $v): \stdClass => (object)($v + ['status' => 'issued', 'number' => '2026-0042', 'issued_on' => '2026-11-11']);
		$this->assertSame(['number' => '2026-0042', 'date' => '2026-11-11'], ManualInvoiceRules::fileable($d([])));
		$this->assertSame(['number' => '2026-0042', 'date' => '2026-11-11'], ManualInvoiceRules::fileable($d(['status' => 'paid'])));
		foreach ([
			'a draft' => ['status' => 'draft', 'number' => null],
			'a draft with a number' => ['status' => 'draft'],
			'cancelled' => ['status' => 'cancelled'],
			'no number' => ['number' => null],
			'a blank number' => ['number' => ' '],
			'no date' => ['issued_on' => null],
			'a date that is none' => ['issued_on' => '11.11.2026'],
		] as $name => $v) {
			$this->assertSame('409 invoice_manual_not_issued', self::outcome(static fn () => ManualInvoiceRules::fileable($d($v))), $name);
		}
		// Reading: number and date name the file, whatever the status.
		$this->assertSame(['number' => '2026-0042', 'date' => '2026-11-11'], ManualInvoiceRules::placed($d(['status' => 'cancelled'])));
		$this->assertNull(ManualInvoiceRules::placed($d(['number' => null])));
	}

	public function testFixedNameReplaces(): void {
		$this->assertSame('2026-11-11_2026-0042_Musterbau-AG.pdf', ManualInvoiceRules::name('2026-11-11', '2026-0042', 'Musterbau AG', 'pdf'));
		$this->assertSame('2026-11-11_2026-0042_Musterbau-AG.docx', ManualInvoiceRules::name('2026-11-11', '2026-0042', 'Musterbau AG', 'docx'));
		$this->assertSame('2026-11-11_R-7.xml', ManualInvoiceRules::name('2026-11-11', 'R/7', '', 'xml'), 'no customer: the part is left out');
		// The same record gives the same name: a second upload writes the same file (no `_2`).
		$this->assertSame(ManualInvoiceRules::name('2026-11-11', '2026-0042', 'Bäckerei Beispiel', 'pdf'), ManualInvoiceRules::name('2026-11-11', '2026-0042', 'Bäckerei Beispiel', 'pdf'));
	}

	public function testCapability(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willReturn('0.48.0');
		$cap = (new Capabilities($apps))->getCapabilities()['timesister'];
		$this->assertSame(1, $cap['invoicing_manual']);
		$this->assertSame(2, $cap['api']);
	}
}
