<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\Tenant;
use Psr\Log\LoggerInterface;

/**
 * A new team from a team ZIP (0.10.2): the team is created the usual way
 * (name, short name, team group), then the kept upload is imported into
 * it with the mapping (`merge` into the empty team). Fails the import,
 * the empty team is removed again and the message goes out.
 *
 * Not final: the unit test replaces the three steps that need Nextcloud.
 *
 * @psalm-suppress ClassMustBeFinal
 */
class TeamFromZipService {
	public function __construct(
		private TeamAdminService $admin,
		private TeamImportService $import,
		private TeamDeleteService $deleter,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param array<string,mixed> $in `token`, `name`, `slug`, `group`, `mapping`
	 * @return array<string,mixed> `{ team, import }`
	 * @throws ApiException 409 when the short name or group is taken (before anything is created)
	 */
	public function create(string $actor, array $in): array {
		$token = ZipRules::checkToken($in['token'] ?? null);
		$t = $this->createTeam(['name' => $in['name'] ?? null, 'slug' => $in['slug'] ?? null, 'groups' => [Role::TEAM_GROUP => $in['group'] ?? null]]);
		try {
			$result = $this->runImport($t, $actor, ['token' => $token, 'mapping' => $in['mapping'] ?? null, 'mode' => ZipRules::MODE_MERGE]);
		} catch (\Throwable $e) {
			$this->rollback($t);
			throw $this->failure($e);
		}
		return ['team' => $this->present($t), 'import' => $result];
	}

	/** The team as POST /admin/teams creates it; 409 on a taken short name or group. */
	protected function createTeam(array $in): Tenant {
		$created = $this->admin->create($in);
		return $this->admin->find((int)$created['id']);
	}

	/**
	 * @param array<string,mixed> $in
	 * @return array<string,mixed>
	 */
	protected function runImport(Tenant $t, string $actor, array $in): array {
		return $this->import->import($t, $actor, $in);
	}

	/** The empty team goes again; a failure here is only logged. */
	protected function rollback(Tenant $t): void {
		try {
			$this->deleter->delete($t, $t->getName());
		} catch (\Throwable $e) {
			$this->logger->error('TimeSister: new team {team} not removed after a failed import', ['app' => 'timesister', 'team' => $t->getId(), 'exception' => $e]);
		}
	}

	/** @return array<string,mixed> */
	protected function present(Tenant $t): array {
		return $this->admin->present($this->admin->find($t->getId()));
	}

	/** The import's message, with the note that the team is gone again; anything else without details. */
	private function failure(\Throwable $e): ApiException {
		if ($e instanceof ApiException) {
			return new ApiException($e->getStatus(), $e->getErrorCode(), Message::of('The import failed and the new team was removed again: {message}', ['message' => $e->getText()]), $e->getExtra());
		}
		$this->logger->error('TimeSister: import into a new team failed', ['app' => 'timesister', 'exception' => $e]);
		return ApiException::conflict('The import failed and the new team was removed again.');
	}
}
