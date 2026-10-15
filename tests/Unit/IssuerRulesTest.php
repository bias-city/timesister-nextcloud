<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\Json;
use OCA\TimeSister\Service\IssuerRules;
use PHPUnit\Framework\TestCase;

/** The invoice issuer in `setting/settings` (`issuer`). */
class IssuerRulesTest extends TestCase {
	private static function check(string $json): string {
		try {
			IssuerRules::validate(Json::decode($json));
		} catch (ApiException $e) {
			return $e->getStatus() . ' ' . $e->getMessage();
		}
		return 'ok';
	}

	public function testShape(): void {
		$this->assertSame('ok', self::check('null'));
		$this->assertSame('ok', self::check('{"name":"Denkstatt sàrl","street":"Dornacherstrasse 192","postcode":"4053","city":"Basel","country":"CH","email":"issuer@example.org","phone":"+41 61","vat_id":"CHE-123.456.789 MWST","foreign_note":"Nicht der MWST unterstellt.\nReverse Charge.","qr":true,'
			. '"accounts":[{"currency":"CHF","iban":"CH9300762011623852957","bic":""},{"currency":"EUR","iban":"DE89370400440532013000","bic":"COBADEFFXXX"}]}'));
		$this->assertSame('422 “issuer” must be an object.', self::check('"x"'));
		$this->assertSame('422 “issuer.qr” must be true or false.', self::check('{"qr":"ja"}'));
		$this->assertSame('422 “issuer.country” must be text of at most 2 characters.', self::check('{"country":"Schweiz"}'));
		$this->assertSame('422 “issuer.foreign_note” must be text of at most 500 characters.', self::check('{"foreign_note":"' . str_repeat('x', 501) . '"}'));
		$this->assertSame('422 One account per currency.', self::check('{"accounts":[{"currency":"CHF","iban":"CH9300762011623852957"},{"currency":"chf","iban":"CH9300762011623852957"}]}'));
		foreach (['{"currency":"CH","iban":"CH9300762011623852957"}', '{"currency":"CHF","iban":"CH93 0076"}', '{"currency":"CHF","iban":"CH9300762011623852957","bic":"ABC"}', '7'] as $bad) {
			$this->assertSame('422 Each account needs a currency (three letters), an IBAN without spaces and, if any, a BIC of 8 or 11 characters.', self::check('{"accounts":[' . $bad . ']}'), $bad);
		}
	}
}
