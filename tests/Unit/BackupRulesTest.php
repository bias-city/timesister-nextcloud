<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\BackupRules;
use OCA\TimeSister\Service\TeamRules;
use PHPUnit\Framework\TestCase;

/** Sicherungen 1.1: Woche, Namen, Kalender, Sicherungs-Konto, Freigabe. */
class BackupRulesTest extends TestCase {
	private static function code(callable $fn): string {
		try {
			$fn();
		} catch (ApiException $e) {
			return $e->getStatus() . ' ' . $e->getErrorCode();
		}
		return 'ok';
	}

	public function testIsoWeek(): void {
		// Montag bis Sonntag
		$this->assertSame('2026-W39', BackupRules::isoWeek('2026-09-21'));
		$this->assertSame('2026-W39', BackupRules::isoWeek('2026-09-27'));
		$this->assertSame('2026-W40', BackupRules::isoWeek('2026-09-28'));
		$this->assertTrue(BackupRules::sameIsoWeek('2026-09-21', '2026-09-27'));
		$this->assertFalse(BackupRules::sameIsoWeek('2026-09-27', '2026-09-28'));
		// Jahreswechsel: 2026-12-28 bis 2027-01-03 ist 2026-W53; 2027-01-04 ist 2027-W01
		$this->assertSame('2026-W53', BackupRules::isoWeek('2027-01-03'));
		$this->assertTrue(BackupRules::sameIsoWeek('2026-12-28', '2027-01-03'));
		$this->assertSame('2027-W01', BackupRules::isoWeek('2027-01-04'));
		// 2024-12-30 gehört schon zu 2025-W01
		$this->assertSame('2025-W01', BackupRules::isoWeek('2024-12-30'));
		$this->assertFalse(BackupRules::sameIsoWeek('2024-12-29', '2024-12-30'));
		// gleiche Wochennummer, anderes Jahr
		$this->assertFalse(BackupRules::sameIsoWeek('2025-09-22', '2026-09-21'));
		$this->assertSame('422 invalid', self::code(fn () => BackupRules::isoWeek('2026-02-30')));
	}

	public function testCleanName(): void {
		$this->assertSame('Mia Muster', BackupRules::cleanName('Mia Muster'));
		$this->assertSame('Müller-Meier', BackupRules::cleanName('Müller/Meier'));
		$this->assertSame('a-b-c', BackupRules::cleanName('a\\b/c'));
		$this->assertSame('-..-etc-passwd', BackupRules::cleanName('../../etc/passwd'));
		$this->assertSame('Zeile eins zwei', BackupRules::cleanName("Zeile\neins\r\n\tzwei"));
		$this->assertSame('ab', BackupRules::cleanName("a\u{202E}b"), 'Richtungszeichen');
		$this->assertSame('ab', BackupRules::cleanName("a\u{200B}b"), 'Nullbreite');
		$this->assertSame('x', BackupRules::cleanName("\x00x\x7F"));
		$this->assertSame('versteckt', BackupRules::cleanName('.versteckt.'));
		$this->assertSame('', BackupRules::cleanName(' .. '));
		$this->assertSame(str_repeat('ä', 100), BackupRules::cleanName(str_repeat('ä', 150)));
		$this->assertTrue(mb_check_encoding(BackupRules::cleanName("a\xFFb"), 'UTF-8'), 'ungültiges UTF-8 wird gültig');
		foreach (["x/y", "x\\y", "x\ny", "x\ty", "x\x00y"] as $bad) {
			$clean = BackupRules::cleanName($bad);
			$this->assertDoesNotMatchRegularExpression('#[/\\\\\x00-\x1F\x7F]#', $clean);
		}
	}

	public function testPersonFolder(): void {
		$this->assertSame('Mia Muster (pbuser1)', BackupRules::personFolder('Mia Muster', 'pbuser1'));
		$this->assertSame('pbuser1 (pbuser1)', BackupRules::personFolder('  ', 'pbuser1'));
		$this->assertSame('A-B (x.y@z)', BackupRules::personFolder('A/B', 'x.y@z'));
		$this->assertSame('TimeSister-Sicherungen', BackupRules::VISIBLE_ROOT);
	}

