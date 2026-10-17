<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCP\DB\Exception as DbException;
use OCP\IDBConnection;

/**
 * One database transaction, retried on a deadlock like the record writes
 * (0.18.0 in IssueService, shared since 0.23.0 with the expense reports).
 */
final class Transaction {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * @template T
	 * @param callable():T $fn
	 * @return T
	 */
	public function run(callable $fn): mixed {
		for ($attempt = 1; ; $attempt++) {
			try {
				$this->db->beginTransaction();
				try {
					$out = $fn();
					$this->db->commit();
					return $out;
				} catch (\Throwable $e) {
					if ($this->db->inTransaction()) {
						$this->db->rollBack();
					}
					throw $e;
				}
			} catch (DbException $e) {
				$retry = in_array($e->getReason(), [DbException::REASON_DEADLOCK, DbException::REASON_LOCK_WAIT_TIMEOUT], true);
				if (!$retry || $attempt >= 3) {
					throw $e;
				}
				usleep(50000 * $attempt);
			}
		}
	}
}
