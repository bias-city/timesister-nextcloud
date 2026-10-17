<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Capabilities;
use OCA\TimeSister\Db\Tenant;
use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\ExportRules;
use OCA\TimeSister\Service\HandoverRules;
use OCA\TimeSister\Service\InvoicingModeRules;
use OCA\TimeSister\Service\Json;
use OCA\TimeSister\Service\LedgerRules;
use OCA\TimeSister\Service\RecordValidator;
use OCA\TimeSister\Service\TeamRules;
use OCA\TimeSister\Service\TenantService;
use OCA\TimeSister\Service\WebhookRules;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * 0.24.0: the team setting `invoicing_mode`, the hand-over body, the
 * refusals (issue, client writes, reverse), the record's `external` block
 * with `number`, the ledger payloads and the webhook events.
 */
class HandoverRulesTest extends TestCase {
	private const NOW = 1792224000; // 2026-10-17T00:00:00Z

	/** "ok" or "<status> <message>". */
	private static function outcome(callable $fn): string {
		try {
			$fn();
			return 'ok';
		} catch (ApiException $e) {
			return $e->getStatus() . ' ' . $e->getMessage();
		}
	}

	public function testModeValid(): void {
		$this->assertNull(InvoicingModeRules::validate(null), 'null: TimeSister without a copy');
		$this->assertSame(['mode' => 'timesister', 'system' => null, 'copy' => 'off'], InvoicingModeRules::validate(json_decode('{"mode":"timesister"}')), 'missing keys: null and off');
		$this->assertSame(['mode' => 'timesister', 'system' => 'bexio', 'copy' => 'auto'], InvoicingModeRules::validate(json_decode('{"mode":"timesister","system":"bexio","copy":"auto"}')), 'case 2');
		$this->assertSame(['mode' => 'timesister', 'system' => 'sevdesk', 'copy' => 'manual'], InvoicingModeRules::validate(['mode' => 'timesister', 'system' => 'sevdesk', 'copy' => 'manual']), 'an array as from /admin/teams');
		$this->assertSame(['mode' => 'timesister', 'system' => 'lexware', 'copy' => 'off'], InvoicingModeRules::validate(['mode' => 'timesister', 'system' => 'lexware', 'copy' => null]), 'a system without copies');
		$this->assertSame(['mode' => 'external', 'system' => 'ch.example.csv', 'copy' => 'off', 'archive_pdf' => true], InvoicingModeRules::validate(json_decode('{"mode":"external","system":"ch.example.csv"}')), 'case 3');
		$this->assertSame(['mode' => 'external', 'system' => 'bexio', 'copy' => 'off', 'archive_pdf' => true], InvoicingModeRules::validate(json_decode('{"mode":"external","system":"bexio","copy":"off"}')));
		// 0.24.1: archive_pdf in the mode external (default true), dropped in the mode timesister.
		$this->assertSame(['mode' => 'external', 'system' => 'bexio', 'copy' => 'off', 'archive_pdf' => false], InvoicingModeRules::validate(json_decode('{"mode":"external","system":"bexio","archive_pdf":false}')));
		$this->assertSame(['mode' => 'external', 'system' => 'bexio', 'copy' => 'off', 'archive_pdf' => true], InvoicingModeRules::validate(json_decode('{"mode":"external","system":"bexio","archive_pdf":null}')));
		$this->assertSame(['mode' => 'timesister', 'system' => null, 'copy' => 'off'], InvoicingModeRules::validate(json_decode('{"mode":"timesister","archive_pdf":true}')));
		$this->assertTrue(InvoicingModeRules::archivesPdf(InvoicingModeRules::validate(json_decode('{"mode":"external","system":"bexio"}'))));
		$this->assertFalse(InvoicingModeRules::archivesPdf(InvoicingModeRules::validate(json_decode('{"mode":"external","system":"bexio","archive_pdf":false}'))));
		$this->assertFalse(InvoicingModeRules::archivesPdf(null));
		$this->assertFalse(InvoicingModeRules::archivesPdf(['mode' => 'timesister', 'system' => 'bexio', 'copy' => 'auto']));
	}

