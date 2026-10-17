<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Capabilities;
use OCA\TimeSister\Db\Record;
use OCA\TimeSister\Db\RecordMapper;
use OCA\TimeSister\Service\AccessPolicy;
use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\Json;
use OCA\TimeSister\Service\Membership;
use OCA\TimeSister\Service\RecordRights;
use OCA\TimeSister\Service\RecordValidator;
use OCA\TimeSister\Service\TemplateRules;
use OCA\TimeSister\Service\TenantService;
use OCA\TimeSister\Service\ZipRules;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * Templates (0.25.0, `templates: 1`): format, key, size and no person or
 * customer fields; Leads and Team Admins read, Team Admins write, a Lead
 * their own.
 */
class TemplateRulesTest extends TestCase {
	private const KEY = 'buero.wohnbau@2026-10';
	private const TEMPLATE = ['format' => 'timesister-template', 'format_version' => 3, 'id' => 'buero.wohnbau', 'edition' => '2026-10',
		'status' => 'custom', 'title' => 'Housing', 'budget_label' => 'Phases', 'currency' => 'CHF',
		'items' => [['nr' => '1', 'title' => 'Preliminary', 'kind' => 'budget', 'children' => [
			['nr' => '1.1', 'title' => 'Survey', 'kind' => 'arbeitspaket', 'rate' => 160, 'hours' => 20],
			['nr' => 'M1', 'title' => 'Hand-in', 'kind' => 'meilenstein', 'offset_days' => 30]]]],
		'billing_plan' => [['offset_days' => 60, 'budget' => '1']]];

	private static function data(array $over = [], array $drop = []): \stdClass {
		$d = array_diff_key(array_merge(self::TEMPLATE, $over), array_flip($drop));
		$v = Json::decode((string)json_encode($d));
		self::assertInstanceOf(\stdClass::class, $v);
		return $v;
	}

	private static function outcome(callable $fn): string {
		try {
			$fn();
		} catch (ApiException $e) {
			return $e->getStatus() . ' ' . $e->getMessage();
		}
		return 'ok';
	}

	private static function check(array $over = [], array $drop = [], string $key = self::KEY): string {
		return self::outcome(fn () => RecordValidator::validate('template', $key, self::data($over, $drop)));
	}

	public function testValid(): void {
		$this->assertContains('template', RecordValidator::KINDS);
		$this->assertContains('template', RecordRights::PER_RECORD);
		$this->assertSame('ok', self::check());
		$this->assertSame('ok', self::check(['status' => 'normative', 'id' => 'ch.sia.112', 'edition' => '2014'], [], 'ch.sia.112@2014'));
		$r = RecordValidator::validate('template', self::KEY, self::data());
		$this->assertStringContainsString('"budget_label":"Phases"', $r['json'], 'stored as sent');
	}

	public function testFormatAndKey(): void {
		$this->assertSame('422 “format” of a template must be “timesister-template”.', self::check(['format' => 'other']));
		$this->assertSame('422 “format_version” of a template must be 3.', self::check(['format_version' => 2]));
		$this->assertSame('422 “format_version” of a template must be 3.', self::check([], ['format_version']));
		$this->assertSame('422 The key of a template is its “id”, “@” and its “edition”.', self::check([], [], 'buero.wohnbau@2027'));
		$this->assertSame('422 “id” of a template: lowercase letters, digits, dots, dashes and underscores, at most 100.', self::check(['id' => 'Buero'], [], 'Buero@2026-10'));
		$this->assertSame('422 “edition” of a template: letters, digits, dots, dashes and underscores, at most 100.', self::check(['edition' => ''], [], 'buero.wohnbau@'));
		$this->assertSame('422 “status” of a template is one of normative, draft, custom.', self::check(['status' => 'mine']));
		$this->assertSame('422 “title” must be text of 1 to 200 characters.', self::check(['title' => ' ']));
		$this->assertSame('422 “title” must be text of 1 to 200 characters.', self::check(['title' => str_repeat('x', 201)]));
		$this->assertSame('422 “items” of a template must be a list.', self::check(['items' => ['a' => 1]]));
	}

