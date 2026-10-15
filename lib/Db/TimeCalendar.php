<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A source of time entries (0.17.0): the named time calendar of a member
 * (`source` calendar, `calendar_id` the dav calendar's id) or the feed of
 * an external person (`source` feed, `uid` the person record's key).
 *
 * @method int getTenantId()
 * @method void setTenantId(int $tenantId)
 * @method string getUid()
 * @method void setUid(string $uid)
 * @method string getSource()
 * @method void setSource(string $source)
 * @method int|null getCalendarId()
 * @method void setCalendarId(?int $calendarId)
 * @method string|null getCalendarUri()
 * @method void setCalendarUri(?string $calendarUri)
 * @method string|null getFeedUrl()
 * @method void setFeedUrl(?string $feedUrl)
 * @method int getNamedAt()
 * @method void setNamedAt(int $namedAt)
 * @method int|null getLastReadAt()
 * @method void setLastReadAt(?int $lastReadAt)
 * @method int|null getLastFullReadAt()
 * @method void setLastFullReadAt(?int $lastFullReadAt)
 * @method string|null getLastError()
 * @method void setLastError(?string $lastError)
 * @method int getReread()
 * @method void setReread(int $reread)
 * @method string|null getEtag()
 * @method void setEtag(?string $etag)
 */
final class TimeCalendar extends Entity {
	public const CALENDAR = 'calendar';
	public const FEED = 'feed';

	protected int $tenantId = 0;
	protected string $uid = '';
	// Empty, not CALENDAR: the mapper only writes what a setter changed.
	protected string $source = '';
	protected ?int $calendarId = null;
	protected ?string $calendarUri = null;
	protected ?string $feedUrl = null;
	protected int $namedAt = 0;
	protected ?int $lastReadAt = null;
	protected ?int $lastFullReadAt = null;
	protected ?string $lastError = null;
	protected int $reread = 1;
	protected ?string $etag = null;

	public function __construct() {
		$this->addType('tenantId', Types::INTEGER);
		$this->addType('uid', Types::STRING);
		$this->addType('source', Types::STRING);
		$this->addType('calendarId', Types::INTEGER);
		$this->addType('calendarUri', Types::STRING);
		$this->addType('feedUrl', Types::STRING);
		$this->addType('namedAt', Types::INTEGER);
		$this->addType('lastReadAt', Types::INTEGER);
		$this->addType('lastFullReadAt', Types::INTEGER);
		$this->addType('lastError', Types::STRING);
		$this->addType('reread', Types::SMALLINT);
		$this->addType('etag', Types::STRING);
	}
}