	public function testModeInvalid(): void {
		$system = '“invoicing_mode.system” must be a name of 1 to 64 characters: lowercase letters, digits, “.”, “_” or “-”, starting with a letter or digit (for example “bexio”).';
		foreach ([
			'"external"' => '“invoicing_mode” must be null or an object with “mode”.',
			'[]' => '“invoicing_mode” must be null or an object with “mode”.',
			'{}' => '“invoicing_mode” must be null or an object with “mode”.',
			'{"system":"bexio"}' => '“invoicing_mode.mode” must be “timesister” or “external”.',
			'{"mode":"mac"}' => '“invoicing_mode.mode” must be “timesister” or “external”.',
			'{"mode":"timesister","note":1}' => 'Unknown team setting “invoicing_mode.note”.',
			'{"mode":"timesister","system":"Bexio"}' => $system,
			'{"mode":"timesister","system":7}' => $system,
			'{"mode":"timesister","system":"bexio","copy":"always"}' => '“invoicing_mode.copy” must be “off”, “auto” or “manual”.',
			'{"mode":"timesister","copy":"auto"}' => '“invoicing_mode.copy” needs “invoicing_mode.system”: name the accounting system that gets the copies.',
			'{"mode":"timesister","system":null,"copy":"manual"}' => '“invoicing_mode.copy” needs “invoicing_mode.system”: name the accounting system that gets the copies.',
			'{"mode":"external"}' => '“invoicing_mode.system” is required for the mode “external”: name the accounting system that issues the invoices.',
			'{"mode":"external","system":null}' => '“invoicing_mode.system” is required for the mode “external”: name the accounting system that issues the invoices.',
			'{"mode":"external","system":"bexio","copy":"auto"}' => '“invoicing_mode.copy” must be “off” for the mode “external”: the accounting system issues the invoices itself.',
			'{"mode":"external","system":"bexio","archive_pdf":"yes"}' => '“invoicing_mode.archive_pdf” must be true, false or null.',
		] as $json => $message) {
			$this->assertSame('422 ' . $message, self::outcome(static fn () => InvoicingModeRules::validate(json_decode($json))), $json);
		}
	}

	public function testModeStoredAndShown(): void {
		$m = InvoicingModeRules::validate(json_decode('{"mode":"external","system":"bexio"}'));
		$json = InvoicingModeRules::encode($m);
		$this->assertSame('{"mode":"external","system":"bexio","copy":"off","archive_pdf":true}', $json);
		$this->assertSame($m, InvoicingModeRules::decode($json), 'round trip');
		$this->assertNull(InvoicingModeRules::encode(null));
		$this->assertNull(InvoicingModeRules::decode(null));
		$this->assertNull(InvoicingModeRules::decode('{"mode":"x"}'), 'unreadable counts as the default');
		$this->assertSame('bexio', InvoicingModeRules::externalSystem($m));
		$this->assertNull(InvoicingModeRules::externalSystem(['mode' => 'timesister', 'system' => 'bexio', 'copy' => 'auto']));
		$this->assertNull(InvoicingModeRules::externalSystem(null));
		// The team's settings: through TeamRules and shown in /me, /team, /admin/teams.
		$this->assertSame(['invoicing_mode' => null], TeamRules::settings(['settings' => ['invoicing_mode' => null]]), 'null clears it');
		$this->assertSame(['invoicing_mode' => $m], TeamRules::settings(['settings' => ['invoicing_mode' => ['mode' => 'external', 'system' => 'bexio']]]));
		$this->assertSame('422 “invoicing_mode.copy” must be “off”, “auto” or “manual”.', self::outcome(static fn () => TeamRules::settings(['settings' => ['invoicing_mode' => ['mode' => 'timesister', 'copy' => 1]]])));
		$t = new Tenant();
		$this->assertNull(TenantService::settingsOf($t)['invoicing_mode'], 'default null');
		$t->setInvoicingModeJson($json);
		$this->assertSame($m, TenantService::settingsOf($t)['invoicing_mode']);
		$this->assertSame($m, $t->invoicingMode());
	}

