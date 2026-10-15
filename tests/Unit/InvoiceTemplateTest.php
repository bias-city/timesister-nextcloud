<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Capabilities;
use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\InvoiceTemplateRules;
use OCA\TimeSister\Service\InvoiceTemplateService;
use OCA\TimeSister\Service\InvoiceTemplateStore;
use OCA\TimeSister\Service\Json;
use OCA\TimeSister\Service\Membership;
use OCA\TimeSister\Service\TeamExportService;
use OCA\TimeSister\Service\ZipFile;
use OCA\TimeSister\Service\ZipReader;
use OCA\TimeSister\Service\ZipRules;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;

/**
 * The team's invoice template (0.16.0): name, Base64, size and Word check,
 * Team Admins only, the store in the app data and the template in the
 * team ZIP.
 */
class InvoiceTemplateTest extends TestCase {
	private const NOW = 1_791_892_800;
	/** @var list<string> */
	private array $paths = [];

	protected function tearDown(): void {
		foreach ($this->paths as $p) {
			@unlink($p);
		}
	}

	private function tmp(): string {
		$p = (string)tempnam(sys_get_temp_dir(), 'ts-docx-');
		$this->paths[] = $p;
		return $p;
	}

	/** A ZIP with the given entries, as bytes. @param array<string,string> $entries */
	private function zip(array $entries): string {
		$path = $this->tmp();
		$z = new \ZipArchive();
		$z->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
		foreach ($entries as $name => $content) {
			$z->addFromString($name, $content);
		}
		$z->close();
		return (string)file_get_contents($path);
	}

	private function docx(string $text = 'Invoice {number}'): string {
		return $this->zip([
			'[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
			'word/document.xml' => '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>' . $text . '</w:t></w:r></w:p></w:body></w:document>',
		]);
	}

	/** Status and message of an ApiException, or `ok`. */
	private static function outcome(callable $fn): string {
		try {
			$fn();
		} catch (ApiException $e) {
			return $e->getStatus() . ' ' . $e->getMessage();
		}
		return 'ok';
	}

	private function service(MemAppData $data): InvoiceTemplateService {
		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTemporaryFile')->willReturnCallback(fn () => $this->tmp());
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		return new InvoiceTemplateService(new InvoiceTemplateStore($data), $temp, $time);
	}

	private static function body(string $name, string $bytes): \stdClass {
		$v = Json::decode((string)json_encode(['name' => $name, 'base64' => base64_encode($bytes)]));
		self::assertInstanceOf(\stdClass::class, $v);
		return $v;
	}

	public function testName(): void {
		foreach (['Rechnung.docx', 'INVOICE.DOCX', 'Rechnung März 2026.Docx', str_repeat('ä', 195) . '.docx'] as $ok) {
			$this->assertSame($ok, InvoiceTemplateRules::checkName($ok), $ok);
		}
		$bad = '422 “name” must be a file name of 1 to 200 characters ending in .docx.';
		foreach (['', '.docx', 'Rechnung.doc', 'Rechnung.docx.pdf', 'a/b.docx', 'a\\b.docx', "a\nb.docx", str_repeat('a', 196) . '.docx', 12, true, ['a.docx']] as $no) {
			$this->assertSame($bad, self::outcome(fn () => InvoiceTemplateRules::checkName($no)), json_encode($no));
		}
		$this->assertSame('422 “name” is missing.', self::outcome(fn () => InvoiceTemplateRules::checkName(null)));
	}

	public function testBase64AndSize(): void {
		$this->assertSame('hello', InvoiceTemplateRules::decode(base64_encode('hello')));
		$this->assertSame('hello', InvoiceTemplateRules::decode("aGVs\r\nbG8="), 'line breaks pass');
		$this->assertSame('422 “base64” is not valid Base64.', self::outcome(fn () => InvoiceTemplateRules::decode('not base64!')));
		$this->assertSame('422 “base64” is not valid Base64.', self::outcome(fn () => InvoiceTemplateRules::decode(42)));
		$this->assertSame('422 “base64” is missing.', self::outcome(fn () => InvoiceTemplateRules::decode(null)));
		$this->assertSame(InvoiceTemplateRules::MAX_BYTES, strlen(InvoiceTemplateRules::decode(base64_encode(str_repeat('x', InvoiceTemplateRules::MAX_BYTES)))));
		$this->assertSame('413 A template may be at most 10 MB.', self::outcome(fn () => InvoiceTemplateRules::decode(base64_encode(str_repeat('x', InvoiceTemplateRules::MAX_BYTES + 1)))));
		// 10 MB in Base64 with the name fits into the body limit.
		$this->assertGreaterThan(4 * (int)ceil(InvoiceTemplateRules::MAX_BYTES / 3) + 2000, InvoiceTemplateRules::MAX_BODY);
	}

