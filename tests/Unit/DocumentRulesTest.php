<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Capabilities;
use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\DocumentRules;
use OCA\TimeSister\Service\RecordValidator;
use OCA\TimeSister\Service\TeamRules;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * Invoice documents, the pure part: the team setting
 * `documents`, the folders and file names, the year, the chain line, the
 * language and, since 0.32.0, the checks of the app's uploads.
 */
class DocumentRulesTest extends TestCase {
	private static function outcome(callable $fn): string {
		try {
			$fn();
		} catch (ApiException $e) {
			return $e->getStatus() . ' ' . $e->getMessage();
		}
		return 'ok';
	}

	public function testSettingsDefaultsAndStored(): void {
		$this->assertSame(['root' => null, 'chain_mail' => null], DocumentRules::settings(null));
		$this->assertSame(['root' => null, 'chain_mail' => null], DocumentRules::settings('garbage'));
		// 0.22.0: the folders of 0.19.0 are ignored, never returned.
		$s = DocumentRules::settings('{"invoices":"Buchhaltung/Rechnungen","per_year":false,"root":"TimeSister Example","chain_mail":"treuhand@example.test"}');
		$this->assertSame(['root' => 'TimeSister Example', 'chain_mail' => 'treuhand@example.test'], $s);
		// Encoding: null when everything is default, else only the two keys.
		$this->assertNull(DocumentRules::encode(DocumentRules::DEFAULTS));
		$this->assertSame('{"root":"A","chain_mail":null}', DocumentRules::encode(['root' => 'A', 'extra' => 1] + DocumentRules::DEFAULTS));
	}

	public function testValidate(): void {
		$cur = DocumentRules::DEFAULTS;
		$v = DocumentRules::validate((object)['root' => 'Gruppe/TimeSister/', 'invoices' => 'X', 'quotes' => 'Y', 'per_year' => 'whatever'], $cur);
		$this->assertSame(['root' => 'Gruppe/TimeSister', 'chain_mail' => null], $v, 'old folder keys ignored');
		// A missing key keeps the stored value; empty means default again.
		$this->assertSame('Gruppe/TimeSister', DocumentRules::validate(['chain_mail' => 'a@example.org'], $v)['root']);
		$this->assertNull(DocumentRules::validate(['root' => ''], $v)['root']);
		$this->assertSame('422 “documents.root” must be a folder path of at most 255 characters, without “..” and without a leading slash.', self::outcome(fn () => DocumentRules::validate(['root' => '/abs'], $cur)));
		$this->assertStringStartsWith('422', self::outcome(fn () => DocumentRules::validate(['root' => 'a/../b'], $cur)));
		$this->assertStringStartsWith('422', self::outcome(fn () => DocumentRules::validate(['root' => str_repeat('x', 256)], $cur)));
		$this->assertStringStartsWith('422', self::outcome(fn () => DocumentRules::validate(['root' => "a\nb"], $cur)));
		$this->assertSame('422 “documents.chain_mail” must be an e-mail address.', self::outcome(fn () => DocumentRules::validate(['chain_mail' => 'nope'], $cur)));
		$this->assertSame('422 Unknown team setting “documents.other”.', self::outcome(fn () => DocumentRules::validate(['other' => 1], $cur)));
		$this->assertSame('422 “documents” must be an object.', self::outcome(fn () => DocumentRules::validate('x', $cur)));
		// Through TeamRules::settings, merged over the stored JSON.
		$s = TeamRules::settings(['settings' => ['documents' => ['chain_mail' => 'a@example.org'], 'invoicing' => true]], '{"root":"R"}');
		$this->assertSame(['root' => 'R', 'chain_mail' => 'a@example.org'], $s['documents']);
		$this->assertTrue($s['invoicing']);
	}

	public function testFoldersAndNames(): void {
		$d = DocumentRules::DEFAULTS;
		$this->assertSame('TimeSister/Example Team', DocumentRules::rootOf($d, 'Example Team'));
		$this->assertSame('TimeSister/A-B', DocumentRules::rootOf($d, 'A/B'));
		$this->assertSame('Gruppe', DocumentRules::rootOf(['root' => 'Gruppe'] + $d, 'X'));
		$this->assertSame('RE-2026-0007.pdf', DocumentRules::fileName('RE-2026-0007'));
		$this->assertSame('2026-7.pdf', DocumentRules::fileName('2026/7'));
		$this->assertSame("team: Example\ndate: 2026-10-15\nentries: 12\nhead seq: 12\nhead hash: abc\n", DocumentRules::chainHeadText('Example', '2026-10-15', 12, 12, 'abc'));
		$this->assertTrue(DocumentRules::isPath('a/b c/d'));
		$this->assertFalse(DocumentRules::isPath('a//b'));
		$this->assertFalse(DocumentRules::isPath('a\\b'));
		$this->assertFalse(DocumentRules::isPath('./a'));
	}

