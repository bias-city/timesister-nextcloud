<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * Webhooks (0.20.0), pure: the team setting `webhooks` (at most ten
 * https addresses with their events and a secret), which ledger action
 * fires which event, the body and the HMAC signature of a delivery and
 * the growing delay between tries. The service (WebhookService) queues
 * and delivers.
 */
final class WebhookRules {
	public const FIELD = 'webhooks';
	public const MAX = 10;
	public const URL_MAX = 512;
	public const SECRET_MAX = 128;
	public const EVENTS = ['issued', 'paid', 'reversed', 'document', 'exported'];
	public const HEADER = 'X-TimeSister-Signature';
	public const MAX_TRIES = 10;
	public const TIMEOUT = 10;
	/** Ledger action → event; actions not listed fire nothing. */
	private const EVENT_OF = [
		LedgerRules::ISSUED => 'issued', LedgerRules::REVERSAL => 'issued', LedgerRules::CREDIT => 'issued',
		LedgerRules::CANCELLED => 'reversed', LedgerRules::PAID => 'paid', LedgerRules::UNPAID => 'paid',
		DocumentRules::ACTION => 'document', ExportRules::EXPORTED => 'exported',
		LedgerRules::EXTERNAL_ISSUED => 'exported', LedgerRules::EXTERNAL_REVOKED => 'exported',
		LedgerRules::EXTERNAL_DOCUMENT => 'document', LedgerRules::EXTERNAL_CANCELLED => 'reversed',
	];

	// ----------------------------------------------------------- settings

	/**
	 * The stored hooks (JSON, null = none).
	 *
	 * @return list<array{url:string,events:list<string>,secret:?string}>
	 */
	public static function hooks(?string $json): array {
		$v = $json === null || $json === '' ? null : json_decode($json, true);
		/** @var list<array{url:string,events:list<string>,secret:?string}> $out */
		$out = [];
		foreach (is_array($v) ? $v : [] as $h) {
			if (!is_array($h) || !is_string($h['url'] ?? null)) {
				continue;
			}
			/** @var list<string> $events */
			$events = [];
			foreach (is_array($h['events'] ?? null) ? $h['events'] : [] as $e) {
				if (is_string($e) && in_array($e, self::EVENTS, true)) {
					$events[] = $e;
				}
			}
			$secret = $h['secret'] ?? null;
			$out[] = ['url' => $h['url'], 'events' => $events, 'secret' => is_string($secret) && $secret !== '' ? $secret : null];
		}
		return $out;
	}

	/**
	 * `webhooks` from a body: a list of at most ten `{ url, events, secret }`;
	 * only https, known events, the secret up to 128 characters. A hook
	 * without `secret` keeps the stored secret of the same address.
	 *
	 * @param list<array{url:string,events:list<string>,secret:?string}> $current
	 * @return list<array{url:string,events:list<string>,secret:?string}>
	 */
	public static function validate(mixed $v, array $current): array {
		if ($v === null) {
			return [];
		}
		if (!is_array($v) || !array_is_list($v) || count($v) > self::MAX) {
			throw ApiException::invalid(Message::of('“{field}” must be a list of at most {max} webhooks.', ['field' => self::FIELD, 'max' => self::MAX]));
		}
		$stored = [];
		foreach ($current as $h) {
			$stored[$h['url']] = $h['secret'];
		}
		$out = [];
		$seen = [];
		foreach ($v as $i => $h) {
			if ($h instanceof \stdClass) {
				$h = get_object_vars($h);
			}
			$n = $i + 1;
			if (!is_array($h)) {
				throw ApiException::invalid(Message::of('Webhook {n}: must be an object with “url”, “events” and “secret”.', ['n' => $n]));
			}
			$url = $h['url'] ?? null;
			if (!is_string($url) || strlen($url) > self::URL_MAX || !self::isHttps($url)) {
				throw ApiException::invalid(Message::of('Webhook {n}: “url” must be an https address of at most {max} characters.', ['n' => $n, 'max' => self::URL_MAX]));
			}
			if (isset($seen[$url])) {
				throw ApiException::invalid(Message::of('Webhook {n}: the address is listed twice.', ['n' => $n]));
			}
			$seen[$url] = true;
			$events = $h['events'] ?? null;
			if (!is_array($events) || !array_is_list($events) || $events === []) {
				throw ApiException::invalid(Message::of('Webhook {n}: “events” must be a list of {events}.', ['n' => $n, 'events' => implode(', ', self::EVENTS)]));
			}
			$list = [];
			foreach ($events as $e) {
				if (!is_string($e) || !in_array($e, self::EVENTS, true)) {
					throw ApiException::invalid(Message::of('Webhook {n}: “events” must be a list of {events}.', ['n' => $n, 'events' => implode(', ', self::EVENTS)]));
				}
				$list[$e] = true;
			}
			$secret = $h['secret'] ?? null;
			if ($secret !== null && $secret !== '' && (!is_string($secret) || mb_strlen($secret) > self::SECRET_MAX || preg_match('/[\x00-\x1F\x7F]/', $secret))) {
				throw ApiException::invalid(Message::of('Webhook {n}: “secret” must be text of at most {max} characters.', ['n' => $n, 'max' => self::SECRET_MAX]));
			}
			if (!is_string($secret) || $secret === '') {
				$secret = $stored[$url] ?? null;
			}
			$out[] = ['url' => $url, 'events' => array_keys($list), 'secret' => $secret];
		}
		return $out;
	}