	public function testWordDocument(): void {
		$check = function (string $bytes): string {
			$path = $this->tmp();
			file_put_contents($path, $bytes);
			return self::outcome(fn () => InvoiceTemplateRules::checkDocx($bytes, $path));
		};
		$this->assertSame('ok', $check($this->docx()));
		$word = '422 The template must be a Word document (.docx).';
		$this->assertSame($word, $check('Hello, I am a text file.'));
		$this->assertSame($word, $check(''));
		$this->assertSame($word, $check("%PDF-1.7\n"));
		$this->assertSame($word, $check("PK\x03\x04 but not really a ZIP archive"));
		$this->assertSame('422 The template is not a Word document: “word/document.xml” is missing.', $check($this->zip(['xl/workbook.xml' => '<x/>'])));
	}

	public function testOnlyTeamAdmins(): void {
		$data = new MemAppData();
		$s = $this->service($data);
		$s->put(new Membership('pbadmin', 7, 'admin'), self::body('Rechnung.docx', $this->docx()));
		$no = '403 Only Team Admins manage the invoice template.';
		foreach (['lead', 'user'] as $role) {
			$m = new Membership('someone', 7, $role);
			$this->assertSame($no, self::outcome(fn () => $s->info($m)), $role);
			$this->assertSame($no, self::outcome(fn () => $s->get($m)), $role);
			$this->assertSame($no, self::outcome(fn () => $s->put($m, self::body('Other.docx', $this->docx()))), $role);
			$this->assertSame($no, self::outcome(fn () => $s->delete($m)), $role);
			$this->assertSame($no, self::outcome(fn () => InvoiceTemplateService::allowed($m)), $role);
		}
		$this->assertSame('Rechnung.docx', $s->info(new Membership('pbadmin', 7, 'admin'))['name'], 'nothing changed');
	}

	public function testRoundTrip(): void {
		$data = new MemAppData();
		$s = $this->service($data);
		$admin = new Membership('pbadmin', 7, 'admin');
		$none = '404 The team has no invoice template.';
		$this->assertSame($none, self::outcome(fn () => $s->info($admin)));
		$this->assertSame($none, self::outcome(fn () => $s->get($admin)));
		$this->assertSame(['deleted' => false], $s->delete($admin));

		$bytes = $this->docx();
		$info = $s->put($admin, self::body('Rechnung.docx', $bytes));
		$want = ['name' => 'Rechnung.docx', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
			'modified_at' => '2026-10-13T12:00:00Z', 'modified_by' => 'pbadmin'];
		$this->assertSame($want, $info);
		$this->assertSame($want, $s->info($admin));
		$this->assertSame($want + ['base64' => base64_encode($bytes)], $s->get($admin));
		$this->assertSame(['templates/t7/invoice.docx', 'templates/t7/invoice.json'], $data->paths());
		// Another team sees none.
		$this->assertSame($none, self::outcome(fn () => $s->info(new Membership('atadmin', 8, 'admin'))));

		// Replaced in place.
		$second = $this->docx('Rechnung {nr}');
		$this->assertSame(hash('sha256', $second), $s->put($admin, self::body('Neu.DOCX', $second))['sha256']);
		$this->assertSame('Neu.DOCX', $s->info($admin)['name']);

		// Refused writes leave the stored one alone.
		$this->assertSame('422 The template must be a Word document (.docx).', self::outcome(fn () => $s->put($admin, self::body('x.docx', 'plain text'))));
		$this->assertSame('422 “name” must be a file name of 1 to 200 characters ending in .docx.', self::outcome(fn () => $s->put($admin, self::body('x.pdf', $bytes))));
		$this->assertSame('Neu.DOCX', $s->info($admin)['name']);

		$this->assertSame(['deleted' => true], $s->delete($admin));
		$this->assertSame($none, self::outcome(fn () => $s->info($admin)));
		$this->assertSame([], $data->paths());
	}

