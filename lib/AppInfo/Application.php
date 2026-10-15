<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\AppInfo;

use OCA\TimeSister\Capabilities;
use OCA\TimeSister\Listener\CalendarObjectListener;
use OCA\TimeSister\Listener\GroupDeletedListener;
use OCA\TimeSister\Listener\UserDeletedListener;
use OCA\TimeSister\Notification\Notifier;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Calendar\Events\CalendarObjectCreatedEvent;
use OCP\Calendar\Events\CalendarObjectDeletedEvent;
use OCP\Calendar\Events\CalendarObjectMovedEvent;
use OCP\Calendar\Events\CalendarObjectMovedToTrashEvent;
use OCP\Calendar\Events\CalendarObjectRestoredEvent;
use OCP\Calendar\Events\CalendarObjectUpdatedEvent;
use OCP\Group\Events\GroupDeletedEvent;
use OCP\User\Events\UserDeletedEvent;

// The libraries of the invoice documents (0.19.0: TCPDF, swiss-qr-bill) live
// in vendor/ and come through Composer's autoloader; Nextcloud loads only lib/.
if (is_file(__DIR__ . '/../../vendor/autoload.php')) {
	require_once __DIR__ . '/../../vendor/autoload.php';
}

final class Application extends App implements IBootstrap {
	public const APP_ID = 'timesister';
	/** API version (see API.md). */
	public const API_VERSION = 2;

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerCapability(Capabilities::class);
		$context->registerNotifierService(Notifier::class);
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
		$context->registerEventListener(GroupDeletedEvent::class, GroupDeletedListener::class);
		// The time entries follow the named calendars object by object (0.17.0).
		foreach ([CalendarObjectCreatedEvent::class, CalendarObjectUpdatedEvent::class, CalendarObjectDeletedEvent::class,
			CalendarObjectMovedToTrashEvent::class, CalendarObjectRestoredEvent::class, CalendarObjectMovedEvent::class] as $event) {
			$context->registerEventListener($event, CalendarObjectListener::class);
		}
	}

	public function boot(IBootContext $context): void {
	}
}
