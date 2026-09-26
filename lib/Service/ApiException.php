<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

/**
 * Ein Fehler, der als OCS-Antwort mit `data = {error, message, …}` hinausgeht.
 *
 * Rein (ohne Nextcloud), damit die Prüfungen ohne Server testbar sind.
 */
final class ApiException extends \RuntimeException {
	public const NO_TEAM = 'no_team';
	public const AMBIGUOUS_TEAM = 'ambiguous_team';
	public const FORBIDDEN = 'forbidden';
	public const NOT_FOUND = 'not_found';
	public const CONFLICT = 'conflict';
	public const INVALID = 'invalid';
	public const TOO_LARGE = 'too_large';

	/**
	 * @param 400|403|404|409|413|422 $status
	 * @param array<string,mixed> $extra
	 */
	public function __construct(
		private int $status,
		private string $errorCode,
		string $message,
		private array $extra = [],
	) {
		parent::__construct($message);
	}

	public static function noTeam(): self {
		return new self(403, self::NO_TEAM, 'Dieses Konto gehört zu keinem TimeSister-Team.');
	}

	public static function ambiguousTeam(): self {
		return new self(409, self::AMBIGUOUS_TEAM, 'Dieses Konto steht in den Gruppen mehrerer Teams. Ein Konto gehört zu genau einem Team.');
	}

	public static function forbidden(string $message = 'Dafür fehlt die Berechtigung.'): self {
		return new self(403, self::FORBIDDEN, $message);
	}

	public static function notFound(string $message = 'Nicht gefunden.'): self {
		return new self(404, self::NOT_FOUND, $message);
	}

	/** @param array<string,mixed> $extra */
	public static function conflict(string $message, array $extra = []): self {
		return new self(409, self::CONFLICT, $message, $extra);
	}

	public static function invalid(string $message): self {
		return new self(422, self::INVALID, $message);
	}

	public static function badRequest(string $message): self {
		return new self(400, self::INVALID, $message);
	}

	public static function tooLarge(string $message): self {
		return new self(413, self::TOO_LARGE, $message);
	}

	/** @return 400|403|404|409|413|422 */
	public function getStatus(): int {
		return $this->status;
	}

	public function getErrorCode(): string {
		return $this->errorCode;
	}

	/** @return array<string,mixed> */
	public function getExtra(): array {
		return $this->extra;
	}

	/** @return array<string,mixed> */
	public function toData(): array {
		return ['error' => $this->errorCode, 'message' => $this->getMessage()] + $this->extra;
	}
}
