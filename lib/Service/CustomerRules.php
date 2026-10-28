<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * The customer record (0.20.0), pure: the optional fields the e-invoices
 * and exports read – `e_invoice` (an XRechnung is wanted), `leitweg_id`
 * (German public customers, up to 46 characters) and `datev_account`
 * (the debtor account, digits), the type and payment term (0.27.0) and the
 * free tags. Other fields pass as before.
 */
final class CustomerRules {
	public const LEITWEG_MAX = 46;
	public const ACCOUNT_MAX = 9;
	/** 0.27.0 (controlling): the customer's payment term in days. */
	public const PAYMENT_DAYS_MAX = 365;
	/** The customer's free tags, words without “#” (`arealentwicklung`) to filter and sort the customer list. */
	public const TAGS_FIELD = 'tags';
	public const TAGS_MAX = 30;
	public const TAG_MAX = 40;
	/** 0.29.0: the team's own customer types, `settings.customer_types: [{id, name}]`. */
	public const TYPES_FIELD = 'customer_types';
	public const TYPES_MAX = 50;
	public const TYPE_ID_MAX = 40;
	public const TYPE_NAME_MAX = 60;
	/** A type's key, like a level's: lower-case letters, digits and dashes, 1–40, not starting with a dash. */
	private const TYPE_PATTERN = '/^[a-z0-9][a-z0-9-]{0,39}$/';

	public static function validate(\stdClass $d): void {
		// Since 0.29.0 a key of the team's list; the server does not check it against the list,
		// which may change while customers keep their type.
		$t = $d->type ?? null;
		if ($t !== null && (!is_string($t) || preg_match(self::TYPE_PATTERN, $t) !== 1)) {
			throw ApiException::invalid(Message::of('“type” of a customer must be null or a key of 1 to {max} lower-case letters, digits or dashes.', ['max' => self::TYPE_ID_MAX]));
		}
		$p = $d->payment_days ?? null;
		if ($p !== null && (!is_int($p) || $p < 1 || $p > self::PAYMENT_DAYS_MAX)) {
			throw ApiException::invalid(Message::of('“{field}” must be null or an integer from {min} to {max}.', ['field' => 'payment_days', 'min' => 1, 'max' => self::PAYMENT_DAYS_MAX]));
		}
		$e = $d->e_invoice ?? null;
		if ($e !== null && !is_bool($e)) {
			throw ApiException::notBool('e_invoice');
		}
		$l = $d->leitweg_id ?? null;
		if ($l !== null && (!is_string($l) || $l === '' || strlen($l) > self::LEITWEG_MAX || !preg_match('/^[A-Za-z0-9\-]+$/', $l))) {
			throw ApiException::invalid(Message::of('“{field}” must be text of at most {max} characters.', ['field' => 'leitweg_id', 'max' => self::LEITWEG_MAX]));
		}
		$a = $d->datev_account ?? null;
		if ($a !== null && (!is_string($a) || !preg_match('/^\d{1,' . self::ACCOUNT_MAX . '}$/', $a))) {
			throw ApiException::invalid(Message::of('“{field}” must be up to {max} digits.', ['field' => 'datev_account', 'max' => self::ACCOUNT_MAX]));
		}
		self::validateTags($d->{self::TAGS_FIELD} ?? null);
	}

	/**
	 * The customer's tags: null or a list of at most 30 words of 1–40 characters,
	 * without “#”, space, comma or control characters (the app strips the “#”).
	 *
	 * @throws ApiException 422
	 */
	private static function validateTags(mixed $tags): void {
		if ($tags === null) {
			return;
		}
		if (!is_array($tags) || !array_is_list($tags) || count($tags) > self::TAGS_MAX) {
			throw ApiException::invalid(Message::of('“tags” of a customer must be null or a list of at most {n} words.', ['n' => self::TAGS_MAX]));
		}
		foreach ($tags as $t) {
			if (!is_string($t) || $t === '' || !mb_check_encoding($t, 'UTF-8') || mb_strlen($t) > self::TAG_MAX || preg_match('/[\s\p{Z},#\p{Cc}]/u', $t) === 1) {
				throw ApiException::invalid(Message::of('Each tag of a customer is one word of 1 to {max} characters without “#”, space or comma.', ['max' => self::TAG_MAX]));
			}
		}
	}

	/**
	 * The team's customer types (0.29.0): null or a list of at most 50 `{id, name}` – `id` a key
	 * like a level's, unique; `name` text of 1–60. Null or missing: the team has no list yet
	 * (TimeSister fills a first one from the team's use case).
	 *
	 * @throws ApiException 422
	 */
	public static function validateTypes(mixed $types): void {
		if ($types === null) {
			return;
		}
		if (!is_array($types) || !array_is_list($types) || count($types) > self::TYPES_MAX) {
			throw ApiException::invalid(Message::of('“{field}” must be null or a list of at most {n} customer types { id, name }.', ['field' => self::TYPES_FIELD, 'n' => self::TYPES_MAX]));
		}
		$ids = [];
		foreach ($types as $t) {
			$id = $t instanceof \stdClass ? ($t->id ?? null) : null;
			$name = $t instanceof \stdClass ? ($t->name ?? null) : null;
			if (!is_string($id) || preg_match(self::TYPE_PATTERN, $id) !== 1) {
				throw ApiException::invalid(Message::of('Each customer type needs an “id” of 1 to {max} lower-case letters, digits or dashes.', ['max' => self::TYPE_ID_MAX]));
			}
			if (!is_string($name) || trim($name) === '' || mb_strlen($name) > self::TYPE_NAME_MAX || !mb_check_encoding($name, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
				throw ApiException::invalid(Message::of('Each customer type needs a “name” of 1 to {max} characters.', ['max' => self::TYPE_NAME_MAX]));
			}
			if (isset($ids[$id])) {
				throw ApiException::invalid(Message::of('“{field}” names the customer type {id} twice.', ['field' => self::TYPES_FIELD, 'id' => $id]));
			}
			$ids[$id] = true;
		}
	}

	/** The customer's payment term in days, when set and valid; else null (the request or the default decides). */
	public static function paymentDays(?\stdClass $d): ?int {
		$p = $d?->payment_days ?? null;
		return is_int($p) && $p >= 1 && $p <= self::PAYMENT_DAYS_MAX ? $p : null;
	}
}
