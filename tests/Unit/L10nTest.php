<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Übersetzungen vollständig: jeder Text aus $l->t()/$l->n() steht in
 * l10n/de.json (du) und l10n/de_DE.json (Sie), jeder Schlüssel aus
 * js/admin.js im Wörterbuch von AdminSettings.
 */
class L10nTest extends TestCase {
	private const ROOT = __DIR__ . '/../..';
	private const Q = "'((?:[^'\\\\]|\\\\.)*)'";

	/** @return list<string> Quelltexte in Nextclouds Schreibweise (Plural als _a_::_b_). */
	private static function sources(): array {
		$files = array_merge(
			glob(self::ROOT . '/lib/*/*.php') ?: [],
			glob(self::ROOT . '/templates/*.php') ?: [],
		);
		$out = [];
		foreach ($files as $file) {
			$code = (string)file_get_contents($file);
			preg_match_all('/->t\(' . self::Q . '/', $code, $m);
			foreach ($m[1] as $s) {
				$out[] = stripslashes($s);
			}
			preg_match_all('/->n\(' . self::Q . ',\s*' . self::Q . '/', $code, $m, PREG_SET_ORDER);
			foreach ($m as $pair) {
				$out[] = '_' . stripslashes($pair[1]) . '_::_' . stripslashes($pair[2]) . '_';
			}
		}
		return array_values(array_unique($out));
	}

	/** @return array<string, string|list<string>> */
	private static function translations(string $lang): array {
		$json = json_decode((string)file_get_contents(self::ROOT . "/l10n/$lang.json"), true);
		self::assertIsArray($json);
		self::assertSame('nplurals=2; plural=(n != 1);', $json['pluralForm'] ?? null);
		self::assertIsArray($json['translations'] ?? null);
		return $json['translations'];
	}

	public function testEverySourceTranslated(): void {
		$sources = self::sources();
		$this->assertGreaterThan(20, count($sources));
		foreach (['de', 'de_DE'] as $lang) {
			$tr = self::translations($lang);
			$this->assertSame([], array_values(array_diff($sources, array_keys($tr))), "$lang: fehlt");
			$this->assertSame([], array_values(array_diff(array_keys($tr), $sources)), "$lang: übrig");
			foreach ($tr as $text) {
				// Nextcloud lehnt | ab; ein einzelnes % bräche vsprintf.
				foreach ((array)$text as $t) {
					$this->assertStringNotContainsString('|', $t);
					$this->assertDoesNotMatchRegularExpression('/%(?!n)/', $t);
				}
			}
		}
	}

	public function testDuAndSie(): void {
		$du = self::translations('de');
		$sie = self::translations('de_DE');
		$this->assertDoesNotMatchRegularExpression('/\bSie\b|\bIhr/', implode(' ', array_map(fn ($t) => implode(' ', (array)$t), $du)));
		$this->assertDoesNotMatchRegularExpression('/\b(du|dein\w*)\b/i', implode(' ', array_map(fn ($t) => implode(' ', (array)$t), $sie)));
	}

	public function testJsKeysProvided(): void {
		$php = (string)file_get_contents(self::ROOT . '/lib/Settings/AdminSettings.php');
		preg_match_all("/'([a-z_]+)' => \\\$l->[tn]\\(/", $php, $m);
		$keys = $m[1];
		$js = (string)file_get_contents(self::ROOT . '/js/admin.js');
		preg_match_all("/\\btr\\('([a-z_]+)'\\s*[,)]/", $js, $used);
		$this->assertNotEmpty($used[1]);
		foreach (['user', 'lead', 'subadmin', 'admin'] as $role) {
			$used[1][] = 'role_' . $role;
		}
		$this->assertSame([], array_values(array_diff(array_unique($used[1]), $keys)));
	}
}