	public function testHalfStoredCountsAsNone(): void {
		$data = new MemAppData();
		$store = new InvoiceTemplateStore($data);
		$store->put(7, InvoiceTemplateRules::meta('R.docx', 'x', self::NOW, 'pbadmin'), 'x');
		$data->getFolder('templates')->getFolder('t7')->getFile('invoice.json')->delete();
		$this->assertNull($store->info(7));
		$this->assertNull($store->get(7));
		$store->put(7, InvoiceTemplateRules::meta('R.docx', 'x', self::NOW, 'pbadmin'), 'x');
		$data->getFolder('templates')->getFolder('t7')->getFile('invoice.json')->putContent('{"name":"R.docx"}');
		$this->assertNull($store->info(7), 'meta without size and checksum');
	}

	public function testMeta(): void {
		$m = InvoiceTemplateRules::meta('R.docx', 'abc', self::NOW, 'pbadmin');
		$this->assertSame($m, InvoiceTemplateRules::readMeta(Json::decode((string)json_encode($m))));
		$this->assertNull(InvoiceTemplateRules::readMeta(null));
		$this->assertNull(InvoiceTemplateRules::readMeta(['name' => 'R.pdf'] + $m));
		$this->assertNull(InvoiceTemplateRules::readMeta(['sha256' => 'abc'] + $m));
		$this->assertNull(InvoiceTemplateRules::readMeta(['modified_at' => 'yesterday'] + $m));
		$this->assertNull(InvoiceTemplateRules::readMeta(['size' => '3'] + $m));
	}

	public function testDecideOnImport(): void {
		$old = ['sha256' => str_repeat('a', 64), 'modified_at' => '2026-10-01T10:00:00Z'];
		$young = ['sha256' => str_repeat('b', 64), 'modified_at' => '2026-10-13T10:00:00Z'];
		$this->assertSame('store', ZipRules::decideTemplate(null, $old, ZipRules::MODE_MERGE));
		$this->assertSame('store', ZipRules::decideTemplate(null, $old, ZipRules::MODE_REPLACE));
		$this->assertSame('same', ZipRules::decideTemplate($old, $old, ZipRules::MODE_REPLACE));
		$this->assertSame('store', ZipRules::decideTemplate($old, $young, ZipRules::MODE_MERGE), 'merge: the younger');
		$this->assertSame('keep', ZipRules::decideTemplate($young, $old, ZipRules::MODE_MERGE), 'merge: never the older');
		$this->assertSame('store', ZipRules::decideTemplate($young, $old, ZipRules::MODE_REPLACE), 'replace: whenever it differs');
	}

	/** The template in a team ZIP: file and meta, both listed; a mismatch refuses the ZIP. */
	public function testInTeamZip(): void {
		$bytes = $this->docx();
		$meta = Json::encode((object)InvoiceTemplateRules::meta('Rechnung.docx', $bytes, self::NOW, 'pbadmin'));
		$read = function (array $files): array {
			$path = $this->tmp();
			$zip = ZipFile::create($path);
			$entries = [];
			$files += [ZipRules::TEAM => '{"name":"PB","slug":"pb","members":[]}'];
			foreach ($files as $p => $c) {
				$zip->add($p, $c);
				$entries[$p] = ZipRules::fileEntry($c);
			}
			$manifest = ZipRules::manifest(['id' => 7, 'name' => 'PB', 'slug' => 'pb', 'group' => 'pb-team'], '0.16.0', 2, self::NOW, $entries);
			$zip->add(ZipRules::MANIFEST, Json::encode(TeamExportService::toObject($manifest)));
			$zip->close();
			$zip = ZipFile::open($path);
			try {
				return ZipReader::read($zip);
			} finally {
				$zip->close();
			}
		};
		$b = $read([ZipRules::TEMPLATE => $bytes, ZipRules::TEMPLATE_META => $meta]);
		$this->assertSame('Rechnung.docx', $b['template']['meta']['name'] ?? null);
		$this->assertSame(ZipRules::TEMPLATE, $b['template']['path'] ?? null);
		$this->assertNull($read([])['template'], 'older backups have none');
		$bad = '422 “templates/invoice.json” in the backup is malformed.';
		$this->assertSame($bad, self::outcome(fn () => $read([ZipRules::TEMPLATE => $bytes])), 'file without meta');
		$this->assertSame($bad, self::outcome(fn () => $read([ZipRules::TEMPLATE_META => $meta])), 'meta without file');
		$this->assertSame($bad, self::outcome(fn () => $read([ZipRules::TEMPLATE => $this->docx('other'), ZipRules::TEMPLATE_META => $meta])), 'meta of another file');
	}

	public function testCapability(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppVersion')->willReturn('0.16.0');
		$this->assertSame(1, (new Capabilities($apps))->getCapabilities()['timesister']['invoice_template']);
	}
}
