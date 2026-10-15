<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\QrReferenceRules;
use PHPUnit\Framework\TestCase;

/** The QR reference (0.20.0): the check digit against the SIX example, the composition, the QR-IBAN test. */
class QrReferenceRulesTest extends TestCase {
	public function testCheckDigit(): void {
		// The example of the Swiss Payment Standards: 21 00000 00003 13947 14300 09017.
		$this->assertSame(7, QrReferenceRules::checkDigit('21000000000313947143000901'));
		$this->assertTrue(QrReferenceRules::isValid('210000000003139471430009017'));
		$this->assertTrue(QrReferenceRules::isValid('21 00000 00003 13947 14300 09017'));
		$this->assertFalse(QrReferenceRules::isValid('210000000003139471430009018'));
		$this->assertFalse(QrReferenceRules::isValid('21000000000313947143000901'));
		$this->assertSame(0, QrReferenceRules::checkDigit('00000000000000000000000000'));
	}

	public function testReference(): void {
		$r = QrReferenceRules::reference('123456', 7, '2026-10-15');
		$this->assertNotNull($r);
		$this->assertSame(27, strlen($r));
		$this->assertStringStartsWith('123456', $r);
		$this->assertSame('2026', substr($r, 22, 4), 'the year before the check digit');
		$this->assertSame('0000000000000007', substr($r, 6, 16), 'the counter zero-padded between');
		$this->assertTrue(QrReferenceRules::isValid($r));
		$plain = QrReferenceRules::reference(null, 7, '2026-10-15');
		$this->assertSame('00000000000000000000072026', substr((string)$plain, 0, 26), 'without BESR id: counter and year alone');
		$this->assertTrue(QrReferenceRules::isValid((string)$plain));
		$this->assertNull(QrReferenceRules::reference(null, null, '2026-10-15'));
		$this->assertNull(QrReferenceRules::reference('123456', 7, 'x'));
		$this->assertSame('12 34560 00000 00000 00007 2026' . substr($r, 26), QrReferenceRules::display($r), 'blocks of five from the right, as printed');
	}

	public function testQrIban(): void {
		$this->assertTrue(QrReferenceRules::isQrIban('CH4431999123000889012'), 'IID 31999');
		$this->assertTrue(QrReferenceRules::isQrIban('CH44 3199 9123 0008 8901 2'));
		$this->assertFalse(QrReferenceRules::isQrIban('CH9300762011623852957'), 'IID 00762');
		$this->assertFalse(QrReferenceRules::isQrIban('DE89370400440532013000'));
		$this->assertFalse(QrReferenceRules::isQrIban(''));
	}

	public function testBesrId(): void {
		$this->assertNull(QrReferenceRules::checkBesrId(null));
		$this->assertNull(QrReferenceRules::checkBesrId(''));
		$this->assertSame('123456', QrReferenceRules::checkBesrId('123456'));
		foreach (['12345', '1234567', 'abcdef', 123456] as $bad) {
			try {
				QrReferenceRules::checkBesrId($bad);
				$this->fail('accepted ' . var_export($bad, true));
			} catch (ApiException $e) {
				$this->assertSame('“issuer.besr_id” must be 6 digits.', $e->getMessage());
			}
		}
	}
}
