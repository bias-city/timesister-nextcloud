<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Controller\BaseController;
use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\TenantService;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/** 0.9.1: a refused write is throttled, and every write route carries the brute-force attribute. */
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

	public function testRefusedWritesAreThrottled(): void {
		$r = $this->controller('PUT')->go(static fn () => throw ApiException::forbidden());
		$this->assertSame(403, $r->getStatus());
		$this->assertTrue($r->isThrottled());
		$this->assertSame(['action' => BaseController::BRUTE_FORCE_ACTION], $r->getThrottleMetadata());

		$this->assertFalse($this->controller('GET')->go(static fn () => throw ApiException::forbidden())->isThrottled(), 'reads carry no attribute');
		$this->assertFalse($this->controller('PUT')->go(static fn () => throw ApiException::invalid('x'))->isThrottled(), 'only 403');
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
}
