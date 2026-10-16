<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\DocumentTree;
use PHPUnit\Framework\TestCase;

/**
 * The document tree (0.22.0): the root, the folders per year and kind,
 * the file names with safe parts and the free name of a taken one.
 */
class DocumentTreeTest extends TestCase {
	public function testRootAndFolders(): void {
		$this->assertSame('TimeSister/Example Team', DocumentTree::root(null, 'Example Team'));
		$this->assertSame('Gruppe/Team', DocumentTree::root('Gruppe/Team', 'X'));
		$r = 'Root';
		$this->assertSame('Root/Buchhaltung/2026/Offerten', DocumentTree::quoteFolder($r, '2026-06-15'));
		$this->assertSame('Root/Buchhaltung/2026/Rechnungen', DocumentTree::invoiceFolder($r, '2026-10-16'));
		$this->assertSame('Root/Buchhaltung/2026/Belege', DocumentTree::receiptFolder($r, '2026-10-03'));
		$this->assertSame('Root/Buchhaltung/2026/Spesen/Juerg-Beispiel', DocumentTree::expenseFolder($r, '2026-10-31', 'Jürg Beispiel'));
		$this->assertSame('Root/Buchhaltung/2026/Spesen/Person', DocumentTree::expenseFolder($r, '2026-10-31', '..'), 'never a dot folder');
		$this->assertSame('Root/Buchhaltung/2026/Kette', DocumentTree::chainFolder($r, '2026-10-16'));
		$this->assertSame('Root/Projekte/D196 Umbau-Musterweg', DocumentTree::projectFolder($r, 'D196', 'Umbau Musterweg'));
		$this->assertSame('Root/Projekte/Umbau', DocumentTree::projectFolder($r, null, 'Umbau'));
		$this->assertSame('Root/Projekte/Projekt', DocumentTree::projectFolder($r, null, '///'));
	}

	public function testNames(): void {
		$this->assertSame('2026-06-15_OF-D200-2606112_Kunde-Sued-AG.pdf', DocumentTree::quoteName('2026-06-15', 'OF-D200-2606112', 'Kunde Süd AG'));
		$this->assertSame('2026-10-16_RE-2026-0012_Beispiel-GmbH.pdf', DocumentTree::invoiceName('2026-10-16', 'RE-2026-0012', 'Beispiel GmbH'));
		$this->assertSame('2026-10-16_RE-2026-0012_Beispiel-GmbH.xml', DocumentTree::invoiceName('2026-10-16', 'RE-2026-0012', 'Beispiel GmbH', 'xml'));
		$this->assertSame('2026-10-16_RE-2026-12.pdf', DocumentTree::invoiceName('2026-10-16', 'RE/2026/12', ''), 'slashes become -, an empty part is left out');
		$this->assertSame('2026-10-03_Druckerei-Muster-AG_1340.44-CHF_D196-K2.pdf', DocumentTree::receiptName('2026-10-03', 'Druckerei Muster AG', 1340.44, 'chf', 'D196', 2, 'pdf'));
		$this->assertSame('2026-10-02_Bahn_84.00-CHF.jpg', DocumentTree::receiptName('2026-10-02', 'Bahn', 84, 'CHF', null, null, 'jpg'));
		$this->assertSame('2026-10-02_Bahn.pdf', DocumentTree::receiptName('2026-10-02', 'Bahn', 0, 'CHF', null, null, 'pdf'), 'no amount yet: left out');
		$this->assertSame('2026-10-02_84.00-CHF_D196.pdf', DocumentTree::receiptName('2026-10-02', '', 84, 'CHF', 'D196', null, 'pdf'), 'a project without cost position');
		$this->assertTrue(DocumentTree::isPlaced('R/Belege/a_b.pdf', 'R/Belege', 'a_b.pdf'));
		$this->assertTrue(DocumentTree::isPlaced('R/Belege/a_b_2.pdf', 'R/Belege', 'a_b.pdf'), 'a free name of unique() stays');
		$this->assertFalse(DocumentTree::isPlaced('R/Belege/a_c.pdf', 'R/Belege', 'a_b.pdf'));
		$this->assertFalse(DocumentTree::isPlaced('R/Spesen/X/a_b.pdf', 'R/Belege', 'a_b.pdf'));
		$this->assertFalse(DocumentTree::isPlaced('R/Belege/a_b_x.pdf', 'R/Belege', 'a_b.pdf'));
		$this->assertSame('2026-10_Spesenblatt.pdf', DocumentTree::reportName('2026-10-31'));
		$this->assertSame('chain-2026-10-16.txt', DocumentTree::chainName('2026-10-16'));
		$this->assertSame('Projektakte-2026-10-16.zip', DocumentTree::dossierName('2026-10-16'));
		$this->assertSame(['pdf', 'jpg', 'png', 'bin'], [DocumentTree::extension('application/pdf'), DocumentTree::extension('image/jpeg'), DocumentTree::extension('image/png'), DocumentTree::extension('text/plain')]);
	}

	public function testParts(): void {
		$this->assertSame('Aeoeue-ss-Cafe-Creme', DocumentTree::part('Äöü ß Café Crème'));
		$this->assertSame('a-b', DocumentTree::part('  a / / b  '), 'runs collapsed, no - at the ends');
		$this->assertSame(40, strlen(DocumentTree::part(str_repeat('x', 60))));
		$this->assertSame('x', DocumentTree::part(str_repeat('x', 1) . str_repeat(' ', 50) . 'y', 2), 'cut, then trimmed');
		$this->assertSame('1340.44', DocumentTree::amount(1340.44));
		$this->assertSame('84.00', DocumentTree::amount(84));
	}

	public function testUnique(): void {
		$taken = ['a.pdf' => true, 'a_2.pdf' => true];
		$free = static fn (string $n): bool => isset($taken[$n]);
		$this->assertSame('b.pdf', DocumentTree::unique('b.pdf', $free));
		$this->assertSame('a_3.pdf', DocumentTree::unique('a.pdf', $free));
		$this->assertSame('noext_2', DocumentTree::unique('noext', static fn (string $n): bool => $n === 'noext'));
	}
}
