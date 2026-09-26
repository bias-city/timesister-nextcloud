<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Die Admin-Seite. Inhalt baut js/admin.js (ohne Build, ohne Inline-Skript).
// Texte über $l (IL10N der App), ausgegeben mit p().
/** @var \OCP\IL10N $l */
?>
<div id="timesister-admin" class="section">
	<h2><?php p($l->t('TimeSister')); ?></h2>
	<p class="settings-hint">
		<?php p($l->t('A team maps four existing Nextcloud groups to the roles. You manage accounts and group members in Nextcloud’s user management; master data, roles and backups in the Mac app.')); ?>
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
			<label for="ts-g-user"><?php p($l->t('Staff')); ?></label>
			<select id="ts-g-user" name="user" required></select>
			<label for="ts-g-lead"><?php p($l->t('Project lead')); ?></label>
			<select id="ts-g-lead" name="lead" required></select>
			<label for="ts-g-subadmin"><?php p($l->t('Office')); ?></label>
			<select id="ts-g-subadmin" name="subadmin" required></select>
			<label for="ts-g-admin"><?php p($l->t('Admin')); ?></label>
			<select id="ts-g-admin" name="admin" required></select>
			<label for="ts-g-accounts"><?php p($l->t('Accounts group (optional)')); ?></label>
			<select id="ts-g-accounts" name="accounts" aria-describedby="ts-g-accounts-hint"></select>
			<p id="ts-g-accounts-hint" class="ts-hint ts-muted"><?php p($l->t('All accounts of the team, not a role. Lets team admins remove every role.')); ?></p>
		</div>
		<p id="ts-error" class="ts-error" role="alert"></p>
		<div class="ts-actions">
			<button type="button" id="ts-cancel" class="button"><?php p($l->t('Cancel')); ?></button>
			<button type="submit" id="ts-save" class="button primary"><?php p($l->t('Save')); ?></button>
		</div>
	</form>

	<h3><?php p($l->t('Status')); ?></h3>
	<div id="ts-state"></div>
</div>