	public function testExternalBlockNumber(): void {
		$b = ExportRules::externalBlock(json_decode('{"system":"bexio","id":4711,"number":"RE-2026-17","url":"https://office.bexio.com/r/4711","at":"2026-10-17T09:00:00Z","by":"pbadmin"}'));
		$this->assertSame('{"system":"bexio","id":"4711","number":"RE-2026-17","url":"https://office.bexio.com/r/4711","at":"2026-10-17T09:00:00Z","by":"pbadmin"}', json_encode($b, Json::FLAGS));
		$this->assertSame('17', ExportRules::externalBlock(json_decode('{"system":"bexio","id":"1","number":17}'))?->number, 'an integer as text');
		$this->assertSame('R 1', ExportRules::externalBlock(json_decode('{"system":"bexio","id":"1","number":" R 1 "}'))?->number, 'trimmed');
		$this->assertNull(ExportRules::externalBlock(json_decode('{"system":"bexio","id":"1","number":null}'))?->number);
		$this->assertFalse(property_exists(ExportRules::externalBlock(json_decode('{"system":"bexio","id":"1"}')), 'number'), 'absent stays absent (0.23.1 form)');
		foreach (['""', '"   "', '"' . str_repeat('9', 65) . '"', '"a\u0007"', '1.5', '[]'] as $n) {
			$this->assertSame('422 “external.number” must be text of 1 to 64 characters.',
				self::outcome(static fn () => ExportRules::externalBlock(json_decode('{"system":"bexio","id":"1","number":' . $n . '}'))), $n);
		}
		// The record takes status external with the block.
		$r = RecordValidator::validate('invoice', 'inv-1a2b3c4d', (object)['schema' => 1, 'project' => 'd200', 'budget' => 'b1', 'from' => '2026-09-01', 'to' => '2026-09-30',
			'status' => 'external', 'number' => null, 'bookings' => [], 'issued_on' => '2026-10-17', 'external' => (object)['system' => 'bexio', 'id' => '4711', 'number' => 'RE-17']]);
		$this->assertStringContainsString('"status":"external"', $r['json']);
		$this->assertStringContainsString('"external":{"system":"bexio","id":"4711","number":"RE-17","url":null,"at":null,"by":null}', $r['json']);
	}

	public function testBody(): void {
		$v = HandoverRules::body(json_decode('{"system":"bexio","id":4711,"number":"RE-17","url":"https://office.bexio.com/r/4711","issued_on":"2026-10-16","extra":1}'), self::NOW);
		$this->assertSame(['system' => 'bexio', 'id' => '4711', 'url' => 'https://office.bexio.com/r/4711', 'number' => 'RE-17', 'issued_on' => '2026-10-16'], $v);
		$this->assertSame('2026-10-17', HandoverRules::body(json_decode('{"system":"bexio","id":"1","number":"1"}'), self::NOW)['issued_on'], 'today by default');
		$this->assertNull(HandoverRules::body(json_decode('{"system":"bexio","id":"1","number":"1","url":""}'), self::NOW)['url']);
		foreach ([
			'{"id":"1","number":"1"}' => '“system” must be a name of 1 to 64 characters: lowercase letters, digits, “.”, “_” or “-”, starting with a letter or digit (for example “bexio”).',
			'{"system":"bexio","number":"1"}' => '“id” must be text of at most 128 characters.',
			'{"system":"bexio","id":"' . str_repeat('1', 129) . '","number":"1"}' => '“id” must be text of at most 128 characters.',
			'{"system":"bexio","id":"1"}' => '“number” must be text of 1 to 64 characters.',
			'{"system":"bexio","id":"1","number":"a\nb"}' => '“number” must be text of 1 to 64 characters.',
			'{"system":"bexio","id":"1","number":"' . str_repeat('n', 65) . '"}' => '“number” must be text of 1 to 64 characters.',
			'{"system":"bexio","id":"1","number":"1","url":"http://x.example"}' => '“url” must be an https address of at most 512 characters.',
			'{"system":"bexio","id":"1","number":"1","issued_on":"17.10.2026"}' => '“issued_on” must be a day in the format YYYY-MM-DD.',
		] as $json => $message) {
			$this->assertSame('422 ' . $message, self::outcome(static fn () => HandoverRules::body(json_decode($json), self::NOW)), $json);
		}
		$this->assertNull(HandoverRules::reason(new \stdClass()));
		$this->assertSame('Wrong customer', HandoverRules::reason((object)['reason' => 'Wrong customer']));
		$this->assertSame('422 “reason” must be text of at most 500 characters.', self::outcome(static fn () => HandoverRules::reason((object)['reason' => str_repeat('r', 501)])));
	}

