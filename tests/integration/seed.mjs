// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Sets up the test environment (version 2): the teams from lib.mjs, each
// with one team group, the accounts from lib.mjs in them, the admins as group admins of
// the team group, a seed project per team whose lead is the team's Lead (0.11.0:
// the role follows from the projects), “allow overriding” off, and levels
// for the check's own teams when they have none (0.33.0). Only via Nextcloud's
// provisioning API and the app's admin endpoints – no occ, no SQL.
// Idempotent; never removes anyone from a group. Old groups from
// version 1 (pb-mitarbeitende …) stay as they are; the app does not read them.
//
//   NC_URL=http://localhost:8081 node tests/integration/seed.mjs
import { ACCOUNTS, ADMIN, CHECK_LEVELS, TEAMS, as, groupsOf, ocs, pw, seedProjectOf, teamGroupOf } from './lib.mjs'

const log = (ok, msg) => console.log(`    ${ok ? '\x1b[32mok\x1b[0m' : '\x1b[33m--\x1b[0m'}  ${msg}`)

async function ensureGroup(gid, label) {
	const found = await ocs(ADMIN, 'GET', `/ocs/v2.php/cloud/groups?search=${encodeURIComponent(gid)}`)
	if (found.status === 200 && (found.data?.groups || []).includes(gid)) {
		log(false, `group ${gid} exists`)
		return
	}
	const r = await ocs(ADMIN, 'POST', '/ocs/v2.php/cloud/groups', { groupid: gid, displayname: label })
	if (r.status !== 200) {
		throw new Error(`Group ${gid}: HTTP ${r.status} ${r.meta?.message || r.text.slice(0, 200)}`)
	}
	log(true, `group ${gid}`)
}

async function ensureUser(uid, name, groups) {
	const found = await ocs(ADMIN, 'GET', `/ocs/v2.php/cloud/users/${encodeURIComponent(uid)}`)
	if (found.status === 200) {
		log(false, `${uid} exists`)
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
		throw new Error(`Account ${uid}: HTTP ${r.status} ${r.meta?.message || r.text.slice(0, 200)}`)
	}
	log(true, `${uid} created`)
}

/** The team's admin = the team group's group admin (also creates accounts). */
async function ensureSubadmin(uid, gid) {
	const r = await ocs(ADMIN, 'POST', `/ocs/v2.php/cloud/users/${encodeURIComponent(uid)}/subadmins`, { groupid: gid })
	if (r.status !== 200) {
		throw new Error(`Group admin ${uid} for ${gid}: HTTP ${r.status} ${r.meta?.message || r.text.slice(0, 200)}`)
	}
	log(true, `${uid} manages ${gid}`)
}

async function ensureTeam(team) {
	const body = { name: team.name, slug: team.slug, groups: groupsOf(team.prefix) }
	const list = await ocs(ADMIN, 'GET', '/admin/teams')
	if (list.status !== 200) {
		throw new Error(`GET /admin/teams: HTTP ${list.status} – is the app enabled?`)
	}
	const existing = list.data.find((t) => t.slug === team.slug)
	const r = existing
		? await ocs(ADMIN, 'PUT', `/admin/teams/${existing.id}`, body)
		: await ocs(ADMIN, 'POST', '/admin/teams', body)
	if (r.status !== 200) {
		throw new Error(`Team ${team.slug}: HTTP ${r.status} ${JSON.stringify(r.data)}`)
	}
	log(true, `Team ${team.name} (${team.slug}) ${existing ? `confirmed, id ${existing.id}` : 'created'}`)
	return r.data.id
}

/**
 * Set the role, undo any departure, overriding off: the check's baseline
 * state. Lead is not set (0.11.0): the account becomes Lead through the
 * seed project; the derived role must come back as expected.
 */
