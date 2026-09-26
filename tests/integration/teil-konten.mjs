// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Die optionale Konten-Gruppe: alle Konten eines Teams, keine Rolle. Kern:
// Ein Gruppenadmin entzieht einem Konto alle vier Rollen, weil es in der
// Konten-Gruppe bleibt (sonst Nextclouds OCS 105). Die Testkonten und
// -gruppen dieses Laufs tragen das Präfix kg<RUN> und werden am Ende gelöscht.
import { ADMIN, as, groupsOf, ocs, sql } from './lib.mjs'
import { A, AA, RUN, check, expect, head } from './harness.mjs'

const PB = groupsOf('pb')
const users = (uid) => `/ocs/v2.php/cloud/users/${encodeURIComponent(uid)}`

/** Konto als Nextcloud-Admin anlegen; false, wenn es nicht geht. */
async function makeUser(uid, groups) {
	const r = await ocs(ADMIN, 'POST', '/ocs/v2.php/cloud/users', { userid: uid, password: `Test-2026-${uid}!`, groups })
	check(`Testkonto ${uid} angelegt`, r.status === 200, r.text.slice(0, 200))
	return r.status === 200
}

async function groupsOfUser(uid) {
	return (await ocs(ADMIN, 'GET', `${users(uid)}/groups`)).data?.groups || []
}

