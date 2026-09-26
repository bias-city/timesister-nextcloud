<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Listener;

use OCA\TimeSister\Db\RoleGroupMapper;
use OCA\TimeSister\Db\TenantMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\GroupDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Gruppe gelöscht: Gehört sie zu einem Team, ist dessen Rollenzuordnung
 * gebrochen. Die Admin-Seite zeigt es rot, bis ein Admin eine neue Gruppe
 * zuordnet.
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
			$ids[] = $rg->getTenantId();
		}
		$ids = array_values(array_unique($ids));
		if ($ids !== []) {
			$this->tenants->markBroken($ids, $this->time->getTime());
			$this->logger->warning('TimeSister: Rollen-Gruppe gelöscht, Zuordnung von {n} Team(s) gebrochen', ['app' => 'timesister', 'n' => count($ids)]);
		}
	}
}