async function ensureRole(teamId, uid, role) {
	const set = role === 'lead' ? 'user' : role
	const r = await ocs(ADMIN, 'PUT', `/admin/teams/${teamId}/members/${encodeURIComponent(uid)}`, { role: set, left: false })
	if (r.status !== 200 || r.data.role !== role) {
		throw new Error(`Role ${uid}: HTTP ${r.status} ${JSON.stringify(r.data)} (expected ${role})`)
	}
	log(true, `${uid}: ${r.data.role}${role === 'lead' ? ` (derived, ${r.data.lead_of} project)` : ''}`)
}

/** The seed project of a team: its leads are the team's Leads. Never overwritten. */
async function ensureLeadProject(team, leads) {
	const admin = as(ACCOUNTS.find(([, , slug, role]) => slug === team.slug && role === 'admin')[0])
	const key = seedProjectOf(team.prefix)
	const cur = await ocs(admin, 'GET', `/records/project/${key}`)
	if (cur.status === 200) {
		log(false, `project ${key} exists (leads ${JSON.stringify(cur.data?.data?.leads)})`)
		return
	}
	const r = await ocs(admin, 'PUT', `/records/project/${key}`, { version: 0, data: {
		schema: 1, id: key, name: `Seed ${team.name}`, codes: [key.toUpperCase()], subprojects: [], leads, status: 'active' } })
	if (r.status !== 200) {
		throw new Error(`Project ${key}: HTTP ${r.status} ${r.text.slice(0, 200)}`)
	}
	log(true, `project ${key} with leads ${JSON.stringify(leads)}`)
}

/** The check's teams get levels when they have none; other settings stay. */
async function ensureLevels(team) {
	const admin = as(ACCOUNTS.find(([, , slug, role]) => slug === team.slug && role === 'admin')[0])
	const cur = await ocs(admin, 'GET', '/records/setting/settings')
	const data = cur.status === 200 ? cur.data.data : {}
	if (Array.isArray(data.levels) && data.levels.length > 0) {
		log(false, `levels of ${team.slug} exist (${data.levels.map((l) => l.id).join(', ')})`)
		return
	}
	const r = await ocs(admin, 'PUT', '/records/setting/settings', { version: cur.status === 200 ? cur.data.version : 0, data: { ...data, levels: CHECK_LEVELS } })
	if (r.status !== 200) {
		throw new Error(`Levels ${team.slug}: HTTP ${r.status} ${r.text.slice(0, 200)}`)
	}
	log(true, `levels of ${team.slug}: ${CHECK_LEVELS.map((l) => l.id).join(', ')}`)
}

console.log('\x1b[1;34m==>\x1b[0m Team groups')
for (const team of TEAMS) {
	await ensureGroup(teamGroupOf(team.prefix), team.name)
}
console.log('\x1b[1;34m==>\x1b[0m Accounts')
for (const [uid, name, slug] of ACCOUNTS) {
	await ensureUser(uid, name, [teamGroupOf(slug)])
}
console.log('\x1b[1;34m==>\x1b[0m Admins as group admins of the team group')
for (const [uid, , slug, role] of ACCOUNTS) {
	if (role === 'admin') {
		await ensureSubadmin(uid, teamGroupOf(slug))
	}
}
console.log('\x1b[1;34m==>\x1b[0m Teams via the admin endpoints')
const ids = {}
for (const team of TEAMS) {
	ids[team.slug] = await ensureTeam(team)
}
console.log('\x1b[1;34m==>\x1b[0m Seed projects: the Leads')
for (const team of TEAMS) {
	await ensureLeadProject(team, ACCOUNTS.filter(([, , slug, role]) => slug === team.slug && role === 'lead').map(([uid]) => uid))
}
console.log('\x1b[1;34m==>\x1b[0m App roles')
for (const [uid, , slug, role] of ACCOUNTS) {
	// Team Admin stays group admin; everyone else user – Lead follows from the seed project.
	await ensureRole(ids[slug], uid, role)
}
console.log('\x1b[1;34m==>\x1b[0m Levels of the integration check’s own teams')
for (const team of TEAMS.filter((t) => t.check)) {
	await ensureLevels(team)
}
