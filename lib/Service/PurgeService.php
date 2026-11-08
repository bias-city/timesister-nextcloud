<?php

declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Service;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\TimeSister\AppInfo\Application;
use OCA\TimeSister\Db\BackupFileMapper;
use OCA\TimeSister\Db\ClientStatusMapper;
use OCA\TimeSister\Db\InvInvoiceMapper;
use OCA\TimeSister\Db\RecordMapper;
use OCA\TimeSister\Db\Tenant;
use OCA\TimeSister\Db\TenantMapper;
use OCA\TimeSister\Db\TimeCalendar;
use OCA\TimeSister\Db\TimeCalendarMapper;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Removing the app's data without a shell (0.43.0): one team or every team,
 * each behind the same three hurdles – a downloaded backup (the note of
 * the download, {@see PurgeRules}), the typed sentence or team name, and
 * Nextcloud's password confirmation at the route.
 *
 * Deleted: the team's rows ({@see TeamDeleteService}), the protected
 * backups and templates in the app data, and – by option – the files the
 * app wrote with the backup owner and the people (only tracked files and
 * folders that are empty afterwards), the team's vacation calendar, the
 * people's time calendars. Never: accounts, Nextcloud groups, any other
 * calendar, any file the app did not write.
 *
 * @psalm-suppress ClassMustBeFinal the unit test replaces the Nextcloud parts
 */