	public function testModeRefusals(): void {
		$external = ['mode' => 'external', 'system' => 'bexio', 'copy' => 'off'];
		$copy = ['mode' => 'timesister', 'system' => 'bexio', 'copy' => 'auto'];
		$this->assertSame('bexio', HandoverRules::requireMode($external));
		foreach ([null, $copy] as $mode) {
			$this->assertSame('409 Handing over needs the team setting invoicing_mode external.', self::outcome(static fn () => HandoverRules::requireMode($mode)));
		}
		$this->assertSame('ok', self::outcome(static fn () => HandoverRules::requireSystem('bexio', 'bexio')));
		$this->assertSame('409 This team hands its invoices over to bexio, not to sevdesk.', self::outcome(static fn () => HandoverRules::requireSystem('bexio', 'sevdesk')));
		$this->assertSame('409 This team hands its invoices over to bexio; TimeSister does not issue them.', self::outcome(static fn () => HandoverRules::refuseIssue($external)));
		$this->assertSame('ok', self::outcome(static fn () => HandoverRules::refuseIssue($copy)), 'case 2 issues');
		$this->assertSame('ok', self::outcome(static fn () => HandoverRules::refuseIssue(null)), 'case 1 issues');
	}

	public function testClientWrites(): void {
		$handed = (object)['status' => 'external', 'external' => (object)['system' => 'bexio', 'id' => '1', 'number' => 'RE-17']];
		$draft = (object)['status' => 'draft'];
		$frozen = '409 This invoice was handed over to bexio; revoke the hand-over first.';
		$this->assertSame($frozen, self::outcome(static fn () => HandoverRules::checkClientWrite($handed, $draft)), 'a write');
		$this->assertSame($frozen, self::outcome(static fn () => HandoverRules::checkClientWrite($handed, null)), 'a delete');
		$this->assertSame($frozen, self::outcome(static fn () => HandoverRules::checkClientWrite($handed, clone $handed)), 'unchanged is still frozen');
		$this->assertSame('422 Only the server sets the status “external”, when an invoice is handed over.', self::outcome(static fn () => HandoverRules::checkClientWrite($draft, $handed)));
		$this->assertSame('422 Only the server sets the status “external”, when an invoice is handed over.', self::outcome(static fn () => HandoverRules::checkClientWrite(null, $handed)), 'new');
		$this->assertSame('ok', self::outcome(static fn () => HandoverRules::checkClientWrite($draft, (object)['status' => 'issued'])));
		$this->assertSame('ok', self::outcome(static fn () => HandoverRules::checkClientWrite(null, null)));
		$this->assertSame($frozen, self::outcome(static fn () => HandoverRules::refuseHandedOver($handed)), 'reverse');
		$this->assertSame('?', HandoverRules::handedOverTo((object)['status' => 'external']));
		$this->assertNull(HandoverRules::handedOverTo((object)['status' => 'issued', 'external' => (object)['system' => 'bexio']]), '0.23.1: a copy is not a hand-over');
	}