	public function testCalendarUriFromUrl(): void {
		$uri = static fn (?string $url, string $uid = 'pbuser1') => BackupRules::calendarUriFromUrl($url, $uid);
		$this->assertSame('zeit-pbuser1', $uri('http://localhost:8081/remote.php/dav/calendars/pbuser1/zeit-pbuser1/'));
		$this->assertSame('zeit-pbuser1', $uri('https://cloud.example/nc/remote.php/dav/calendars/pbuser1/zeit-pbuser1'));
		$this->assertSame('mein kalender', $uri('https://x/remote.php/dav/calendars/pbuser1/mein%20kalender/'));
		$this->assertSame('zeit-a b', $uri('https://x/remote.php/dav/calendars/a%20b/zeit-a%20b/', 'a b'));
		// fremdes Heim: nie
		$this->assertNull($uri('https://x/remote.php/dav/calendars/pbadmin/zeit-pbadmin/'));
		$this->assertNull($uri('https://x/remote.php/dav/calendars/pbuser1/'));
		$this->assertNull($uri('https://x/remote.php/dav/calendars/pbuser1/a/b/'));
		$this->assertNull($uri('https://x/remote.php/dav/calendars/pbuser1/%2E%2E/'));
		$this->assertNull($uri('https://x/remote.php/dav/calendars/pbuser1/a%2Fb/'));
		$this->assertNull($uri(null));
		$this->assertNull($uri(''));
		$this->assertNull($uri('kein url'));
	}

	public function testPickOwner(): void {
		$this->assertSame('anna', BackupRules::pickOwner(null, ['zora', 'anna']));
		$this->assertSame('zora', BackupRules::pickOwner('zora', ['zora', 'anna']));
		$this->assertSame('anna', BackupRules::pickOwner('weg', ['zora', 'anna']), 'Wahl nicht mehr admin');
		$this->assertNull(BackupRules::pickOwner('zora', []));
		$this->assertSame('B', BackupRules::pickOwner(null, ['a', 'B']), 'nach Kennung, Byte-Ordnung');
	}

	public function testTeamBackupOwnerInput(): void {
		$this->assertSame([false, null], TeamRules::backupOwner(['name' => 'x']));
		$this->assertSame([true, null], TeamRules::backupOwner(['backup_owner' => null]));
		$this->assertSame([true, null], TeamRules::backupOwner(['backup_owner' => '']));
		$this->assertSame([true, 'pbadmin'], TeamRules::backupOwner(['backup_owner' => 'pbadmin']));
		$this->assertSame('422 invalid', self::code(fn () => TeamRules::backupOwner(['backup_owner' => 5])));
		$this->assertSame('422 invalid', self::code(fn () => TeamRules::backupOwner(['backup_owner' => str_repeat('a', 65)])));
	}

	public function testConsentInput(): void {
		$this->assertSame(['consent' => true, 'notice' => '2026-09-26'], BackupRules::consentInput(['consent' => true, 'notice' => '2026-09-26']));
		$this->assertSame(['consent' => true, 'notice' => '2026-09-26.2'], BackupRules::consentInput(['consent' => true, 'notice' => '2026-09-26.2']), 'Fassung 1.2 der Aufklärung');
		$this->assertSame(['consent' => false, 'notice' => null], BackupRules::consentInput(['consent' => false]));
		$this->assertSame(['consent' => false, 'notice' => null], BackupRules::consentInput(['consent' => false, 'notice' => 7]), 'Zurückziehen ohne notice');
		$this->assertSame('422 invalid', self::code(fn () => BackupRules::consentInput(['consent' => true])));
		$this->assertSame('422 invalid', self::code(fn () => BackupRules::consentInput(['consent' => true, 'notice' => ''])));
		$this->assertSame('422 invalid', self::code(fn () => BackupRules::consentInput(['consent' => true, 'notice' => str_repeat('x', 33)])));
		$this->assertSame('ok', self::code(fn () => BackupRules::consentInput(['consent' => true, 'notice' => str_repeat('ü', 32)])));
		$this->assertSame('422 invalid', self::code(fn () => BackupRules::consentInput(['consent' => true, 'notice' => "a\nb"])));
		$this->assertSame('422 invalid', self::code(fn () => BackupRules::consentInput(['consent' => true, 'notice' => 20260926])));
		$this->assertSame('400 invalid', self::code(fn () => BackupRules::consentInput([])));
		$this->assertSame('400 invalid', self::code(fn () => BackupRules::consentInput(['consent' => 'true'])));
		$this->assertSame('400 invalid', self::code(fn () => BackupRules::consentInput(['consent' => 1])));
		$this->assertSame('400 invalid', self::code(fn () => BackupRules::consentInput(['consent' => true, 'notice' => 'v1', 'uid' => 'pbuser1'])), 'nie für ein fremdes Konto');
	}
}
