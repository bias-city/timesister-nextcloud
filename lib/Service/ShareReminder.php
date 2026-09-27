<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\AppInfo\Application;
use OCA\TimeSister\Notification\Notifier;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * “Please open TimeSister – your calendar shares have changed.” as a
 * Nextcloud notification to the owner of a time calendar. One per person
 * and team: a new one replaces the old; it goes away once the owner's
 * client has set everything.
 */
final class ShareReminder {
	public function __construct(
		private IManager $notifications,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	public function notify(int $tenantId, string $uid): void {
		try {
			$n = $this->base($tenantId, $uid);
			$this->notifications->markProcessed($n);
			$n->setDateTime(new \DateTime('@' . $this->time->getTime()));
			$this->notifications->notify($n);
		} catch (\Throwable $e) {
			// A reminder that fails must not undo the change; no identifier in the log.
			$this->logger->warning('TimeSister: reminder not sent: ' . $e->getMessage(), ['app' => Application::APP_ID]);
		}
	}

	public function done(int $tenantId, string $uid): void {
		try {
			$this->notifications->markProcessed($this->base($tenantId, $uid));
		} catch (\Throwable $e) {
			$this->logger->warning('TimeSister: reminder not withdrawn: ' . $e->getMessage(), ['app' => Application::APP_ID]);
		}
	}

	private function base(int $tenantId, string $uid): \OCP\Notification\INotification {
		return $this->notifications->createNotification()
			->setApp(Application::APP_ID)
			->setUser($uid)
			->setObject(Notifier::OBJECT, (string)$tenantId)
			->setSubject(Notifier::SUBJECT);
	}
}
