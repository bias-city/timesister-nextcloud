<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\RecordValidator;
use PHPUnit\Framework\TestCase;

/** The customer's e-invoice and DATEV fields (0.20.0) through the record validator. */
class CustomerRulesTest extends TestCase {
	private static function check(string $json): string {
		try {
			RecordValidator::validate('customer', 'k1', json_decode($json));
		} catch (ApiException $e) {
			return $e->getStatus() . ' ' . $e->getMessage();
		}
		return 'ok';
	}

	public function testFields(): void {
		$this->assertSame('ok', self::check('{"id":"k1","name":"Kunde"}'));
		$this->assertSame('ok', self::check('{"id":"k1","e_invoice":true,"leitweg_id":"04011000-1234512345-06","datev_account":"10001"}'));
		$this->assertSame('ok', self::check('{"id":"k1","e_invoice":null,"leitweg_id":null,"datev_account":null}'));
		$this->assertSame('422 “e_invoice” must be true or false.', self::check('{"id":"k1","e_invoice":"yes"}'));
		$this->assertSame('422 “leitweg_id” must be text of at most 46 characters.', self::check('{"id":"k1","leitweg_id":"' . str_repeat('1', 47) . '"}'));
		$this->assertSame('422 “leitweg_id” must be text of at most 46 characters.', self::check('{"id":"k1","leitweg_id":"a b"}'));
		$this->assertSame('422 “datev_account” must be up to 9 digits.', self::check('{"id":"k1","datev_account":10001}'));
		$this->assertSame('422 “datev_account” must be up to 9 digits.', self::check('{"id":"k1","datev_account":"1000x"}'));
	}
}
