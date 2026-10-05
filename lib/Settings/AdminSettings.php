<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Settings;

use OCA\TimeSister\AppInfo\Application;
use OCA\TimeSister\Service\RoleName;
use OCA\TimeSister\Service\TeamAdminService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * The admin page: teams (through the admin endpoints) and status. A PHP
 * template with a little vanilla JavaScript, without a build step.
 */
final class AdminSettings implements ISettings {
	public function __construct(
		private TeamAdminService $admin,
		private IInitialState $initialState,
		private IAppManager $appManager,
		private IURLGenerator $url,
		private IL10N $l,
	) {
	}

	public function getForm(): TemplateResponse {
		$this->initialState->provideInitialState('overview', [
			'api' => Application::API_VERSION,
			// The page needs no OC globals: the API address comes from here.
			'api_base' => $this->url->getWebroot() . '/ocs/v2.php/apps/' . Application::APP_ID . '/api/v1',
			'version' => $this->appManager->getAppVersion(Application::APP_ID),
			'locale' => str_replace('_', '-', $this->l->getLocaleCode()),
			'silent_days' => TeamAdminService::SILENT_DAYS,
			'state' => $this->admin->overview(),
			'groups' => $this->admin->allGroups(),
		]);
		// Texts for js/admin.js, translated by the server, without OC.L10N.
		$this->initialState->provideInitialState('l10n', $this->jsTexts());
		// Role words: the same in every language, never through the l10n.
		$this->initialState->provideInitialState('roles', RoleName::ALL);
		Util::addScript(Application::APP_ID, 'admin');
		Util::addStyle(Application::APP_ID, 'admin');
		return new TemplateResponse(Application::APP_ID, 'admin', [], '');
	}

	/**
	 * What the script writes itself. Keys as in js/admin.js; the script
	 * replaces placeholders like {name}. The role words come separately
	 * ({@see RoleName}), untranslated.
	 *
	 * @return array<string, string>
	 */
	private function jsTexts(): array {
		$l = $this->l;
		return [
			'team_group' => $l->t('Team group'),
			'admins' => $l->t('Team Admins'),
			'no_admin' => $l->t('No Team Admin yet'),
			'admins_after_save' => $l->t('Team Admins appear after saving.'),
			'member' => $l->t('Member'),
			'role' => $l->t('Role'),
			'left' => $l->t('Left'),
			'left_on' => $l->t('left on {date}'),
			'role_of' => $l->t('Role of {name}'),
			'left_of' => $l->t('{name} has left the team'),
			'members_after_save' => $l->t('Members appear after saving.'),
			'no_members' => $l->t('No members'),
			'member_failed' => $l->t('Role of {name} not saved: {message}'),
			'error_status' => $l->t('Error {status}'),
			'no_team' => $l->t('No team yet. Create one and assign a group to it.'),
			'broken' => $l->t('Group mapping broken'),
			'edit' => $l->t('Edit'),
			'delete_team' => $l->t('Delete team'),
			'delete_team_named' => $l->t('Delete team {name}'),
			'team' => $l->t('Team'),
			'group_col' => $l->t('Group and members'),
			'api_version' => $l->t('API version'),
			'app' => $l->t('App'),
			'new_after_reload' => $l->t('New – status after reloading.'),
			'all_reported' => $l->t('all reported'),
			'last_seen' => $l->t('(last {time})'),
			'never' => $l->t('(never)'),
			'records' => $l->t('Records'),
			'last_change' => $l->t('Last change'),
			'revision' => $l->t('Revision'),
			'silent' => $l->n('No sign of life for %n day', 'No sign of life for %n days', TeamAdminService::SILENT_DAYS),
			'choose_group' => $l->t('– Choose group –'),
			'other_team' => $l->t('– used by another team'),
			'new_team' => $l->t('New team'),
			'edit_team' => $l->t('Edit team'),
			'confirm_delete' => $l->t('Delete team “{name}”? This only works as long as it has no records and backups. The groups and accounts remain.'),
			'load_failed' => $l->t('Teams cannot be loaded: {message}'),
			'backups_stored_with' => $l->t('Backups stored with'),
			'owner_auto' => $l->t('Automatic: first Team Admin'),
			'owner_none' => $l->t('No Team Admin in the team'),
			'backups_col' => $l->t('Server backups'),
			'calendars_col' => $l->t('Time calendars'),
			'not_shared' => $l->t('Not shared: {n}'),
			'consent_count' => $l->t('Consent: {n} of {total}'),
			'without_week' => $l->t('Without backup this week: {n}'),
			'last_server' => $l->t('Last: {date}'),
			'no_server_backup' => $l->t('No server backup yet'),
			'backup_required' => $l->t('Backup required'),
			'yes' => $l->t('Yes'),
			'no' => $l->t('No'),
			// Team backup as ZIP (0.10.0).
			'export_zip' => $l->t('Back up team (ZIP)'),
			'export_zip_title' => $l->t('Download everything of this team: master data with history, roles, shares, absences and the time calendars'),
			'export_failed' => $l->t('The backup could not be created: {message}'),
			'import_zip' => $l->t('Restore from ZIP'),
			'import_title' => $l->t('Restore “{name}” from a backup'),
			'previewing' => $l->t('Checking the backup …'),
			'importing' => $l->t('Importing …'),
			'preview_source' => $l->t('Backup of “{name}” from {date}'),
			'preview_counts' => $l->t('Records: {n} · Calendars: {c} · People with absences: {a}'),
			'preview_not_empty' => $l->t('This team already has content. Before importing, a backup of it is stored (protected and with the backup owner).'),
			'person_col' => $l->t('Person in the backup'),
			'account_col' => $l->t('Account here'),
			'account_for' => $l->t('Account for {name}'),
			'no_account' => $l->t('– without account –'),
			'not_here' => $l->t('No account with this name here.'),
			'already_member' => $l->t('Already in this team.'),
			'mode_merge' => $l->t('Merge: add what is missing; records that are newer here stay.'),
			'mode_replace' => $l->t('Replace: the backup wins; records that are not in it are deleted (as versions, restorable).'),
			'import_ok' => $l->t('Imported into “{name}”.'),
			'import_done' => $l->t('{inserted} new, {updated} updated and {tombstoned} deleted records · {members} member rows · {calendars} calendars stored as backups'),
			'pre_backup' => $l->t('Backup before importing: {path}'),
			'without_account' => $l->t('Without account here: {list}'),
			'calendars_kept' => $l->t('Calendar backup of that day kept, not replaced: {list}'),
		];
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 10;
	}
}