	public function testLedgerPayloads(): void {
		$v = ['system' => 'bexio', 'id' => '4711', 'url' => null, 'number' => 'RE-17', 'issued_on' => '2026-10-16'];
		$bookings = [(object)['person' => 'pbuser1', 'uid' => 'ev-1@example.test', 'start' => '2026-10-12T08:00:00', 'hours' => 1, 'rate' => 150]];
		$p = HandoverRules::payload('inv-1a2b3c4d', $v, 'd200', 'b1', 'k1', 'CHF', $bookings, ['rc-1']);
		$this->assertSame('{"bookings":[{"event_uid":"ev-1@example.test","start":"2026-10-12T08:00:00","uid":"pbuser1"}],"budget":"b1","currency":"CHF","customer_key":"k1","id":"4711","issued_on":"2026-10-16","key":"inv-1a2b3c4d","number":"RE-17","project":"d200","receipts":["rc-1"],"system":"bexio","url":null}',
			LedgerRules::canonical($p));
		$this->assertSame('{"id":"4711","key":"inv-1a2b3c4d","number":"RE-17","reason":"Wrong customer","system":"bexio"}',
			LedgerRules::canonical(HandoverRules::revokePayload('inv-1a2b3c4d', HandoverRules::externalBlock($v, '2026-10-17T09:00:00Z', 'pbadmin'), 'Wrong customer')));
		$this->assertEquals((object)['system' => 'bexio', 'id' => '4711', 'number' => 'RE-17', 'url' => null, 'at' => '2026-10-17T09:00:00Z', 'by' => 'pbadmin'], HandoverRules::externalBlock($v, '2026-10-17T09:00:00Z', 'pbadmin'));
		$this->assertSame('exported', WebhookRules::eventOf(LedgerRules::EXTERNAL_ISSUED));
		$this->assertSame('exported', WebhookRules::eventOf(LedgerRules::EXTERNAL_REVOKED));
		$this->assertSame('external', HandoverRules::statusAfter(LedgerRules::EXTERNAL_ISSUED));
		$this->assertSame('draft', HandoverRules::statusAfter(LedgerRules::EXTERNAL_REVOKED));
		$this->assertSame('', HandoverRules::statusAfter(LedgerRules::ISSUED));
		$this->assertSame('external', HandoverRules::statusAfter(LedgerRules::EXTERNAL_DOCUMENT));
		$this->assertSame('external', HandoverRules::statusAfter(LedgerRules::EXTERNAL_CANCELLED));
		$this->assertSame('document', WebhookRules::eventOf(LedgerRules::EXTERNAL_DOCUMENT));
		$this->assertSame('reversed', WebhookRules::eventOf(LedgerRules::EXTERNAL_CANCELLED));
	}