	public function testLanguage(): void {
		$this->assertSame('de', DocumentRules::language((object)['language' => 'de'], 'en'));
		$this->assertSame('en', DocumentRules::language((object)['language' => 'en'], 'de'));
		$this->assertSame('de', DocumentRules::language(null, 'de_CH'));
		$this->assertSame('en', DocumentRules::language((object)['language' => null], 'fr'));
		$this->assertSame('ok', self::outcome(static fn () => DocumentRules::validateLanguage(null)));
		$this->assertStringStartsWith('422', self::outcome(static fn () => DocumentRules::validateLanguage('de_CH')));
	}

	/** 0.32.0: the app's uploads – PDF magic and size, number and chain line in the bytes, a well-formed CII/UBL XML. */
	public function testUploads(): void {
		$pdf = "%PDF-1.7\n% Beleg RE-2026-0017 · Kette 42 ← 3f9c1a2b7d4e\n%%EOF";
		$this->assertSame('ok', self::outcome(static fn () => DocumentRules::requirePdf($pdf)));
		$this->assertSame('422 The document must be a PDF.', self::outcome(static fn () => DocumentRules::requirePdf('hello')));
		$this->assertSame('413 A file may be at most 20 MB.', self::outcome(static fn () => DocumentRules::requirePdf(str_repeat('x', DocumentRules::FILE_MAX + 1))));
		$this->assertSame('ok', self::outcome(static fn () => DocumentRules::requireMentions($pdf, ['RE-2026-0017', 'Beleg RE-2026-0017 · Kette 42 ← 3f9c1a2b7d4e'])));
		try {
			DocumentRules::requireMentions($pdf, ['RE-2026-0017', 'Beleg RE-2026-0017 · Kette 41 ← 3f9c1a2b7d4e']);
			$this->fail('an older render must not close the issue');
		} catch (ApiException $e) {
			$this->assertSame([422, 'document_mismatch', 'content'], [$e->getStatus(), $e->getRuleCode(), $e->getField()]);
		}
		$cii = '<?xml version="1.0" encoding="UTF-8"?><rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100"><rsm:ExchangedDocument><ram:ID xmlns:ram="urn:x">RE-2026-0017</ram:ID></rsm:ExchangedDocument></rsm:CrossIndustryInvoice>';
		$this->assertSame('ok', self::outcome(static fn () => DocumentRules::requireXml($cii)));
		$this->assertSame('ok', self::outcome(static fn () => DocumentRules::requireXml('<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"><ID>1</ID></Invoice>')));
		foreach (['', 'not xml', '<Other/>', '<Invoice><ID>1</Invoice>', "<!DOCTYPE x [<!ENTITY e SYSTEM \"file:///etc/passwd\">]><Invoice>&e;</Invoice>", "\xFF\xFE<Invoice/>"] as $bad) {
			$this->assertSame('422 The file must be a well-formed e-invoice XML (CII or UBL).', self::outcome(static fn () => DocumentRules::requireXml($bad)), $bad);
		}
		$this->assertSame('413 A file may be at most 20 MB.', self::outcome(static fn () => DocumentRules::requireXml(str_repeat('<a/>', DocumentRules::FILE_MAX / 4 + 1))));
	}

	/** `chain/verify?files=true`: which entries name a file, and how a difference is reported. */
	public function testHashedFiles(): void {
		$entries = [
			['seq' => 1, 'action' => 'issued', 'invoice' => 7, 'payload' => '{"key":"inv-1","document":{"kind":"pdf","name":"a.pdf","size":3,"sha256":"aa"}}'],
			['seq' => 2, 'action' => 'document', 'invoice' => 7, 'payload' => '{"key":"inv-1","kind":"xrechnung","path":"T/a.xml","sha256":"bb"}'],
			['seq' => 3, 'action' => 'paid', 'invoice' => 7, 'payload' => '{"key":"inv-1"}'],
			['seq' => 4, 'action' => 'issued', 'invoice' => 8, 'payload' => '{"key":"inv-2"}'],
			['seq' => 5, 'action' => 'expense_document', 'invoice' => 0, 'payload' => '{"sha256":"cc"}'],
		];
		$this->assertSame([
			['seq' => 1, 'invoice' => 7, 'path' => null, 'sha256' => 'aa'],
			['seq' => 2, 'invoice' => 7, 'path' => 'T/a.xml', 'sha256' => 'bb'],
		], DocumentRules::hashedFiles($entries));
		$this->assertSame(['ok' => false, 'first_break' => 2, 'field' => 'document', 'expected' => 'bb', 'found' => 'missing'], DocumentRules::fileBreak(2, 'bb', null));
	}

	public function testCapability(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willReturn('0.19.0');
		$caps = (new Capabilities($apps))->getCapabilities()['timesister'];
		$this->assertSame(2, $caps['invoice_documents'], '0.32.0: the two-step issue, the app renders');
		$this->assertSame([2, 1], [$caps['receipts'], $caps['document_tree']], '0.22.0; receipts 2 since 0.27.0 (reopen)');
		$this->assertSame(1, $caps['expense_fixing'], '0.23.0');
		$this->assertArrayNotHasKey('expense_reports', $caps, 'replaced by expense_fixing');
	}
}
