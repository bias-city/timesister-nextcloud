<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// The personal page (0.49.0). js/personal.js fills the time calendar and the list.
/** @var \OCP\IL10N $l */
/** @var array{member:bool,reason:string} $_ */
?>
<div id="timesister-personal" class="section">
	<h2><?php p($l->t('TimeSister')); ?></h2>
<?php if (!$_['member']) { ?>
	<p class="settings-hint"><?php p($_['reason']); ?></p>
<?php } else { ?>
	<p class="settings-hint">
		<?php p($l->t('Only one of your calendars counts as your time calendar. Entries with a project code in other calendars are shown, but never counted. The Mac app creates a calendar “TimeSister – <name>” when you sign in and names it itself; without the Mac app, choose your time calendar here.')); ?>
	</p>
	<p id="ts-p-team" class="ts-muted"></p>

	<h3><?php p($l->t('Time calendar')); ?></h3>
	<div id="ts-p-current" aria-live="polite"><p class="ts-muted"><?php p($l->t('Loading …')); ?></p></div>
	<div class="ts-p-buttons">
		<button type="button" id="ts-p-stop" class="button" hidden><?php p($l->t('Stop counting this calendar')); ?></button>
	</div>

	<h3><?php p($l->t('Your calendars')); ?></h3>
	<div id="ts-p-calendars"></div>
	<p id="ts-p-message" class="ts-muted" aria-live="polite"></p>
	<p id="ts-p-error" class="ts-error" role="alert"></p>
<?php } ?>
</div>
