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

	/** 0.27.0 (controlling): the customer's type and payment term. */
	public function testTypeAndPaymentDays(): void {
		$this->assertSame('ok', self::check('{"id":"k1","name":"Kunde","type":"public","payment_days":45}'));
		$this->assertSame('ok', self::check('{"id":"k1","name":"Kunde","type":null,"payment_days":null}'));
		// 0.29.0: a key of the team's list – the server checks the form, not the list.
		$this->assertSame('ok', self::check('{"id":"k1","type":"wohnbau-genossenschaft"}'));
		$this->assertSame('422 “type” of a customer must be null or a key of 1 to 40 lower-case letters, digits or dashes.', self::check('{"id":"k1","type":"Club"}'));
		$this->assertSame('422 “type” of a customer must be null or a key of 1 to 40 lower-case letters, digits or dashes.', self::check('{"id":"k1","type":"-club"}'));
		$this->assertSame('422 “type” of a customer must be null or a key of 1 to 40 lower-case letters, digits or dashes.', self::check('{"id":"k1","type":5}'));
		$this->assertSame('422 “payment_days” must be null or an integer from 1 to 365.', self::check('{"id":"k1","payment_days":0}'));
		$this->assertSame('422 “payment_days” must be null or an integer from 1 to 365.', self::check('{"id":"k1","payment_days":"30"}'));
		$d = json_decode('{"id":"k1","payment_days":45}');
		$this->assertSame(45, \OCA\TimeSister\Service\CustomerRules::paymentDays($d));
		$this->assertNull(\OCA\TimeSister\Service\CustomerRules::paymentDays(json_decode('{"id":"k1"}')));
		$this->assertNull(\OCA\TimeSister\Service\CustomerRules::paymentDays(null));
	}

	/** The customer's free tags: words without “#”, space or comma. */
	public function testTags(): void {
		$this->assertSame('ok', self::check('{"id":"k1","tags":["arealentwicklung","betreibermodelle"]}'));
		$this->assertSame('ok', self::check('{"id":"k1","tags":null}'));
		$this->assertSame('ok', self::check('{"id":"k1","tags":[]}'));
		$list = '422 “tags” of a customer must be null or a list of at most 30 words.';
		$this->assertSame($list, self::check('{"id":"k1","tags":"arealentwicklung"}'));
		$this->assertSame($list, self::check('{"id":"k1","tags":{"a":"b"}}'));
		$this->assertSame($list, self::check('{"id":"k1","tags":[' . implode(',', array_map(static fn ($i) => '"t' . $i . '"', range(1, 31))) . ']}'));
		$word = '422 Each tag of a customer is one word of 1 to 40 characters without “#”, space or comma.';
		foreach (['"zwei worte"', '"#areal"', '"a,b"', '""', '5', '"' . str_repeat('x', 41) . '"', '"a\u00a0b"', '"a\u0007"'] as $bad) {
			$this->assertSame($word, self::check('{"id":"k1","tags":[' . $bad . ']}'), $bad);
		}
	}

	/** 0.29.0: the team's own customer types in `settings.customer_types`. */
	public function testTeamTypes(): void {
		$check = static function (string $json): string {
			try {
				RecordValidator::validate('setting', 'settings', json_decode($json));
			} catch (ApiException $e) {
				return $e->getStatus() . ' ' . $e->getMessage();
			}
			return 'ok';
		};
		$this->assertSame('ok', $check('{"customer_types":null}'));
		$this->assertSame('ok', $check('{"customer_types":[{"id":"public","name":"Öffentliche Hand"},{"id":"wohnbau","name":"Wohnbau"}]}'));
		$this->assertSame('422 “customer_types” must be null or a list of at most 50 customer types { id, name }.', $check('{"customer_types":{"id":"public"}}'));
		$this->assertSame('422 “customer_types” must be null or a list of at most 50 customer types { id, name }.',
			$check('{"customer_types":[' . implode(',', array_map(static fn ($i) => '{"id":"t' . $i . '","name":"T"}', range(1, 51))) . ']}'));
		$this->assertSame('422 Each customer type needs an “id” of 1 to 40 lower-case letters, digits or dashes.', $check('{"customer_types":[{"id":"Gross","name":"A"}]}'));
		$this->assertSame('422 Each customer type needs a “name” of 1 to 60 characters.', $check('{"customer_types":[{"id":"a","name":" "}]}'));
		$this->assertSame('422 Each customer type needs a “name” of 1 to 60 characters.', $check('{"customer_types":[{"id":"a","name":"' . str_repeat('x', 61) . '"}]}'));
		$this->assertSame('422 “customer_types” names the customer type a twice.', $check('{"customer_types":[{"id":"a","name":"A"},{"id":"a","name":"B"}]}'));
	}
}
