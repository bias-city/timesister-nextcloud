<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Die Admin-Seite. Inhalt baut js/admin.js (ohne Build, ohne Inline-Skript).
// Texte über $l (IL10N der App), ausgegeben mit p().
/** @var \OCP\IL10N $l */
?>
<div id="timesister-admin" class="section">
	<h2><?php p($l->t('TimeSister')); ?></h2>
	<p class="settings-hint">
		<?php p($l->t('A team builds on an existing Nextcloud group, the team group. Its group admins are the team’s admins: you appoint them in Nextcloud’s user management. The roles project lead and office and leaving the team are set here or in the Mac app; master data and backups in the Mac app.')); ?>
	</p>

	<div class="ts-head">
		<h3><?php p($l->t('Teams')); ?></h3>
		<button type="button" id="ts-new" class="button"><?php p($l->t('New team')); ?></button>
	</div>
	<div id="ts-teams" aria-live="polite"><p class="ts-muted"><?php p($l->t('Loading …')); ?></p></div>

	<form id="ts-form" class="ts-form" hidden>
		<h3 id="ts-form-title"><?php p($l->t('New team')); ?></h3>
		<div class="ts-grid">
			<label for="ts-name"><?php p($l->t('Name')); ?></label>
			<input id="ts-name" name="name" type="text" maxlength="100" required autocomplete="off">
			<label for="ts-slug"><?php p($l->t('Short name')); ?></label>
			<input id="ts-slug" name="slug" type="text" maxlength="32" pattern="[a-z0-9\-]{2,32}" required autocomplete="off">
			<label for="ts-g-team"><?php p($l->t('Team group')); ?></label>
			<select id="ts-g-team" name="team" required aria-describedby="ts-g-team-hint"></select>
			<p id="ts-g-team-hint" class="ts-hint ts-muted"><?php p($l->t('All members of the team. The group admins of the team group are the team’s admins.')); ?></p>
			<span class="ts-label"><?php p($l->t('Admins')); ?></span>
			<div id="ts-admins" aria-describedby="ts-admins-hint"></div>
			<p id="ts-admins-hint" class="ts-hint ts-muted"><?php p($l->t('Admins are the group admins of the team group. You appoint them in Nextcloud’s user management.')); ?></p>
			<label for="ts-backup-owner"><?php p($l->t('Backups stored with')); ?></label>
			<select id="ts-backup-owner" name="backup_owner" aria-describedby="ts-backup-owner-hint"></select>
			<p id="ts-backup-owner-hint" class="ts-hint ts-muted"><?php p($l->t('Server backups of people who agreed are stored as files in this account’s folder “TimeSister-Sicherungen”.')); ?></p>
			<label for="ts-leads-see"><?php p($l->t('Leads see all time calendars')); ?></label>
			<input id="ts-leads-see" name="leads_see_calendars" type="checkbox" aria-describedby="ts-leads-see-hint">
			<p id="ts-leads-see-hint" class="ts-hint ts-muted"><?php p($l->t('Off: leads no longer receive the members’ time calendars; the apps withdraw the share at their next sync.')); ?></p>
			<label for="ts-backup-required"><?php p($l->t('Backup required')); ?></label>
			<input id="ts-backup-required" name="backup_required" type="checkbox" aria-describedby="ts-backup-required-hint">
			<p id="ts-backup-required-hint" class="ts-hint ts-muted"><?php p($l->t('The administration requires members to enable the backup.')); ?></p>
		</div>
		<h4><?php p($l->t('Members')); ?></h4>
		<div id="ts-members"></div>
		<p id="ts-error" class="ts-error" role="alert"></p>
		<div class="ts-actions">
			<button type="button" id="ts-cancel" class="button"><?php p($l->t('Cancel')); ?></button>
			<button type="submit" id="ts-save" class="button primary"><?php p($l->t('Save')); ?></button>
		</div>
	</form>

	<h3><?php p($l->t('Status')); ?></h3>
	<div id="ts-state"></div>
</div>