export async function konten() {
	const made = []
	const tmpGroups = []
	let tmpTeam = null
	try {
		head('Konten-Gruppe in /me, /team und /admin/teams')
		{
			const m = await ocs(A, 'GET', '/me')
			check('/me pbadmin: groups.accounts = pb-konten', m.data?.groups?.accounts === 'pb-konten', m.data?.groups)
			const ma = await ocs(AA, 'GET', '/me')
			check('/me atadmin: groups.accounts = at-konten', ma.data?.groups?.accounts === 'at-konten', ma.data?.groups)
			const t = await ocs(A, 'GET', '/team')
			check('/team: groups.accounts, members ohne eigenen Eintrag für die Konten-Gruppe',
				t.status === 200 && t.data?.groups?.accounts === 'pb-konten'
				&& JSON.stringify(Object.keys(t.data?.members || {})) === JSON.stringify(['user', 'lead', 'subadmin', 'admin']), t.text.slice(0, 300))
		}

		head('Konten-Gruppe: Mitgliedschaft nur über die vier Rollen-Gruppen')
		{
			const nur = `kg${RUN}nur`
			if (await makeUser(nur, ['pb-konten'])) {
				made.push(nur)
				expect('Konto nur in der Konten-Gruppe: /me', await ocs(as(nur), 'GET', '/me'), 403, 'no_team')
				const t = await ocs(A, 'GET', '/team')
				check('… und kein Mitglied in /team', t.status === 200 && !JSON.stringify(t.data?.members).includes(nur), t.text.slice(0, 300))
			}
			const zwei = `kg${RUN}zwei`
			if (await makeUser(zwei, ['pb-konten', 'at-mitarbeitende'])) {
				made.push(zwei)
				const r = await ocs(as(zwei), 'GET', '/me')
				check('Konten-Gruppe von pb und Rollen-Gruppe von at: gehört zu at, kein ambiguous_team',
					r.status === 200 && r.data?.team?.slug === 'at' && r.data?.role === 'user', r.text.slice(0, 300))
			}
		}

		head('Konten-Gruppe: Prüfungen der Verwaltung')
		{
			const g = { user: `kg${RUN}-u`, lead: `kg${RUN}-l`, subadmin: `kg${RUN}-s`, admin: `kg${RUN}-a` }
			const acc = `kg${RUN}-k`
			for (const gid of [...Object.values(g), acc]) {
				const r = await ocs(ADMIN, 'POST', '/ocs/v2.php/cloud/groups', { groupid: gid })
				if (r.status === 200) {
					tmpGroups.push(gid)
				}
			}
			const body = (accounts) => ({ name: 'Konten-Test', slug: `kg-${RUN}`, groups: accounts === undefined ? g : { ...g, accounts } })
			expect('Konten-Gruppe eines anderen Teams', await ocs(ADMIN, 'POST', '/admin/teams', body('pb-konten')), 409, 'conflict')
			expect('Rollen-Gruppe eines anderen Teams als Konten-Gruppe', await ocs(ADMIN, 'POST', '/admin/teams', body('pb-leitung')), 409, 'conflict')
			expect('Konten-Gruppe eines anderen Teams als Rollen-Gruppe', await ocs(ADMIN, 'POST', '/admin/teams', { ...body(acc), groups: { ...g, lead: 'at-konten', accounts: acc } }), 409, 'conflict')
			expect('eigene Rollen-Gruppe als Konten-Gruppe', await ocs(ADMIN, 'POST', '/admin/teams', body(g.user)), 422, 'invalid')
			expect('unbekannte Konten-Gruppe', await ocs(ADMIN, 'POST', '/admin/teams', body(`fehlt-${RUN}`)), 422, 'invalid')
			expect('Konten-Gruppe keine Zeichenkette', await ocs(ADMIN, 'POST', '/admin/teams', body(7)), 422, 'invalid')

			const c = await ocs(ADMIN, 'POST', '/admin/teams', body(acc))
			tmpTeam = c.data?.id ?? null
			check('Team mit Konten-Gruppe anlegen: groups.accounts, counts.accounts = 0',
				c.status === 200 && c.data?.groups?.accounts === acc && c.data?.counts?.accounts === 0, c.text.slice(0, 300))
			const leer = await ocs(ADMIN, 'PUT', `/admin/teams/${tmpTeam}`, body(''))
			check('PUT mit leerer Konten-Gruppe entfernt sie: groups und counts ohne accounts',
				leer.status === 200 && !('accounts' in (leer.data?.groups || {})) && !('accounts' in (leer.data?.counts || {})), leer.text.slice(0, 300))
			const wieder = await ocs(ADMIN, 'PUT', `/admin/teams/${tmpTeam}`, body(acc))
			check('PUT setzt sie wieder', wieder.status === 200 && wieder.data?.groups?.accounts === acc, wieder.text.slice(0, 300))
			const ohne = await ocs(ADMIN, 'PUT', `/admin/teams/${tmpTeam}`, body(undefined))
			check('PUT ohne accounts entfernt sie', ohne.status === 200 && !('accounts' in (ohne.data?.groups || {})), ohne.text.slice(0, 300))
			await ocs(ADMIN, 'PUT', `/admin/teams/${tmpTeam}`, body(acc))

			// Konten-Gruppe gelöscht: Zuordnung weg, Team nicht gebrochen.
			const del = await ocs(ADMIN, 'DELETE', `/ocs/v2.php/cloud/groups/${acc}`)
			if (del.status === 200) {
				tmpGroups.splice(tmpGroups.indexOf(acc), 1)
			}
			const list = await ocs(ADMIN, 'GET', '/admin/teams')
			const t = list.data?.find?.((x) => x.id === tmpTeam)
			check('GroupDeletedEvent auf die Konten-Gruppe: Zuordnung entfernt', del.status === 200 && t && !('accounts' in t.groups), t)
			const broken = sql(`SELECT broken_at IS NULL FROM oc_ts_tenants WHERE id = ${Number(tmpTeam)};`)
			if (broken === null) {
				console.log('    --  Team nicht gebrochen: ohne TS_SQL nicht geprüft')
			} else {
				check('… und das Team gilt nicht als gebrochen', broken === '1', broken)
			}
		}

		head('Kern: Gruppenadmin entzieht alle vier Rollen über die Konten-Gruppe')
		{
			const weg = `kg${RUN}weg`
			if (await makeUser(weg, [PB.user, PB.lead, PB.subadmin, PB.admin, 'pb-konten'])) {
				made.push(weg)
				const vorher = await ocs(as(weg), 'GET', '/me')
				check('vorher: Team pb, Rolle admin', vorher.status === 200 && vorher.data?.team?.slug === 'pb' && vorher.data?.role === 'admin', vorher.text.slice(0, 200))
				for (const gid of [PB.admin, PB.subadmin, PB.lead, PB.user]) {
					const r = await ocs(A, 'DELETE', `${users(weg)}/groups`, { groupid: gid }, { form: true })
					check(`pbadmin (Gruppenadmin) nimmt ${weg} aus ${gid}`, r.status === 200, r.text.slice(0, 200))
				}
				const rest = await groupsOfUser(weg)
				check('danach nur noch in pb-konten', JSON.stringify(rest) === JSON.stringify(['pb-konten']), rest)
				expect('danach /me', await ocs(as(weg), 'GET', '/me'), 403, 'no_team')
				const letzte = await ocs(A, 'DELETE', `${users(weg)}/groups`, { groupid: 'pb-konten' }, { form: true })
				check('die letzte verwaltete Gruppe bleibt Nextcloud-Admins vorbehalten (OCS 105)', letzte.status !== 200, `HTTP ${letzte.status} ${letzte.meta?.message}`)
			}
			// Gegenprobe ohne Konten-Gruppe: die letzte Rolle lässt sich nicht entziehen.
			const ohne = `kg${RUN}ohne`
			if (await makeUser(ohne, [PB.user])) {
				made.push(ohne)
				const r = await ocs(A, 'DELETE', `/ocs/v1.php/cloud/users/${ohne}/groups`, { groupid: PB.user }, { form: true })
				check('Gegenprobe ohne Konten-Gruppe: OCS 105', r.meta?.statuscode === 105, `HTTP ${r.status} ${JSON.stringify(r.meta)}`)
			}
		}
	} finally {
		head('Konten-Gruppe: aufräumen')
		if (tmpTeam !== null) {
			expect('Testteam löschen', await ocs(ADMIN, 'DELETE', `/admin/teams/${tmpTeam}`), 200)
		}
		for (const uid of made) {
			const r = await ocs(ADMIN, 'DELETE', users(uid))
			check(`Testkonto ${uid} gelöscht`, r.status === 200, r.text.slice(0, 200))
		}
		for (const gid of tmpGroups) {
			await ocs(ADMIN, 'DELETE', `/ocs/v2.php/cloud/groups/${gid}`)
		}
		const left = (await ocs(ADMIN, 'GET', `/ocs/v2.php/cloud/groups?search=kg${RUN}`)).data?.groups || []
		check('keine Testgruppen übrig', left.length === 0, left)
	}
}
