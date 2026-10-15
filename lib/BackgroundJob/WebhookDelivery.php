<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\BackgroundJob;

use OCA\TimeSister\Service\WebhookService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Every 5 minutes: the webhook deliveries that are due are posted, up to
 * ten tries each with a growing delay (0.20.0). The service keeps the
 * error per row.
 */
final class WebhookDelivery extends TimedJob {
	public const MAX_PER_RUN = 100;

	public function __construct(
		ITimeFactory $time,
		private WebhookService $webhooks,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(300);
		$this->setTimeSensitivity(IJob::TIME_SENSITIVE);
	}

	protected function run(mixed $argument): void {
		$n = $this->webhooks->deliver(self::MAX_PER_RUN);
		if ($n['delivered'] + $n['failed'] > 0) {
			$this->logger->info('TimeSister: webhooks – {delivered} delivered, {failed} failed', ['app' => 'timesister', 'delivered' => $n['delivered'], 'failed' => $n['failed']]);
		}
	}
}
