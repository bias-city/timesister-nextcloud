<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCP\IL10N;

/** An IL10N without Nextcloud: English source texts, as the server would answer in English. */
final class FakeL10n implements IL10N {
	public function t(string $text, $parameters = []): string {
		return $parameters === [] ? $text : vsprintf($text, (array)$parameters);
	}

	public function n(string $text_singular, string $text_plural, int $count, array $parameters = []): string {
		return str_replace('%n', (string)$count, $count === 1 ? $text_singular : $text_plural);
	}

	public function l(string $type, $data, array $options = []): string {
		return is_string($data) ? $data : '';
	}

	public function getLanguageCode(): string {
		return 'en';
	}

	public function getLocaleCode(): string {
		return 'en';
	}
}
