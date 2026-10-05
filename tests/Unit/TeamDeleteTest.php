<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Db\Tenant;
use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\TeamDeleteRules;
use OCA\TimeSister\Service\TeamDeleteService;
use PHPUnit\Framework\TestCase;

/**
 * Deleting a team with content (0.10.1): the typed name, the ZIP first,
 * nothing deleted when the ZIP fails, and the answer with the counts.
 */
class TeamDeleteTest extends TestCase {
	/** The steps in the order they ran. */
	private \ArrayObject $steps;

	protected function setUp(): void {
		$this->steps = new \ArrayObject();
	}

	private function tenant(): Tenant {
		$t = new Tenant();
		$t->setId(58);
		$t->setName('ZZ Test');
		$t->setSlug('zz');
		return $t;
	}

	/**
	 * The service with the four steps that need Nextcloud replaced; the
	 * constructor gets empty collaborators (interfaces as mocks, final
	 * classes without their constructor) so the untouched ones exist.
	 */
	private function service(bool $content, bool $keepFails): TeamDeleteService {
		$args = [];
		foreach ((new \ReflectionClass(TeamDeleteService::class))->getConstructor()?->getParameters() ?? [] as $p) {
			$type = $p->getType();
			$this->assertInstanceOf(\ReflectionNamedType::class, $type);
			$name = $type->getName();
			$args[] = interface_exists($name) ? $this->createMock($name) : (new \ReflectionClass($name))->newInstanceWithoutConstructor();
		}
		return new class($args, $content, $keepFails, $this->steps) extends TeamDeleteService {
			/** @param list<mixed> $args */
			public function __construct(array $args, private bool $content, private bool $keepFails, private \ArrayObject $steps) {
				parent::__construct(...$args);
			}

			protected function countContent(int $id): array {
				$this->steps->append('count');
				return $this->content ? ['records' => 57, 'backups' => 1] : ['records' => 0, 'backups' => 0];
			}

			protected function keep(Tenant $t): array {
				$this->steps->append('keep');
				if ($this->keepFails) {
					throw ApiException::conflict('The backup before deleting could not be written; nothing was deleted.');
				}
				return ['protected' => 'exports/t58/timesister-zz-2026-10-05_1700.zip', 'visible' => 'TimeSister Backups/_team/timesister-zz-2026-10-05_1700.zip'];
			}

			protected function wipe(Tenant $t): array {
				$this->steps->append('wipe');
				return ['records' => 57, 'versions' => 185, 'status' => 1, 'absences' => 0, 'members' => 2, 'access' => 0, 'consents' => 0, 'groups' => 1, 'backups' => 1, 'backup_files' => 1];
			}

			protected function wipeFiles(int $id): void {
				$this->steps->append('files');
			}
		};
	}

	public function testWrongNameIs422ConfirmAndNothingRuns(): void {
		foreach (['zz', 'ZZ Tes', null, 42, ''] as $given) {
			$this->steps = new \ArrayObject();
			try {
				$this->service(true, false)->delete($this->tenant(), $given);
				$this->fail('not refused: ' . var_export($given, true));
			} catch (ApiException $e) {
				$this->assertSame(422, $e->getStatus());
				$this->assertSame(ApiException::CONFIRM, $e->getErrorCode());
			}
			$this->assertSame([], $this->steps->getArrayCopy());
		}
	}

	public function testNameIsComparedTrimmedAndCaseless(): void {
		$this->assertTrue(TeamDeleteRules::same('ZZ Test', '  zz test '));
		$this->assertTrue(TeamDeleteRules::same('Büro Müller', 'BÜRO MÜLLER'));
		$this->assertFalse(TeamDeleteRules::same('ZZ Test', 'ZZ'));
		$this->assertFalse(TeamDeleteRules::same('ZZ Test', 'zz  test'));
	}

	public function testFailedBackupDeletesNothing(): void {
		try {
			$this->service(true, true)->delete($this->tenant(), 'zz test');
			$this->fail('not refused');
		} catch (ApiException $e) {
			$this->assertSame(409, $e->getStatus());
			$this->assertSame(ApiException::CONFLICT, $e->getErrorCode());
		}
		$this->assertSame(['count', 'keep'], $this->steps->getArrayCopy(), 'no wipe after a failed backup');
	}

	public function testTeamWithContentIsKeptThenWiped(): void {
		$r = $this->service(true, false)->delete($this->tenant(), 'ZZ Test');
		$this->assertSame(['count', 'keep', 'wipe', 'files'], $this->steps->getArrayCopy());
		$this->assertTrue($r['deleted']);
		$this->assertSame(58, $r['id']);
		$this->assertSame('exports/t58/timesister-zz-2026-10-05_1700.zip', $r['backup']['protected']);
		$this->assertSame('TimeSister Backups/_team/timesister-zz-2026-10-05_1700.zip', $r['backup']['visible']);
		$this->assertSame(57, $r['counts']['records']);
		$this->assertSame(185, $r['counts']['versions']);
		$this->assertSame(2, $r['counts']['members']);
		$this->assertSame(1, $r['counts']['backups']);
	}

	public function testEmptyTeamNeedsNoZipButStillTheName(): void {
		$r = $this->service(false, false)->delete($this->tenant(), 'ZZ Test');
		$this->assertSame(['count', 'wipe', 'files'], $this->steps->getArrayCopy(), 'no ZIP for an empty team');
		$this->assertNull($r['backup']);
		$this->assertTrue($r['deleted']);
	}

	public function testRules(): void {
		$this->assertFalse(TeamDeleteRules::hasContent(0, 0));
		$this->assertTrue(TeamDeleteRules::hasContent(1, 0), 'a tombstone counts');
		$this->assertTrue(TeamDeleteRules::hasContent(0, 1), 'a backup counts');
		$this->assertSame(
			['id' => 3, 'deleted' => true, 'backup' => null, 'counts' => ['records' => 0]],
			TeamDeleteRules::answer(3, null, ['records' => 0]),
		);
	}
}
