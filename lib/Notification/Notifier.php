<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Notification;

use OCA\TimeSister\AppInfo\Application;
use OCA\TimeSister\Service\JobMessages;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/** Turns the app's notifications into text, in the recipient's language. */
final class Notifier implements INotifier {
	public const OBJECT = 'shares';
	public const SUBJECT = 'shares_changed';
	/** Jobs (0.6.0): object `job`/key, one subject per event ({@see JobMessages}). */
	public const JOB_OBJECT = 'job';
	public const JOB_OFFERED = JobMessages::OFFERED;
	public const JOB_COUNTER = JobMessages::COUNTER;
	public const JOB_COUNTER_ACCEPTED = JobMessages::COUNTER_ACCEPTED;
	public const JOB_COUNTER_REJECTED = JobMessages::COUNTER_REJECTED;
	public const JOB_COUNTER_LAPSED = JobMessages::COUNTER_LAPSED;
	public const JOB_CHANGED = JobMessages::CHANGED;
	public const JOB_RETURNED = JobMessages::RETURNED;
	public const JOB_DELETED = JobMessages::DELETED;

	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $url,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('TimeSister');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}
		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		if ($notification->getSubject() === self::SUBJECT) {
			$notification->setParsedSubject($l->t('Please open TimeSister – your calendar shares have changed.'));
			$notification->setParsedMessage($l->t('Your Team Admin has changed who sees your time calendar. TimeSister sets the shares the next time it syncs.'));
		} else {
			$subject = JobMessages::subject($notification->getSubject(), $notification->getSubjectParameters());
			if ($subject === null) {
				throw new UnknownNotificationException();
			}
			$notification->setParsedSubject($subject->text($l));
			$notification->setParsedMessage(JobMessages::message()->text($l));
		}
		$notification->setIcon($this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app-dark.svg')));
		return $notification;
	}
}
