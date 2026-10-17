<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\ExportRules;
use PHPUnit\Framework\TestCase;

/** The exports (0.20.0): the `datev` setting, CSV lines, DATEV header and rows (Soll/Haben, accounts, BU key), the external body. */
class ExportRulesTest extends TestCase {
	/** @return array<string,mixed> */
	private static function fixed(array $more = []): array {
		return $more + [
			'number' => 'RE-2026-0007', 'status' => 'issued', 'issued_on' => '2026-10-15', 'due_on' => '2026-11-14', 'paid_on' => null,
			'customer' => ['name' => 'Müller AG', 'vat_id' => 'CHE-1', 'tax_case' => 'domestic'], 'project' => 'd200', 'budget' => 'b1', 'currency' => 'CHF',
			'net' => 1825.0, 'vat' => 8.1, 'vat_amount' => 147.83, 'gross' => 1972.83, 'reverses' => null, 'credits' => null,
			'document_sha256' => 'abc', 'chain' => ['seq' => 3, 'hash' => 'def'],
		];
	}

	public function testDatevSetting(): void {
		$d = ExportRules::validateDatev((object)['consultant' => '1234567', 'client' => 12345, 'fiscal_year_start' => '04-01', 'account_length' => 4, 'debtor_account' => '10000', 'revenue_domestic' => '8400', 'revenue_eu' => '8336', 'revenue_export' => '8338', 'bu_eu' => null]);
		$this->assertSame('12345', $d['client'], 'an integer becomes digits');
		$this->assertSame(4, $d['account_length']);
		$this->assertNull($d['bu_domestic']);
		$json = ExportRules::encodeDatev($d);
		$this->assertSame($d, ExportRules::datev($json), 'round trip');
		$this->assertNull(ExportRules::encodeDatev([]), 'nothing set: null');
		$this->assertSame(ExportRules::DATEV_DEFAULTS, ExportRules::datev(null));
		foreach ([
			'"x"' => '“datev” must be an object.',
			'{"consultant":"12345678"}' => '“datev.consultant” must be up to 7 digits.',
			'{"fiscal_year_start":"13-01"}' => '“datev.fiscal_year_start” must be a day of the year in the format MM-DD.',
			'{"account_length":9}' => '“datev.account_length” must be an integer from 4 to 8.',
			'{"revenue_eu":"83a6"}' => '“datev.revenue_eu” must be up to 9 digits.',
			'{"other":1}' => 'Unknown team setting “datev.other”.',
		] as $json => $message) {
			try {
				ExportRules::validateDatev(json_decode($json));
				$this->fail($json);
			} catch (ApiException $e) {
				$this->assertSame($message, $e->getMessage(), $json);
			}
		}
		try {
			ExportRules::requireAccounts(ExportRules::datev('{"debtor_account":"10000","revenue_domestic":"8400"}'));
			$this->fail('accounts missing');
		} catch (ApiException $e) {
			$this->assertStringContainsString('“revenue_eu” is missing', $e->getMessage());
		}
	}

	public function testQueryAndCsv(): void {
		$this->assertSame('csv', ExportRules::kind('csv'));
		$this->assertNull(ExportRules::day(null, 'from'));
		$this->assertSame('2026-01-01', ExportRules::day('2026-01-01', 'from'));
		foreach ([['kind', 'xlsx'], ['day', '2026-13-01']] as [$what, $v]) {
			try {
				$what === 'kind' ? ExportRules::kind($v) : ExportRules::day($v, 'to');
				$this->fail($what);
			} catch (ApiException $e) {
				$this->assertSame(422, $e->getStatus());
			}
		}
		$this->assertTrue(ExportRules::inPeriod('2026-10-15', '2026-10-01', '2026-10-31'));
		$this->assertFalse(ExportRules::inPeriod('2026-11-01', null, '2026-10-31'));
		$this->assertSame('invoices-2026-01-01-end.csv', ExportRules::fileName('csv', '2026-01-01', null));
		$this->assertSame("a;\"b;c\";\"say \"\"hi\"\"\";d\r\n", ExportRules::line(['a', 'b;c', 'say "hi"', 'd']));
		$row = ExportRules::csvRow(self::fixed(), null);
		$this->assertCount(count(ExportRules::CSV_HEAD), $row);
		$this->assertSame(['RE-2026-0007', 'issued', '2026-10-15', '2026-11-14', '', 'Müller AG', 'CHE-1', 'd200', 'b1', 'CHF', '1825.00', '8.10', '147.83', '1972.83', 'domestic', '', 'abc', '3', 'def', '8.1:1825.00', '8.1:147.83', '0.00', '1972.83'], $row, 'an invoice of 0.20.0: one rate, no pass-through items');
		$two = ExportRules::csvRow(self::fixed(['vat_lines' => [['rate' => 2.6, 'net' => 100.0, 'vat' => 2.6], ['rate' => 8.1, 'net' => 1234.0, 'vat' => 99.95]], 'pass_through' => 50.0, 'total' => 1486.55]), null);
		$this->assertSame(['2.6:100.00;8.1:1234.00', '2.6:2.60;8.1:99.95', '50.00', '1486.55'], array_slice($two, 19), 'per rate, pass-through, total');
		$this->assertStringContainsString(';"2.6:100.00;8.1:1234.00";', ExportRules::line($two), 'quoted: the separator is inside');
		$this->assertSame('RE-2026-0007', ExportRules::csvRow(self::fixed(['reverses' => 'inv-1']), 'RE-2026-0007')[15], 'the reversed number');
		$this->assertSame(['3', '2026-10-15T14:30:00Z', 'pbadmin', 'issued', 'RE-2026-0007', 'p', 'q', 'h'], ExportRules::ledgerRow(['seq' => 3, 'at' => '2026-10-15T14:30:00Z', 'actor' => 'pbadmin', 'action' => 'issued', 'number' => 'RE-2026-0007', 'payload_hash' => 'p', 'prev_hash' => 'q', 'hash' => 'h', 'payload' => '{}']));
	}

