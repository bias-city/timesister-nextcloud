<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Settings;

use OCA\TimeSister\AppInfo\Application;
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
		Util::addScript(Application::APP_ID, 'admin');
		Util::addStyle(Application::APP_ID, 'admin');
		return new TemplateResponse(Application::APP_ID, 'admin', [], '');
	}

	/**
	 * What the script writes itself. Keys as in js/admin.js; the script
	 * replaces placeholders like {name}. Roles are User, Lead, Manager and
	 * Admin in every language.
	 *
	 * @return array<string, string>
	 */
	private function jsTexts(): array {
		$l = $this->l;
		return [
			'role_user' => $l->t('User'),
			'role_lead' => $l->t('Lead'),
			'role_subadmin' => $l->t('Manager'),
			'role_admin' => $l->t('Admin'),
			'team_group' => $l->t('Team group'),
			'admins' => $l->t('Admins'),
			'no_admin' => $l->t('No admin yet'),
			'admins_after_save' => $l->t('Admins appear after saving.'),
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
			'owner_auto' => $l->t('Automatic: first admin'),
			'owner_none' => $l->t('No admin in the team'),
			'backups_col' => $l->t('Server backups'),
			'calendars_col' => $l->t('Time calendars'),
			'not_shared' => $l->t('Not shared: {n}'),
			'consent_count' => $l->t('Consent: {n} of {total}'),
			'without_week' => $l->t('Without backup this week: {n}'),
			'last_server' => $l->t('Last: {date}'),
			'no_server_backup' => $l->t('No server backup yet'),
			'leads_see' => $l->t('Leads see all time calendars'),
			'backup_required' => $l->t('Backup required'),
			'yes' => $l->t('Yes'),
			'no' => $l->t('No'),
		];
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 10;
	}
}
