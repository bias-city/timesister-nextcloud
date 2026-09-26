// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Fassung 2: Mitglied über die Teamgruppe, Admin als Gruppenadmin, App-Rollen
// und Austritt (PUT /team/members, Admin-Seite), Kalenderfreigabe. Die
// Testkonten dieses Laufs heissen kr<RUN>… und werden am Ende gelöscht.
import { ADMIN, as, ocs, sql } from './lib.mjs'
import { A, AA, ISO, L, RUN, U1, V, check, expect, head } from './harness.mjs'

const users = (uid) => `/ocs/v2.php/cloud/users/${encodeURIComponent(uid)}`
const member = (uid) => `/team/members/${encodeURIComponent(uid)}`

/** Konto als Nextcloud-Admin anlegen; false, wenn es nicht geht. */
async function makeUser(uid, groups) {
	const r = await ocs(ADMIN, 'POST', '/ocs/v2.php/cloud/users', { userid: uid, password: `Test-2026-${uid}!`, groups })
	check(`Testkonto ${uid} angelegt`, r.status === 200, r.text.slice(0, 200))
	return r.status === 200
}

export async function konten() {
	const made = []
	const kr = `kr${RUN}`
	const ga = `kr${RUN}ga`
	const pbId = (await ocs(ADMIN, 'GET', '/admin/teams')).data?.find?.((t) => t.slug === 'pb')?.id
	try {
		head('GET /team: Mitglieder mit Rolle')
		{
			const t = await ocs(A, 'GET', '/team')
			const list = t.data?.members || []
			const by = Object.fromEntries(list.map((x) => [x.uid, x]))
			check('pb: Rollen aus Gruppenadmin und App-Rolle', t.status === 200 && by.pbadmin?.role === 'admin' && by.pbverw?.role === 'subadmin'
				&& by.pblead?.role === 'lead' && by.pbuser1?.role === 'user' && by.pbuser2?.role === 'user', list)
			check('Einträge mit uid, display_name, role, left_at; keine at-Konten', list.every((x) => JSON.stringify(Object.keys(x)) === '["uid","display_name","role","left_at"]')
				&& by.pblead?.display_name === 'Lea Planer' && by.pblead?.left_at === null && !list.some((x) => x.uid.startsWith('at')), list[0])
		}

		if (await makeUser(kr, ['pb-team'])) {
			made.push(kr)
			head('PUT /team/members: Rechte')
			expect('user setzt lead', await ocs(U1, 'PUT', member(kr), { role: 'lead' }), 403, 'forbidden')
			expect('lead setzt lead', await ocs(L, 'PUT', member(kr), { role: 'lead' }), 403, 'forbidden')
			const r = await ocs(V, 'PUT', member(kr), { role: 'lead' })
			check('Verwaltung setzt lead → Eintrag wie in /team', r.status === 200 && r.data?.uid === kr && r.data?.role === 'lead' && r.data?.left_at === null, r.text)
			expect('Verwaltung vergibt subadmin', await ocs(V, 'PUT', member(kr), { role: 'subadmin' }), 403, 'forbidden')
			expect('admin ist keine App-Rolle', await ocs(A, 'PUT', member(kr), { role: 'admin' }), 422, 'invalid')
			expect('leerer Rumpf', await ocs(A, 'PUT', member(kr), {}), 400, 'invalid')
			expect('left kein Wahrheitswert', await ocs(A, 'PUT', member(kr), { left: 'ja' }), 422, 'invalid')
			expect('Konto eines anderen Teams', await ocs(A, 'PUT', member('atuser1'), { role: 'lead' }), 404, 'not_found')
			expect('Konto, das es nicht gibt', await ocs(A, 'PUT', member(`nie${RUN}`), { role: 'lead' }), 404, 'not_found')
			expect('at-Admin für ein pb-Konto', await ocs(AA, 'PUT', member(kr), { role: 'user' }), 404, 'not_found')
			const s = await ocs(A, 'PUT', member(kr), { role: 'subadmin' })
			check('Admin vergibt subadmin', s.status === 200 && s.data?.role === 'subadmin', s.text)
			check('… /me des Kontos: subadmin', (await ocs(as(kr), 'GET', '/me')).data?.role === 'subadmin')
			expect('Verwaltung ändert eine andere Verwaltung', await ocs(V, 'PUT', member(kr), { role: 'user' }), 403, 'forbidden')
			expect('Verwaltung vermerkt den Austritt des Admins', await ocs(V, 'PUT', member('pbadmin'), { left: true }), 403, 'forbidden')
			expect('eigener Austritt', await ocs(A, 'PUT', member('pbadmin'), { left: true }), 403, 'forbidden')
			await ocs(A, 'PUT', member(kr), { role: 'lead' })

			head('Austritt und Wiederaufnahme')
			const vorher = (await ocs(as(kr), 'GET', '/me')).data
			check('vorher: lead, steht in leads', vorher?.role === 'lead' && vorher?.leads?.includes(kr), vorher)
			const weg = await ocs(V, 'PUT', member(kr), { left: true })
			check('left: true → left_at gesetzt, Rolle bleibt', weg.status === 200 && ISO.test(weg.data?.left_at || '') && weg.data?.role === 'lead', weg.text)
			expect('danach /me', await ocs(as(kr), 'GET', '/me'), 403, 'no_team')
			expect('… und /records', await ocs(as(kr), 'GET', '/records'), 403, 'no_team')
			check('… nicht mehr in leads der anderen', !(await ocs(U1, 'GET', '/me')).data?.leads?.includes(kr))
			const e = ((await ocs(A, 'GET', '/team')).data?.members || []).find((x) => x.uid === kr)
			check('/team nennt den Ausgetretenen mit Datum', e?.left_at === weg.data?.left_at && e?.role === 'lead', e)
			check('/status nennt ihn nicht', !((await ocs(A, 'GET', '/status')).data || []).some((x) => x.uid === kr))
			const counts = (await ocs(ADMIN, 'GET', '/admin/teams')).data?.find?.((t) => t.id === pbId)?.counts
			check('/admin/teams zählt ihn unter left', counts?.left === 1 && counts?.lead === 1, counts)
			const again = await ocs(V, 'PUT', member(kr), { left: true })
			check('zweimal left: true behält das erste Datum', again.data?.left_at === weg.data?.left_at, again.text)
			const back = await ocs(V, 'PUT', member(kr), { left: false })
			check('left: false nimmt wieder auf', back.status === 200 && back.data?.left_at === null, back.text)
			const nachher = (await ocs(as(kr), 'GET', '/me')).data
			check('Wiederaufnahme gibt die Rolle zurück', nachher?.role === 'lead' && nachher?.team?.slug === 'pb', nachher)

			// Ausgetreten aus pb und in at-team: gehört zu at, nicht zu zwei Teams.
			await ocs(V, 'PUT', member(kr), { left: true })
			await ocs(ADMIN, 'POST', `${users(kr)}/groups`, { groupid: 'at-team' })
			try {
				const at = await ocs(as(kr), 'GET', '/me')
				check('ausgetreten aus pb, in at-team: Team at, Rolle user', at.status === 200 && at.data?.team?.slug === 'at' && at.data?.role === 'user', at.text.slice(0, 300))
				await ocs(V, 'PUT', member(kr), { left: false })
				expect('wieder in pb aufgenommen, dazu in at-team', await ocs(as(kr), 'GET', '/me'), 409, 'ambiguous_team')
			} finally {
				await ocs(ADMIN, 'DELETE', `${users(kr)}/groups`, { groupid: 'at-team' }, { form: true })
			}

			head('Kalenderfreigabe: /me/calendar-share und calendar_shared')
			const K = as(kr)
			const d = await ocs(K, 'GET', '/me/calendar-share')
			check('Standard: an', d.status === 200 && JSON.stringify(d.data) === '{"enabled":true}', d.text)
			const off = await ocs(K, 'PUT', '/me/calendar-share', { enabled: false })
			check('PUT false', off.status === 200 && off.data?.enabled === false, off.text)
			check('GET und /me lesen false', (await ocs(K, 'GET', '/me/calendar-share')).data?.enabled === false
				&& (await ocs(K, 'GET', '/me')).data?.calendar_share === false)
			check('andere Konten unberührt', (await ocs(U1, 'GET', '/me')).data?.calendar_share !== undefined)
			expect('enabled kein Wahrheitswert', await ocs(K, 'PUT', '/me/calendar-share', { enabled: 'nein' }), 400, 'invalid')
			expect('uid im Rumpf', await ocs(K, 'PUT', '/me/calendar-share', { enabled: true, uid: 'pbuser1' }), 400, 'invalid')
			check('PUT true', (await ocs(K, 'PUT', '/me/calendar-share', { enabled: true })).data?.enabled === true)
			expect('Nextcloud-Admin ohne Team', await ocs(ADMIN, 'GET', '/me/calendar-share'), 403, 'no_team')

			const sh = await ocs(K, 'POST', '/status', { calendar_shared: false })
			const st1 = ((await ocs(V, 'GET', '/status')).data || []).find((x) => x.uid === kr)
			check('POST /status calendar_shared false → GET /status', sh.status === 200 && st1?.calendar_shared === false, st1)
			await ocs(K, 'POST', '/status', { app_version: '0.3.0' })
			const st2 = ((await ocs(V, 'GET', '/status')).data || []).find((x) => x.uid === kr)
			check('fehlt es, bleibt es', st2?.calendar_shared === false && st2?.app_version === '0.3.0', st2)
			await ocs(K, 'POST', '/status', { calendar_shared: true })
			check('true', ((await ocs(V, 'GET', '/status')).data || []).find((x) => x.uid === kr)?.calendar_shared === true)
			expect('calendar_shared kein Wahrheitswert', await ocs(K, 'POST', '/status', { calendar_shared: 1 }), 422, 'invalid')
		}

		head('Admin = Gruppenadmin der Teamgruppe')
		if (await makeUser(ga, [])) {
			made.push(ga)
			expect('ohne Gruppe: /me', await ocs(as(ga), 'GET', '/me'), 403, 'no_team')
			const sub = await ocs(ADMIN, 'POST', `${users(ga)}/subadmins`, { groupid: 'pb-team' })
			check('Nextcloud-Admin macht das Konto zum Gruppenadmin von pb-team', sub.status === 200, sub.text.slice(0, 200))
			const m = await ocs(as(ga), 'GET', '/me')
			check('Gruppenadmin, nicht in der Gruppe: Team pb, Rolle admin', m.status === 200 && m.data?.team?.slug === 'pb' && m.data?.role === 'admin', m.text.slice(0, 300))
			check('… in admins neben pbadmin', m.data?.admins?.includes(ga) && m.data?.admins?.includes('pbadmin'), m.data?.admins)
			check('… und in /team als admin', ((await ocs(A, 'GET', '/team')).data?.members || []).some((x) => x.uid === ga && x.role === 'admin'))
			const st = ((await ocs(V, 'GET', '/status')).data || []).find((x) => x.uid === ga)
			check('… und in /status, calendar_shared nie gemeldet: null', st && st.calendar_shared === null, st)
		}

		head('Rollen von der Admin-Seite (PUT /admin/teams/{id}/members/{uid})')
		{
			expect('als pbadmin (kein Nextcloud-Admin)', await ocs(A, 'PUT', `/admin/teams/${pbId}/members/pbuser2`, { role: 'lead' }), 403)
			const r = await ocs(ADMIN, 'PUT', `/admin/teams/${pbId}/members/pbuser2`, { role: 'subadmin' })
			check('Nextcloud-Admin vergibt subadmin', r.status === 200 && r.data?.role === 'subadmin', r.text)
			const back = await ocs(ADMIN, 'PUT', `/admin/teams/${pbId}/members/pbuser2`, { role: 'user' })
			check('… und nimmt sie zurück', back.status === 200 && back.data?.role === 'user', back.text)
			expect('App-Rolle admin', await ocs(ADMIN, 'PUT', `/admin/teams/${pbId}/members/pbuser2`, { role: 'admin' }), 422, 'invalid')
			expect('unbekanntes Team', await ocs(ADMIN, 'PUT', '/admin/teams/999999/members/pbuser2', { role: 'lead' }), 404, 'not_found')
			expect('Konto nicht in der Teamgruppe', await ocs(ADMIN, 'PUT', `/admin/teams/${pbId}/members/atuser1`, { role: 'lead' }), 404, 'not_found')
		}
	} finally {
		head('Rollen: aufräumen')
		for (const uid of made) {
			const r = await ocs(ADMIN, 'DELETE', users(uid))
			check(`Testkonto ${uid} gelöscht`, r.status === 200, r.text.slice(0, 200))
		}
		const left = sql(`SELECT COUNT(*) FROM oc_ts_members WHERE uid LIKE 'kr${RUN}%';`)
		if (left === null) {
			console.log('    --  App-Rollen gelöschter Konten: ohne TS_SQL nicht geprüft')
		} else {
			check('UserDeletedEvent: App-Rolle und Austritt entfernt', left === '0', left)
		}
	}
}