	public function testDatev(): void {
		$datev = ExportRules::datev('{"consultant":"1234567","client":"12345","fiscal_year_start":"04-01","account_length":4,"debtor_account":"10000","revenue_domestic":"8400","revenue_eu":"8336","revenue_export":"8338","bu_eu":"94"}');
		$head = ExportRules::datevHeader($datev, '2026-10-01', '2026-10-31', 1792074600, 'pbadmin', 'CHF');
		$this->assertCount(31, $head);
		$this->assertSame(['EXTF', '700', '21', 'Buchungsstapel', '12'], array_slice($head, 0, 5));
		$this->assertSame('20261015143000000', $head[5], 'created at, UTC, milliseconds');
		$this->assertSame('TS', $head[7]);
		$this->assertSame('pbadmin', $head[8]);
		$this->assertSame(['1234567', '12345', '20260401', '4', '20261001', '20261031', 'TimeSister Rechnungen', '', '1', '0', '0', 'CHF'], array_slice($head, 10, 12));
		$this->assertSame('20250401', ExportRules::datevHeader($datev, '2026-02-01', '2026-02-28', 0, 'x', 'EUR')[12], 'a fiscal year starting in April: February belongs to the previous one');
		$row = ExportRules::datevRows(self::fixed(), $datev, 'Umbau Rheingasse')[0];
		$this->assertSame(['1972,83', 'S', 'CHF', '', '', '', '10000', '8400', '', '1510', 'RE-2026-0007', '', '', 'Müller AG Umbau Rheingasse'], $row);
		$this->assertCount(count(ExportRules::DATEV_HEAD), $row);
		$rev = ExportRules::datevRows(self::fixed(['reverses' => 'inv-1', 'gross' => -1972.83]), $datev, 'P')[0];
		$this->assertSame(['1972,83', 'H'], array_slice($rev, 0, 2), 'a reversal is Haben with the absolute amount');
		$eu = ExportRules::datevRows(self::fixed(['customer' => ['name' => 'SARL', 'tax_case' => 'eu_reverse', 'datev_account' => '10077']]), $datev, 'P')[0];
		$this->assertSame(['10077', '8336', '94'], array_slice($eu, 6, 3), 'the customer’s debtor account, the EU revenue account, the BU key of the setting');
		$export = ExportRules::datevRows(self::fixed(['customer' => ['name' => 'Inc', 'tax_case' => 'export']]), $datev, 'P')[0];
		$this->assertSame(['10000', '8338', ''], array_slice($export, 6, 3));
		// 0.21.0: one booking per rate and one for the pass-through items on their own account.
		$datev['revenue_pass_through'] = '1590';
		$rows = ExportRules::datevRows(self::fixed(['vat_lines' => [['rate' => 2.6, 'net' => 100.0, 'vat' => 2.6], ['rate' => 8.1, 'net' => 1234.0, 'vat' => 99.95]], 'pass_through' => 50.0, 'total' => 1486.55]), $datev, 'P');
		$this->assertSame([['102,60', 'S', '8400', ''], ['1333,95', 'S', '8400', ''], ['50,00', 'S', '1590', '']],
			array_map(static fn (array $r): array => [$r[0], $r[1], $r[7], $r[8]], $rows));
		$rev = ExportRules::datevRows(self::fixed(['reverses' => 'inv-1', 'vat_lines' => [['rate' => 8.1, 'net' => -100.0, 'vat' => -8.1]], 'pass_through' => -50.0, 'total' => -158.1]), $datev, 'P');
		$this->assertSame([['108,10', 'H'], ['50,00', 'H']], array_map(static fn (array $r): array => [$r[0], $r[1]], $rev), 'a reversal: every booking Haben');
		try {
			ExportRules::requirePassThroughAccount(ExportRules::datev(null));
			$this->fail('pass-through account missing');
		} catch (ApiException $e) {
			$this->assertStringContainsString('“revenue_pass_through” is missing', $e->getMessage());
		}
		$this->assertSame('1590', ExportRules::validateDatev((object)['revenue_pass_through' => 1590])['revenue_pass_through']);
		$this->assertSame('RE-2026-0007', ExportRules::belegfeld('RE/2026_0007') === 'RE/2026-0007' ? 'RE-2026-0007' : 'x', 'slash allowed, underscore not');
		$this->assertSame(36, strlen(ExportRules::belegfeld(str_repeat('1', 40))));
	}

