<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Die Admin-Seite. Inhalt baut js/admin.js (ohne Build, ohne Inline-Skript).
?>
<div id="timesister-admin" class="section">
	<h2>TimeSister</h2>
	<p class="settings-hint">
		Ein Team ordnet vier bestehende Nextcloud-Gruppen den Rollen zu.
		Konten und Gruppenmitglieder pflegen Sie in der Benutzerverwaltung von Nextcloud;
		Stammdaten, Rollen und Sicherungen in der Mac-App.
	</p>

	<div class="ts-head">
		<h3>Teams</h3>
		<button type="button" id="ts-new" class="button">Neues Team</button>
	</div>
	<div id="ts-teams" aria-live="polite"><p class="ts-muted">Lädt …</p></div>

	<form id="ts-form" class="ts-form" hidden>
		<h3 id="ts-form-title">Neues Team</h3>
		<div class="ts-grid">
			<label for="ts-name">Name</label>
			<input id="ts-name" name="name" type="text" maxlength="100" required autocomplete="off">
			<label for="ts-slug">Kurzname</label>
			<input id="ts-slug" name="slug" type="text" maxlength="32" pattern="[a-z0-9\-]{2,32}" required autocomplete="off">
			<label for="ts-g-user">Mitarbeitende</label>
			<select id="ts-g-user" name="user" required></select>
			<label for="ts-g-lead">Projektleitung</label>
			<select id="ts-g-lead" name="lead" required></select>
			<label for="ts-g-subadmin">Verwaltung</label>
			<select id="ts-g-subadmin" name="subadmin" required></select>
			<label for="ts-g-admin">Admin</label>
			<select id="ts-g-admin" name="admin" required></select>
		</div>
		<p id="ts-error" class="ts-error" role="alert"></p>
		<div class="ts-actions">
			<button type="button" id="ts-cancel" class="button">Abbrechen</button>
			<button type="submit" id="ts-save" class="button primary">Sichern</button>
		</div>
	</form>

	<h3>Zustand</h3>
	<div id="ts-state"></div>
</div>
