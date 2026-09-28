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
 * Nextcloud notifications about Jobs: new offer, counter-proposal and its
 * answer, change, return, deletion. One per person and Job: a new one
 * replaces the older; they go away once the person has answered.
 */
final class JobNotifications {
	public function __construct(
		private IManager $notifications,
		private ITimeFactory $time,
		private TenantService $tenants,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Tell `$uids` about the Job; `$actor` did it.
	 *
	 * @param array<string,mixed> $job
	 * @param list<string> $uids
	 */
	public function send(string $subject, array $job, array $uids, string $actor): void {
		$params = [
			'title' => mb_substr(self::title($job), 0, 120),
			'user' => $this->tenants->displayName($actor),
		];
		foreach (array_unique($uids) as $uid) {
			if ($uid === $actor || $uid === '') {
				continue;
			}
			try {
				$this->notifications->markProcessed($this->base($uid, (string)$job['id']));
				$n = $this->base($uid, (string)$job['id'])
					->setSubject($subject, $params)
					->setDateTime(new \DateTime('@' . $this->time->getTime()));
				$this->notifications->notify($n);
			} catch (\Throwable $e) {
				// A notification that fails must not undo the Job; no identifier in the log.
				$this->logger->warning('TimeSister: Job notification not sent: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			}
		}
	}

	/**
	 * Withdraw what `$uids` were told about this Job.
	 *
	 * @param list<string> $uids
	 */
	public function clear(string $key, array $uids): void {
		foreach (array_unique($uids) as $uid) {
			try {
				$this->notifications->markProcessed($this->base($uid, $key));
			} catch (\Throwable $e) {
				$this->logger->warning('TimeSister: Job notification not withdrawn: ' . $e->getMessage(), ['app' => Application::APP_ID]);
			}
		}
	}

	/** The title for sentences: title, else the code. @param array<string,mixed> $job */
	public static function title(array $job): string {
		$t = $job['title'] ?? null;
		return is_string($t) && $t !== '' ? $t : (string)($job['code'] ?? $job['id'] ?? '');
	}

	private function base(string $uid, string $key): \OCP\Notification\INotification {
		return $this->notifications->createNotification()
			->setApp(Application::APP_ID)
			->setUser($uid)
			->setObject(Notifier::JOB_OBJECT, $key);
	}
}
