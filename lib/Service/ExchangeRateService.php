<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\TimeSister\AppInfo\Application;
use OCA\TimeSister\Db\RecordMapper;
use OCA\TimeSister\Db\TenantMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Fetches the ECB rates from Frankfurter (0.30.0, PLAN-SPESEN 9 H) for the
 * teams that switched `settings.exchange_rates` on: per day one request
 * naming only the team's currency, the foreign currencies and the day –
 * no person, amount or receipt. The answer becomes the record
 * `exchange_rate`, written by the server alone.
 */
final class ExchangeRateService {
	public const TIMEOUT = 10;
	/** After this many failures in a row the run ends: the service is not there. */
	public const FAILURES_MAX = 3;
	/** An answer is a small JSON object. */
	private const ANSWER_MAX = 64 * 1024;

	public function __construct(
		private TenantMapper $tenants,
		private RecordMapper $records,
		private RecordService $recordService,
		private Transaction $tx,
		private IClientService $http,
		private IAppConfig $config,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/** The service's address from the app config (default Frankfurter); null when it is no http(s) address. */
	public function baseUrl(): ?string {
		return ExchangeRateRules::baseUrl($this->config->getValueString(Application::APP_ID, ExchangeRateRules::CONFIG_KEY, ExchangeRateRules::DEFAULT_URL));
	}

	/** One run: the days due in every team with the switch on, at most 50 days in all; three failures in a row end it. @return array{days:int,failed:int} */
	public function fetchDue(): array {
		$n = ['days' => 0, 'failed' => 0];
		$base = $this->baseUrl();
		if ($base === null) {
			$this->logger->warning('TimeSister: the app config “exchange_rates_url” is no http(s) address; no exchange rates are fetched', ['app' => 'timesister']);
			return $n;
		}
		$today = gmdate('Y-m-d', $this->time->getTime());
		$left = ExchangeRateRules::DAYS_PER_RUN;
		$inRow = 0;
		foreach ($this->tenants->findAll() as $t) {
			$tid = $t->getId();
			$settings = $this->live($tid, 'setting', 'settings');
			if ($left <= 0 || $inRow >= self::FAILURES_MAX || !ExchangeRateRules::on($settings)) {
				continue;
			}
			$team = ReceiptBooking::teamCurrency($settings);
			if (!ExchangeRateRules::supported($team, $today)) {
				continue;
			}
			$due = ExchangeRateRules::due($this->all($tid, ReceiptRules::KIND), $this->all($tid, ExpenseReportRules::KIND), $team,
				$this->all($tid, ExchangeRateRules::KIND), $today, $left);
			foreach ($due as $day => $currencies) {
				if ($inRow >= self::FAILURES_MAX) {
					break;
				}
				$left--;
				try {
					$data = ExchangeRateRules::fromAnswer($this->get(ExchangeRateRules::url($base, $day, $team, $currencies)), $team, $day, $currencies);
					$this->save($tid, ExchangeRateRules::key($team, $day), $data);
					$n['days']++;
					$inRow = 0;
				} catch (\Throwable $e) {
					// The cause stays in the log; the next run tries again.
					$this->logger->warning('TimeSister: exchange rates of {day} not fetched for team {team}', ['app' => 'timesister', 'day' => $day, 'team' => $tid, 'exception' => $e]);
					$n['failed']++;
					$inRow++;
				}
			}
		}
		return $n;
	}

	/** The decoded answer of one request; an error unless 200 with JSON. */
	private function get(string $url): mixed {
		$r = $this->http->newClient()->get($url, [
			'timeout' => self::TIMEOUT,
			'connect_timeout' => self::TIMEOUT,
			'headers' => ['Accept' => 'application/json', 'User-Agent' => 'TimeSister'],
			// The address is the Nextcloud admin's choice: an own Frankfurter may run in the local network.
			'nextcloud' => ['allow_local_address' => true],
		]);
		if ($r->getStatusCode() !== 200) {
			throw new \RuntimeException('HTTP ' . $r->getStatusCode());
		}
		$body = $r->getBody();
		$text = is_string($body) ? $body : (is_resource($body) ? (string)stream_get_contents($body, self::ANSWER_MAX + 1) : '');
		if (strlen($text) > self::ANSWER_MAX) {
			throw new \RuntimeException('The answer is larger than 64 KB.');
		}
		return json_decode($text, false, 16, JSON_THROW_ON_ERROR);
	}

	/** Writes the day's record as the server, under the team lock. */
	private function save(int $tid, string $key, \stdClass $data): void {
		$this->tx->run(function () use ($tid, $key, $data): bool {
			$top = $this->tenants->bumpRevision($tid, 1);
			$cur = $this->records->findOne($tid, ExchangeRateRules::KIND, $key);
			$v = RecordValidator::validate(ExchangeRateRules::KIND, $key, $data);
			$m = new Membership(DocumentService::ACTOR_SYSTEM, $tid, Role::ADMIN);
			$this->recordService->applyWithin($m, [['kind' => ExchangeRateRules::KIND, 'key' => $key, 'version' => $cur?->getVersion() ?? 0,
				'json' => $v['json'], 'accounts' => $v['accounts']]], false, $top, true);
			return true;
		});
	}

	/** The live records of a kind by key. @return array<string,?\stdClass> */
	private function all(int $tid, string $kind): array {
		$out = [];
		foreach ($this->records->findLiveByKind($tid, $kind) as $r) {
			$out[$r->getRkey()] = InvoiceRules::decode($r->getData());
		}
		return $out;
	}

	private function live(int $tid, string $kind, string $key): ?\stdClass {
		$r = $this->records->findOne($tid, $kind, $key);
		return $r === null || $r->isTombstone() ? null : InvoiceRules::decode($r->getData());
	}
}
