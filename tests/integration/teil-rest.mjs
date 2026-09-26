// SPDX-License-Identifier: AGPL-3.0-or-later
import { ADMIN, as, ocs, sql } from './lib.mjs'
import { A, AA, AL, AU, C, L, NOAH, P, R, RUN, U1, U2, V, ISO, b64, check, expect, head, isObj, keysOf, me, sha256, ensure } from './harness.mjs'
import { restoreConsent } from './teil-sicherungen.mjs'

export async function rest() {
	await kalendersicherungen()
	await restOhneSicherungen()
}

/** POST/GET /backups (Fassung 1), eigener Teil: läuft auch allein. */
export async function kalendersicherungen() {
	let backupId
	head('Kalendersicherungen')
	// Seit Fassung 1.1 nur mit Freigabe; danach wieder wie vorher.
	const prior = (await ocs(U1, 'GET', '/backups/consent')).data
	await ocs(U1, 'PUT', '/backups/consent', { consent: true, notice: '2026-09-26.2' })
	try {
		const ics1 = `BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//TimeSister//Test ${RUN}//DE\r\nEND:VCALENDAR\r\n`
		const ics2 = ics1.replace('Test', 'Zweite')
		const up = await ocs(U1, 'POST', '/backups', { taken_on: '2099-12-31', ics_base64: b64(ics1) })
		expect('Sicherung hochladen', up, 200)
		backupId = up.data?.id
		check('Antwort mit id, uid, taken_on, size, sha256', Number.isInteger(backupId) && up.data?.uid === 'pbuser1' && up.data?.taken_on === '2099-12-31'
			&& up.data?.size === Buffer.byteLength(ics1) && up.data?.sha256 === sha256(ics1), up.text)
		check('hochgeladen: source client, sichtbare Kopie beim Sicherungs-Konto', up.data?.source === 'client'
			&& up.data?.file_path === 'TimeSister-Sicherungen/Mia Muster (pbuser1)/2099-12-31.ics', up.text)
		const up2 = await ocs(U1, 'POST', '/backups', { taken_on: '2099-12-31', ics_base64: b64(ics2) })
		check('zweite am selben Tag ersetzt die erste (gleiche id, neuer Hash)', up2.status === 200 && up2.data?.id === backupId && up2.data?.sha256 === sha256(ics2), up2.text)
		expect('ältere Sicherung', await ocs(U1, 'POST', '/backups', { taken_on: '2099-12-30', ics_base64: b64(ics1) }), 200)
		const list = await ocs(U1, 'GET', '/backups')
		const days = (list.data || []).map((x) => x.taken_on)
		check('Liste neueste zuerst, eine je Tag, ohne Inhalt', list.status === 200 && days.indexOf('2099-12-31') < days.indexOf('2099-12-30') && days.filter((d) => d === '2099-12-31').length === 1
			&& days.indexOf('2099-12-30') >= 0 && !('ics_base64' in (list.data?.[0] || {})), list.text)
		const one = await ocs(U1, 'GET', `/backups/${backupId}`)
		check('GET /backups/{id} mit ics_base64', one.status === 200 && Buffer.from(one.data?.ics_base64 || '', 'base64').toString() === ics2, one.text.slice(0, 200))
		expect('falscher Tag', await ocs(U1, 'POST', '/backups', { taken_on: '2026-02-30', ics_base64: b64(ics1) }), 422, 'invalid')
		expect('kein Kalender', await ocs(U1, 'POST', '/backups', { taken_on: '2026-09-21', ics_base64: b64('BEGIN:VCARD\r\nEND:VCARD\r\n') }), 422, 'invalid')
		expect('kein Base64', await ocs(U1, 'POST', '/backups', { taken_on: '2026-09-21', ics_base64: '%%%' }), 422, 'invalid')
		const headIcs = 'BEGIN:VCALENDAR\r\n'
		const exact = headIcs + 'x'.repeat(20 * 1024 * 1024 - headIcs.length)
		const ex = await ocs(U1, 'POST', '/backups', { taken_on: '2000-01-01', ics_base64: b64(exact) })
		check('genau 20 MB gehen', ex.status === 200 && ex.data?.size === 20 * 1024 * 1024, ex.text.slice(0, 200))
		expect('20 MB + 1 Byte', await ocs(U1, 'POST', '/backups', { taken_on: '2000-01-02', ics_base64: b64(exact + 'y') }), 413, 'too_large')

		expect('fremde Sicherung als user', await ocs(U2, 'GET', `/backups/${backupId}`), 403, 'forbidden')
		expect('fremde Liste als user', await ocs(U2, 'GET', '/backups?uid=pbuser1'), 403, 'forbidden')
		expect('fremde Sicherung als lead', await ocs(L, 'GET', `/backups/${backupId}`), 403, 'forbidden')
		const vl = await ocs(V, 'GET', '/backups?uid=pbuser1')
		check('Verwaltung sieht fremde Liste', vl.status === 200 && vl.data?.some?.((x) => x.id === backupId))
		expect('Verwaltung holt fremde Sicherung', await ocs(A, 'GET', `/backups/${backupId}`), 200)
		expect('at-Admin holt pb-Sicherung', await ocs(AA, 'GET', `/backups/${backupId}`), 404, 'not_found')
		const al = await ocs(AA, 'GET', '/backups?uid=pbuser1')
		check('at-Admin: Liste für pb-Konto leer', al.status === 200 && Array.isArray(al.data) && al.data.length === 0, al.text)
		expect('unbekannte Sicherung', await ocs(A, 'GET', '/backups/999999999'), 404, 'not_found')
	} finally {
		await restoreConsent(U1, prior)
	}
}

