// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Shared by seed.mjs and integration.mjs. Node ≥ 18 (fetch), no
// dependencies. Runs only against localhost – never against a real server.
//
//   NC_URL          Nextcloud, default http://localhost:8081 (docker-next)
//   NC_ADMIN_USER   default admin
//   NC_ADMIN_PASS   default admin123 (CI: admin)
//   TS_SQL          optional: a command that takes SQL on stdin and prints rows,
//                   e.g. "docker exec -i timesister-next-db-1 mariadb -N -unextcloud -pncpw nextcloud"
//                   or "sqlite3 ../../data/nextcloud.db". Without it: DB checks are skipped.
//   TS_OCC          optional: a command for occ, e.g.
//                   "docker exec -u www-data timesister-next-app-1 php occ". Without it: job checks are skipped.
import { execSync } from 'node:child_process'

export const NC = (process.env.NC_URL || 'http://localhost:8081').replace(/\/$/, '')
export const ADMIN = { user: process.env.NC_ADMIN_USER || 'admin', pass: process.env.NC_ADMIN_PASS || 'admin123' }
export const API = '/ocs/v2.php/apps/timesister/api/v1'

const host = new URL(NC).hostname
if (!['localhost', '127.0.0.1', '::1', '[::1]'].includes(host)) {
	console.error(`Aborting: ${NC} is not local. This check only runs against a test Nextcloud.`)
	process.exit(2)
}

/** Password of the test accounts: Test-2026-<account>! */
export const pw = (uid) => `Test-2026-${uid}!`
export const as = (uid) => ({ user: uid, pass: pw(uid) })

/** The teams from docker-next/aufsetzen.sh (version 2: one team group per team). */
export const TEAMS = [
	{ name: 'Planungsbüro', slug: 'pb', prefix: 'pb' },
	{ name: 'Atelier', slug: 'at', prefix: 'at' },
]
/**
 * Account, display name, team, role. All are in the team group; admin
 * is the team group's group admin, lead and subadmin are app roles.
 */
export const ACCOUNTS = [
	['pbadmin', 'Petra Brunner', 'pb', 'admin'],
	['pbverw', 'Paul Vogel', 'pb', 'subadmin'],
	['pblead', 'Lea Planer', 'pb', 'lead'],
	['pbuser1', 'Mia Muster', 'pb', 'user'],
	['pbuser2', 'Noah Beispiel', 'pb', 'user'],
	['atadmin', 'Anna Keller', 'at', 'admin'],
	['atlead', 'Luca Leitner', 'at', 'lead'],
	['atuser1', 'Tim Test', 'at', 'user'],
]

/** A team's team group. */
export const teamGroupOf = (prefix) => `${prefix}-team`
/** `groups` as in /me, /team and /admin/teams. */
export const groupsOf = (prefix) => ({ team: teamGroupOf(prefix) })

/**
 * An OCS request. `path` starts with `/ocs/…` or is relative to the app API.
 * `Accept-Language` defaults to `en` so checks do not depend on the
 * environment; pass `{ lang: 'de' }` to ask for another language.
 * @returns {Promise<{status:number, data:any, meta:any, text:string}>}
 */
export async function ocs(who, method, path, body, { form = false, raw, lang = 'en' } = {}) {
	const url = NC + (path.startsWith('/ocs/') ? path : API + path)
	const headers = {
		'OCS-APIRequest': 'true',
		Accept: 'application/json',
		'Accept-Language': lang,
		Authorization: 'Basic ' + Buffer.from(`${who.user}:${who.pass}`).toString('base64'),
	}
	let payload
	if (raw !== undefined) {
		headers['Content-Type'] = 'application/json'
		payload = raw
	} else if (body !== undefined) {
		if (form) {
			headers['Content-Type'] = 'application/x-www-form-urlencoded'
			payload = new URLSearchParams(body).toString()
		} else {
			headers['Content-Type'] = 'application/json'
			payload = JSON.stringify(body)
		}
	}
	const res = await fetch(url, { method, headers, body: payload })
	const text = await res.text()
	let json = null
	try {
		json = JSON.parse(text)
	} catch {
		json = null
	}
	return { status: res.status, data: json?.ocs?.data ?? null, meta: json?.ocs?.meta ?? null, text }
}

/** SQL via TS_SQL, otherwise null. */
export function sql(query) {
	if (!process.env.TS_SQL) {
		return null
	}
	return execSync(process.env.TS_SQL, { input: query + '\n', encoding: 'utf8' }).trim()
}

/** occ via TS_OCC, otherwise null. Arguments are each put in '…'. */
export function occ(...args) {
	if (!process.env.TS_OCC) {
		return null
	}
	const quoted = args.map((a) => `'${String(a).replace(/'/g, `'\\''`)}'`).join(' ')
	return execSync(`${process.env.TS_OCC} ${quoted}`, { encoding: 'utf8' }).trim()
}

/**
 * A WebDAV/CalDAV request under /remote.php/dav. `path` without a leading
 * slash, segments already encoded.
 * @returns {Promise<{status:number, text:string}>}
 */
export async function dav(who, method, path, body, headers = {}) {
	const res = await fetch(`${NC}/remote.php/dav/${path}`, {
		method,
		headers: { Authorization: 'Basic ' + Buffer.from(`${who.user}:${who.pass}`).toString('base64'), ...headers },
		body,
	})
	return { status: res.status, text: await res.text() }
}
