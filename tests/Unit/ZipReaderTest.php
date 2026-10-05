<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\TeamExportService;
use OCA\TimeSister\Service\ZipFile;
use OCA\TimeSister\Service\ZipReader;
use OCA\TimeSister\Service\ZipRules;
use PHPUnit\Framework\TestCase;

/** Team backup 0.10.0: a ZIP on disk, read with limits and checksums. */
class ZipReaderTest extends TestCase {
	/** @var list<string> */
	private array $paths = [];

	protected function tearDown(): void {
		foreach ($this->paths as $p) {
			@unlink($p);
		}
	}

	private function tmp(): string {
		$p = tempnam(sys_get_temp_dir(), 'ts-zip-');
		$this->paths[] = $p;
		return $p;
	}

	private static function code(callable $fn): string {
		try {
			$fn();
		} catch (ApiException $e) {
			return $e->getStatus() . ' ' . $e->getErrorCode();
		}
		return 'ok';
	}

	/**
	 * A small, complete backup; `$tweak` changes files before the manifest
	 * is written, `$raw` adds entries the manifest does not know.
	 *
	 * @param array<string,string|null> $tweak path → content (null removes)
	 * @param array<string,string> $raw
	 */
	private function backup(array $tweak = [], array $raw = []): string {
		$files = [
			ZipRules::TEAM => json_encode(['name' => 'AT', 'slug' => 'at', 'backup_owner' => 'atadmin',
				'members' => [['uid' => 'atadmin', 'display_name' => 'Ada', 'role' => 'admin', 'left_at' => null, 'may_override' => false],
					['uid' => 'atuser', 'display_name' => 'Uli', 'role' => 'user', 'left_at' => '2026-09-01T00:00:00Z', 'may_override' => true]],
				'access' => [['viewer' => 'atadmin', 'owner' => 'atuser', 'source' => 'admin', 'level' => 'view', 'updated_by' => 'atadmin', 'updated_at' => '2026-09-01T00:00:00Z']],
				'consents' => [['uid' => 'atuser', 'consent' => true, 'since' => '2026-09-01T00:00:00Z', 'revoked_at' => null, 'notice' => 'v1', 'notice_at' => '2026-09-01T00:00:00Z']],
			]),
			ZipRules::STATUS => '{"atuser":{"seen_at":"2026-10-01T10:00:00Z","app_version":"0.5.1","last_sync":null,"calendar_url":null,"calendar_shared":1,"job_weeks":{"weeks":[]},"job_weeks_at":"2026-10-01T10:00:00Z"}}',
			ZipRules::ABSENCES => '{"atuser":[{"source_uid":"u1","kind":"vacation","start":"2026-10-10","end":"2026-10-12"}]}',
			ZipRules::recordPath('person', 'atuser') => '{"kind":"person","key":"atuser","version":1,"deleted":false,"modified_by":"atadmin","modified_at":"2026-10-01T10:00:00Z","data":{"login":"atuser","name":"Uli"},"accounts":["atuser"],"history":[]}',
			ZipRules::calendarPath('atuser') => "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n",
		];
		foreach ($tweak as $p => $c) {
			if ($c === null) {
				unset($files[$p]);
			} else {
				$files[$p] = $c;
			}
		}
		$path = $this->tmp();
		$zip = ZipFile::create($path);
		$entries = [];
		foreach ($files as $p => $c) {
			$zip->add($p, $c);
			$entries[$p] = ZipRules::fileEntry($c, str_starts_with($p, ZipRules::CALENDARS) ? ['uid' => 'atuser'] : []);
		}
		$entries[ZipRules::calendarPath('atadmin')] = ['missing' => true, 'uid' => 'atadmin'];
		$manifest = ZipRules::manifest(['id' => 3, 'name' => 'AT', 'slug' => 'at', 'group' => 'at'], '0.10.0', 2, 1_800_000_000, $entries);
		$zip->add(ZipRules::MANIFEST, json_encode(TeamExportService::toObject($manifest)));
		foreach ($raw as $p => $c) {
			$zip->add($p, $c);
		}
		$zip->close();
		return $path;
	}

