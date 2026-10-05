<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Db\Tenant;
use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\TeamFromZipService;
use PHPUnit\Framework\TestCase;

/**
 * A new team from a team ZIP (0.10.2): the token first, a taken short
 * name stops everything before anything is created, a failed import
 * removes the new team again and carries the message.
 */
class TeamFromZipTest extends TestCase {
	private \ArrayObject $steps;

	protected function setUp(): void {
		$this->steps = new \ArrayObject();
	}

	private const TOKEN = '0123456789abcdef0123456789abcdef';

	/** The service with the three steps that need Nextcloud replaced; `$fail` says which one throws what. */
	private function service(?string $failCreate, ?\Throwable $failImport): TeamFromZipService {
		$args = [];
		foreach ((new \ReflectionClass(TeamFromZipService::class))->getConstructor()?->getParameters() ?? [] as $p) {
			$type = $p->getType();
			$this->assertInstanceOf(\ReflectionNamedType::class, $type);
			$name = $type->getName();
			$args[] = interface_exists($name) ? $this->createMock($name) : (new \ReflectionClass($name))->newInstanceWithoutConstructor();
		}
		return new class($args, $failCreate, $failImport, $this->steps) extends TeamFromZipService {
			/** @param list<mixed> $args */
			public function __construct(array $args, private ?string $failCreate, private ?\Throwable $failImport, private \ArrayObject $steps) {
				parent::__construct(...$args);
			}

			protected function createTeam(array $in): Tenant {
				$this->steps->append('create ' . json_encode($in));
				if ($this->failCreate !== null) {
					throw ApiException::conflict($this->failCreate);
				}
				$t = new Tenant();
				$t->setId(77);
				$t->setName((string)$in['name']);
				$t->setSlug((string)$in['slug']);
				return $t;
			}

			protected function runImport(Tenant $t, string $actor, array $in): array {
				$this->steps->append('import ' . $t->getId() . ' ' . $actor . ' ' . json_encode($in));
				if ($this->failImport !== null) {
					throw $this->failImport;
				}
				return ['records' => ['inserted' => 3]];
			}

			protected function rollback(Tenant $t): void {
				$this->steps->append('rollback ' . $t->getId());
			}

			protected function present(Tenant $t): array {
				return ['id' => $t->getId(), 'name' => $t->getName(), 'slug' => $t->getSlug()];
			}
		};
	}

	private function in(array $over = []): array {
		return $over + ['token' => self::TOKEN, 'name' => 'Atelier Kopie', 'slug' => 'zzk', 'group' => 'zz', 'mapping' => ['atadmin' => 'zzadmin', 'atuser1' => null]];
	}

	public function testCreatesThenImportsWithMerge(): void {
		$r = $this->service(null, null)->create('admin', $this->in());
		$this->assertSame(['id' => 77, 'name' => 'Atelier Kopie', 'slug' => 'zzk'], $r['team']);
		$this->assertSame(['records' => ['inserted' => 3]], $r['import']);
		$this->assertSame([
			'create {"name":"Atelier Kopie","slug":"zzk","groups":{"team":"zz"}}',
			'import 77 admin {"token":"' . self::TOKEN . '","mapping":{"atadmin":"zzadmin","atuser1":null},"mode":"merge"}',
		], $this->steps->getArrayCopy());
	}

	public function testBadTokenStopsBeforeCreating(): void {
		try {
			$this->service(null, null)->create('admin', $this->in(['token' => 'nope']));
			$this->fail('400 expected');
		} catch (ApiException $e) {
			$this->assertSame(400, $e->getStatus());
		}
		$this->assertSame([], $this->steps->getArrayCopy());
	}

	public function testTakenShortNameIs409BeforeAnythingElse(): void {
		try {
			$this->service('Another team already has this short name.', null)->create('admin', $this->in());
			$this->fail('409 expected');
		} catch (ApiException $e) {
			$this->assertSame(409, $e->getStatus());
			$this->assertSame('Another team already has this short name.', $e->getMessage());
		}
		$this->assertCount(1, $this->steps);
		$this->assertStringStartsWith('create ', (string)$this->steps[0]);
	}

	public function testFailedImportRollsBackAndKeepsTheMessage(): void {
		$inner = ApiException::invalid('Record “person/x” of the backup: broken');
		try {
			$this->service(null, $inner)->create('admin', $this->in());
			$this->fail('422 expected');
		} catch (ApiException $e) {
			$this->assertSame(422, $e->getStatus());
			$this->assertSame('invalid', $e->getErrorCode());
			$this->assertSame('The import failed and the new team was removed again: Record “person/x” of the backup: broken', $e->getMessage());
		}
		$this->assertSame(['create', 'import', 'rollback 77'], array_map(static fn (string $s) => str_starts_with($s, 'rollback') ? $s : strtok($s, ' '), $this->steps->getArrayCopy()));
	}

	public function testUnexpectedFailureIs409WithoutDetails(): void {
		try {
			$this->service(null, new \RuntimeException('disk full at /var/www'))->create('admin', $this->in());
			$this->fail('409 expected');
		} catch (ApiException $e) {
			$this->assertSame(409, $e->getStatus());
			$this->assertStringNotContainsString('/var/www', $e->getMessage());
		}
		$this->assertSame('rollback 77', (string)$this->steps[2]);
	}
}
