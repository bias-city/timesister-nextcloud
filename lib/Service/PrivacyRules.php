<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The record `setting/privacy` (0.10.2): what the team's Team Admins or
 * the Nextcloud admin fill in for the privacy notice. Pure.
 *
 * `controller {name, address, contact}`, `privacy_contact`,
 * `hosting {provider, location}`, `retention {time_years, billing_years,
 * note}`, `law` (ch | eu | both) and `authority`. Texts at most 500
 * characters, years 0–30. Missing fields appear as placeholders in the
 * notice, never as a complete-looking document.
 */
final class PrivacyRules {
	public const KEY = 'privacy';
	public const LAWS = ['ch', 'eu', 'both'];
	public const DEFAULT_LAW = 'both';
	public const DEFAULT_TIME_YEARS = 5;
	public const DEFAULT_BILLING_YEARS = 10;
	public const MAX_TEXT = 500;
	public const MAX_YEARS = 30;

	/** Text fields by object: top level, `controller`, `hosting`, `retention`. */
	private const TEXTS = [
		'' => ['privacy_contact', 'authority'],
		'controller' => ['name', 'address', 'contact'],
		'hosting' => ['provider', 'location'],
		'retention' => ['note'],
	];
	private const YEARS = ['time_years', 'billing_years'];

	/** @throws ApiException 422 */
	public static function validate(\stdClass $data): void {
		foreach (get_object_vars($data) as $key => $value) {
			if ($key === 'schema') {
				if (!is_int($value)) {
					throw ApiException::invalid('“schema” must be an integer.');
				}
				continue;
			}
			if ($key === 'law') {
				if (!in_array($value, self::LAWS, true)) {
					throw ApiException::invalid('“law” must be “ch”, “eu” or “both”.');
				}
				continue;
			}
			if (in_array($key, self::TEXTS[''], true)) {
				self::checkText($key, $value);
				continue;
			}
			if (!isset(self::TEXTS[$key])) {
				throw ApiException::invalid(Message::of('Unknown privacy field “{field}”.', ['field' => $key]));
			}
			if (!($value instanceof \stdClass)) {
				throw ApiException::invalid(Message::of('“{field}” must be an object.', ['field' => $key]));
			}
			foreach (get_object_vars($value) as $sub => $v) {
				$path = $key . '.' . $sub;
				if (in_array($sub, self::TEXTS[$key], true)) {
					self::checkText($path, $v);
				} elseif ($key === 'retention' && in_array($sub, self::YEARS, true)) {
					if (!is_int($v) || $v < 0 || $v > self::MAX_YEARS) {
						throw ApiException::invalid(Message::of('“{field}” must be an integer from 0 to {max}.', ['field' => $path, 'max' => self::MAX_YEARS]));
					}
				} else {
					throw ApiException::invalid(Message::of('Unknown privacy field “{field}”.', ['field' => $path]));
				}
			}
		}
	}

	private static function checkText(string $field, mixed $v): void {
		if ($v === null) {
			return;
		}
		if (!is_string($v) || mb_strlen($v) > self::MAX_TEXT || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $v)) {
			throw ApiException::invalid(Message::of('“{field}” must be a text of at most {max} characters.', ['field' => $field, 'max' => self::MAX_TEXT]));
		}
	}

	/**
	 * The settings in full: every text as a trimmed string ('' when
	 * missing), the years with their defaults, the law with its default.
	 *
	 * @return array{controller:array{name:string,address:string,contact:string},privacy_contact:string,hosting:array{provider:string,location:string},retention:array{time_years:int,billing_years:int,note:string},law:string,authority:string}
	 */
	public static function normalize(?\stdClass $data): array {
		$text = static function (mixed $v): string {
			return is_string($v) ? trim($v) : '';
		};
		$years = static function (mixed $v, int $default): int {
			return is_int($v) && $v >= 0 && $v <= self::MAX_YEARS ? $v : $default;
		};
		$c = $data?->controller ?? null;
		$h = $data?->hosting ?? null;
		$r = $data?->retention ?? null;
		$law = $data?->law ?? null;
		return [
			'controller' => [
				'name' => $text($c instanceof \stdClass ? ($c->name ?? null) : null),
				'address' => $text($c instanceof \stdClass ? ($c->address ?? null) : null),
				'contact' => $text($c instanceof \stdClass ? ($c->contact ?? null) : null),
			],
			'privacy_contact' => $text($data?->privacy_contact ?? null),
			'hosting' => [
				'provider' => $text($h instanceof \stdClass ? ($h->provider ?? null) : null),
				'location' => $text($h instanceof \stdClass ? ($h->location ?? null) : null),
			],
			'retention' => [
				'time_years' => $years($r instanceof \stdClass ? ($r->time_years ?? null) : null, self::DEFAULT_TIME_YEARS),
				'billing_years' => $years($r instanceof \stdClass ? ($r->billing_years ?? null) : null, self::DEFAULT_BILLING_YEARS),
				'note' => $text($r instanceof \stdClass ? ($r->note ?? null) : null),
			],
			'law' => is_string($law) && in_array($law, self::LAWS, true) ? $law : self::DEFAULT_LAW,
			'authority' => $text($data?->authority ?? null),
		];
	}
}
