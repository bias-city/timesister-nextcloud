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

	public function testChainLineWatermarkLanguage(): void {
		$this->assertSame('Beleg RE-2026-0007 · Kette 3 3f9c0123abcd ← a1b2c3d4e5f6', DocumentRules::chainLine('RE-2026-0007', 3, '3f9c0123abcdef', 'a1b2c3d4e5f6a7b8'));
		$this->assertSame('Beleg RE-1 · Kette 1 abcdef012345 ← –', DocumentRules::chainLine('RE-1', 1, 'abcdef0123456789', ''));
		$this->assertSame('VORABZUG', DocumentRules::watermark('de'));
		$this->assertSame('DRAFT', DocumentRules::watermark('en'));
		$this->assertSame('de', DocumentRules::language((object)['language' => 'de_CH'], 'en'));
		$this->assertSame('en', DocumentRules::language((object)['language' => 'en'], 'de'));
		$this->assertSame('de', DocumentRules::language(null, 'de'));
		$this->assertSame('en', DocumentRules::language((object)['language' => 'fr'], 'fr'));
		// What a client may write (0.22.0): null, de or en, else 422.
		foreach ([null, 'de', 'en'] as $ok) {
			$this->assertSame('ok', self::outcome(fn () => DocumentRules::validateLanguage($ok)));
		}
		foreach (['de_CH', 'DE', 'fr', '', 1, true] as $bad) {
			$this->assertSame('422 The team’s “language” is null, “de” or “en”.', self::outcome(fn () => DocumentRules::validateLanguage($bad)));
		}
		$this->assertSame('ok', self::outcome(fn () => RecordValidator::validate('setting', 'settings', (object)['language' => 'de', 'currency' => 'CHF'])));
		$this->assertStringStartsWith('422 ', self::outcome(fn () => RecordValidator::validate('setting', 'settings', (object)['language' => 'fr'])));
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
		$caps = (new Capabilities($apps))->getCapabilities()['timesister'];
		$this->assertSame(1, $caps['invoice_documents']);
		$this->assertSame([1, 1, 1], [$caps['receipts'], $caps['expense_reports'], $caps['document_tree']], '0.22.0');
	}
}
