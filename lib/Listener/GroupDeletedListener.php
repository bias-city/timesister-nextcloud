<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Listener;

use OCA\TimeSister\Db\RoleGroupMapper;
use OCA\TimeSister\Db\TenantMapper;
use OCA\TimeSister\Service\Role;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\GroupDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Group deleted: if it is a team's team group, that team's mapping is now
 * broken. The admin page shows it in red until an admin assigns a new
 * group. Old mappings from API version 1 (role and account groups) are
 * silently dropped.
 *
 * @template-implements IEventListener<GroupDeletedEvent>
 */
final class GroupDeletedListener implements IEventListener {
	public function __construct(
		private RoleGroupMapper $roleGroups,
		private TenantMapper $tenants,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof GroupDeletedEvent)) {
			return;
		}
		$ids = [];
		foreach ($this->roleGroups->findByGid($event->getGroup()->getGID()) as $rg) {
			if ($rg->getRole() !== Role::TEAM_GROUP) {
				$this->roleGroups->delete($rg);
				continue;
			}
			$ids[] = $rg->getTenantId();
		}
		$ids = array_values(array_unique($ids));
		if ($ids !== []) {
			$this->tenants->markBroken($ids, $this->time->getTime());
			$this->logger->warning('TimeSister: team group deleted, mapping of {n} team(s) broken', ['app' => 'timesister', 'n' => count($ids)]);
		}
	}
}
