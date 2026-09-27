<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Notification;

use OCA\TimeSister\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/** Turns the app's notifications into text, in the recipient's language. */
final class Notifier implements INotifier {
	public const OBJECT = 'shares';
	public const SUBJECT = 'shares_changed';

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
		if ($notification->getApp() !== Application::APP_ID || $notification->getSubject() !== self::SUBJECT) {
			throw new UnknownNotificationException();
		}
		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$notification->setParsedSubject($l->t('Please open TimeSister – your calendar shares have changed.'));
		$notification->setParsedMessage($l->t('Your Team Admin has changed who sees your time calendar. TimeSister sets the shares the next time it syncs.'));
		$notification->setIcon($this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app-dark.svg')));
		return $notification;
	}
}