async function restOhneSicherungen() {

	// ---------------------------------------------------------------------------
	head('Lebenszeichen')
	{
		const s = await ocs(U1, 'POST', '/status', {
			app_version: '0.2.0', last_sync: '2026-09-26T08:15:00Z', last_backup: '2026-09-22',
			calendar_url: 'http://localhost:8081/remote.php/dav/calendars/pbuser1/zeit-pbuser1/',
		})
		check('POST /status → seen_at', s.status === 200 && ISO.test(s.data?.seen_at), s.text)
		expect('calendar_url javascript:', await ocs(U1, 'POST', '/status', { calendar_url: 'javascript:alert(1)' }), 422, 'invalid')
		expect('last_sync kein ISO', await ocs(U1, 'POST', '/status', { last_sync: 'gestern' }), 422, 'invalid')
		// Ein frisches Konto, das sicher noch nie gemeldet hat (die Umgebung teilen sich mehrere Prüfer).
		const still = `still${RUN}`
		await ocs(ADMIN, 'POST', '/ocs/v2.php/cloud/users', { userid: still, password: `Test-2026-${still}!`, groups: ['pb-team'] })
		const list = await ocs(V, 'GET', '/status')
		const by = Object.fromEntries((list.data || []).map((x) => [x.uid, x]))
		check('alle pb-Mitglieder, auch ohne Lebenszeichen', ['pbadmin', 'pblead', 'pbuser1', 'pbuser2', 'pbverw', still].every((u) => u in by) && !Object.keys(by).some((u) => u.startsWith('at')), Object.keys(by))
		check('pbuser1 mit seinen Angaben', by.pbuser1?.app_version === '0.2.0' && by.pbuser1?.last_sync === '2026-09-26T08:15:00Z'
			&& by.pbuser1?.last_backup === '2026-09-22' && by.pbuser1?.role === 'user' && by.pbuser1?.display_name === 'Mia Muster', by.pbuser1)
		check('neues Konto ohne Lebenszeichen: alles null', by[still] && by[still].seen_at === null && by[still].app_version === null && by[still].calendar_url === null && by[still].role === 'user', by[still])
		check('Rollen aus Gruppenadmin und App-Rolle', by.pbadmin?.role === 'admin' && by.pbverw?.role === 'subadmin' && by.pblead?.role === 'lead')
		const part = await ocs(U1, 'POST', '/status', { app_version: '0.2.1' })
		const after = Object.fromEntries(((await ocs(V, 'GET', '/status')).data || []).map((x) => [x.uid, x]))
		check('fehlende Felder bleiben stehen', part.status === 200 && after.pbuser1?.app_version === '0.2.1' && after.pbuser1?.last_backup === '2026-09-22', after.pbuser1)
		await ocs(ADMIN, 'DELETE', `/ocs/v2.php/cloud/users/${still}`)
	}

	// ---------------------------------------------------------------------------
	head('Konto in zwei Teams, Konto gelöscht')
	{
		await ocs(ADMIN, 'POST', '/ocs/v2.php/cloud/users/pbuser1/groups', { groupid: 'at-team' })
		try {
			expect('pbuser1 zusätzlich in at: /me', await ocs(U1, 'GET', '/me'), 409, 'ambiguous_team')
			expect('… und /records', await ocs(U1, 'GET', '/records'), 409, 'ambiguous_team')
			expect('… und /backups', await ocs(U1, 'GET', '/backups'), 409, 'ambiguous_team')
		} finally {
			await ocs(ADMIN, 'DELETE', '/ocs/v2.php/cloud/users/pbuser1/groups', { groupid: 'at-team' }, { form: true })
		}
		const back = await ocs(U1, 'GET', '/me')
		check('aufgeräumt: pbuser1 wieder nur in pb', back.status === 200 && back.data?.team?.slug === 'pb', back.text)

		const tmp = `weg${RUN}`
		await ocs(ADMIN, 'POST', '/ocs/v2.php/cloud/users', { userid: tmp, password: `Test-2026-${tmp}!`, groups: ['pb-team'] })
		const pr = await ocs(A, 'PUT', `/records/person/${tmp}`, { version: 0, data: { login: tmp, first_name: 'Weg' } })
		const del = await ocs(ADMIN, 'DELETE', `/ocs/v2.php/cloud/users/${tmp}`)
		check('Konto gelöscht', pr.status === 200 && del.status === 200, `${pr.status} ${del.status}`)
		const still = await ocs(A, 'GET', `/records/person/${tmp}`)
		check('Personendatensatz bleibt, data und Fassung unverändert', still.status === 200 && still.data?.version === 1 && still.data?.data?.first_name === 'Weg'
			&& still.data?.revision === pr.data?.revision, still.text)
		const mark = sql(`SELECT account_deleted_at IS NOT NULL FROM oc_ts_records WHERE kind = 'person' AND rkey = '${tmp}';`)
		if (mark === null) {
			console.log('    --  Vermerk account_deleted_at: ohne TS_SQL nicht geprüft')
		} else {
			check('UserDeletedEvent: Vermerk account_deleted_at gesetzt', mark === '1', mark)
		}
	}

	// ---------------------------------------------------------------------------
	head('Team mit Datensätzen löschen')
	{
		const pb = (await ocs(ADMIN, 'GET', '/admin/teams')).data?.find?.((t) => t.slug === 'pb')
		expect('DELETE /admin/teams/{pb}', await ocs(ADMIN, 'DELETE', `/admin/teams/${pb?.id}`), 409, 'conflict')
	}

	// ---------------------------------------------------------------------------
	head('Aufräumen')
	{
		// Alles, was dieser Lauf angelegt hat, als Grabstein löschen: Ein frischer
		// Client (since=0) sieht danach nur die festen Testdaten.
		for (const who of [A, AA]) {
			const live = ((await ocs(who, 'GET', '/records?since=0')).data?.records || []).filter((x) => x.key.includes(RUN))
			for (let i = 0; i < live.length; i += 500) {
				const writes = live.slice(i, i + 500).map((x) => ({ kind: x.kind, key: x.key, version: x.version, data: null }))
				const r = await ocs(who, 'POST', '/records/batch', { writes })
				check(`${who.user}: ${writes.length} Testdatensätze gelöscht`, r.status === 200, r.text.slice(0, 300))
			}
		}
	}

}
