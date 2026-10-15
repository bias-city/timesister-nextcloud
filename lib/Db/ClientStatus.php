<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * The last status report of a client.
 *
 * @method int getTenantId()
 * @method void setTenantId(int $tenantId)
 * @method string getUid()
 * @method void setUid(string $uid)
 * @method string|null getAppVersion()
 * @method void setAppVersion(?string $appVersion)
 * @method int|null getLastSync()
 * @method void setLastSync(?int $lastSync)
 * @method string|null getLastBackup()
 * @method void setLastBackup(?string $lastBackup)
 * @method string|null getCalendarUrl()
 * @method void setCalendarUrl(?string $calendarUrl)
 * @method int getSeenAt()
 * @method void setSeenAt(int $seenAt)
 * @method string|null getJobWeeks()
 * @method void setJobWeeks(?string $jobWeeks)
 * @method int|null getJobWeeksAt()
 * @method void setJobWeeksAt(?int $jobWeeksAt)
 * @method string|null getProjects()
 * @method void setProjects(?string $projects)
 * @method int|null getProjectsAt()
 * @method void setProjectsAt(?int $projectsAt)
 */
final class ClientStatus extends Entity {
	protected int $tenantId = 0;
	protected string $uid = '';
	protected ?string $appVersion = null;
	protected ?int $lastSync = null;
	protected ?string $lastBackup = null;
	protected ?string $calendarUrl = null;
	/** Columns of the former shares matrix (removed in 0.17.0); unused, kept because the table keeps them. */
	protected ?int $calendarShared = null;
	protected ?string $appliedShares = null;
	protected ?int $appliedAt = null;
	protected int $seenAt = 0;
	/** JSON: the weekly numbers of the person's Jobs (0.7.2, {@see \OCA\TimeSister\Service\JobWeeks}); null: never reported. */
	protected ?string $jobWeeks = null;
	protected ?int $jobWeeksAt = null;
	/** JSON list of the project keys booked on in the last 12 months (0.12.0); null: never reported. */
	protected ?string $projects = null;
	protected ?int $projectsAt = null;

	public function __construct() {
		$this->addType('tenantId', Types::INTEGER);
		$this->addType('uid', Types::STRING);
		$this->addType('appVersion', Types::STRING);
		$this->addType('lastSync', Types::INTEGER);
		$this->addType('lastBackup', Types::STRING);
		$this->addType('calendarUrl', Types::STRING);
		$this->addType('calendarShared', Types::SMALLINT);
		$this->addType('seenAt', Types::INTEGER);
		$this->addType('appliedShares', Types::STRING);
		$this->addType('appliedAt', Types::INTEGER);
		$this->addType('jobWeeks', Types::STRING);
		$this->addType('jobWeeksAt', Types::INTEGER);
		$this->addType('projects', Types::STRING);
		$this->addType('projectsAt', Types::INTEGER);
	}
}
