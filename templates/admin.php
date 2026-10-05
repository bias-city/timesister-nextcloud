<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// The admin page. js/admin.js builds the content (no build step, no inline script).
// Texts through $l (the app's IL10N), printed with p().
/** @var \OCP\IL10N $l */
?>
<div id="timesister-admin" class="section">
	<h2><?php p($l->t('TimeSister')); ?></h2>
	<p class="settings-hint">
		<?php p($l->t('A team builds on an existing Nextcloud group, the team group. Its group admins are the team’s Team Admins. The roles Team Admin, Lead and User and leaving the team are set here under Members or in the Mac app; master data, calendar shares and backups in the Mac app.')); ?>
	</p>

	<div class="ts-head">
		<h3><?php p($l->t('Teams')); ?></h3>
		<button type="button" id="ts-new" class="button"><?php p($l->t('New team')); ?></button>
	</div>
	<div id="ts-teams" aria-live="polite"><p class="ts-muted"><?php p($l->t('Loading …')); ?></p></div>

	<form id="ts-form" class="ts-form" hidden>
		<h3 id="ts-form-title"><?php p($l->t('New team')); ?></h3>
		<div class="ts-grid" id="ts-zip-row">
			<label for="ts-zip"><?php p($l->t('From team ZIP')); ?></label>
			<input id="ts-zip" type="file" accept=".zip,application/zip" aria-describedby="ts-zip-hint">
			<p id="ts-zip-hint" class="ts-hint ts-muted"><?php p($l->t('Optional: a team backup (ZIP) from “Back up team”. The team is created and the backup imported into it; choose for every person which account here takes over.')); ?></p>
		</div>
		<div id="ts-zip-preview" aria-live="polite"></div>
		<div class="ts-grid">
			<label for="ts-name"><?php p($l->t('Name')); ?></label>
			<input id="ts-name" name="name" type="text" maxlength="100" required autocomplete="off">
			<label for="ts-slug"><?php p($l->t('Short name')); ?></label>
			<input id="ts-slug" name="slug" type="text" maxlength="32" pattern="[a-z0-9\-]{2,32}" required autocomplete="off" aria-describedby="ts-slug-hint" title="<?php p($l->t('Lowercase letters, digits and hyphens, 2 to 32 characters.')); ?>">
			<p id="ts-slug-hint" class="ts-hint ts-muted"><?php p($l->t('Lowercase letters, digits and hyphens, 2 to 32 characters – suggested from the name, e.g. studio-kleinbasel.')); ?></p>
			<label for="ts-g-team"><?php p($l->t('Team group')); ?></label>
			<select id="ts-g-team" name="team" required aria-describedby="ts-g-team-hint"></select>
			<p id="ts-g-team-hint" class="ts-hint ts-muted"><?php p($l->t('All members of the team. The group admins of the team group are the team’s Team Admins.')); ?></p>
			<span class="ts-label"><?php p($l->t('Team Admins')); ?></span>
			<div id="ts-admins" aria-describedby="ts-admins-hint"></div>
			<p id="ts-admins-hint" class="ts-hint ts-muted"><?php p($l->t('Team Admins are the group admins of the team group. Choose the role Team Admin under Members, or appoint them in Nextcloud’s user management.')); ?></p>
			<label for="ts-backup-owner"><?php p($l->t('Backups stored with')); ?></label>
			<select id="ts-backup-owner" name="backup_owner" aria-describedby="ts-backup-owner-hint"></select>
			<p id="ts-backup-owner-hint" class="ts-hint ts-muted"><?php p($l->t('Server backups of people who agreed are stored as files in this account’s folder “TimeSister Backups”.')); ?></p>
			<label for="ts-backup-required"><?php p($l->t('Backup required')); ?></label>
			<input id="ts-backup-required" name="backup_required" type="checkbox" aria-describedby="ts-backup-required-hint">
			<p id="ts-backup-required-hint" class="ts-hint ts-muted"><?php p($l->t('Team Admins require members to enable the backup.')); ?></p>
		</div>
		<h4><?php p($l->t('Members')); ?></h4>
		<div id="ts-members"></div>
		<div id="ts-privacy" hidden>
			<h4><?php p($l->t('Privacy')); ?></h4>
			<p class="ts-hint ts-muted"><?php p($l->t('For the privacy notice every member can download (GET /team/privacy): controller, contact, hosting, retention periods and legal basis. Members, roles, shares and backups are read live from the team. Team Admins can keep these entries from the Mac app as the record setting/privacy. The notice is a template, not legal advice.')); ?></p>
			<div class="ts-grid">
				<label for="ts-p-name"><?php p($l->t('Controller (company)')); ?></label>
				<input id="ts-p-name" type="text" maxlength="500" autocomplete="off">
				<label for="ts-p-address"><?php p($l->t('Address')); ?></label>
				<textarea id="ts-p-address" rows="2" maxlength="500"></textarea>
				<label for="ts-p-contact"><?php p($l->t('Contact (e-mail, phone)')); ?></label>
				<input id="ts-p-contact" type="text" maxlength="500" autocomplete="off">
				<label for="ts-p-dpo"><?php p($l->t('Privacy contact')); ?></label>
				<input id="ts-p-dpo" type="text" maxlength="500" autocomplete="off">
				<label for="ts-p-provider"><?php p($l->t('Hosting provider')); ?></label>
				<input id="ts-p-provider" type="text" maxlength="500" autocomplete="off">
				<label for="ts-p-location"><?php p($l->t('Location of the Nextcloud (country, city)')); ?></label>
				<input id="ts-p-location" type="text" maxlength="500" autocomplete="off">
				<label for="ts-p-time"><?php p($l->t('Retention of time records (years)')); ?></label>
				<input id="ts-p-time" type="number" min="0" max="30" step="1">
				<label for="ts-p-billing"><?php p($l->t('Retention of billing records (years)')); ?></label>
				<input id="ts-p-billing" type="number" min="0" max="30" step="1">
				<label for="ts-p-note"><?php p($l->t('Note on retention')); ?></label>
				<textarea id="ts-p-note" rows="2" maxlength="500"></textarea>
				<label for="ts-p-law"><?php p($l->t('Legal basis')); ?></label>
				<select id="ts-p-law">
					<option value="both"><?php p($l->t('Switzerland and EU')); ?></option>
					<option value="ch"><?php p($l->t('Switzerland (DSG, ArG)')); ?></option>
					<option value="eu"><?php p($l->t('EU (GDPR)')); ?></option>
				</select>
				<label for="ts-p-authority"><?php p($l->t('Supervisory authority')); ?></label>
				<input id="ts-p-authority" type="text" maxlength="500" autocomplete="off">
			</div>
			<div class="ts-buttons">
				<button type="button" id="ts-p-html" class="button"><?php p($l->t('Privacy notice (HTML)')); ?></button>
				<button type="button" id="ts-p-md" class="button"><?php p($l->t('Privacy notice (Markdown)')); ?></button>
			</div>
		</div>
		<p id="ts-error" class="ts-error" role="alert"></p>
		<div class="ts-actions">
			<button type="button" id="ts-cancel" class="button"><?php p($l->t('Cancel')); ?></button>
			<button type="submit" id="ts-save" class="button primary"><?php p($l->t('Save')); ?></button>
		</div>
	</form>

	<form id="ts-import" class="ts-form" hidden>
		<h3 id="ts-import-title"><?php p($l->t('Restore from ZIP')); ?></h3>
		<p class="ts-hint ts-muted"><?php p($l->t('A team backup (ZIP) from “Back up team”. Restoring into the same Nextcloud or moving to another one: choose for every person which account here takes over. Calendars are stored as backups per person; people fetch them in the Mac app under “Old events”.')); ?></p>
		<div class="ts-grid">
			<label for="ts-import-file"><?php p($l->t('Backup (ZIP)')); ?></label>
			<input id="ts-import-file" type="file" accept=".zip,application/zip">
		</div>
		<div id="ts-import-preview" aria-live="polite"></div>
		<p id="ts-import-error" class="ts-error" role="alert"></p>
		<div class="ts-actions">
			<button type="button" id="ts-import-cancel" class="button"><?php p($l->t('Cancel')); ?></button>
			<button type="submit" id="ts-import-go" class="button primary" disabled><?php p($l->t('Import')); ?></button>
		</div>
	</form>

	<form id="ts-delete" class="ts-form" hidden>
		<h3 id="ts-delete-title"><?php p($l->t('Delete team')); ?></h3>
		<p class="ts-hint ts-muted"><?php p($l->t('A ZIP of the team is stored first – protected in the app data and with the backup owner under “TimeSister Backups/_team”. Then records with history, roles, shares, absences and the server backups are removed. The Nextcloud group, the accounts and their time calendars stay.')); ?></p>
		<div class="ts-grid">
			<label for="ts-delete-name"><?php p($l->t('Team name')); ?></label>
			<input id="ts-delete-name" type="text" autocomplete="off" spellcheck="false">
			<p id="ts-delete-ask" class="ts-hint ts-muted"></p>
		</div>
		<div id="ts-delete-result" aria-live="polite"></div>
		<p id="ts-delete-error" class="ts-error" role="alert"></p>
		<div class="ts-actions">
			<button type="button" id="ts-delete-cancel" class="button"><?php p($l->t('Cancel')); ?></button>
			<button type="submit" id="ts-delete-go" class="button primary" disabled><?php p($l->t('Delete team')); ?></button>
		</div>
	</form>

	<h3><?php p($l->t('Status')); ?></h3>
	<div id="ts-state"></div>
</div>
