<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\BackupRules;
use OCA\TimeSister\Service\StatusRules;
use OCA\TimeSister\Service\TeamRules;
use OCA\TimeSister\Service\Time;
use PHPUnit\Framework\TestCase;

/** Backups, heartbeat, teams, times. */
class RulesTest extends TestCase {
	private static function code(callable $fn): string {
		try {
			$fn();
		} catch (ApiException $e) {
			return $e->getStatus() . ' ' . $e->getErrorCode();
		}
		return 'ok';
	}

	public function testBackupDay(): void {
		$this->assertSame('2026-09-22', BackupRules::checkDay('2026-09-22'));
		$this->assertSame('422 invalid', self::code(fn () => BackupRules::checkDay('2026-02-30')));
		$this->assertSame('422 invalid', self::code(fn () => BackupRules::checkDay('22.09.2026')));
		$this->assertSame('422 invalid', self::code(fn () => BackupRules::checkDay('../../etc')));
		$this->assertSame('422 invalid', self::code(fn () => BackupRules::checkDay(20260922)));
		$this->assertSame('2026-09-22.ics', BackupRules::fileFor('2026-09-22'));
	}

	public function testBackupDecode(): void {
		$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n";
		$this->assertSame($ics, BackupRules::decode(base64_encode($ics)));
		$this->assertSame($ics, BackupRules::decode(chunk_split(base64_encode($ics), 8, "\r\n")));
		$bom = "\xEF\xBB\xBF\r\n" . $ics;
		$this->assertSame($bom, BackupRules::decode(base64_encode($bom)));
		$this->assertSame('422 invalid', self::code(fn () => BackupRules::decode(base64_encode('BEGIN:VCARD'))));
		$this->assertSame('422 invalid', self::code(fn () => BackupRules::decode('%%%not base64%%%')));
		$this->assertSame('422 invalid', self::code(fn () => BackupRules::decode('')));
		$this->assertSame('422 invalid', self::code(fn () => BackupRules::decode(null)));
	}

	public function testBackupSizeLimit(): void {
		$head = "BEGIN:VCALENDAR\r\n";
		$exact = $head . str_repeat('x', BackupRules::MAX_BYTES - strlen($head));
		$this->assertSame(BackupRules::MAX_BYTES, strlen(BackupRules::decode(base64_encode($exact))));
		$this->assertSame('413 too_large', self::code(fn () => BackupRules::decode(base64_encode($exact . 'y'))));
		$this->assertSame('413 too_large', self::code(fn () => BackupRules::decode(str_repeat('QUFB', 8000000))));
	}

	public function testBackupFolderNeverAPath(): void {
		$this->assertSame('u-alice', BackupRules::folderFor('alice'));
		$this->assertSame('u-a.muster@example.org', BackupRules::folderFor('a.muster@example.org'));
		foreach (['..', '.', '.hidden', 'a/b', "o'neil", 'has space'] as $uid) {
			$f = BackupRules::folderFor($uid);
			$this->assertMatchesRegularExpression('/^h-[0-9a-f]{64}$/', $f, $uid);
		}
	}

	public function testStatus(): void {
		$v = StatusRules::validate([
			'app_version' => '0.2.0',
			'last_sync' => '2026-09-26T08:15:00Z',
			'last_backup' => '2026-09-22',
			'calendar_url' => 'https://cloud.example.test/remote.php/dav/calendars/alice/zeit-alice/',
		]);
		$this->assertSame(gmmktime(8, 15, 0, 9, 26, 2026), $v['last_sync']);
		$this->assertSame('0.2.0', $v['app_version']);
		$this->assertSame(['app_version' => null, 'last_sync' => null, 'last_backup' => null, 'calendar_url' => null, 'projects' => null], StatusRules::validate([]));
		// 0.17.0: `calendar_shared` and `applied_shares` (shares matrix) are no longer fields; unknown keys are ignored.
		$this->assertNull(StatusRules::validate(['calendar_shared' => 1, 'applied_shares' => 'x'])['app_version']);
		$this->assertSame(gmmktime(6, 15, 0, 9, 26, 2026), StatusRules::validate(['last_sync' => '2026-09-26T08:15:00+02:00'])['last_sync']);
		$this->assertSame('422 invalid', self::code(fn () => StatusRules::validate(['calendar_url' => 'javascript:alert(1)'])));
		$this->assertSame('422 invalid', self::code(fn () => StatusRules::validate(['calendar_url' => 'file:///etc/passwd'])));
		$this->assertSame('422 invalid', self::code(fn () => StatusRules::validate(['last_sync' => 'gestern'])));
		$this->assertSame('422 invalid', self::code(fn () => StatusRules::validate(['last_sync' => '2026-09-26 08:15'])));
		$this->assertSame('422 invalid', self::code(fn () => StatusRules::validate(['last_backup' => '2026-13-01'])));
		$this->assertSame('422 invalid', self::code(fn () => StatusRules::validate(['app_version' => str_repeat('1', 33)])));
		$this->assertSame('422 invalid', self::code(fn () => StatusRules::validate(['app_version' => '<b>'])));
	}

