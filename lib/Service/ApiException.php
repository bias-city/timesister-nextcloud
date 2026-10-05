<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCP\IL10N;

/**
 * An error that goes out as an OCS answer with `data = {error, message, …}`.
 *
 * The message is an English source text ({@see Message}); `toData()`
 * translates it into the account's language. Pure (without Nextcloud),
 * so the rules are testable without a server. `getMessage()` is English.
 */
final class ApiException extends \RuntimeException {
	public const NO_TEAM = 'no_team';
	public const AMBIGUOUS_TEAM = 'ambiguous_team';
	public const FORBIDDEN = 'forbidden';
	public const NOT_FOUND = 'not_found';
	public const CONFLICT = 'conflict';
	public const INVALID = 'invalid';
	public const TOO_LARGE = 'too_large';
	public const CONFIRM = 'confirm';

	private Message $text;

	/**
	 * @param 400|403|404|409|413|422 $status
	 * @param array<string,mixed> $extra
	 */
	public function __construct(
		private int $status,
		private string $errorCode,
		Message|string $message,
		private array $extra = [],
	) {
		$this->text = is_string($message) ? Message::of($message) : $message;
		parent::__construct($this->text->text());
	}

	public static function noTeam(): self {
		return new self(403, self::NO_TEAM, Message::of('This account does not belong to any TimeSister team.'));
	}

	public static function ambiguousTeam(): self {
		return new self(409, self::AMBIGUOUS_TEAM, Message::of('This account is in the groups of several teams. An account belongs to exactly one team.'));
	}

	public static function forbidden(Message|string|null $message = null): self {
		return new self(403, self::FORBIDDEN, $message ?? Message::of('You do not have permission for this.'));
	}

	public static function notFound(Message|string|null $message = null): self {
		return new self(404, self::NOT_FOUND, $message ?? Message::of('Not found.'));
	}

	/** @param array<string,mixed> $extra */
	public static function conflict(Message|string $message, array $extra = []): self {
		return new self(409, self::CONFLICT, $message, $extra);
	}

	public static function invalid(Message|string $message): self {
		return new self(422, self::INVALID, $message);
	}

	/** 422 with code `confirm`: the typed confirmation does not match. */
	public static function confirm(Message|string $message): self {
		return new self(422, self::CONFIRM, $message);
	}

	public static function badRequest(Message|string $message): self {
		return new self(400, self::INVALID, $message);
	}

	public static function tooLarge(Message|string $message): self {
		return new self(413, self::TOO_LARGE, $message);
	}

	/** A missing field, e.g. “data” is missing. */
	public static function missing(string $field, bool $badRequest = false): self {
		$m = Message::of('“{field}” is missing.', ['field' => $field]);
		return $badRequest ? self::badRequest($m) : self::invalid($m);
	}

	/** A field with an invalid value. */
	public static function invalidField(string $field, bool $badRequest = false): self {
		$m = Message::of('“{field}” is invalid.', ['field' => $field]);
		return $badRequest ? self::badRequest($m) : self::invalid($m);
	}

	/** A field that must be a boolean. */
	public static function notBool(string $field, bool $badRequest = false): self {
		$m = Message::of('“{field}” must be true or false.', ['field' => $field]);
		return $badRequest ? self::badRequest($m) : self::invalid($m);
	}

	/** `uid` in the body of a call that only applies to the calling account. */
	public static function ownAccountOnly(): self {
		return self::badRequest(Message::of('This only applies to the calling account; “uid” does not belong in the body.'));
	}

	/** @return 400|403|404|409|413|422 */
	public function getStatus(): int {
		return $this->status;
	}

	public function getErrorCode(): string {
		return $this->errorCode;
	}

	public function getText(): Message {
		return $this->text;
	}

	/** @return array<string,mixed> */
	public function getExtra(): array {
		return $this->extra;
	}

	/** @return array<string,mixed> `message` in the language of `$l`, English without it */
	public function toData(?IL10N $l = null): array {
		return ['error' => $this->errorCode, 'message' => $this->text->text($l)] + $this->extra;
	}
}
