// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Legt die Testumgebung an (Fassung 2): zwei Teams mit je einer Teamgruppe,
// die Konten aus lib.mjs darin, die Admins als Gruppenadmins der
// Teamgruppe und die App-Rollen lead und subadmin. Nur über Nextclouds
// Provisioning-API und die Admin-Endpunkte der App – kein occ, kein SQL.
// Idempotent; nimmt nie jemanden aus einer Gruppe. Alte Gruppen aus
// Fassung 1 (pb-mitarbeitende …) bleiben, wie sie sind; die App liest sie nicht.
//
//   NC_URL=http://localhost:8081 node tests/integration/seed.mjs
import { ACCOUNTS, ADMIN, TEAMS, groupsOf, ocs, pw, teamGroupOf } from './lib.mjs'

const log = (ok, msg) => console.log(`    ${ok ? '\x1b[32mok\x1b[0m' : '\x1b[33m--\x1b[0m'}  ${msg}`)

async function ensureGroup(gid, label) {
	const found = await ocs(ADMIN, 'GET', `/ocs/v2.php/cloud/groups?search=${encodeURIComponent(gid)}`)
	if (found.status === 200 && (found.data?.groups || []).includes(gid)) {
		log(false, `Gruppe ${gid} existiert`)
		return
	}
	const r = await ocs(ADMIN, 'POST', '/ocs/v2.php/cloud/groups', { groupid: gid, displayname: label })
	if (r.status !== 200) {
		throw new Error(`Gruppe ${gid}: HTTP ${r.status} ${r.meta?.message || r.text.slice(0, 200)}`)
	}
	log(true, `Gruppe ${gid}`)
}

async function ensureUser(uid, name, groups) {
	const found = await ocs(ADMIN, 'GET', `/ocs/v2.php/cloud/users/${encodeURIComponent(uid)}`)
	if (found.status === 200) {
		log(false, `${uid} existiert`)
		for (const g of groups) {
			if (!(found.data?.groups || []).includes(g)) {
				await ocs(ADMIN, 'POST', `/ocs/v2.php/cloud/users/${encodeURIComponent(uid)}/groups`, { groupid: g })
				log(true, `${uid} in ${g}`)
			}
		}
		return
	}
	const r = await ocs(ADMIN, 'POST', '/ocs/v2.php/cloud/users', {
		userid: uid, password: pw(uid), displayName: name, email: `${uid}@example.test`, groups,
	})
	if (r.status !== 200) {
		throw new Error(`Konto ${uid}: HTTP ${r.status} ${r.meta?.message || r.text.slice(0, 200)}`)
	}
	log(true, `${uid} angelegt`)
}

/** Admin des Teams = Gruppenadmin der Teamgruppe (legt auch Konten an). */
async function ensureSubadmin(uid, gid) {
	const r = await ocs(ADMIN, 'POST', `/ocs/v2.php/cloud/users/${encodeURIComponent(uid)}/subadmins`, { groupid: gid })
	if (r.status !== 200) {
		throw new Error(`Gruppenadmin ${uid} für ${gid}: HTTP ${r.status} ${r.meta?.message || r.text.slice(0, 200)}`)
	}
	log(true, `${uid} verwaltet ${gid}`)
}

async function ensureTeam(team) {
	const body = { name: team.name, slug: team.slug, groups: groupsOf(team.prefix) }
	const list = await ocs(ADMIN, 'GET', '/admin/teams')
	if (list.status !== 200) {
		throw new Error(`GET /admin/teams: HTTP ${list.status} – ist die App aktiv?`)
	}
	const existing = list.data.find((t) => t.slug === team.slug)
	const r = existing
		? await ocs(ADMIN, 'PUT', `/admin/teams/${existing.id}`, body)
		: await ocs(ADMIN, 'POST', '/admin/teams', body)
	if (r.status !== 200) {
		throw new Error(`Team ${team.slug}: HTTP ${r.status} ${JSON.stringify(r.data)}`)
	}
	log(true, `Team ${team.name} (${team.slug}) ${existing ? `bestätigt, id ${existing.id}` : 'angelegt'}`)
	return r.data.id
}

/** App-Rolle setzen und Austritt zurücknehmen: der Grundzustand der Prüfung. */
async function ensureRole(teamId, uid, role) {
	const r = await ocs(ADMIN, 'PUT', `/admin/teams/${teamId}/members/${encodeURIComponent(uid)}`, { role, left: false })
	if (r.status !== 200) {
		throw new Error(`Rolle ${uid}: HTTP ${r.status} ${JSON.stringify(r.data)}`)
	}
	log(true, `${uid}: ${r.data.role}`)
}

console.log('\x1b[1;34m==>\x1b[0m Teamgruppen')
for (const team of TEAMS) {
	await ensureGroup(teamGroupOf(team.prefix), team.name)
}
console.log('\x1b[1;34m==>\x1b[0m Konten')
for (const [uid, name, slug] of ACCOUNTS) {
	await ensureUser(uid, name, [teamGroupOf(slug)])
}
console.log('\x1b[1;34m==>\x1b[0m Admins als Gruppenadmins der Teamgruppe')
for (const [uid, , slug, role] of ACCOUNTS) {
	if (role === 'admin') {
		await ensureSubadmin(uid, teamGroupOf(slug))
	}
}
console.log('\x1b[1;34m==>\x1b[0m Teams über die Admin-Endpunkte')
const ids = {}
for (const team of TEAMS) {
	ids[team.slug] = await ensureTeam(team)
}
console.log('\x1b[1;34m==>\x1b[0m App-Rollen')
for (const [uid, , slug, role] of ACCOUNTS) {
	// admin kommt aus Nextcloud; für alle anderen die Rolle, user eingeschlossen.
	await ensureRole(ids[slug], uid, role === 'admin' ? 'user' : role)
}
