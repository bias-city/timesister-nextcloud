<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Capabilities;
use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\IssueRules;
use OCA\TimeSister\Service\LedgerRules;
use OCA\TimeSister\Service\WebhookRules;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * The two-step issue of 0.32.0, the pure part: the footer sentence the app
 * prints and the close searches for, the `issue_pending` answer, the
 * document block in the payload, and the webhook events of a close.
 */
class IssueRulesTest extends TestCase {
	public function testChainLine(): void {
		$this->assertSame('Beleg RE-2026-0017 · Kette 42 ← 3f9c1a2b7d4e', IssueRules::chainLine('de', 'RE-2026-0017', 42, '3f9c1a2b7d4e5f60718293a4b5c6d7e8f9a0b1c2d3e4f5a6b7c8d9e0f1a2b3c4'));
		$this->assertSame('Document RE-2026-0017 · Chain 42 ← 3f9c1a2b7d4e', IssueRules::chainLine('en', 'RE-2026-0017', 42, '3f9c1a2b7d4e5f60'));
		$this->assertSame('Beleg RE-1 · Kette 1 ← –', IssueRules::chainLine('de', 'RE-1', 1, ''), 'the first entry of a team');
		$this->assertSame(900, IssueRules::EXPIRY);
	}

	public function testPendingAndDocumentBlock(): void {
		$e = IssueRules::pending('inv-7', 'RE-2026-0017', 1793000000);
		$this->assertSame(409, $e->getStatus());
		$this->assertSame('issue_pending', $e->getRuleCode());
		$this->assertSame(['key' => 'inv-7', 'number' => 'RE-2026-0017', 'expires_at' => LedgerRules::at(1793000000)], $e->toData()['pending']);
		$this->assertSame(['kind' => 'pdf', 'name' => 'a.pdf', 'size' => 3, 'sha256' => 'ab'], IssueRules::documentBlock('pdf', 'a.pdf', 3, 'ab'));
		// The payload of a close: the frozen invoice of 0.18.0 plus the document; one hash covers content and PDF.
		$payload = IssueRules::payload('inv-7', 'RE-1', '2026-10-29', 'pbadmin', '2026-11-28', 'p', 'c', '2026-10-01', '2026-10-31', 'CHF',
			\OCA\TimeSister\Service\VatLines::of([], 8.1, 'domestic'), new \stdClass(), new \stdClass(), [], [], null, null, null, null, null) + ['document' => IssueRules::documentBlock('pdf', 'a.pdf', 3, 'ab')];
		$canonical = LedgerRules::canonical($payload);
		// 0.38.0: the budget is an assignment, not part of what is fixed.
		$this->assertArrayNotHasKey('budget', $payload);
		$this->assertStringContainsString('"customer_key":"c"', $canonical);
		$this->assertStringContainsString('"document":{"kind":"pdf","name":"a.pdf","sha256":"ab","size":3}', $canonical);
		$this->assertNotSame(LedgerRules::payloadHash($canonical), LedgerRules::payloadHash(LedgerRules::canonical(['document' => ['sha256' => 'ac']] + $payload)), 'a changed byte of the PDF changes the entry');
	}

	/** A close fires `issued` and `document` for its one entry; older entries as before. */
	public function testWebhookEventsOfAClose(): void {
		$this->assertSame(['issued', 'document'], WebhookRules::eventsOf('issued', '{"key":"inv-7","document":{"kind":"pdf","sha256":"ab"}}'));
		$this->assertSame(['issued', 'document'], WebhookRules::eventsOf('reversal', '{"document":{"kind":"pdf"}}'));
		$this->assertSame(['issued'], WebhookRules::eventsOf('issued', '{"key":"inv-7"}'), 'an entry of ≤ 0.31.0');
		$this->assertSame(['document'], WebhookRules::eventsOf('document', '{"kind":"xrechnung"}'));
		$this->assertSame([], WebhookRules::eventsOf('dunning', null));
	}

	public function testCapability(): void {
		$cap = (new Capabilities($this->createMock(IAppManager::class)))->getCapabilities()['timesister'];
		$this->assertSame([2, 1, 4, 1, 2], [$cap['invoice_documents'], $cap['e_invoice'], $cap['quotes'], $cap['quote_documents'], $cap['invoice_layout']], '0.32.0');
		$g = ApiException::gone();
		$this->assertSame(410, $g->getStatus());
	}

	/** 0.46.0: a position taken out of the draft (`excluded`) is not frozen. */
	public function testExcludedPositionsAreNotFrozen(): void {
		$d = (object)['positions' => [(object)['kind' => 'hours', 'text' => 'AP1', 'amount' => 100], (object)['kind' => 'flat', 'text' => 'Weg', 'amount' => 50, 'excluded' => true], 'x', (object)['kind' => 'text', 'text' => 'Hinweis', 'amount' => 0, 'excluded' => false]]];
		$this->assertSame(['AP1', 'Hinweis'], array_map(static fn (\stdClass $p): string => $p->text, IssueRules::positionsOf($d)));
	}

	/** 0.47.0: the draft's reference lines frozen as kind/title/value; in the ledger payload only when there are any. */
	public function testReferencesFrozen(): void {
		$d = (object)['references' => [(object)['kind' => 'cost_center', 'title' => 'Cost center', 'value' => '4711', 'extra' => 'x'], (object)['kind' => 'order', 'title' => 'Order', 'value' => 'B-7']]];
		$json = IssueRules::referencesJsonOf($d);
		$this->assertSame('[{"kind":"cost_center","title":"Cost center","value":"4711"},{"kind":"order","title":"Order","value":"B-7"}]', $json);
		$this->assertNull(IssueRules::referencesJsonOf((object)['references' => []]));
		$this->assertNull(IssueRules::referencesJsonOf(new \stdClass()));
		$lines = IssueRules::referencesOf($json);
		$this->assertSame([['kind' => 'cost_center', 'title' => 'Cost center', 'value' => '4711'], ['kind' => 'order', 'title' => 'Order', 'value' => 'B-7']], $lines);
		$this->assertNull(IssueRules::referencesOf(null));
		$this->assertNull(IssueRules::referencesOf('not json'));
		$payload = static fn (?array $refs): array => IssueRules::payload('inv-7', 'RE-1', '2026-11-10', 'pbadmin', '2026-12-10', 'p', 'c', '2026-10-01', '2026-10-31', 'CHF',
			\OCA\TimeSister\Service\VatLines::of([], 8.1, 'domestic'), new \stdClass(), new \stdClass(), [], [], null, null, null, null, null, null, null, null, 'KST 4711', $refs);
		$with = $payload($lines);
		$this->assertSame($lines, $with['references']);
		$this->assertStringContainsString('"references":[{"kind":"cost_center","title":"Cost center","value":"4711"},{"kind":"order","title":"Order","value":"B-7"}]', LedgerRules::canonical($with));
		$this->assertArrayNotHasKey('references', $payload(null), 'older entries keep their exact payload');
		$this->assertArrayNotHasKey('references', $payload([]));
		$this->assertSame(LedgerRules::canonical($payload(null)), LedgerRules::canonical($payload([])));
	}
}
