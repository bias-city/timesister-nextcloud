<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\WebhookRules;
use PHPUnit\Framework\TestCase;

/** Webhooks (0.20.0): the setting, the events, the body and its signature, the delay. */
class WebhookRulesTest extends TestCase {
	public function testSetting(): void {
		$hooks = WebhookRules::validate([(object)['url' => 'https://hooks.example.org/ts', 'events' => ['issued', 'paid', 'issued'], 'secret' => 's3cret']], []);
		$this->assertSame([['url' => 'https://hooks.example.org/ts', 'events' => ['issued', 'paid'], 'secret' => 's3cret']], $hooks);
		$this->assertSame($hooks, WebhookRules::hooks(WebhookRules::encode($hooks)), 'round trip');
		$this->assertSame([['url' => 'https://hooks.example.org/ts', 'events' => ['issued', 'paid'], 'secret_set' => true]], WebhookRules::present($hooks), 'the secret is never shown');
		$kept = WebhookRules::validate([['url' => 'https://hooks.example.org/ts', 'events' => ['document']]], $hooks);
		$this->assertSame('s3cret', $kept[0]['secret'], 'a hook without secret keeps the stored one of the address');
		$this->assertSame([], WebhookRules::validate(null, $hooks));
		$this->assertNull(WebhookRules::encode([]));
		$this->assertSame([], WebhookRules::hooks('nonsense'));
		foreach ([
			'"x"' => '“webhooks” must be a list of at most 10 webhooks.',
			'[1]' => 'Webhook 1: must be an object with “url”, “events” and “secret”.',
			'[{"url":"http://x.example.org","events":["issued"]}]' => 'Webhook 1: “url” must be an https address of at most 512 characters.',
			'[{"url":"https://u:p@example.org","events":["issued"]}]' => 'Webhook 1: “url” must be an https address of at most 512 characters.',
			'[{"url":"https://x.example.org","events":["issued"]},{"url":"https://x.example.org","events":["paid"]}]' => 'Webhook 2: the address is listed twice.',
			'[{"url":"https://x.example.org","events":[]}]' => 'Webhook 1: “events” must be a list of issued, paid, reversed, document, exported.',
			'[{"url":"https://x.example.org","events":["deleted"]}]' => 'Webhook 1: “events” must be a list of issued, paid, reversed, document, exported.',
			'[{"url":"https://x.example.org","events":["paid"],"secret":"' . str_repeat('s', 129) . '"}]' => 'Webhook 1: “secret” must be text of at most 128 characters.',
		] as $json => $message) {
			try {
				WebhookRules::validate(json_decode($json), []);
				$this->fail($json);
			} catch (ApiException $e) {
				$this->assertSame($message, $e->getMessage(), $json);
			}
		}
		try {
			WebhookRules::validate(array_fill(0, 11, (object)['url' => 'https://x.example.org', 'events' => ['paid']]), []);
			$this->fail('eleven');
		} catch (ApiException $e) {
			$this->assertSame(422, $e->getStatus());
		}
	}

	public function testEventsBodySignatureDelay(): void {
		$this->assertSame('issued', WebhookRules::eventOf('issued'));
		$this->assertSame('issued', WebhookRules::eventOf('reversal'));
		$this->assertSame('issued', WebhookRules::eventOf('credit'));
		$this->assertSame('reversed', WebhookRules::eventOf('cancelled'));
		$this->assertSame('paid', WebhookRules::eventOf('paid'));
		$this->assertSame('paid', WebhookRules::eventOf('unpaid'));
		$this->assertSame('document', WebhookRules::eventOf('document'));
		$this->assertSame('exported', WebhookRules::eventOf('exported'));
		$this->assertNull(WebhookRules::eventOf('other'));
		$body = WebhookRules::body('pb', 'issued', 'issued', '2026-10-15T14:30:00Z', ['key' => 'inv-1', 'number' => 'RE-2026-0007', 'status' => 'issued', 'gross' => 1972.83, 'currency' => 'CHF'], 3, 'abc');
		$this->assertSame('{"team":"pb","event":"issued","at":"2026-10-15T14:30:00Z","invoice":{"key":"inv-1","number":"RE-2026-0007","status":"issued","gross":1972.83,"total":1972.83,"currency":"CHF"},"ledger":{"seq":3,"hash":"abc","action":"issued"}}', $body);
		$this->assertSame('sha256=' . hash_hmac('sha256', $body, 's3cret'), WebhookRules::signature('s3cret', $body));
		$this->assertSame('sha256=f7bc83f430538424b13298e6aa6fb143ef4d59a14946175997479dbc2d1a3cd8', WebhookRules::signature('key', 'The quick brown fox jumps over the lazy dog'), 'RFC 2104 example');
		$this->assertSame(60, WebhookRules::delay(1));
		$this->assertSame(120, WebhookRules::delay(2));
		$this->assertSame(960, WebhookRules::delay(5));
		$this->assertSame(86400, WebhookRules::delay(12), 'at most a day');
		$this->assertSame('pending', WebhookRules::state(null, 3));
		$this->assertSame('failed', WebhookRules::state(null, 10));
		$this->assertSame('delivered', WebhookRules::state(1, 10));
		$this->assertTrue(WebhookRules::delivered(204));
		$this->assertFalse(WebhookRules::delivered(302));
	}
}
