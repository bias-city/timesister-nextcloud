<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\TimeSister;

use OCA\TimeSister\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\Capabilities\ICapability;

/**
 * `timesister: {api, version, jobs, billing}` – if the entry is missing, the
 * app is not installed. `jobs: 1` since 0.6.0: the Job endpoints are there;
 * `jobs: 2` since 0.7.0: counter-proposals per recipient in a market;
 * `jobs: 3` since 0.7.2: weekly numbers per person and the capacity check;
 * `jobs: 4` since 0.7.4: the weekly numbers carry the non-billable booked
 * hours. `billing: 1` since 0.7.6: billing marks as records of kind
 * `billing`, feed only for those who may.
 * `absences: 1` since 0.9.0. `team_backup: 1` since 0.10.0: the team
 * backup as a ZIP with restore and move on the admin page. `privacy_notice: 1`
 * since 0.10.2: GET /team/privacy and the record `setting/privacy`.
 * `derived_lead: 1` since 0.11.0: the role Lead follows from the project
 * leads, `/me.lead_of`, Leads read customers. `lead_reach` (0.12.0, a
 * Lead's default `edit` for the people on their projects) is gone since
 * 0.14.0. `lead_scope: 1` since 0.14.0: members see themselves, Leads their
 * projects, Team Admins everything – name cards of others and the
 * customers of their projects for Leads; `booking_reports: 1`: the kinds `booking-report` and `billable`;
 * `job_fits: 1`: POST /jobs/fits. `quotes: 1` since 0.16.0: project statuses
 * `draft`, `quoted`, `declined`, the project's `team`, `currency` and
 * work package `rate` in the budgets – only for Team Admins and the
 * project's Leads. `quotes: 2` since 0.16.0: also the kind `quote` (Team
 * Admins, Leads the quotes naming them or of their projects); the project
 * statuses above stay. `cost_rates: 1` since 0.16.0: the person's `cost_rates`
 * (hourly cost from a day on), read by Team Admins alone. `invoices: 1`
 * since 0.16.0: the kind `invoice` (Team Admins, Leads their drafts), the
 * pattern `invoice_number` in `setting/settings` and the team's counter
 * through /invoices/next-number. `invoice_template: 1` since 0.16.0: the
 * team's Word template for invoices under /templates/invoice (Team Admins).
 * `entries: 1` since 0.17.0: the app holds the team's time entries –
 * named time calendars, feeds read by the server, GET /entries by role; the
 * shares matrix (/team/access, 0.5.0 to 0.16.x) is gone. `invoicing: 1`
 * since 0.18.0: invoices are issued on the server (POST /invoices/{key}/issue,
 * reverse, paid), frozen with a ledger and a SHA-256 chain; team setting
 * `invoicing`. `invoice_documents: 1` since 0.19.0: the server renders the
 * PDF with the Swiss QR bill, stores it in the team's folder (team setting
 * `documents`), hashes it into the ledger, serves a watermarked preview
 * and writes the daily chain head file.
 */
final class Capabilities implements ICapability {
	public function __construct(
		private IAppManager $appManager,
	) {
	}

	/** @return array{timesister: array{api: int, version: string, jobs: int, billing: int, absences: int, team_backup: int, privacy_notice: int, derived_lead: int, lead_scope: int, booking_reports: int, job_fits: int, quotes: int, cost_rates: int, invoices: int, invoice_template: int, entries: int, invoicing: int, invoice_documents: int}} */
	public function getCapabilities(): array {
		return [
			'timesister' => [
				'api' => Application::API_VERSION,
				'version' => $this->appManager->getAppVersion(Application::APP_ID),
				'jobs' => 5,
				'billing' => 1,
				'absences' => 1,
				'team_backup' => 1,
				'privacy_notice' => 1,
				'derived_lead' => 1,
				'lead_scope' => 1,
				'booking_reports' => 1,
				'job_fits' => 1,
				'quotes' => 2,
				'cost_rates' => 1,
				'invoices' => 1,
				'invoice_template' => 1,
				'entries' => 1,
				'invoicing' => 1,
				'invoice_documents' => 1,
			],
		];
	}
}