	public function testNoPersonOrCustomer(): void {
		$this->assertSame('422 A template carries no person or customer data: “customer”.', self::check(['customer' => 'kanton']));
		$items = self::TEMPLATE['items'];
		$items[0]['children'][0]['lead'] = 'pblead';
		$this->assertSame('422 A template carries no person or customer data: “items[0].children[0].lead”.', self::check(['items' => $items]));
		$this->assertSame('422 A template carries no person or customer data: “project.Email”.', self::check(['project' => ['Email' => 'a@b.c']]), 'any case');
		$this->assertNull(TemplateRules::personField(self::data(), '', 0));
		$this->assertNull(TemplateRules::personField(Json::decode('{"1":{"2":"x"}}'), '', 0), 'numeric field names');
	}

	public function testSize(): void {
		$items = [];
		for ($i = 0; $i < 1800; $i++) {
			$items[] = ['nr' => (string)$i, 'title' => str_repeat('t', 150), 'kind' => 'arbeitspaket'];
		}
		$this->assertSame('413 A template may be at most 256 KB.', self::check(['items' => $items]));
	}

	/** @param array<string,list<string>> $led */
	private static function rights(array $led): RecordRights {
		$team = (new \ReflectionClass(TenantService::class))->newInstanceWithoutConstructor();
		$byUid = [];
		foreach ($led as $uid => $keys) {
			$byUid[$uid] = array_map(static fn (string $k) => ['kind' => 'project', 'key' => $k, 'code' => null, 'name' => $k], $keys);
		}
		(new \ReflectionProperty(TenantService::class, 'leads'))->setValue($team, [1 => $byUid]);
		$records = (new \ReflectionClass(RecordMapper::class))->newInstanceWithoutConstructor();
		return new RecordRights(new AccessPolicy(), $team, $records);
	}

	private static function record(string $by, bool $tombstone = false): Record {
		$r = new Record();
		$r->setKind('template');
		$r->setRkey(self::KEY);
		$r->setData(Json::encode(self::data()));
		$r->setModifiedBy($by);
		$r->setDeleted($tombstone ? 1 : 0);
		return $r;
	}

	public function testRightsPerRole(): void {
		$rights = self::rights(['lea' => ['d200']]);
		$admin = new Membership('petra', 1, 'admin');
		$lea = new Membership('lea', 1, 'lead');
		$mia = new Membership('mia', 1, 'user');
		$read = static fn (Membership $m): bool => $rights->canRead($m, 'template', self::KEY, [], [$m->uid], self::record('petra'));
		$this->assertTrue($read($admin));
		$this->assertTrue($read($lea), 'Leads read every template');
		$this->assertFalse($read($mia), 'Members see none');

		$this->assertSame('ok', self::outcome(fn () => $rights->requireWrite($admin, 'template', self::KEY)));
		$this->assertSame('ok', self::outcome(fn () => $rights->requireWrite($lea, 'template', self::KEY)), 'a Lead passes the first check');
		$this->assertSame('403 Only Team Admins and Leads write templates.', self::outcome(fn () => $rights->requireWrite($mia, 'template', self::KEY)));

		$own = '403 A Lead changes and removes only their own templates.';
		$this->assertSame('ok', self::outcome(fn () => $rights->requireTemplateWrite($admin, self::record('lea'))), 'Team Admin: any');
		$this->assertSame('ok', self::outcome(fn () => $rights->requireTemplateWrite($lea, null)), 'a new one');
		$this->assertSame('ok', self::outcome(fn () => $rights->requireTemplateWrite($lea, self::record('lea'))), 'their own');
		$this->assertSame('ok', self::outcome(fn () => $rights->requireTemplateWrite($lea, self::record('petra', true))), 'on a tombstone: new');
		$this->assertSame($own, self::outcome(fn () => $rights->requireTemplateWrite($lea, self::record('petra'))), 'another’s');

		$w = static fn (string $kind): \stdClass => (object)['kind' => $kind, 'key' => 'k'];
		$this->assertTrue(RecordRights::perRecordOnly([$w('template')]), 'a Lead batches templates');
	}

	public function testCapabilityAndZip(): void {
		$cap = (new Capabilities($this->createMock(IAppManager::class)))->getCapabilities()['timesister'];
		$this->assertSame(1, $cap['templates']);
		$this->assertSame('records/template/' . self::KEY . '.json', ZipRules::recordPath('template', self::KEY), 'the team ZIP takes templates along');
		$mapped = ZipRules::mapData(self::data(), ['pblead' => 'lea']);
		$this->assertEquals(self::data(), $mapped, 'a template names no accounts');
	}
}
