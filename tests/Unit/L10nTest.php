<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\Message;
use OCA\TimeSister\Service\Role;
use OCA\TimeSister\Service\RoleName;
use PHPUnit\Framework\TestCase;

/**
 * Translations complete: every source text – from $l->t()/$l->n() and from
 * messages of ApiException and Message – is in l10n/de.json (informal) and
 * l10n/de_DE.json (formal), and every key of js/admin.js is in the
 * dictionary of AdminSettings.
 */
class L10nTest extends TestCase {
	private const ROOT = __DIR__ . '/../..';
	private const Q = "'((?:[^'\\\\]|\\\\.)*)'";
	private const LANGUAGES = ['de', 'de_DE'];
	/** The factories of ApiException that take a message. */
	private const FACTORIES = '(?:forbidden|notFound|conflict|invalid|badRequest|tooLarge)';

	/** @return list<string> */
	private static function files(): array {
		return array_merge(
			glob(self::ROOT . '/lib/*/*.php') ?: [],
			glob(self::ROOT . '/templates/*.php') ?: [],
		);
	}

	/** @return list<string> source texts as Nextcloud keys them (plural as _a_::_b_) */
	private static function sources(): array {
		$out = [];
		foreach (self::files() as $file) {
			$code = (string)file_get_contents($file);
			preg_match_all('/(?:->t|ApiException::' . self::FACTORIES . '|Message::of)\(\s*' . self::Q . '/', $code, $m);
			foreach ($m[1] as $s) {
				$out[] = stripslashes($s);
			}
			preg_match_all('/(?:->n|Message::plural)\(\s*' . self::Q . ',\s*' . self::Q . '/', $code, $m, PREG_SET_ORDER);
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
		$this->assertGreaterThan(100, count($sources));
		foreach (self::LANGUAGES as $lang) {
			$tr = self::translations($lang);
			$this->assertSame([], array_values(array_diff($sources, array_keys($tr))), "$lang: missing");
			$this->assertSame([], array_values(array_diff(array_keys($tr), $sources)), "$lang: left over");
			foreach ($tr as $source => $text) {
				// Nextcloud rejects |; a lone % would break vsprintf.
				foreach ([$source, ...(array)$text] as $t) {
					$this->assertStringNotContainsString('|', $t);
					$this->assertDoesNotMatchRegularExpression('/%(?!n)/', $t);
				}
				// The same placeholders in source and translation.
				preg_match_all('/\{\w+\}/', $source, $want);
				foreach ((array)$text as $t) {
					preg_match_all('/\{\w+\}/', $t, $got);
					$this->assertEqualsCanonicalizing(array_unique($want[0]), array_unique($got[0]), "$lang: $source");
				}
			}
		}
	}

	/** Messages are literals, so the test above sees every one of them. */
	public function testNoDynamicMessages(): void {
		foreach (self::files() as $file) {
			$code = (string)file_get_contents($file);
			preg_match_all("/ApiException::" . self::FACTORIES . "\\(\\s*+(?!'|Message::|\\))(.{0,40})/", $code, $m);
			$this->assertSame([], $m[1], basename($file));
			// ApiException turns the literals of its factories into a Message.
			if (basename($file) !== 'ApiException.php') {
				preg_match_all("/Message::(?:of|plural)\\(\\s*+(?!')(.{0,40})/", $code, $m);
				$this->assertSame([], $m[1], basename($file));
			}
		}
	}

	public function testDuAndSie(): void {
		$du = self::translations('de');
		$sie = self::translations('de_DE');
		$this->assertDoesNotMatchRegularExpression('/\bSie\b|\bIhr/', implode(' ', array_map(fn ($t) => implode(' ', (array)$t), $du)));
		$this->assertDoesNotMatchRegularExpression('/\b(du|dein\w*)\b/i', implode(' ', array_map(fn ($t) => implode(' ', (array)$t), $sie)));
	}

	/**
	 * Role words: Team Admin, Lead and User in every language, never
	 * translated. Each translation carries exactly the role words of its
	 * source; nothing translates or renames them.
	 */
	public function testRolesSameInEveryLanguage(): void {
		$this->assertSame(['admin' => 'Team Admin', 'lead' => 'Lead', 'user' => 'User'], RoleName::ALL);
		$words = static function (string $t): array {
			preg_match_all('/\b(Team Admin|Lead|User)s?\b/', $t, $m);
			sort($m[1]);
			return $m[1];
		};
		foreach (self::LANGUAGES as $lang) {
			$tr = self::translations($lang);
			foreach (RoleName::ALL as $word) {
				$this->assertArrayNotHasKey($word, $tr, "$lang: role words do not go through the l10n");
			}
			foreach ($tr as $source => $text) {
				foreach ((array)$text as $t) {
					$this->assertSame($words($source), $words($t), "$lang: $source");
				}
			}
			$all = implode(' ', array_map(fn ($t) => implode(' ', (array)$t), $tr));
			$this->assertDoesNotMatchRegularExpression('/Manager|Verwaltung|Leitung|Mitarbeitende|Teamadmin|Team-Admin|(?<!Team |Nextcloud-)\bAdmins?\b/', $all, $lang);
		}
		foreach (self::files() as $file) {
			$this->assertDoesNotMatchRegularExpression('/[\'"]Manager|role_subadmin/', (string)file_get_contents($file), basename($file));
		}
	}

	/** The Mac app uses the same three words (`marke::ROLLEN`), when it is in the same repository. */
	public function testSameRoleWordsAsTheMacApp(): void {
		$marke = self::ROOT . '/../../core/src/marke.rs';
		if (!is_file($marke)) {
			$this->markTestSkipped('Mac app not in this repository');
		}
		preg_match_all('/\("(\w+)", "([^"]+)"\)/', (string)file_get_contents($marke), $m, PREG_SET_ORDER);
		$rust = [];
		foreach ($m as $pair) {
			if (in_array($pair[1], Role::ALL, true)) {
				$rust[$pair[1]] = $pair[2];
			}
		}
		ksort($rust);
		$php = RoleName::ALL;
		ksort($php);
		$this->assertSame($php, $rust);
	}

	public function testMessagesEnglishWithoutL10n(): void {
		$e = new ApiException(422, ApiException::INVALID, Message::of('Write {n}: {message}', [
			'n' => 2,
			'message' => ApiException::missing('data')->getText(),
		]));
		$this->assertSame('Write 2: “data” is missing.', $e->getMessage());
		$this->assertSame(['error' => 'invalid', 'message' => 'Write 2: “data” is missing.'], $e->toData());
		$three = Message::plural('A record was changed in the meantime; nothing was written.', '%n records were changed in the meantime; nothing was written.', 3);
		$this->assertSame('3 records were changed in the meantime; nothing was written.', $three->text());
		$one = Message::plural('A record was changed in the meantime; nothing was written.', '%n records were changed in the meantime; nothing was written.', 1);
		$this->assertSame('A record was changed in the meantime; nothing was written.', $one->text());
		// Values go in as they are, never read as placeholders or formats.
		$this->assertSame('The group “50% {key}” does not exist.', Message::of('The group “{group}” does not exist.', ['group' => '50% {key}'])->text());
	}

	public function testJsKeysProvided(): void {
		$php = (string)file_get_contents(self::ROOT . '/lib/Settings/AdminSettings.php');
		preg_match_all("/'([a-z_]+)' => \\\$l->[tn]\\(/", $php, $m);
		$keys = $m[1];
		$js = (string)file_get_contents(self::ROOT . '/js/admin.js');
		preg_match_all("/\\btr\\('([a-z_]+)'\\s*[,)]/", $js, $used);
		$this->assertNotEmpty($used[1]);
		$this->assertDoesNotMatchRegularExpression("/tr\\('role_(user|lead|admin|subadmin)'/", $js, 'role words come from RoleName, not from tr()');
		$this->assertSame([], array_values(array_diff(array_unique($used[1]), $keys)));
	}
}
