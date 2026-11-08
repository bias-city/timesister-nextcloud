<?php

declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\PurgeRules;
use PHPUnit\Framework\TestCase;

/** Removing the app's data (0.43.0): options, sentence, the download note and its checks. */
class PurgeRulesTest extends TestCase {
	private static function code(callable $fn): string {
		try {
			$fn();
		} catch (ApiException $e) {
			$d = $e->toData();
			return $e->getStatus() . ' ' . $e->getErrorCode() . (isset($d['reason']) ? ' ' . $d['reason'] : '');
		}
		return 'ok';
	}

	public function testOptionsDefaultsAndCoercion(): void {
		$this->assertSame(['folders' => true, 'vacation' => true, 'time_calendars' => false], PurgeRules::options(null));
		$this->assertSame(['folders' => true, 'vacation' => true, 'time_calendars' => false], PurgeRules::options([]));
		$this->assertSame(['folders' => false, 'vacation' => true, 'time_calendars' => true],
			PurgeRules::options(['folders' => 'false', 'time_calendars' => '1']));
		$o = new \stdClass();
		$o->vacation = false;
		$this->assertSame(['folders' => true, 'vacation' => false, 'time_calendars' => false], PurgeRules::options($o));
	}

	public function testSentenceExactInAnyAcceptedLanguage(): void {
		$accepted = [PurgeRules::SENTENCE, 'Ich will alle Daten der App deinstallieren.'];
		$this->assertSame('ok', self::code(fn () => PurgeRules::checkSentence(PurgeRules::SENTENCE, $accepted)));
		$this->assertSame('ok', self::code(fn () => PurgeRules::checkSentence('  Ich will alle Daten der App deinstallieren. ', $accepted)));
		$this->assertSame('422 confirm', self::code(fn () => PurgeRules::checkSentence('I want to uninstall all data of the app', $accepted)), 'without the full stop');
		$this->assertSame('422 confirm', self::code(fn () => PurgeRules::checkSentence('i want to uninstall all data of the app.', $accepted)), 'case matters');
		$this->assertSame('422 confirm', self::code(fn () => PurgeRules::checkSentence(null, $accepted)));
		$this->assertSame('422 confirm', self::code(fn () => PurgeRules::checkSentence('', [''])), 'an empty accepted form never matches');
	}

	public function testNoteRoundTripAndFreshness(): void {
		$note = PurgeRules::note(str_repeat('a', 64), 1000, 7, 3, ['u1', 'u2'], []);
		$json = json_encode($note, JSON_THROW_ON_ERROR);
		$this->assertSame($note, PurgeRules::noteFrom($json));
		$this->assertNull(PurgeRules::noteFrom(''));
		$this->assertNull(PurgeRules::noteFrom('{"sha256":"x"}'), 'incomplete');
		$this->assertNull(PurgeRules::noteFrom('not json'));
		$this->assertTrue(PurgeRules::fresh($note, 7, 3, 1000 + PurgeRules::NOTE_TTL - 1));
		$this->assertFalse(PurgeRules::fresh($note, 7, 3, 1000 + PurgeRules::NOTE_TTL), 'a day later');
		$this->assertFalse(PurgeRules::fresh($note, 8, 3, 1001), 'a record changed');
		$this->assertFalse(PurgeRules::fresh($note, 7, 4, 1001), 'an entry changed');
		$this->assertFalse(PurgeRules::fresh(null, 7, 3, 1001));
	}

	public function testCheckExport(): void {
		$sha = str_repeat('b', 64);
		$note = PurgeRules::note($sha, 1000, 7, 3, ['u1'], []);
		$this->assertSame('409 conflict export_required', self::code(fn () => PurgeRules::checkExport(null, $sha, 7, 3, 1001)));
		$this->assertSame('409 conflict export_stale', self::code(fn () => PurgeRules::checkExport($note, $sha, 9, 3, 1001)));
		$this->assertSame('409 conflict export_stale', self::code(fn () => PurgeRules::checkExport($note, $sha, 7, 3, 1000 + PurgeRules::NOTE_TTL + 1)));
		$this->assertSame('409 conflict export_token', self::code(fn () => PurgeRules::checkExport($note, str_repeat('c', 64), 7, 3, 1001)));
		$this->assertSame('409 conflict export_token', self::code(fn () => PurgeRules::checkExport($note, null, 7, 3, 1001)));
		$this->assertSame('ok', self::code(fn () => PurgeRules::checkExport($note, $sha, 7, 3, 1001)));
	}

	public function testCalendarsOnlyWhenComplete(): void {
		$complete = PurgeRules::note(str_repeat('b', 64), 1000, 7, 3, ['u1'], []);
		$incomplete = PurgeRules::note(str_repeat('b', 64), 1000, 7, 3, ['u1'], ['u2']);
		$this->assertSame('ok', self::code(fn () => PurgeRules::checkCalendarsComplete($complete)));
		$this->assertSame('409 conflict export_incomplete', self::code(fn () => PurgeRules::checkCalendarsComplete($incomplete)));
		$this->assertSame('ok', self::code(fn () => PurgeRules::checkCalendarsComplete(null)), 'no note: the export check speaks first');
	}

	public function testTeamZipNaming(): void {
		$this->assertTrue(PurgeRules::isTeamZip('studio', 'timesister-studio-2026-11-08_1849.zip'));
		$this->assertFalse(PurgeRules::isTeamZip('studio', 'timesister-other-2026-11-08_1849.zip'));
		$this->assertFalse(PurgeRules::isTeamZip('studio', 'my-notes.zip'));
		$this->assertFalse(PurgeRules::isTeamZip('studio', 'timesister-studio-2026-11-08_1849.zip.bak'));
		$this->assertFalse(PurgeRules::isTeamZip('st.udio', 'timesister-stXudio-2026-11-08_1849.zip'), 'the slug is quoted');
	}
}
