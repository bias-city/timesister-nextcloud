// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Removing data (0.43.0): deleting a team stands behind the downloaded
// backup, the typed name and the password; the options take only what the
// app wrote; a foreign calendar, a foreign file and the account survive.
import { execSync } from 'node:child_process'
import { mkdtempSync, writeFileSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { ADMIN, as, dav, ensureTimeCalendar, exportTeam, ocs, putEvent, pw, timeCalendarPath } from './lib.mjs'
import { RUN, check, expect, head } from './harness.mjs'

export async function purge() {
	head('Removing data (0.43.0): team deletion behind backup, name and password; foreign data stays')
	const uid = `zp${RUN}`
	const who = as(uid)
	const gid = `zp-${RUN}-team`
	const name = `Purge Probe ${RUN}`
	let id = null
	try {
		// A throwaway account and group of this check – the only account the check ever removes is its own.
		await ocs(ADMIN, 'POST', '/ocs/v2.php/cloud/groups', { groupid: gid })
		const made = await ocs(ADMIN, 'POST', '/ocs/v2.php/cloud/users', { userid: uid, password: pw(uid), displayName: 'Purge Probe', groups: [gid] })
		check('probe account created', made.status === 200, made.text.slice(0, 200))
		await ocs(ADMIN, 'POST', `/ocs/v2.php/cloud/users/${encodeURIComponent(uid)}/subadmins`, { groupid: gid })
		const team = await ocs(ADMIN, 'POST', '/admin/teams', { name, slug: `zp-${RUN}`, groups: { team: gid }, backup_owner: uid })
		expect('probe team created', team, 200)
		id = team.data?.id
		if (!id) {
			return
		}
		// The member's hours, a private calendar and a private file – and a hand-made file in the admin's TimeSister Backups.
		check('time calendar of the probe account', await ensureTimeCalendar(who))
		check('one booked block', [201, 204].includes(await putEvent(who, `zp-${RUN}-1`, ['DTSTART:20260902T080000Z', 'DTEND:20260902T100000Z', 'SUMMARY:#ZP1 probe'])))
		check('private calendar of the probe account', (await dav(who, 'MKCALENDAR', `calendars/${uid}/privat-${RUN}/`)).status === 201)
		check('private file of the probe account', [201, 204].includes((await dav(who, 'PUT', `files/${uid}/Notes-${RUN}.txt`, 'mine')).status))
		// The probe account is the backup owner (a Team Admin): a hand-made file in its TimeSister Backups/_team must stay.
		await dav(who, 'MKCOL', `files/${uid}/TimeSister Backups/`)
		await dav(who, 'MKCOL', `files/${uid}/TimeSister Backups/_team/`)
		check('hand-made file in the backup owner’s TimeSister Backups/_team', [201, 204].includes((await dav(who, 'PUT', `files/${uid}/TimeSister Backups/_team/keep-${RUN}.txt`, 'keep')).status))
		// One record, written by the probe account as Team Admin.
		const rec = await ocs(who, 'PUT', '/records/project/ZP1', { version: 0, data: { id: 'ZP1', name: 'Purge project', codes: ['ZP1'], status: 'active' } })
		check('a record of the probe team', rec.status === 200, rec.text.slice(0, 200))

		// Without the download: nothing goes.
		const r1 = await ocs(ADMIN, 'DELETE', `/admin/teams/${id}`, { confirm: name })
		check('delete without a downloaded backup → 409 export_required', r1.status === 409 && r1.data?.reason === 'export_required', r1.text.slice(0, 200))
		const p0 = await ocs(ADMIN, 'GET', '/admin/purge')
		const t0 = p0.data?.teams?.find?.((t) => t.id === id)
		check('preview: the probe team with its counts and no backup yet', p0.status === 200 && t0 && t0.calendars === 1 && t0.records >= 1 && t0.export?.at === null && typeof p0.data?.sentence === 'string', JSON.stringify(t0))

		// The download: README and the calendar in the ZIP, the note fresh and complete.
		const ex = await exportTeam(ADMIN, id)
		check('export → 200 with a 64-hex token', ex.status === 200 && /^[0-9a-f]{64}$/.test(ex.token), ex.status)
		const dir = mkdtempSync(join(tmpdir(), 'ts-purge-'))
		try {
			const zipPath = join(dir, 'team.zip')
			writeFileSync(zipPath, ex.bytes)
			const list = execSync(`unzip -Z1 "${zipPath}"`, { encoding: 'utf8' })
			check('ZIP holds README.md, manifest.json, team.json and the member’s calendar', ['README.md', 'manifest.json', 'team.json', `calendars/${uid}.ics`].every((f) => list.split('\n').includes(f)), list.slice(0, 300))
			const readme = execSync(`unzip -p "${zipPath}" README.md`, { encoding: 'utf8' })
			check('README explains records, calendars and the manifest', /## Records/.test(readme) && /## Calendars/.test(readme) && /manifest\.json/.test(readme) && /\| `project` \| 1 \|/.test(readme), readme.slice(0, 400))
		} finally {
			rmSync(dir, { recursive: true, force: true })
		}
		const p1 = await ocs(ADMIN, 'GET', '/admin/purge')
		const t1 = p1.data?.teams?.find?.((t) => t.id === id)
		check('preview: backup noted, fresh, complete, token = SHA-256 of the ZIP', t1?.export?.fresh === true && t1?.export?.complete === true && t1?.export?.token === ex.token, JSON.stringify(t1?.export))

		// A change after the download makes it stale.
		const rec2 = await ocs(who, 'PUT', '/records/project/ZP2', { version: 0, data: { id: 'ZP2', name: 'Second', codes: ['ZP2'], status: 'active' } })
		check('a second record after the download', rec2.status === 200, rec2.text.slice(0, 200))
		const r2 = await ocs(ADMIN, 'DELETE', `/admin/teams/${id}`, { confirm: name, token: ex.token })
		check('delete with a stale backup → 409 export_stale', r2.status === 409 && r2.data?.reason === 'export_stale', r2.text.slice(0, 200))
		const r3 = await ocs(ADMIN, 'DELETE', `/admin/teams/${id}`, { confirm: name, token: 'f'.repeat(64) })
		check('wrong token → 409 (stale speaks first, else export_token)', r3.status === 409, r3.text.slice(0, 200))
		const ex2 = await exportTeam(ADMIN, id)

		// The sentence for the whole app is checked before anything else; a wrong one deletes nothing.
		const s1 = await ocs(ADMIN, 'POST', '/admin/purge', { confirm: 'I want to uninstall all data of the app', tokens: {} })
		expect('purge with the sentence missing its full stop', s1, 422, 'confirm')
		const s2 = await ocs(ADMIN, 'POST', '/admin/purge', { confirm: 'I want to uninstall all data of the app.', tokens: {} })
		// Other teams may carry an older download note (stale) or none (required): either way a 409 before anything goes.
		check('purge without fresh backups of every team → 409 export_*, nothing deleted', s2.status === 409 && /^export_/.test(String(s2.data?.reason)), s2.text.slice(0, 200))
		const still = await ocs(ADMIN, 'GET', `/admin/teams`)
		check('the probe team is still there after the refused purge', still.data?.some?.((t) => t.id === id))

		// Now for real: folders, vacation and the time calendar; the private calendar, the private file and the account stay.
		const del = await ocs(ADMIN, 'DELETE', `/admin/teams/${id}`, { confirm: name, token: ex2.token, folders: true, vacation: true, time_calendars: true })
		expect('delete the probe team with the fresh backup', del, 200)
		check('… removed: the time calendar, nothing left behind', del.data?.removed?.calendars === 1 && del.data?.removed?.calendars_left === 0, JSON.stringify(del.data?.removed))
		check('… the team ZIP of the deletion stayed with the admin (backup before deleting)', typeof del.data?.backup?.protected === 'string', JSON.stringify(del.data?.backup))
		if (del.status === 200) {
			id = null
		}
		check('time calendar of the probe account is gone', (await dav(who, 'PROPFIND', timeCalendarPath(uid), undefined, { Depth: '0' })).status === 404)
		check('private calendar stays', (await dav(who, 'PROPFIND', `calendars/${uid}/privat-${RUN}/`, undefined, { Depth: '0' })).status === 207)
		check('private file stays', (await dav(who, 'PROPFIND', `files/${uid}/Notes-${RUN}.txt`, undefined, { Depth: '0' })).status === 207)
		check('hand-made file in TimeSister Backups/_team stays', (await dav(who, 'PROPFIND', `files/${uid}/TimeSister Backups/_team/keep-${RUN}.txt`, undefined, { Depth: '0' })).status === 207)
		check('… and the team ZIP of the deletion lies next to it', (await dav(who, 'PROPFIND', `files/${uid}/TimeSister Backups/_team/`, undefined, { Depth: '1' })).text.includes(`timesister-zp-${RUN}-`))
		check('the account stays', (await ocs(ADMIN, 'GET', `/ocs/v2.php/cloud/users/${encodeURIComponent(uid)}`)).status === 200)
		check('the group stays', (await ocs(ADMIN, 'GET', `/ocs/v2.php/cloud/groups/${encodeURIComponent(gid)}`)).status === 200)
	} finally {
		if (id) {
			const tok = await exportTeam(ADMIN, id)
			await ocs(ADMIN, 'DELETE', `/admin/teams/${id}`, { confirm: name, token: tok.token })
		}
		// Only what this check created: its own account, group and hand-made file.
		await ocs(ADMIN, 'DELETE', `/ocs/v2.php/cloud/users/${encodeURIComponent(uid)}`)
		await ocs(ADMIN, 'DELETE', `/ocs/v2.php/cloud/groups/${encodeURIComponent(gid)}`)
	}
}
