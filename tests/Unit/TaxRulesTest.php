<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\IssueRules;
use OCA\TimeSister\Service\TaxRules;
use PHPUnit\Framework\TestCase;

/** The tax case (0.20.0): the rule, the override, the codes and notes, the frozen block. */
class TaxRulesTest extends TestCase {
	public function testRule(): void {
		$this->assertSame('domestic', TaxRules::of('CH', 'CH', null), 'same country');
		$this->assertSame('domestic', TaxRules::of('DE', 'de', 'DE123'), 'case does not matter');
		$this->assertSame('domestic', TaxRules::of('CH', null, null), 'missing customer country counts as domestic');
		$this->assertSame('domestic', TaxRules::of('CH', '', 'X'), 'empty too');
		$this->assertSame('domestic', TaxRules::of(null, 'DE', null), 'no issuer country: nothing to compare');
		$this->assertSame('eu_reverse', TaxRules::of('DE', 'FR', 'FR12345678901'), 'both EU, different, VAT id');
		$this->assertSame('export', TaxRules::of('DE', 'FR', null), 'both EU but no VAT id: export');
		$this->assertSame('export', TaxRules::of('DE', 'FR', '  '), 'blank VAT id is none');
		$this->assertSame('export', TaxRules::of('CH', 'DE', 'DE123'), 'issuer outside the EU');
		$this->assertSame('export', TaxRules::of('DE', 'CH', 'CHE-1'), 'customer outside the EU');
		$this->assertSame('export', TaxRules::of('DE', 'US', null));
		$this->assertSame('eu_reverse', TaxRules::of('CH', 'CH', null, 'eu_reverse'), 'the override wins');
		$this->assertSame('domestic', TaxRules::of('DE', 'FR', 'FR1', 'domestic'));
	}

	public function testOverride(): void {
		$this->assertNull(TaxRules::checkOverride(null));
		$this->assertSame('export', TaxRules::checkOverride('export'));
		foreach (['Export', 'eu', 1, true, ''] as $bad) {
			try {
				TaxRules::checkOverride($bad);
				$this->fail('accepted ' . var_export($bad, true));
			} catch (ApiException $e) {
				$this->assertSame(422, $e->getStatus());
			}
		}
	}

	public function testCodesRatesNotes(): void {
		$this->assertSame(8.1, TaxRules::rate('domestic', 8.1));
		$this->assertSame(0.0, TaxRules::rate('eu_reverse', 8.1));
		$this->assertSame(0.0, TaxRules::rate('export', 19.0));
		$this->assertSame('S', TaxRules::code('domestic', 8.1, 'CH'));
		$this->assertSame('Z', TaxRules::code('domestic', 0.0, 'CH'));
		$this->assertSame('AE', TaxRules::code('eu_reverse', 0.0, 'FR'));
		$this->assertSame('G', TaxRules::code('export', 0.0, 'CH'), 'outside the EU');
		$this->assertSame('G', TaxRules::code('export', 0.0, null));
		$this->assertSame('E', TaxRules::code('export', 0.0, 'DE'), 'inside the EU without reverse charge');
		$this->assertSame('VATEX-EU-AE', TaxRules::exemptionCode('AE'));
		$this->assertSame('VATEX-EU-G', TaxRules::exemptionCode('G'));
		$this->assertNull(TaxRules::exemptionCode('S'));
		$this->assertNull(TaxRules::exemptionCode('E'));
		$this->assertNull(TaxRules::note('domestic', 'de'));
		$this->assertStringContainsString('Reverse Charge', (string)TaxRules::note('eu_reverse', 'de'));
		$this->assertStringContainsString('Reverse charge', (string)TaxRules::note('eu_reverse', 'en'));
		$this->assertSame('Export, nicht der Mehrwertsteuer unterstellt.', TaxRules::note('export', 'de'));
		$this->assertSame('Export, not subject to VAT.', TaxRules::note('export', 'en'));
		$this->assertTrue(TaxRules::inEu('fr'));
		$this->assertFalse(TaxRules::inEu('CH'));
		$this->assertFalse(TaxRules::inEu(null));
		$this->assertCount(27, TaxRules::EU);
	}

	public function testFrozenBlock(): void {
		$customer = (object)['id' => 'k1', 'name' => 'Kunde', 'country' => 'FR', 'vat_id' => 'FR1', 'leitweg_id' => '04011000-1234512345-06', 'datev_account' => '10001', 'e_invoice' => true];
		$block = IssueRules::customerBlock($customer, (object)[], 'DE');
		$this->assertSame('eu_reverse', $block->tax_case);
		$this->assertSame('04011000-1234512345-06', $block->leitweg_id);
		$this->assertSame('10001', $block->datev_account);
		$this->assertTrue($block->e_invoice);
		$this->assertSame('export', IssueRules::customerBlock($customer, (object)['tax_case' => 'export'], 'DE')->tax_case, 'the draft overrides');
		$this->assertFalse(property_exists(IssueRules::customerBlock((object)['id' => 'k', 'e_invoice' => false], (object)[], 'DE'), 'e_invoice'), 'false is not frozen');
		$this->assertSame('domestic', TaxRules::ofBlock([]), 'older invoices');
		$this->assertSame('export', TaxRules::ofBlock(['tax_case' => 'export']));
		$this->assertSame('domestic', TaxRules::ofBlock(['tax_case' => 'x']));
	}
}