	/** An absolute https address with a host. */
	public static function isHttps(string $url): bool {
		$p = parse_url($url);
		if (!is_array($p) || isset($p['user']) || isset($p['pass'])) {
			return false;
		}
		$host = $p['host'] ?? '';
		return strtolower($p['scheme'] ?? '') === 'https' && $host !== '';
	}

	/** The hooks (as {@see validate()} returns them) as stored (JSON), null when there are none. */
	public static function encode(array $hooks): ?string {
		return $hooks === [] ? null : json_encode($hooks, Json::FLAGS);
	}

	/**
	 * The hooks as the API shows them: the secret only as `secret_set`.
	 *
	 * @param list<array{url:string,events:list<string>,secret:?string}> $hooks
	 * @return list<array{url:string,events:list<string>,secret_set:bool}>
	 */
	public static function present(array $hooks): array {
		/** @var list<array{url:string,events:list<string>,secret_set:bool}> $out */
		$out = [];
		foreach ($hooks as $h) {
			$out[] = ['url' => $h['url'], 'events' => $h['events'], 'secret_set' => $h['secret'] !== null];
		}
		return $out;
	}

	// ----------------------------------------------------------- delivery

	/** The event a ledger action fires, or null. */
	public static function eventOf(string $action): ?string {
		return self::EVENT_OF[$action] ?? null;
	}

	/**
	 * The body of a delivery; `total` (0.21.0) is what the customer pays,
	 * gross when not given.
	 *
	 * @param array{key:string,number:string,status:string,gross:float,currency:string,total?:float} $invoice
	 */
	public static function body(string $team, string $event, string $action, string $at, array $invoice, int $seq, string $hash): string {
		return json_encode([
			'team' => $team, 'event' => $event, 'at' => $at,
			'invoice' => ['key' => $invoice['key'], 'number' => $invoice['number'], 'status' => $invoice['status'], 'gross' => $invoice['gross'], 'total' => $invoice['total'] ?? $invoice['gross'], 'currency' => $invoice['currency']],
			'ledger' => ['seq' => $seq, 'hash' => $hash, 'action' => $action],
		], Json::FLAGS);
	}

	/** `sha256=<HMAC-SHA256(secret, body)>`, hex lower case. */
	public static function signature(string $secret, string $body): string {
		return 'sha256=' . hash_hmac('sha256', $body, $secret);
	}

	/** Seconds until the next try after `$tries` failed ones: 1, 2, 4 … minutes, at most a day. */
	public static function delay(int $tries): int {
		return min(86400, 60 * (1 << min(20, max(0, $tries - 1))));
	}

	/** The state of a queue row: pending, delivered or failed. */
	public static function state(?int $deliveredAt, int $tries): string {
		if ($deliveredAt !== null) {
			return 'delivered';
		}
		return $tries >= self::MAX_TRIES ? 'failed' : 'pending';
	}

	/** Is the answer a success (2xx)? */
	public static function delivered(int $status): bool {
		return $status >= 200 && $status < 300;
	}
}
