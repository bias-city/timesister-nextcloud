<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\InvoiceLayout;
use OCA\TimeSister\Service\Json;
use PHPUnit\Framework\TestCase;

/**
 * The invoice layout (0.26.0): grouping by level, appended tables per
 * level, per tag and the state of the budget; since 0.32.0 six appendices,
 * every table from the client - checked and stored, never drawn here.
 */
class InvoiceLayoutTest extends TestCase {
	private static function outcome(callable $fn): string {
		try {
			$fn();
		} catch (ApiException $e) {
			return $e->getStatus() . ' ' . $e->getMessage();
		}
		return 'ok';
	}

	private static function obj(string $json): \stdClass {
		$v = Json::decode($json);
		self::assertInstanceOf(\stdClass::class, $v);
		return $v;
	}

	public function testCheck(): void {
		$this->assertNull(InvoiceLayout::check(null));
		$l = InvoiceLayout::check(self::obj('{"grouping":"level","appendix":["levels","tags","tags"],"levels":[{"title":" 3 Projektierung ","target":45000,"before":13500,"this":9000.004}],"budget":{"target":66700,"before":13500,"this":null}}'));
		$this->assertSame('{"grouping":"level","appendix":["levels","tags"],"levels":[{"target":45000,"before":13500,"this":9000,"title":"3 Projektierung"}],"budget":{"target":66700,"before":13500,"this":null}}', json_encode($l));
		$this->assertSame('{"grouping":"flat","appendix":[]}', json_encode(InvoiceLayout::check(new \stdClass())));
		foreach ([
			['"x"', '422 “layout” must be null or an object.'],
			['{"grouping":"tree"}', '422 “layout.grouping” must be one of flat, level.'],
			['{"appendix":["costs"]}', '422 “layout.appendix” must be a list of levels, tags, budget, aps, companies, rates.'],
			['{"aps":{"no":"1"}}', '422 “layout.aps” must be a list of at most 500 entries.'],
			['{"companies":[1]}', '422 Each entry of “layout.companies” must be an object.'],
			['{"rates":[{"level":"' . str_repeat('x', 501) . '"}]}', '422 “layout.rates” takes flat objects with texts of at most 500 characters and finite numbers.'],
			['{"tags":[{"tag":"a","rows":[1]}]}', '422 “layout.tags” takes flat objects with texts of at most 500 characters and finite numbers.'],
			['{"levels":[{"title":""}]}', '422 “layout.levels.title” must be text of 1 to 500 characters.'],
			['{"levels":[{"title":"A","target":"1"}]}', '422 “layout.levels.target” must be null or a number.'],
			['{"budget":7}', '422 “layout.budget” must be an object.'],
		] as [$json, $want]) {
			$this->assertSame($want, self::outcome(static fn () => InvoiceLayout::check(Json::decode($json))), $json);
		}
		$p = self::obj('{"kind":"hours","path":[" 3 Projektierung ","31 Vorprojekt"],"tags":["Planung"]}');
		InvoiceLayout::checkPosition($p);
		$this->assertSame(['3 Projektierung', '31 Vorprojekt'], $p->path);
		$this->assertSame('422 “tags” must be null or a list of at most 20 texts of 1 to 60 characters.', self::outcome(static fn () => InvoiceLayout::checkPosition(self::obj('{"tags":[""]}'))));
		$this->assertSame('422 “path” must be null or a list of at most 10 texts of 1 to 500 characters.', self::outcome(static fn () => InvoiceLayout::checkPosition(self::obj('{"path":"x"}'))));
	}

	/** 0.32.0: the six appendices, every table as the client computed it – form only, stored as sent. */
	public function testSixAppendicesFromTheClient(): void {
		$l = InvoiceLayout::check(self::obj('{"appendix":["aps","companies","rates","tags","budget","levels"],'
			. '"aps":[{"no":"D196.4","name":"Vorprojekt","hours":62.5,"amount":9375,"before":3000,"remaining":2625}],'
			. '"companies":[{"name":"Muster sàrl","hours":40,"amount":6000}],'
			. '"rates":[{"level":"Projektleitung","rate":150,"hours":62.5,"amount":9375}],'
			. '"tags":[{"tag":"bauleitung","hours":12,"amount":1800}]}'));
		$this->assertSame(['aps', 'companies', 'rates', 'tags', 'budget', 'levels'], $l->appendix);
		$this->assertSame('{"no":"D196.4","name":"Vorprojekt","hours":62.5,"amount":9375,"before":3000,"remaining":2625}', json_encode($l->aps[0]));
		$this->assertSame('Muster sàrl', $l->companies[0]->name);
		$this->assertSame(150, $l->rates[0]->rate);
		$this->assertSame('bauleitung', $l->tags[0]->tag);
		// A listed appendix without its table is no error (older drafts); the app shows it empty.
		$this->assertSame('{"grouping":"flat","appendix":["tags"]}', json_encode(InvoiceLayout::check(self::obj('{"appendix":["tags"]}'))));
		$this->assertCount(6, InvoiceLayout::APPENDIX);
	}
}
