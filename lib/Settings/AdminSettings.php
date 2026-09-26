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
 * Die Admin-Seite: Teams (über die Admin-Endpunkte) und Zustand. Ein
 * PHP-Template mit etwas Vanilla-JavaScript, ohne Build.
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
			// Die Seite braucht keine OC-Globals: Adresse der Schnittstelle von hier.
			'api_base' => $this->url->getWebroot() . '/ocs/v2.php/apps/' . Application::APP_ID . '/api/v1',
			'version' => $this->appManager->getAppVersion(Application::APP_ID),
			'locale' => str_replace('_', '-', $this->l->getLocaleCode()),
			'silent_days' => TeamAdminService::SILENT_DAYS,
			'state' => $this->admin->overview(),
			'groups' => $this->admin->allGroups(),
		]);
		// Texte für js/admin.js: übersetzt vom Server, ohne OC.L10N.
		$this->initialState->provideInitialState('l10n', $this->jsTexts());
		Util::addScript(Application::APP_ID, 'admin');
		Util::addStyle(Application::APP_ID, 'admin');
		return new TemplateResponse(Application::APP_ID, 'admin', [], '');
	}

	/**
	 * Was das Skript selbst schreibt. Schlüssel wie in js/admin.js,
	 * Platzhalter {name} ersetzt das Skript.
	 *
	 * @return array<string, string>
	 */
	private function jsTexts(): array {
		$l = $this->l;
		return [
			'role_user' => $l->t('Staff'),
			'role_lead' => $l->t('Project lead'),
			'role_subadmin' => $l->t('Office'),
			'role_admin' => $l->t('Admin'),
			'accounts_group' => $l->t('Accounts group'),
			'no_group' => $l->t('– None –'),
			'error_status' => $l->t('Error {status}'),
			'no_team' => $l->t('No team yet. Create one and assign four groups to it.'),
			'broken' => $l->t('Group mapping broken'),
			'edit' => $l->t('Edit'),
			'delete_team' => $l->t('Delete team'),
			'delete_team_named' => $l->t('Delete team {name}'),
			'team' => $l->t('Team'),
			'roles_col' => $l->t('Roles, group and members'),
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
		];
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 10;
	}
}
