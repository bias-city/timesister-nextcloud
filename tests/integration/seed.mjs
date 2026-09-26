// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Legt die Testumgebung an: zwei Teams mit je vier Rollen-Gruppen, einer
// Konten-Gruppe (alle Konten des Teams, keine Rolle) und die Konten aus
// lib.mjs. Nur über Nextclouds Provisioning-API und die Admin-Endpunkte der
// App – kein occ, kein SQL. Idempotent; nimmt nie jemanden aus einer Gruppe.
//
//   NC_URL=http://localhost:8081 node tests/integration/seed.mjs
import { ACCOUNTS, ACCOUNTS_LABEL, ADMIN, GROUP_LABEL, TEAMS, accountsOf, groupsOf, ocs, pw, teamGroupsOf } from './lib.mjs'

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

async function ensureTeam(team) {
	const body = { name: team.name, slug: team.slug, groups: teamGroupsOf(team.prefix) }
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
}

for (const team of TEAMS) {
	console.log(`\x1b[1;34m==>\x1b[0m Gruppen ${team.name}`)
	for (const [role, gid] of Object.entries(groupsOf(team.prefix))) {
		await ensureGroup(gid, `${team.name} – ${GROUP_LABEL[role]}`)
	}
	await ensureGroup(accountsOf(team.prefix), `${team.name} – ${ACCOUNTS_LABEL}`)
}
console.log('\x1b[1;34m==>\x1b[0m Konten')
for (const [uid, name, slug, role] of ACCOUNTS) {
	const g = groupsOf(slug)
	await ensureUser(uid, name, role === 'user' ? [g.user, accountsOf(slug)] : [g.user, g[role], accountsOf(slug)])
}
console.log('\x1b[1;34m==>\x1b[0m Teams über die Admin-Endpunkte')
for (const team of TEAMS) {
	await ensureTeam(team)
}