	public function testExternal(): void {
		$this->assertSame(['system' => 'bexio', 'id' => '4711', 'url' => 'https://office.bexio.com/kb_invoice/show/4711'], ExportRules::external((object)['system' => 'bexio', 'id' => 4711, 'url' => 'https://office.bexio.com/kb_invoice/show/4711']));
		$this->assertNull(ExportRules::external((object)['system' => 'other', 'id' => 'x', 'url' => ''])['url']);
		// 0.23.1: any system name of the pattern; the three of 0.20.0 still fit.
		foreach (['bexio', 'lexware', 'other', 'sevdesk', 'datev', 'ch.example.csv', 'a', '0-x_y', str_repeat('a', 64)] as $name) {
			$this->assertSame($name, ExportRules::external((object)['system' => $name, 'id' => '1'])['system'], $name);
		}
		$this->assertSame('x', ExportRules::external((object)['system' => 'other', 'id' => 'x', 'extra' => 1])['id'], 'the body ignores other fields');
		$system = '“system” must be a name of 1 to 64 characters: lowercase letters, digits, “.”, “_” or “-”, starting with a letter or digit (for example “bexio”).';
		foreach ([
			'{"system":"Sage","id":"1"}' => $system,
			'{"system":".x","id":"1"}' => $system,
			'{"system":"-x","id":"1"}' => $system,
			'{"system":"a b","id":"1"}' => $system,
			'{"system":"","id":"1"}' => $system,
			'{"system":"' . str_repeat('a', 65) . '","id":"1"}' => $system,
			'{"system":7,"id":"1"}' => $system,
			'{"id":"1"}' => $system,
			'{"system":"bexio"}' => '“id” must be text of at most 128 characters.',
			'{"system":"bexio","id":"' . str_repeat('1', 129) . '"}' => '“id” must be text of at most 128 characters.',
			'{"system":"bexio","id":"1","url":"http://x"}' => '“url” must be an https address of at most 512 characters.',
		] as $json => $message) {
			try {
				ExportRules::external(json_decode($json));
				$this->fail($json);
			} catch (ApiException $e) {
				$this->assertSame($message, $e->getMessage(), $json);
			}
		}
	}

	/** 0.23.1: the field `external` of the invoice record. */
	public function testExternalBlock(): void {
		$this->assertNull(ExportRules::externalBlock(null));
		$this->assertEquals((object)['system' => 'sevdesk', 'id' => '4711', 'url' => null, 'at' => null, 'by' => null], ExportRules::externalBlock(json_decode('{"system":"sevdesk","id":4711}')), 'complete, the number as text');
		$full = (object)['system' => 'ch.example.csv', 'id' => 'R-17', 'url' => 'https://example.org/r/17', 'at' => '2026-10-17T09:45:00Z', 'by' => 'pbadmin'];
		$this->assertEquals($full, ExportRules::externalBlock(clone $full));
		$this->assertNull(ExportRules::externalBlock((object)['system' => 'datev', 'id' => '1', 'url' => ''])?->url);
		foreach ([
			'"bexio"' => '“external” must be null or an object with “system” and “id”.',
			'[]' => '“external” must be null or an object with “system” and “id”.',
			'{"system":"bexio","id":"1","note":"x"}' => '“external” has an unknown field “note”; allowed are system, id, number, url, at, by, document and cancelled_at.',
			'{"system":"Bexio","id":"1"}' => '“external.system” must be a name of 1 to 64 characters: lowercase letters, digits, “.”, “_” or “-”, starting with a letter or digit (for example “bexio”).',
			'{"system":"bexio"}' => '“external.id” must be text of at most 128 characters.',
			'{"system":"bexio","id":"' . str_repeat('1', 129) . '"}' => '“external.id” must be text of at most 128 characters.',
			'{"system":"bexio","id":"a\u0007"}' => '“external.id” must be text of at most 128 characters.',
			'{"system":"bexio","id":1.5}' => '“external.id” must be text of at most 128 characters.',
			'{"system":"bexio","id":"1","url":"http://example.org"}' => '“external.url” must be an https address of at most 512 characters.',
			'{"system":"bexio","id":"1","url":"https://' . str_repeat('a', 510) . '"}' => '“external.url” must be an https address of at most 512 characters.',
			'{"system":"bexio","id":"1","at":"' . str_repeat('2', 33) . '"}' => '“external.at” must be null or text of at most 32 characters.',
			'{"system":"bexio","id":"1","at":17}' => '“external.at” must be null or text of at most 32 characters.',
			'{"system":"bexio","id":"1","by":"' . str_repeat('b', 65) . '"}' => '“external.by” must be null or text of at most 64 characters.',
		] as $json => $message) {
			try {
				ExportRules::externalBlock(json_decode($json));
				$this->fail($json);
			} catch (ApiException $e) {
				$this->assertSame(422, $e->getStatus(), $json);
				$this->assertSame($message, $e->getMessage(), $json);
			}
		}
	}
}
