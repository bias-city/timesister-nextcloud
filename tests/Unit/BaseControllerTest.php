<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Controller\BaseController;
use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\TenantService;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/** Every write route carries the brute-force attribute; since 0.45.1 a refused write is not throttled (counted per IP, it held back whole offices). */
class BaseControllerTest extends TestCase {
	private function controller(string $method): BaseController {
		$request = $this->createMock(IRequest::class);
		$request->method('getMethod')->willReturn($method);
		$tenants = (new \ReflectionClass(TenantService::class))->newInstanceWithoutConstructor();
		return new class($request, $tenants, $this->createMock(IL10N::class)) extends BaseController {
			public function go(callable $fn): DataResponse {
				return $this->run($fn);
			}
		};
	}

	public function testRefusedWritesAreNotThrottled(): void {
		foreach (['PUT', 'POST', 'DELETE', 'GET'] as $verb) {
			$r = $this->controller($verb)->go(static fn () => throw ApiException::forbidden());
			$this->assertSame(403, $r->getStatus());
			$this->assertFalse($r->isThrottled(), "$verb: a refusal of a signed-in account is no guessing attempt");
		}
		$this->assertFalse($this->controller('PUT')->go(static fn () => throw ApiException::invalid('x'))->isThrottled());
		$this->assertFalse($this->controller('PUT')->go(static fn () => ['ok' => true])->isThrottled());
	}

	public function testEveryWriteRouteHasBruteForceProtection(): void {
		$checked = 0;
		foreach (glob(__DIR__ . '/../../lib/Controller/*Controller.php') ?: [] as $file) {
			$class = 'OCA\\TimeSister\\Controller\\' . basename($file, '.php');
			foreach ((new \ReflectionClass($class))->getMethods() as $m) {
				foreach ($m->getAttributes(ApiRoute::class) as $a) {
					if (!in_array($a->getArguments()['verb'] ?? '', ['PUT', 'POST', 'DELETE'], true)) {
						continue;
					}
					$bf = $m->getAttributes(BruteForceProtection::class);
					$this->assertCount(1, $bf, "$class::{$m->getName()}");
					$this->assertSame(BaseController::BRUTE_FORCE_ACTION, $bf[0]->getArguments()['action'] ?? null);
					$checked++;
				}
			}
		}
		$this->assertGreaterThan(25, $checked);
	}

	/** Every route is rate-limited per user (0.30.1: writes too); the heavy ones (ledger, verify, weeks, capacity) tighter. */
	public function testEveryRouteHasUserRateLimit(): void {
		$checked = 0;
		foreach (glob(__DIR__ . '/../../lib/Controller/*Controller.php') ?: [] as $file) {
			$class = 'OCA\\TimeSister\\Controller\\' . basename($file, '.php');
			foreach ((new \ReflectionClass($class))->getMethods() as $m) {
				foreach ($m->getAttributes(ApiRoute::class) as $a) {
					$rl = $m->getAttributes(UserRateLimit::class);
					$this->assertCount(1, $rl, "$class::{$m->getName()}");
					$args = $rl[0]->getArguments();
					$this->assertSame(60, $args['period'] ?? null, "$class::{$m->getName()}");
					$limit = $args['limit'] ?? 0;
					$this->assertIsInt($limit, "$class::{$m->getName()}");
					$this->assertTrue($limit > 0 && $limit <= 300, "$class::{$m->getName()}: $limit");
					$checked++;
				}
			}
		}
		$this->assertGreaterThan(30, $checked);
		foreach (['InvoicingController::ledger', 'InvoicingController::verify', 'JobController::weeks', 'JobController::capacity'] as $heavy) {
			[$class, $method] = explode('::', $heavy);
			$rl = (new \ReflectionMethod('OCA\\TimeSister\\Controller\\' . $class, $method))->getAttributes(UserRateLimit::class);
			$this->assertSame(30, $rl[0]->getArguments()['limit'] ?? null, $heavy);
		}
	}
}
