// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Gemeinsames für seed.mjs und integration.mjs. Node ≥ 18 (fetch), keine
// Abhängigkeiten. Läuft nur gegen localhost – nie gegen einen echten Server.
//
//   NC_URL          Nextcloud, Standard http://localhost:8081 (docker-next)
//   NC_ADMIN_USER   Standard admin
//   NC_ADMIN_PASS   Standard admin123 (CI: admin)
//   TS_SQL          optional: Befehl, der SQL auf stdin nimmt und Zeilen ausgibt,
//                   z. B. "docker exec -i timesister-next-db-1 mariadb -N -unextcloud -pncpw nextcloud"
//                   oder "sqlite3 ../../data/nextcloud.db". Ohne: DB-Prüfungen entfallen.
//   TS_OCC          optional: Befehl für occ, z. B.
//                   "docker exec -u www-data timesister-next-app-1 php occ". Ohne: Job-Prüfungen entfallen.
import { execSync } from 'node:child_process'

export const NC = (process.env.NC_URL || 'http://localhost:8081').replace(/\/$/, '')
export const ADMIN = { user: process.env.NC_ADMIN_USER || 'admin', pass: process.env.NC_ADMIN_PASS || 'admin123' }
export const API = '/ocs/v2.php/apps/timesister/api/v1'

const host = new URL(NC).hostname
if (!['localhost', '127.0.0.1', '::1', '[::1]'].includes(host)) {
	console.error(`Abbruch: ${NC} ist nicht lokal. Diese Prüfung läuft nur gegen eine Test-Nextcloud.`)
	process.exit(2)
}

/** Passwort der Testkonten: Test-2026-<konto>! */
export const pw = (uid) => `Test-2026-${uid}!`
export const as = (uid) => ({ user: uid, pass: pw(uid) })

/** Die Teams aus docker-next/aufsetzen.sh (Fassung 2: eine Teamgruppe je Team). */
export const TEAMS = [
	{ name: 'Planungsbüro', slug: 'pb', prefix: 'pb' },
	{ name: 'Atelier', slug: 'at', prefix: 'at' },
]
/**
 * Konto, Anzeigename, Team, Rolle. Alle stehen in der Teamgruppe; admin
 * ist Gruppenadmin der Teamgruppe, lead und subadmin sind App-Rollen.
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

/** Die Teamgruppe eines Teams. */
export const teamGroupOf = (prefix) => `${prefix}-team`
/** `groups` wie in /me, /team und /admin/teams. */
export const groupsOf = (prefix) => ({ team: teamGroupOf(prefix) })

/**
 * Eine OCS-Anfrage. `path` beginnt mit `/ocs/…` oder ist relativ zur App-API.
 * @returns {Promise<{status:number, data:any, meta:any, text:string}>}
 */
export async function ocs(who, method, path, body, { form = false, raw } = {}) {
	const url = NC + (path.startsWith('/ocs/') ? path : API + path)
	const headers = {
		'OCS-APIRequest': 'true',
		Accept: 'application/json',
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

/** SQL über TS_SQL, sonst null. */
export function sql(query) {
	if (!process.env.TS_SQL) {
		return null
	}
	return execSync(process.env.TS_SQL, { input: query + '\n', encoding: 'utf8' }).trim()
}

/** occ über TS_OCC, sonst null. Argumente werden einzeln in '…' gesetzt. */
export function occ(...args) {
	if (!process.env.TS_OCC) {
		return null
	}
	const quoted = args.map((a) => `'${String(a).replace(/'/g, `'\\''`)}'`).join(' ')
	return execSync(`${process.env.TS_OCC} ${quoted}`, { encoding: 'utf8' }).trim()
}

/**
 * WebDAV/CalDAV-Anfrage unter /remote.php/dav. `path` ohne führenden
 * Schrägstrich, Segmente schon kodiert.
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
