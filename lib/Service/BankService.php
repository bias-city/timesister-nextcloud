<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\RecordMapper;
use OCA\TimeSister\Db\TenantMapper;

/**
 * Bank entries (0.40.0, PLAN-BANK §3.2): `POST /bank/entries` merges the
 * entries of one account into the records `bank/<account>.<year>` – an
 * upsert per entry key under the team lock, so two Team Admins importing
 * the same year at once do not overwrite each other; a confirmed entry
 * wins against an unconfirmed one. The records are read like any other
 * (`GET /records`), by Team Admins only.
 */
final class BankService {
	public function __construct(
		private RecordMapper $records,
		private RecordService $recordService,
		private TenantMapper $tenants,
		private Transaction $tx,
	) {
	}

	/**
	 * `{ account, entries: [...] }` → `{ revision, records, saved, unchanged, kept }`.
	 *
	 * @return array<string,mixed>
	 */
	public function entries(Membership $m, \stdClass $body): array {
		BankRules::requireAdmin($m);
		$account = $body->account ?? null;
		if (!is_string($account) || preg_match(BankRules::ACCOUNT_PATTERN, $account) !== 1) {
			throw ApiException::invalidField('account');
		}
		$list = $body->entries ?? null;
		if (!is_array($list) || !array_is_list($list)) {
			throw ApiException::invalidField('entries');
		}
		if (count($list) > BankRules::ENTRIES_MAX) {
			throw ApiException::tooLarge(Message::of('At most {max} entries per call.', ['max' => BankRules::ENTRIES_MAX]));
		}
		// `remove[]` (bank_entries: 3) drops entry keys of one year – `year` names it.
		$remove = BankRules::validateRemove($body->remove ?? null, $account);
		$removeYear = null;
		if ($remove !== []) {
			$y = $body->year ?? null;
			if (!is_int($y) || $y < 1970 || $y > 9999) {
				throw ApiException::invalidField('year');
			}
			$removeYear = $y;
		}
		/** @var array<int, list<\stdClass>> $byYear */
		$byYear = [];
		foreach ($list as $e) {
			BankRules::validateEntry($e, $account);
			$byYear[(int)substr((string)$e->date, 0, 4)][] = $e;
		}
		if ($removeYear !== null && !isset($byYear[$removeYear])) {
			$byYear[$removeYear] = [];
		}
		ksort($byYear);
		return $this->tx->run(function () use ($m, $account, $byYear, $remove, $removeYear): array {
			$tid = $m->tenantId;
			// The team lock first: what is stored cannot change under the merge.
			$this->tenants->bumpRevision($tid, 0);
			$ops = [];
			$saved = 0;
			$unchanged = 0;
			$kept = [];
			$removed = 0;
			$missing = [];
			foreach ($byYear as $year => $incoming) {
				$key = $account . '.' . sprintf('%04d', $year);
				$cur = $this->records->findOne($tid, BankRules::KIND, $key);
				$stored = $cur === null || $cur->isTombstone() ? null : InvoiceRules::decode($cur->getData());
				$dropping = $year === $removeYear ? $remove : [];
				if ($stored === null && $incoming === []) {
					// Nothing to write: only removals of a year the server never had.
					$missing = [...$missing, ...$dropping];
					continue;
				}
				$out = BankRules::mergeYear($stored, $account, $year, $incoming, $dropping);
				$saved += $out['saved'];
				$unchanged += $out['unchanged'];
				$kept = [...$kept, ...$out['kept']];
				$removed += $out['removed'];
				$missing = [...$missing, ...$out['missing']];
				$v = RecordValidator::validate(BankRules::KIND, $key, $out['record']);
				$ops[] = ['kind' => BankRules::KIND, 'key' => $key, 'version' => $cur?->getVersion() ?? 0, 'json' => $v['json'], 'accounts' => $v['accounts']];
			}
			if ($ops === []) {
				return ['revision' => $this->tenants->bumpRevision($tid, 0), 'records' => [], 'saved' => 0, 'unchanged' => 0, 'kept' => [], 'removed' => 0, 'missing' => $missing];
			}
			$top = $this->tenants->bumpRevision($tid, count($ops));
			$written = $this->recordService->applyWithin($m, $ops, false, $top, true);
			return $written + ['saved' => $saved, 'unchanged' => $unchanged, 'kept' => $kept, 'removed' => $removed, 'missing' => $missing];
		});
	}
}
