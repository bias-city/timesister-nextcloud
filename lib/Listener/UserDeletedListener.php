<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Listener;

use OCA\TimeSister\Db\BackupConsentMapper;
use OCA\TimeSister\Db\MemberMapper;
use OCA\TimeSister\Service\RecordService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Konto gelöscht: Die Personendatensätze bleiben – an ihnen hängen gebuchte
 * Stunden. Sie bekommen nur den Vermerk `account_deleted_at`; `data`,
 * Fassung und Revision bleiben unverändert. Die Freigabe der Sicherung
 * fällt weg; vorhandene Sicherungen bleiben bis zur Aufbewahrungsfrist.
 * App-Rolle und Austritt fallen weg: Ein neues Konto mit derselben Kennung
 * erbt nichts.
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
final class UserDeletedListener implements IEventListener {
	public function __construct(
		private RecordService $records,
		private BackupConsentMapper $consents,
		private MemberMapper $members,
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
		$n = $this->records->markAccountDeleted($uid);
		if ($n > 0) {
			// Ohne Kennung im Protokoll: keine Personaldaten.
			$this->logger->info('TimeSister: Konto gelöscht, {n} Personendatensätze vermerkt', ['app' => 'timesister', 'n' => $n]);
		}
	}
}
