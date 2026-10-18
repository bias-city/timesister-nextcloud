<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\DocumentRules;
use OCA\TimeSister\Service\InvoiceDocument;
use OCA\TimeSister\Service\InvoiceLayout;
use OCA\TimeSister\Service\InvoicePdf;
use OCA\TimeSister\Service\Json;
use OCA\TimeSister\Service\VatLines;
use PHPUnit\Framework\TestCase;

/**
 * The invoice layout (0.26.0): grouping by level, appended tables per
 * level, per tag and the state of the budget - checked, grouped, drawn.
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
			['{"appendix":["costs"]}', '422 “layout.appendix” must be a list of levels, tags, budget.'],
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

	public function testGroupedAndTags(): void {
		$pos = [
			['kind' => 'hours', 'text' => '31.1 Varianten', 'hours' => 10, 'amount' => 1500, 'path' => ['3 Projektierung', '31 Vorprojekt'], 'tags' => ['Planung']],
			['kind' => 'hours', 'text' => '31.2 Kosten', 'hours' => 4, 'amount' => 600, 'path' => ['3 Projektierung', '31 Vorprojekt'], 'tags' => ['Planung', 'Kosten']],
			['kind' => 'hours', 'text' => '41 Ausschreibung', 'hours' => 2, 'amount' => 280, 'path' => ['4 Ausschreibung']],
			['kind' => 'flat', 'text' => 'Modell', 'amount' => 500],
		];
		$g = InvoiceLayout::grouped($pos);
		$this->assertSame(['head:3 Projektierung', 'head:31 Vorprojekt', 'pos', 'pos', 'sub:3 Projektierung', 'head:4 Ausschreibung', 'pos', 'sub:4 Ausschreibung', 'pos'],
			array_map(static fn (array $x): string => $x['type'] . (isset($x['title']) ? ':' . $x['title'] : ''), $g));
		$this->assertSame(2100.0, $g[4]['amount']);
		$this->assertSame([['tag' => 'Planung', 'hours' => 14.0, 'amount' => 2100.0], ['tag' => 'Kosten', 'hours' => 4.0, 'amount' => 600.0]], InvoiceLayout::tagRows($pos));
	}

	/** The PDF with grouping and all three tables renders (the page content is compressed; the figures are checked above). */
	public function testPdfWithLayout(): void {
		$d = new InvoiceDocument();
		$d->lang = DocumentRules::DE;
		$d->number = 'RE-2026-0009';
		$d->issuer = ['name' => 'Denkstatt', 'country' => 'CH'];
		$d->customer = ['name' => 'Stadt Basel', 'country' => 'CH'];
		$d->issuedOn = '2026-12-31';
		$d->dueOn = '2027-01-30';
		$d->positions = [
			['kind' => 'hours', 'text' => '31 Vorprojekt', 'hours' => 80, 'rate' => 150, 'amount' => 12000, 'path' => ['3 Projektierung'], 'tags' => ['Planung']],
			['kind' => 'advance_deduction', 'text' => 'Abzüglich Akonto', 'amount' => -2000, 'invoice' => 'inv-1'],
		];
		$d->vat = 8.1;
		$d->sums = VatLines::of($d->positions, $d->vat, $d->taxCase);
		$d->layout = ['grouping' => 'level', 'appendix' => ['levels', 'tags', 'budget'],
			'levels' => [(object)['title' => '3 Projektierung', 'target' => 34500, 'before' => 0, 'this' => 12000]],
			'budget' => (object)['target' => 66700, 'before' => 2000, 'this' => 10000]];
		$pdf = (new InvoicePdf())->render($d);
		$this->assertStringStartsWith('%PDF-', $pdf);
		$this->assertGreaterThanOrEqual(1, preg_match_all('/\/Type \/Page\b/', $pdf));
	}
}
