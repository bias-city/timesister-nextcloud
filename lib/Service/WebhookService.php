<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\Db\InvInvoiceMapper;
use OCA\TimeSister\Db\InvLedger;
use OCA\TimeSister\Db\TenantMapper;
use OCA\TimeSister\Db\WebhookQueue;
use OCA\TimeSister\Db\WebhookQueueMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Webhooks (0.20.0): after every ledger entry of a listed event one queue
 * row per hook; the job WebhookDelivery posts the body with the HMAC
 * signature, up to ten tries with a growing delay; Team Admins read the
 * state per hook. Queueing never throws into the caller's transaction;
 * delivery never blocks issuing.
 */
final class WebhookService {
	private const STATUS_ROWS = 2000;
	private const ERROR_MAX = 500;

	public function __construct(
		private WebhookQueueMapper $queue,
		private TenantMapper $tenants,
		private TenantService $team,
		private InvInvoiceMapper $invoices,
		private IClientService $clients,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/** After a ledger entry: one row per hook that lists the entry's event. Logs instead of throwing. */
	public function enqueue(int $tenantId, InvLedger $entry, int $now): void {
		try {
			$event = WebhookRules::eventOf($entry->getAction());
			if ($event === null) {
				return;
			}
			$t = $this->tenants->find($tenantId);
			$hooks = $t === null ? [] : array_values(array_filter(WebhookRules::hooks($t->getWebhooksJson()), static fn (array $h): bool => in_array($event, $h['events'], true)));
			if ($hooks === [] || $t === null) {
				return;
			}
			$row = $this->invoices->findById($tenantId, $entry->getInvoiceId());
			// 0.24.0: a hand-over has no frozen row; key and currency come from its payload.
			$p = $row === null ? json_decode((string)$entry->getPayload(), true) : null;
			$p = is_array($p) ? $p : [];
			$invoice = [
				'key' => $row?->getRkey() ?? (is_string($p['key'] ?? null) ? $p['key'] : ''), 'number' => $entry->getNumber(),
				'status' => $row?->getStatus() ?? HandoverRules::statusAfter($entry->getAction()),
				'gross' => (float)($row?->getGross() ?? '0'), 'currency' => $row?->getCurrency() ?? (is_string($p['currency'] ?? null) ? $p['currency'] : ''),
				'total' => $row === null ? 0.0 : InvoiceFixed::sumsOf($row)->total,
			];
			$body = WebhookRules::body($t->getSlug(), $event, $entry->getAction(), $entry->getAt(), $invoice, $entry->getSeq(), $entry->getHash());
			foreach ($hooks as $h) {
				$q = new WebhookQueue();
				$q->setTenantId($tenantId);
				$q->setLedgerSeq($entry->getSeq());
				$q->setUrl($h['url']);
				$q->setEvent($event);
				$q->setBody($body);
				$q->setTries(0);
				$q->setNextAt($now);
				$q->setCreatedAt($now);
				$this->queue->insert($q);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('TimeSister: webhook not queued for ledger entry {seq}: {message}', ['app' => 'timesister', 'seq' => $entry->getSeq(), 'message' => $e->getMessage()]);
		}
	}

	/**
	 * The job: every due delivery, up to `$limit`.
	 *
	 * @return array{delivered:int,failed:int}
	 */
	public function deliver(int $limit): array {
		$n = ['delivered' => 0, 'failed' => 0];
		$now = $this->time->getTime();
		$hooksOf = [];
		foreach ($this->queue->findDue($now, WebhookRules::MAX_TRIES, $limit) as $q) {
			$tid = $q->getTenantId();
			if (!isset($hooksOf[$tid])) {
				$t = $this->tenants->find($tid);
				$hooksOf[$tid] = $t === null ? [] : WebhookRules::hooks($t->getWebhooksJson());
			}
			$hook = null;
			foreach ($hooksOf[$tid] as $h) {
				if ($h['url'] === $q->getUrl()) {
					$hook = $h;
				}
			}
			$q->setTries($q->getTries() + 1);
			if ($hook === null) {
				// Removed from the settings meanwhile: give it up.
				$q->setTries(WebhookRules::MAX_TRIES);
				$q->setLastError('The webhook is no longer configured.');
				$q->setNextAt($now);
				$this->queue->update($q);
				$n['failed']++;
				continue;
			}
			$error = $this->post($q, $hook['secret']);
			if ($error === null) {
				$q->setDeliveredAt($now);
				$q->setLastError(null);
				$n['delivered']++;
			} else {
				$q->setLastError(mb_substr($error, 0, self::ERROR_MAX));
				$q->setNextAt($now + WebhookRules::delay($q->getTries()));
				$n['failed']++;
			}
			$this->queue->update($q);
		}
		return $n;
	}

	/** One POST; null on 2xx, else the error. */
	private function post(WebhookQueue $q, ?string $secret): ?string {
		$body = (string)$q->getBody();
		$headers = ['Content-Type' => 'application/json', 'User-Agent' => 'TimeSister'];
		if ($secret !== null) {
			$headers[WebhookRules::HEADER] = WebhookRules::signature($secret, $body);
		}
		try {
			$r = $this->clients->newClient()->post($q->getUrl(), [
				'body' => $body, 'headers' => $headers, 'timeout' => WebhookRules::TIMEOUT, 'connect_timeout' => WebhookRules::TIMEOUT,
			]);
			$status = $r->getStatusCode();
			return WebhookRules::delivered($status) ? null : 'HTTP ' . $status;
		} catch (\Throwable $e) {
			// The cause (address, TLS, timeout) stays in the log; the status shows a plain text.
			$this->logger->warning('TimeSister: webhook not delivered to {url}', ['app' => 'timesister', 'url' => $q->getUrl(), 'exception' => $e]);
			return Message::of('The delivery failed; see the server log.')->text();
		}
	}

	/**
	 * `GET /invoices/webhooks/status` – Team Admins: per hook, how many
	 * deliveries wait, were delivered or failed, the last error and the
	 * last delivery.
	 *
	 * @return array{hooks:list<array<string,mixed>>}
	 */
	public function status(Membership $m): array {
		$t = $this->team->tenant($m->tenantId);
		if (!$t->invoicingOn()) {
			throw ApiException::notFound('Invoicing on the server is off for this team.');
		}
		if (!$m->manages()) {
			throw ApiException::forbidden('Only Team Admins read the webhook status.');
		}
		$out = [];
		foreach (WebhookRules::hooks($t->getWebhooksJson()) as $h) {
			$out[$h['url']] = ['url' => $h['url'], 'events' => $h['events'], 'secret_set' => $h['secret'] !== null,
				'pending' => 0, 'delivered' => 0, 'failed' => 0, 'last_error' => null, 'last_delivered_at' => null, 'last_at' => null];
		}
		foreach ($this->queue->findByTenant($m->tenantId, self::STATUS_ROWS) as $q) {
			$url = $q->getUrl();
			if (!isset($out[$url])) {
				$out[$url] = ['url' => $url, 'events' => [], 'secret_set' => false, 'pending' => 0, 'delivered' => 0, 'failed' => 0, 'last_error' => null, 'last_delivered_at' => null, 'last_at' => null];
			}
			$state = WebhookRules::state($q->getDeliveredAt(), $q->getTries());
			$out[$url][$state]++;
			// Newest first: the first error and delivery seen are the latest.
			if ($out[$url]['last_at'] === null) {
				$out[$url]['last_at'] = LedgerRules::at($q->getCreatedAt());
			}
			if ($out[$url]['last_error'] === null && $q->getLastError() !== null && $q->getDeliveredAt() === null) {
				$out[$url]['last_error'] = $q->getLastError();
			}
			if ($out[$url]['last_delivered_at'] === null && $q->getDeliveredAt() !== null) {
				$out[$url]['last_delivered_at'] = LedgerRules::at($q->getDeliveredAt());
			}
		}
		return ['hooks' => array_values($out)];
	}
}
