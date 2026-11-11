<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister\Settings;

use OCA\TimeSister\AppInfo\Application;
use OCA\TimeSister\Service\AbsenceService;
use OCA\TimeSister\Service\ApiException;
use OCA\TimeSister\Service\CalendarChoice;
use OCA\TimeSister\Service\EntryService;
use OCA\TimeSister\Service\TenantService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Calendar\IManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * The personal page (0.49.0): a member without the Mac app names one of
 * their own calendars as time calendar. Naming and un-naming go through
 * `PUT`/`DELETE /me/time-calendar`, like the Mac app.
 */
final class PersonalSettings implements ISettings {
	public function __construct(
		private TenantService $tenants,
		private EntryService $entries,
		private AbsenceService $absences,
		private IManager $calendarManager,
		private IInitialState $initialState,
		private IURLGenerator $url,
		private IL10N $l,
	) {
	}

	public function getForm(): TemplateResponse {
		try {
			$m = $this->tenants->current();
		} catch (ApiException $e) {
			// No team or several: one sentence, no script.
			return new TemplateResponse(Application::APP_ID, 'personal', ['member' => false, 'reason' => $e->getText()->text($this->l)], '');
		}
		$rows = array_map(CalendarChoice::row(...), array_values($this->calendarManager->getCalendarsForPrincipal('principals/users/' . $m->uid)));
		$mine = $this->entries->mine($m);
		$offered = CalendarChoice::offered($rows, $mine['calendar_uri'] ?? null, $this->absences->vacationUriOf($m->tenantId, $m->uid));
		$this->initialState->provideInitialState('personal', [
			'api_base' => $this->url->getWebroot() . '/ocs/v2.php/apps/' . Application::APP_ID . '/api/v1',
			'locale' => str_replace('_', '-', $this->l->getLocaleCode()),
			'team' => $this->tenants->tenant($m->tenantId)->getName(),
			'current' => CalendarChoice::current($offered, $mine),
			'calendars' => $offered,
		]);
		$this->initialState->provideInitialState('l10n', $this->jsTexts());
		Util::addScript(Application::APP_ID, 'personal');
		Util::addStyle(Application::APP_ID, 'personal');
		return new TemplateResponse(Application::APP_ID, 'personal', ['member' => true, 'reason' => ''], '');
	}

	/**
	 * What js/personal.js writes itself; placeholders like {name}.
	 *
	 * @return array<string, string>
	 */
	private function jsTexts(): array {
		$l = $this->l;
		return [
			'team' => $l->t('Team: {name}'),
			'none_yet' => $l->t('None yet'),
			'not_found' => $l->t('{name} – no longer among your calendars'),
			'last_read' => $l->t('Last read: {date}'),
			'not_read' => $l->t('Not read yet; the server reads it within a few minutes.'),
			'read_failed' => $l->t('Last read failed: {message}'),
			'no_calendars' => $l->t('You have no calendar of your own yet. Create one in the Calendar app, then reload this page.'),
			'use' => $l->t('Use as time calendar'),
			'in_use' => $l->t('Your time calendar'),
			'vacation' => $l->t('The team’s vacation calendar'),
			'confirm_switch' => $l->t('Use “{name}” as your time calendar? The entries of “{old}” no longer count.'),
			'confirm_stop' => $l->t('Stop counting “{name}”? Its entries no longer count; billed hours stay.'),
			'named' => $l->t('“{name}” is your time calendar now.'),
			'stopped' => $l->t('No calendar counts as your time calendar now.'),
			'failed' => $l->t('Not saved: {message}'),
			'error_status' => $l->t('Error {status}'),
		];
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 10;
	}
}
