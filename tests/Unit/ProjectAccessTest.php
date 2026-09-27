<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Tests\Unit;

use OCA\TimeSister\Service\AccessPolicy;
use OCA\TimeSister\Service\Json;
use OCA\TimeSister\Service\Membership;
use OCA\TimeSister\Service\ProjectAccess;
use PHPUnit\Framework\TestCase;

/** Full projects only for manager, admin and lead; otherwise the booking catalog. */
class ProjectAccessTest extends TestCase {
	private const PROJECT = '{"schema":2,"id":"P1","name":"Hafen","codes":["H"],"subprojects":[{"id":"a"}],"start":"2026-01-01","end":null,'
		. '"status":"aktiv","mapping":{"rules":[]},"description":"geheim","cost_center":"KS-1","customer":"stadt","quote":"O-7",'
		. '"leads":["lea","other"],"budget":[1],"milestones":[],"budgets":[{"amount":1000}],"neu_2027":"x"}';

	private static function project(): \stdClass {
		$p = Json::decode(self::PROJECT);
		self::assertInstanceOf(\stdClass::class, $p);
		return $p;
	}

	public function testCatalogIsAllowList(): void {
		$c = ProjectAccess::catalog(self::project());
		$this->assertSame(['schema', 'id', 'name', 'codes', 'subprojects', 'start', 'end', 'status', 'mapping'], array_keys(get_object_vars($c)));
		// A new, unknown field does not slip through.
		$this->assertFalse(property_exists($c, 'neu_2027'));
		$this->assertNull($c->end, 'null stays null');
		$this->assertTrue(property_exists(self::project(), 'budgets'), 'the original stays');
		$this->assertSame('{"id":"P2"}', Json::encode(ProjectAccess::catalog((object)['id' => 'P2', 'leads' => ['x']])));
	}

	public function testIsLead(): void {
		$p = self::project();
		$this->assertTrue(ProjectAccess::isLead($p, ['lea']));
		$this->assertTrue(ProjectAccess::isLead($p, ['lea.person', 'other']), 'via a person with the account in accounts');
		$this->assertFalse(ProjectAccess::isLead($p, ['mia']));
		$this->assertFalse(ProjectAccess::isLead((object)['leads' => 'lea'], ['lea']), 'leads must be a list');
		$this->assertFalse(ProjectAccess::isLead((object)['id' => 'P'], ['lea']));
		$this->assertFalse(ProjectAccess::isLead(null, ['lea']), 'tombstone or new');
	}

	public function testFullProjectByRole(): void {
		$policy = new AccessPolicy();
		$p = self::project();
		$this->assertTrue($policy->canSeeFullProject(new Membership('verw', 1, 'subadmin'), $p, ['verw']));
		$this->assertTrue($policy->canSeeFullProject(new Membership('boss', 1, 'admin'), $p, ['boss']));
		$this->assertTrue($policy->canSeeFullProject(new Membership('lea', 1, 'lead'), $p, ['lea']));
		$this->assertFalse($policy->canSeeFullProject(new Membership('luca', 1, 'lead'), $p, ['luca']), 'lead of another project');
		$this->assertFalse($policy->canSeeFullProject(new Membership('mia', 1, 'user'), $p, ['mia']));
		$this->assertTrue($policy->canSeeFullProject(new Membership('other', 1, 'user'), $p, ['other']), 'in leads, even with role user');
	}
}
