<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Capabilities;
use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\DocumentRules;
use OCA\TimeSister\Service\TeamRules;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * Invoice documents (0.19.0), the pure part: the team setting
 * `documents`, the folders and file names, the year, the chain line, the
 * watermark, the language and the labels.
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
		$this->assertSame(['invoices' => null, 'quotes' => null, 'per_year' => true, 'chain_mail' => null], DocumentRules::settings(null));
		$this->assertSame(['invoices' => null, 'quotes' => null, 'per_year' => true, 'chain_mail' => null], DocumentRules::settings('garbage'));
		$s = DocumentRules::settings('{"invoices":"Buchhaltung/Rechnungen","per_year":false,"chain_mail":"treuhand@example.test"}');
		$this->assertSame(['invoices' => 'Buchhaltung/Rechnungen', 'quotes' => null, 'per_year' => false, 'chain_mail' => 'treuhand@example.test'], $s);
		// Encoding: null when everything is default, else only the four keys.
		$this->assertNull(DocumentRules::encode(DocumentRules::DEFAULTS));
		$this->assertSame('{"invoices":"A","quotes":null,"per_year":true,"chain_mail":null}', DocumentRules::encode(['invoices' => 'A', 'extra' => 1] + DocumentRules::DEFAULTS));
	}

	public function testValidate(): void {
		$cur = DocumentRules::DEFAULTS;
		$v = DocumentRules::validate((object)['invoices' => 'Buchhaltung/Rechnungen/', 'per_year' => false], $cur);
		$this->assertSame(['invoices' => 'Buchhaltung/Rechnungen', 'quotes' => null, 'per_year' => false, 'chain_mail' => null], $v);
		// A missing key keeps the stored value; empty means default again.
		$this->assertSame('Buchhaltung/Rechnungen', DocumentRules::validate(['chain_mail' => 'a@example.org'], $v)['invoices']);
		$this->assertNull(DocumentRules::validate(['invoices' => ''], $v)['invoices']);
		$this->assertSame('422 “documents.invoices” must be a folder path of at most 255 characters, without “..” and without a leading slash.', self::outcome(fn () => DocumentRules::validate(['invoices' => '/abs'], $cur)));
		$this->assertStringStartsWith('422', self::outcome(fn () => DocumentRules::validate(['quotes' => 'a/../b'], $cur)));
		$this->assertStringStartsWith('422', self::outcome(fn () => DocumentRules::validate(['quotes' => str_repeat('x', 256)], $cur)));
		$this->assertStringStartsWith('422', self::outcome(fn () => DocumentRules::validate(['quotes' => "a\nb"], $cur)));
		$this->assertStringStartsWith('422', self::outcome(fn () => DocumentRules::validate(['per_year' => 'yes'], $cur)));
		$this->assertSame('422 “documents.chain_mail” must be an e-mail address.', self::outcome(fn () => DocumentRules::validate(['chain_mail' => 'nope'], $cur)));
		$this->assertSame('422 Unknown team setting “documents.other”.', self::outcome(fn () => DocumentRules::validate(['other' => 1], $cur)));
		$this->assertSame('422 “documents” must be an object.', self::outcome(fn () => DocumentRules::validate('x', $cur)));
		// Through TeamRules::settings, merged over the stored JSON.
		$s = TeamRules::settings(['settings' => ['documents' => ['quotes' => 'Offerten'], 'invoicing' => true]], '{"invoices":"R"}');
		$this->assertSame(['invoices' => 'R', 'quotes' => 'Offerten', 'per_year' => true, 'chain_mail' => null], $s['documents']);
		$this->assertTrue($s['invoicing']);
	}

	public function testFoldersAndNames(): void {
		$d = DocumentRules::DEFAULTS;
		$this->assertSame('TimeSister Invoices/Planungsbüro', DocumentRules::invoicesFolder($d, 'Planungsbüro'));
		$this->assertSame('TimeSister Quotes/Planungsbüro', DocumentRules::quotesFolder($d, 'Planungsbüro'));
		$this->assertSame('TimeSister Invoices/A-B', DocumentRules::invoicesFolder($d, 'A/B'));
		$this->assertSame('Buchhaltung/Rechnungen', DocumentRules::invoicesFolder(['invoices' => 'Buchhaltung/Rechnungen'] + $d, 'X'));
		$this->assertSame('2026', DocumentRules::year('2026-10-15'));
		$this->assertSame('TimeSister Invoices/Planungsbüro/2026', DocumentRules::invoiceFolder($d, 'Planungsbüro', '2026-10-15'));
		$this->assertSame('Buchhaltung/Rechnungen', DocumentRules::invoiceFolder(['invoices' => 'Buchhaltung/Rechnungen', 'per_year' => false] + $d, 'X', '2026-10-15'));
		$this->assertSame('RE-2026-0007.pdf', DocumentRules::fileName('RE-2026-0007'));
		$this->assertSame('2026-7.pdf', DocumentRules::fileName('2026/7'));
		$this->assertSame('chain-2026-10-15.txt', DocumentRules::chainFileName('2026-10-15'));
		$this->assertSame("team: Planungsbüro\ndate: 2026-10-15\nentries: 12\nhead seq: 12\nhead hash: abc\n", DocumentRules::chainHeadText('Planungsbüro', '2026-10-15', 12, 12, 'abc'));
		$this->assertTrue(DocumentRules::isPath('a/b c/d'));
		$this->assertFalse(DocumentRules::isPath('a//b'));
		$this->assertFalse(DocumentRules::isPath('a\\b'));
		$this->assertFalse(DocumentRules::isPath('./a'));
	}

	public function testChainLineWatermarkLanguage(): void {
		$this->assertSame('Beleg RE-2026-0007 · Kette 3 3f9c0123abcd ← a1b2c3d4e5f6', DocumentRules::chainLine('RE-2026-0007', 3, '3f9c0123abcdef', 'a1b2c3d4e5f6a7b8'));
		$this->assertSame('Beleg RE-1 · Kette 1 abcdef012345 ← –', DocumentRules::chainLine('RE-1', 1, 'abcdef0123456789', ''));
		$this->assertSame('VORABZUG', DocumentRules::watermark('de'));
		$this->assertSame('DRAFT', DocumentRules::watermark('en'));
		$this->assertSame('de', DocumentRules::language((object)['language' => 'de_CH'], 'en'));
		$this->assertSame('en', DocumentRules::language((object)['language' => 'en'], 'de'));
		$this->assertSame('de', DocumentRules::language(null, 'de'));
		$this->assertSame('en', DocumentRules::language((object)['language' => 'fr'], 'fr'));
		$this->assertSame('Rechnung', DocumentRules::texts('de')['invoice']);
		$this->assertSame('Invoice', DocumentRules::texts('en')['invoice']);
		$t = DocumentRules::texts('en');
		$this->assertSame('Invoice RE-1', DocumentRules::title($t, 'RE-1', null, null, null));
		$this->assertSame('Reversal of RE-1 – RE-2', DocumentRules::title($t, 'RE-2', 'inv-x', null, 'RE-1'));
		$this->assertSame('Credit note RE-3', DocumentRules::title($t, 'RE-3', null, 'inv-x', null));
		$this->assertSame('1’234.50', DocumentRules::money(1234.5, 'de'));
		$this->assertSame('1,234.50', DocumentRules::money(1234.5, 'en'));
		$this->assertSame('15.10.2026', DocumentRules::day('2026-10-15', 'de'));
		$this->assertSame('2026-10-15', DocumentRules::day('2026-10-15T08:00:00', 'en'));
	}

	public function testCapability(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willReturn('0.19.0');
		$this->assertSame(1, (new Capabilities($apps))->getCapabilities()['timesister']['invoice_documents']);
	}
}
