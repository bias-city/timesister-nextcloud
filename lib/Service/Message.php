<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCP\IL10N;

/**
 * A sentence for people: English source text with {placeholders},
 * translated only when it is shown. Pure apart from the optional IL10N,
 * so the rules stay testable without Nextcloud.
 */
final class Message {
	/**
	 * @param array<string, string|int|Message> $params values for the {placeholders}
	 * @param ?string $plural plural source text; `%n` stands for $count
	 */
	private function __construct(
		private string $source,
		private array $params,
		private ?string $plural = null,
		private int $count = 0,
	) {
	}

	/** @param array<string, string|int|Message> $params */
	public static function of(string $source, array $params = []): self {
		return new self($source, $params);
	}

	/** @param array<string, string|int|Message> $params */
	public static function plural(string $singular, string $plural, int $count, array $params = []): self {
		return new self($singular, $params, $plural, $count);
	}

	/** In the language of `$l`; without it English (logs, tests). */
	public function text(?IL10N $l = null): string {
		if ($this->plural === null) {
			$text = $l === null ? $this->source : $l->t($this->source);
		} elseif ($l === null) {
			$text = str_replace('%n', (string)$this->count, $this->count === 1 ? $this->source : $this->plural);
		} else {
			$text = $l->n($this->source, $this->plural, $this->count);
		}
		// Values go in after translating, so they never pass through vsprintf.
		$vars = [];
		foreach ($this->params as $name => $value) {
			$vars['{' . $name . '}'] = $value instanceof self ? $value->text($l) : (string)$value;
		}
		return strtr($text, $vars);
	}
}