class PurgeService {
	public function __construct(
		private TenantMapper $tenants,
		private TenantService $team,
		private TeamDeleteService $deleter,
		private RecordMapper $records,
		private BackupFileMapper $backupFiles,
		private ClientStatusMapper $status,
		private TimeCalendarMapper $timeCalendars,
		private InvInvoiceMapper $invoices,
		private CalendarExporter $exporter,
		private VisibleCopy $visible,
		private IRootFolder $root,
		private IAppConfig $config,
		private IAppDataFactory $appDataFactory,
		private IAppManager $appManager,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	// ------------------------------------------------------------------ notes

	/** Remember a downloaded team backup: SHA-256 of the ZIP and the team's counters. */
	public function noteExport(Tenant $t, string $content, array $built): void {
		$note = PurgeRules::note(hash('sha256', $content), $this->time->getTime(), $t->getRevision(), $t->getEntriesRevision(),
			is_array($built['calendars'] ?? null) ? $built['calendars'] : [], is_array($built['missing'] ?? null) ? $built['missing'] : []);
		$this->config->setValueString(Application::APP_ID, PurgeRules::NOTE_KEY . $t->getId(), json_encode($note, JSON_THROW_ON_ERROR));
	}

	public function noteOf(int $tenantId): ?array {
		return PurgeRules::noteFrom($this->config->getValueString(Application::APP_ID, PurgeRules::NOTE_KEY . $tenantId, ''));
	}

	// ---------------------------------------------------------------- preview

	/**
	 * What a deletion would touch, per team, with the state of its backup.
	 *
	 * @return array{teams:list<array<string,mixed>>}
	 */
	public function preview(?int $tenantId = null): array {
		$now = $this->time->getTime();
		$out = [];
		foreach ($this->tenants->findAll() as $t) {
			if ($tenantId !== null && $t->getId() !== $tenantId) {
				continue;
			}
			$tid = $t->getId();
			$members = array_map('strval', array_keys($this->team->memberRoles($tid)));
			$calendars = 0;
			foreach ($members as $uid) {
				if ($this->exporter->has($uid, $this->status->findOne($tid, $uid)?->getCalendarUrl())) {
					$calendars++;
				}
			}
			$note = $this->noteOf($tid);
			$documents = 0;
			foreach ($this->invoices->findAll($tid) as $inv) {
				$documents += ($inv->getPdfFileId() !== null ? 1 : 0) + ($inv->getXmlFileId() !== null ? 1 : 0);
			}
			$vc = $this->vacationOf($tid);
			$out[] = [
				'id' => $tid, 'name' => $t->getName(), 'slug' => $t->getSlug(),
				'members' => count($members),
				'records' => $this->records->stats($tid)['all'],
				'files' => count($this->backupFiles->findByTenant($tid)),
				'documents' => $documents,
				'documents_root' => $this->documentsRoot($t),
				'calendars' => $calendars,
				'vacation' => $vc !== null,
				'export' => [
					'at' => $note === null ? null : Time::iso((int)$note['at']),
					'fresh' => PurgeRules::fresh($note, $t->getRevision(), $t->getEntriesRevision(), $now),
					'complete' => $note !== null && ($note['missing'] ?? []) === [],
					'token' => $note === null ? null : (string)$note['sha256'],
				],
			];
		}
		return ['teams' => $out];
	}

	// --------------------------------------------------------------- deleting

	/**
	 * One team: the typed name, the backup note, then files, calendars and rows.
	 *
	 * @param array{folders:bool,vacation:bool,time_calendars:bool} $opts
	 * @return array<string,mixed> the answer of {@see TeamDeleteService::delete} plus `removed`
	 */
	public function deleteTeam(Tenant $t, array $opts, mixed $token, mixed $confirm): array {
		TeamDeleteRules::checkConfirm($t->getName(), $confirm);
		$this->checkNote($t, $opts, $token);
		$removed = $this->wipeOutside($t, $opts);
		$answer = $this->deleter->delete($t, $t->getName());
		$answer['removed'] = $removed;
		return $answer;
	}

	/**
	 * Every team, then the app's own leftovers; the app disables itself.
	 *
	 * @param array{folders:bool,vacation:bool,time_calendars:bool} $opts
	 * @param array<string,mixed> $tokens team ID → token
	 * @param list<string> $accepted the sentence in the accepted languages
	 * @return array<string,mixed>
	 */
	public function purgeAll(array $opts, mixed $sentence, array $tokens, array $accepted): array {
		PurgeRules::checkSentence($sentence, $accepted);
		$teams = $this->tenants->findAll();
		// All notes first: nothing goes when one team lacks its backup.
		foreach ($teams as $t) {
			$this->checkNote($t, $opts, $tokens[(string)$t->getId()] ?? null);
		}
		$done = [];
		foreach ($teams as $t) {
			$removed = $this->wipeOutside($t, $opts);
			$answer = $this->deleter->delete($t, $t->getName());
			$done[] = ['id' => $t->getId(), 'name' => $t->getName(), 'counts' => $answer['counts'] ?? [], 'removed' => $removed];
		}
		$this->wipeAppData();
		$this->config->deleteApp(Application::APP_ID);
		$this->appManager->disableApp(Application::APP_ID);
		$this->logger->info('TimeSister: all data removed by an admin; the app disabled itself', ['app' => 'timesister', 'teams' => count($done)]);
		return ['teams' => $done, 'disabled' => true];
	}

	/** @param array{folders:bool,vacation:bool,time_calendars:bool} $opts */
	private function checkNote(Tenant $t, array $opts, mixed $token): void {
		$note = $this->noteOf($t->getId());
		PurgeRules::checkExport($note, $token, $t->getRevision(), $t->getEntriesRevision(), $this->time->getTime());
		if ($opts['time_calendars']) {
			PurgeRules::checkCalendarsComplete($note);
		}
	}

	/**
	 * What lies outside the app's tables and app data, by option; read what
	 * is needed before the rows go. Best effort per item, counted.
	 *
	 * @param array{folders:bool,vacation:bool,time_calendars:bool} $opts
	 * @return array{files:int,files_left:int,folders:int,documents:int,documents_left:int,vacation:bool,calendars:int,calendars_left:int}
	 */
	protected function wipeOutside(Tenant $t, array $opts): array {
		$tid = $t->getId();
		$r = ['files' => 0, 'files_left' => 0, 'folders' => 0, 'documents' => 0, 'documents_left' => 0, 'vacation' => false, 'calendars' => 0, 'calendars_left' => 0];
		if ($opts['folders']) {
			$this->wipeFolders($t, $r);
		}
		if ($opts['vacation']) {
			$vc = $this->vacationOf($tid);
			if ($vc !== null) {
				$r['vacation'] = $this->deleteCalendar($vc['owner'], $vc['uri']);
			}
		}
		if ($opts['time_calendars']) {
			foreach (array_map('strval', array_keys($this->team->memberRoles($tid))) as $uid) {
				$uri = $this->timeCalendarUri($tid, $uid);
				if ($uri === null) {
					continue;
				}
				if ($this->deleteCalendar($uid, $uri)) {
					$r['calendars']++;
				} else {
					$r['calendars_left']++;
				}
			}
		}
		return $r;
	}

	/**
	 * Only tracked files – the visible backup copies, the team ZIPs of the app's own naming, the filed invoice
	 * documents – and only folders left empty by that, up to the app's root folders, never a person's home.
	 *
	 * @param array{files:int,files_left:int,folders:int,documents:int,documents_left:int,vacation:bool,calendars:int,calendars_left:int} $r
	 */
	private function wipeFolders(Tenant $t, array &$r): void {
		$tid = $t->getId();
		/** @var array<string,array<string,true>> $dirs owner → folder paths touched */
		$dirs = [];
		foreach ($this->backupFiles->findByTenant($tid) as $f) {
			try {
				if ($this->visible->deleteTracked($f->getOwner(), $f->getFileId(), $f->getPath())) {
					$r['files']++;
					$dirs[$f->getOwner()][dirname($f->getPath())] = true;
				} else {
					$r['files_left']++;
				}
			} catch (\Throwable $e) {
				$r['files_left']++;
				$this->logger->warning('TimeSister: tracked file not removed', ['app' => 'timesister', 'path' => $f->getPath(), 'exception' => $e]);
			}
		}
		$owner = $this->team->backupOwner($t);
		if ($owner !== null) {
			// The team ZIPs in `TimeSister Backups/_team`: only the app's own naming.
			try {
				$home = $this->root->getUserFolder($owner);
				$teamDir = $home->get(BackupRules::VISIBLE_ROOT . '/' . BackupRules::TEAM_FOLDER);
				if ($teamDir instanceof Folder) {
					foreach ($teamDir->getDirectoryListing() as $node) {
						if (PurgeRules::isTeamZip($t->getSlug(), $node->getName())) {
							$node->delete();
							$r['files']++;
						}
					}
					$dirs[$owner][BackupRules::VISIBLE_ROOT . '/' . BackupRules::TEAM_FOLDER] = true;
				}
			} catch (NotFoundException) {
				// no team folder
			} catch (\Throwable $e) {
				$this->logger->warning('TimeSister: team ZIPs not removed', ['app' => 'timesister', 'team' => $tid, 'exception' => $e]);
			}
			// The filed invoice documents (PDF, XML) with the backup owner.
			$docsRoot = $this->documentsRoot($t);
			foreach ($this->invoices->findAll($tid) as $inv) {
				foreach ([[$inv->getPdfFileId(), $inv->getDocumentPath()], [$inv->getXmlFileId(), $inv->getXmlPath()]] as [$fileId, $path]) {
					if ($fileId === null || $path === null || $path === '') {
						continue;
					}
					$path = ltrim($path, '/');
					try {
						if ($this->visible->deleteTracked($owner, $fileId, $path)) {
							$r['documents']++;
							$dirs[$owner][dirname($path)] = true;
						} else {
							$r['documents_left']++;
						}
					} catch (\Throwable $e) {
						$r['documents_left']++;
						$this->logger->warning('TimeSister: filed document not removed', ['app' => 'timesister', 'path' => $path, 'exception' => $e]);
					}
				}
			}
			$stops = array_filter([$docsRoot]);
		} else {
			$stops = [];
		}
		// Folders left empty: up to and including the app's backup root, never the documents root or a home.
		foreach (array_map('strval', array_keys($dirs)) as $uid) {
			$paths = $dirs[$uid];
			foreach (array_map('strval', array_keys($paths)) as $path) {
				$r['folders'] += $this->removeEmpty($uid, $path, $stops);
			}
		}
	}

	/**
	 * Removes `path` and its parents while they are empty and belong to the app:
	 * inside `TimeSister Backups` (that root included) or inside a documents
	 * root (that root excluded). Never a person's home.
	 *
	 * @param list<string> $stops roots that stay
	 * @return int folders removed
	 */
	private function removeEmpty(string $uid, string $path, array $stops): int {
		$n = 0;
		try {
			$home = $this->root->getUserFolder($uid);
			$path = trim($path, '/');
			while ($path !== '' && $path !== '.') {
				$inBackups = $path === BackupRules::VISIBLE_ROOT || str_starts_with($path, BackupRules::VISIBLE_ROOT . '/');
				$inDocs = false;
				foreach ($stops as $root) {
					$root = trim($root, '/');
					if ($root !== '' && $path !== $root && str_starts_with($path, $root . '/')) {
						$inDocs = true;
					}
				}
				if (!$inBackups && !$inDocs) {
					break;
				}
				$node = $home->get($path);
				if (!$node instanceof Folder || $node->getDirectoryListing() !== []) {
					break;
				}
				$node->delete();
				$n++;
				$parent = dirname($path);
				$path = $parent === '.' ? '' : $parent;
			}
		} catch (NotFoundException) {
			// already gone
		} catch (\Throwable $e) {
			$this->logger->warning('TimeSister: empty folder not removed', ['app' => 'timesister', 'path' => $path, 'exception' => $e]);
		}
		return $n;
	}

	/** The protected backups, templates and exports of every team in the app data. */
	protected function wipeAppData(): void {
		try {
			$appData = $this->appDataFactory->get(Application::APP_ID);
			foreach ($appData->getDirectoryListing() as $folder) {
				$folder->delete();
			}
		} catch (\Throwable $e) {
			$this->logger->warning('TimeSister: app data not emptied', ['app' => 'timesister', 'exception' => $e]);
		}
	}

	// ----------------------------------------------------------------- lookups

	/** @return ?array{url:string,owner:string,uri:string} */
	private function vacationOf(int $tid): ?array {
		$r = $this->records->findOne($tid, 'setting', 'settings');
		$raw = $r?->getData();
		if ($r === null || $r->isTombstone() || $raw === null) {
			return null;
		}
		try {
			return AbsenceRules::vacationCalendar(Json::decode($raw));
		} catch (\JsonException) {
			return null;
		}
	}

	private function documentsRoot(Tenant $t): ?string {
		$json = $t->getDocumentsJson();
		if ($json === null || $json === '') {
			return null;
		}
		try {
			$d = Json::decode($json);
		} catch (\JsonException) {
			return null;
		}
		$root = $d instanceof \stdClass ? ($d->root ?? null) : null;
		return is_string($root) && trim($root, '/ ') !== '' ? trim($root, '/') : null;
	}

	/** The URI of the person's time calendar as the app knows it – the named one, the reported one, or `zeit-<uid>`. */
	private function timeCalendarUri(int $tid, string $uid): ?string {
		$named = $this->timeCalendars->findOne($tid, $uid, TimeCalendar::CALENDAR)?->getCalendarUri();
		if (is_string($named) && $named !== '') {
			return $named;
		}
		$fromStatus = BackupRules::calendarUriFromUrl($this->status->findOne($tid, $uid)?->getCalendarUrl(), $uid);
		if ($fromStatus !== null) {
			return $fromStatus;
		}
		return $this->exporter->has($uid, null) ? 'zeit-' . $uid : null;
	}

	/**
	 * Deletes one calendar in the person's own account, permanently (no
	 * trash bin: the ZIP is the copy). False when it is not there.
	 */
	protected function deleteCalendar(string $uid, string $uri): bool {
		try {
			$backend = \OCP\Server::get(CalDavBackend::class);
			$cal = $backend->getCalendarByUri('principals/users/' . $uid, $uri);
			if ($cal === null) {
				return false;
			}
			$backend->deleteCalendar((int)$cal['id'], true);
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning('TimeSister: calendar not removed', ['app' => 'timesister', 'uid' => $uid, 'uri' => $uri, 'exception' => $e]);
			return false;
		}
	}
}