	/** `projects` (0.12.0): a list of keys, without duplicates and sorted; anything else 400. */
	public function testStatusProjects(): void {
		$this->assertSame(['P-1', 'P-2', 'P10'], StatusRules::validate(['projects' => ['P10', 'P-2', 'P-1', 'P-2']])['projects']);
		$this->assertSame([], StatusRules::validate(['projects' => []])['projects']);
		$this->assertNull(StatusRules::validate(['projects' => null])['projects']);
		$this->assertSame([str_repeat('k', 64)], StatusRules::validate(['projects' => [str_repeat('k', 64)]])['projects']);
		$this->assertCount(500, StatusRules::validate(['projects' => array_map(static fn (int $i) => "p$i", range(1, 500))])['projects']);
		$bad = [
			'P-1', ['P-1' => 'x'], [''], [str_repeat('k', 65)], ["a\nb"], ["tab\t"], ["del\x7F"], [1], [null], [['P-1']],
			array_map(static fn (int $i) => "p$i", range(1, 501)),
		];
		foreach ($bad as $i => $in) {
			$this->assertSame('400 invalid', self::code(fn () => StatusRules::validate(['projects' => $in])), "case $i");
		}
	}

	public function testTeam(): void {
		$ok = ['name' => ' Planungsbüro ', 'slug' => 'pb', 'groups' => ['team' => 'pb-team']];
		$v = TeamRules::validate($ok);
		$this->assertSame('Planungsbüro', $v['name']);
		$this->assertSame(['team' => 'pb-team'], $v['groups']);
		$this->assertSame('422 invalid', self::code(fn () => TeamRules::validate(['slug' => 'Pb'] + $ok)));
		$this->assertSame('422 invalid', self::code(fn () => TeamRules::validate(['slug' => 'p'] + $ok)));
		$this->assertSame('422 invalid', self::code(fn () => TeamRules::validate(['slug' => str_repeat('a', 33)] + $ok)));
		$this->assertSame('422 invalid', self::code(fn () => TeamRules::validate(['name' => '  '] + $ok)));
	}

	/** Version 2: only `team`; role, account and time groups no longer exist. */
	public function testTeamGroupOnly(): void {
		$with = fn (mixed $groups): array => ['name' => 'T', 'slug' => 'tt', 'groups' => $groups];
		foreach ([[], ['team' => ''], ['team' => 7], ['team' => str_repeat('g', 65)], 'pb-team', null] as $bad) {
			$this->assertSame('422 invalid', self::code(fn () => TeamRules::validate($with($bad))), json_encode($bad));
		}
		foreach (['user', 'lead', 'subadmin', 'admin', 'accounts', 'zeit'] as $old) {
			$this->assertSame('422 invalid', self::code(fn () => TeamRules::validate($with(['team' => 'pb-team', $old => 'x']))), $old);
		}
	}

	public function testTeamSettings(): void {
		$this->assertSame(['backup_required' => false], TeamRules::SETTINGS, 'default values');
		$this->assertSame([], TeamRules::settings(['name' => 'x']), 'missing: unchanged');
		$this->assertSame([], TeamRules::settings(['settings' => null]));
		$this->assertSame([], TeamRules::settings(['settings' => []]));
		$this->assertSame(['backup_required' => true], TeamRules::settings(['settings' => ['backup_required' => true]]));
		// 0.17.0: `leads_see_calendars` (shares matrix) is no longer a setting.
		$this->assertSame('422 invalid', self::code(fn () => TeamRules::settings(['settings' => ['leads_see_calendars' => false]])));
		$this->assertSame('422 invalid', self::code(fn () => TeamRules::settings(['settings' => ['backup_required' => 'true']])));
		$this->assertSame('422 invalid', self::code(fn () => TeamRules::settings(['settings' => ['unbekannt' => true]])));
		$this->assertSame('422 invalid', self::code(fn () => TeamRules::settings(['settings' => [true]])));
		$this->assertSame('422 invalid', self::code(fn () => TeamRules::settings(['settings' => 'an'])));
	}

	public function testTime(): void {
		$this->assertSame('2026-09-26T08:15:00Z', Time::iso(gmmktime(8, 15, 0, 9, 26, 2026)));
		$this->assertNull(Time::iso(null));
		$this->assertTrue(Time::isDay('2028-02-29'));
		$this->assertFalse(Time::isDay('2026-02-29'));
		$this->assertNull(Time::parseIso('2026-02-30T00:00:00Z'));
		$this->assertSame(gmmktime(8, 15, 0, 9, 26, 2026), Time::parseIso('2026-09-26T08:15:00.123Z'));
	}
}