	/** 0.24.1: the accounting system's PDF of a handed-over invoice. */
	public function testExternalDocument(): void {
		$this->assertSame('ok', self::outcome(static fn () => HandoverRules::requireDocument('%PDF-1.7 …')));
		$this->assertSame('422 The document must be a PDF.', self::outcome(static fn () => HandoverRules::requireDocument("\x89PNG\r\n")));
		$this->assertSame('422 The document must be a PDF.', self::outcome(static fn () => HandoverRules::requireDocument('')));
		$this->assertSame('413 The PDF may be at most 20 MB.', self::outcome(static fn () => HandoverRules::requireDocument('%PDF-' . str_repeat('x', HandoverRules::DOCUMENT_MAX))));
		$off = '409 This team does not store the accounting system’s PDFs (invoicing_mode.archive_pdf).';
		$this->assertSame('ok', self::outcome(static fn () => HandoverRules::requireArchive(['mode' => 'external', 'system' => 'bexio', 'copy' => 'off', 'archive_pdf' => true])));
		$this->assertSame($off, self::outcome(static fn () => HandoverRules::requireArchive(['mode' => 'external', 'system' => 'bexio', 'copy' => 'off', 'archive_pdf' => false])));
		$this->assertSame($off, self::outcome(static fn () => HandoverRules::requireArchive(null)));
		$this->assertSame('2026-10-16_bexio-RE-17_Mueller-AG.pdf', HandoverRules::documentName('2026-10-16', 'bexio', 'RE-17', 'Müller AG'));
		$this->assertSame('2026-10-16_lexware-RE-2026-0001.pdf', HandoverRules::documentName('2026-10-16', 'lexware', 'RE 2026/0001', ''));
		$sha = str_repeat('a', 64);
		$ext = (object)['system' => 'bexio', 'id' => '4711', 'number' => 'RE-17'];
		$this->assertNull(HandoverRules::documentAgain($ext, $sha), 'none yet');
		$ext->document = (object)['path' => 'TimeSister/T/Buchhaltung/2026/Rechnungen/x.pdf', 'sha256' => $sha, 'size' => 9];
		$this->assertSame($ext->document, HandoverRules::documentAgain($ext, $sha), 'the same file: no change');
		$this->assertSame('409 The PDF of this invoice is already stored; it stays as it is.', self::outcome(static fn () => HandoverRules::documentAgain($ext, str_repeat('b', 64))));
		$this->assertSame('{"id":"4711","key":"inv-1a2b3c4d","number":"RE-17","path":"p/x.pdf","sha256":"' . $sha . '","size":9,"system":"bexio"}',
			LedgerRules::canonical(HandoverRules::documentPayload('inv-1a2b3c4d', $ext, ['path' => 'p/x.pdf', 'size' => 9, 'file_id' => 3], $sha)));
		$this->assertSame('{"document":"TimeSister/T/Buchhaltung/2026/Rechnungen/x.pdf","id":"4711","key":"inv-1a2b3c4d","number":"RE-17","system":"bexio"}',
			LedgerRules::canonical(HandoverRules::cancelPayload('inv-1a2b3c4d', $ext)));
		// The record keeps `document` and `cancelled_at` in `external`.
		$b = ExportRules::externalBlock(json_decode('{"system":"bexio","id":"1","number":"RE-17","document":{"size":9,"path":"a/b.pdf","sha256":"' . $sha . '"},"cancelled_at":"2026-10-17T10:00:00Z"}'));
		$this->assertSame('{"system":"bexio","id":"1","number":"RE-17","url":null,"at":null,"by":null,"document":{"path":"a/b.pdf","sha256":"' . $sha . '","size":9},"cancelled_at":"2026-10-17T10:00:00Z"}', json_encode($b, Json::FLAGS));
		foreach (['{"path":"a.pdf"}', '{"path":"","sha256":"' . $sha . '","size":1}', '{"path":"a","sha256":"x","size":1}', '{"path":"a","sha256":"' . $sha . '","size":-1}', '"a.pdf"', '{"path":"a","sha256":"' . $sha . '","size":1,"x":1}'] as $bad) {
			$this->assertSame('422 “external.document” must be null or an object with “path”, “sha256” and “size”.',
				self::outcome(static fn () => ExportRules::externalBlock(json_decode('{"system":"bexio","id":"1","document":' . $bad . '}'))), $bad);
		}
	}

	public function testCapability(): void {
		$apps = $this->createStub(IAppManager::class);
		$apps->method('getAppVersion')->willReturn('0.24.1');
		$this->assertSame(2, (new Capabilities($apps))->getCapabilities()['timesister']['invoice_handover'], '0.24.1: the system\'s PDF and cancellation');
	}
}