	public function testReadsACompleteBackup(): void {
		$zip = ZipFile::open($this->backup());
		$b = ZipReader::read($zip);
		$zip->close();
		$this->assertSame('at', $b['manifest']['team']['slug']);
		$this->assertSame('AT', $b['team']['name']);
		$this->assertCount(2, $b['team']['members']);
		$this->assertSame(1788220800, $b['team']['members'][1]['left_at']);
		$this->assertTrue($b['team']['members'][1]['may_override']);
		$this->assertSame('view', $b['team']['access'][0]['level']);
		$this->assertTrue($b['team']['consents'][0]['consent']);
		$this->assertCount(1, $b['records']);
		$this->assertSame('atuser', $b['records'][0]['key']);
		$this->assertSame('{"weeks":[]}', $b['status']['atuser']['job_weeks']);
		$this->assertSame(1, $b['status']['atuser']['calendar_shared']);
		$this->assertSame('vacation', $b['absences']['atuser'][0]['kind']);
		$cals = array_column($b['calendars'], 'missing', 'uid');
		$this->assertSame(['atuser' => false, 'atadmin' => true], $cals);
		$this->assertSame(['atadmin', 'atuser'], ZipReader::accounts($b));
	}

	public function testRejectsTampering(): void {
		// Content changed after the manifest: checksum differs.
		$path = $this->backup();
		$z = new \ZipArchive();
		$z->open($path);
		$z->addFromString(ZipRules::TEAM, '{"name":"Other","members":[]}');
		$z->close();
		$this->assertSame('422 invalid', self::code(function () use ($path): void {
			$zip = ZipFile::open($path);
			try {
				ZipReader::read($zip);
			} finally {
				$zip->close();
			}
		}));
		// A file the manifest does not list.
		$path = $this->backup([], ['extra.txt' => 'x']);
		$this->assertSame('422 invalid', self::code(function () use ($path): void {
			ZipReader::read(ZipFile::open($path));
		}));
		// No manifest at all.
		$path = $this->tmp();
		$zip = ZipFile::create($path);
		$zip->add('team.json', '{}');
		$zip->close();
		$this->assertSame('422 invalid', self::code(function () use ($path): void {
			ZipReader::read(ZipFile::open($path));
		}));
		// Not a ZIP.
		$path = $this->tmp();
		file_put_contents($path, 'hello');
		$this->assertSame('422 invalid', self::code(function () use ($path): void {
			ZipFile::open($path);
		}));
	}

	public function testRejectsUnsafePaths(): void {
		$path = $this->tmp();
		$z = new \ZipArchive();
		$z->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
		$z->addFromString('../evil.json', '{}');
		$z->addFromString(ZipRules::MANIFEST, '{}');
		$z->close();
		$this->assertSame('422 invalid', self::code(function () use ($path): void {
			ZipFile::open($path)->entries();
		}));
		$this->assertSame('422 invalid', self::code(function () use ($path): void {
			ZipFile::open($path)->read('/etc/passwd');
		}));
		$zip = ZipFile::create($this->tmp());
		$this->expectException(\InvalidArgumentException::class);
		$zip->add('a/../b', 'x');
	}

	public function testMalformedParts(): void {
		foreach ([
			[ZipRules::TEAM => '[]'],
			[ZipRules::TEAM => '{"name":"AT","members":[{"uid":"a","role":"boss"}]}'],
			[ZipRules::TEAM => '{"name":"AT","access":[{"viewer":"a","owner":"b","source":"x","level":"view"}]}'],
			[ZipRules::ABSENCES => '{"atuser":[{"source_uid":"u\n1","kind":"vacation","start":"2026-10-10","end":"2026-10-12"}]}'],
			[ZipRules::recordPath('person', 'atuser') => '{"kind":"person","key":"atuser"}'],
			[ZipRules::STATUS => 'nope'],
		] as $tweak) {
			$path = $this->backup($tweak);
			$this->assertSame('422 invalid', self::code(function () use ($path): void {
				ZipReader::read(ZipFile::open($path));
			}), json_encode($tweak));
		}
		// Optional parts may be missing.
		$path = $this->backup([ZipRules::STATUS => null, ZipRules::ABSENCES => null]);
		$b = ZipReader::read(ZipFile::open($path));
		$this->assertSame([], $b['status']);
		$this->assertSame([], $b['absences']);
	}
}
