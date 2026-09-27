<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Listener;

use OCA\TimeSister\Db\AccessMapper;
use OCA\TimeSister\Db\BackupConsentMapper;
use OCA\TimeSister\Db\MemberMapper;
use OCA\TimeSister\Service\RecordService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Account deleted: the person records stay – booked hours are attached to
 * them. They only get the `account_deleted_at` note; `data`, version and
 * revision stay unchanged. Backup consent is dropped; existing backups stay
 * until the retention period ends. App role, leaving date and shares are
 * dropped: a new account with the same identifier inherits nothing.
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
final class UserDeletedListener implements IEventListener {
	public function __construct(
		private RecordService $records,
		private BackupConsentMapper $consents,
		private MemberMapper $members,
		private AccessMapper $access,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof UserDeletedEvent)) {
			return;
		}
		$uid = $event->getUser()->getUID();
		$this->consents->deleteByUid($uid);
		$this->members->deleteByUid($uid);
		$this->access->deleteByUid($uid);
		$n = $this->records->markAccountDeleted($uid);
		if ($n > 0) {
			// No identifier in the log: no personal data.
			$this->logger->info('TimeSister: account deleted, {n} person record(s) marked', ['app' => 'timesister', 'n' => $n]);
		}
	}
}
