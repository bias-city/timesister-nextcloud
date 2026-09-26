// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Sicherungen, Fassung 1.1: Freigabe, Server-Sicherung nur bei Änderung,
// Kopie beim Sicherungs-Konto und im eigenen Ordner, Rechte, Wochenjob und
// Ausdünnen (beide mit TS_OCC), Sicherungs-Konto je Team.
import { ADMIN, NC, dav, occ, ocs, sql } from './lib.mjs'
import { A, AA, L, RUN, U1, U2, V, ISO, check, expect, head, sha256 } from './harness.mjs'

const REFUSED = 'Die Person hat die Sicherung beim Admin nicht freigegeben.'
const NOTICE = '2026-09-26.2' // Fassung der Aufklärung seit 1.2
const today = () => new Date().toISOString().slice(0, 10)
const davPath = (owner, filePath) => `files/${encodeURIComponent(owner)}/${filePath.split('/').map(encodeURIComponent).join('/')}`
const icsOf = (r) => Buffer.from(r.data?.ics_base64 || '', 'base64').toString()
const b64 = (s) => Buffer.from(s).toString('base64')
const START = Math.floor(Date.now() / 1000) - 5

/** Freigabe und eigene Kopie wie vorher (Wahrheitswert und Fassung). */
export async function restoreConsent(who, prior) {
	const now = (await ocs(who, 'GET', '/backups/consent')).data
	if (prior?.consent && !(now?.consent && now?.notice === prior.notice)) {
		await ocs(who, 'PUT', '/backups/consent', { consent: true, notice: prior.notice || NOTICE })
	} else if (!prior?.consent && now?.consent) {
		await ocs(who, 'PUT', '/backups/consent', { consent: false })
	}
}
async function restoreOwnCopy(who, prior) {
	if (typeof prior?.enabled === 'boolean') {
		await ocs(who, 'PUT', '/backups/own-copy', { enabled: prior.enabled })
	}
}

/** Einen Job der App einmal erzwingen; false ohne TS_OCC. */
function runJob(name) {
	const list = occ('background-job:list', `--class=OCA\\TimeSister\\BackgroundJob\\${name}`, '--output=json')
	if (list === null) {
		return false
	}
	occ('background-job:execute', String(JSON.parse(list)[0]?.id), '--force-execute')
	return true
}

export async function sicherungen() {
	head('Freigabe der Sicherung')
	const prior1 = (await ocs(U1, 'GET', '/backups/consent')).data
	const prior2 = (await ocs(U2, 'GET', '/backups/consent')).data
	const own1 = (await ocs(U1, 'GET', '/backups/own-copy')).data
	const ownPath = `TimeSister-Sicherungen/${today()}.ics`
	const ownBefore = (await dav(U1, 'GET', davPath('pbuser1', ownPath))).status
	const ownDirBefore = (await dav(U1, 'PROPFIND', davPath('pbuser1', 'TimeSister-Sicherungen'), undefined, { Depth: '0' })).status
	try {
		check('GET /backups/consent: consent, since, revoked_at, notice', typeof prior1?.consent === 'boolean'
			&& ['since', 'revoked_at', 'notice'].every((k) => k in (prior1 || {})), prior1)
		check('GET /backups/own-copy: enabled', typeof own1?.enabled === 'boolean', own1)
		await ocs(U1, 'PUT', '/backups/own-copy', { enabled: false })
		await ocs(U1, 'PUT', '/backups/consent', { consent: false })
		const now0 = await ocs(U1, 'POST', '/backups/now', {})
		check('ohne Freigabe: POST /backups/now → 403 mit dem Satz aus dem Vertrag', now0.status === 403 && now0.data?.error === 'forbidden' && now0.data?.message === REFUSED, now0.text)
		const up0 = await ocs(U1, 'POST', '/backups', { taken_on: '2099-12-29', ics_base64: b64('BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n') })
		check('ohne Freigabe: POST /backups → 403 mit dem Satz', up0.status === 403 && up0.data?.message === REFUSED, up0.text)
		expect('Freigabe ohne notice', await ocs(U1, 'PUT', '/backups/consent', { consent: true }), 422, 'invalid')
		expect('notice zu lang', await ocs(U1, 'PUT', '/backups/consent', { consent: true, notice: 'x'.repeat(33) }), 422, 'invalid')
		expect('consent kein Wahrheitswert', await ocs(U1, 'PUT', '/backups/consent', { consent: 'ja', notice: NOTICE }), 400, 'invalid')
		expect('PUT mit uid (fremdes Konto)', await ocs(U1, 'PUT', '/backups/consent', { consent: true, notice: NOTICE, uid: 'pbuser2' }), 400, 'invalid')
		expect('eigene Kopie: PUT mit uid', await ocs(U1, 'PUT', '/backups/own-copy', { enabled: true, uid: 'pbuser2' }), 400, 'invalid')
		const u2after = (await ocs(U2, 'GET', '/backups/consent')).data
		check('… pbuser2 unverändert', JSON.stringify(u2after) === JSON.stringify(prior2), { prior2, u2after })
		const on = await ocs(U1, 'PUT', '/backups/consent', { consent: true, notice: NOTICE })
		check('Freigabe mit notice → 200, Fassung zurück', on.status === 200 && on.data?.consent === true && on.data?.notice === NOTICE
			&& ISO.test(on.data?.since) && on.data?.revoked_at === null, on.text)
		const st = ((await ocs(V, 'GET', '/status')).data || []).find((x) => x.uid === 'pbuser1')
		check('GET /status: backup_consent, _since, _notice, ohne own_copy', st?.backup_consent === true && st?.backup_consent_since === on.data?.since
			&& st?.backup_consent_notice === NOTICE && !('own_copy' in st), st)

		head('Server-Sicherung und Kopie beim Sicherungs-Konto')
		const team = await ocs(A, 'GET', '/team')
		check('GET /team nennt backup_owner (pbadmin)', team.data?.backup_owner === 'pbadmin', team.data?.backup_owner)
		const adm = (await ocs(ADMIN, 'GET', '/admin/teams')).data?.find?.((t) => t.slug === 'pb')
		check('GET /admin/teams nennt backup_owner', adm?.backup_owner === 'pbadmin', adm)

		const now = await ocs(U1, 'POST', '/backups/now')
		const path = `TimeSister-Sicherungen/Mia Muster (pbuser1)/${now.data?.taken_on}.ics`
		check('POST /backups/now → source server, file_path beim Sicherungs-Konto', now.status === 200 && now.data?.source === 'server'
			&& now.data?.uid === 'pbuser1' && now.data?.file_path === path && !('own_copy' in now.data), now.text)
		const one = await ocs(U1, 'GET', `/backups/${now.data?.id}`)
		const ics = icsOf(one)
		check('GET /backups/{id}: vollständiger Kalender, Hash stimmt', one.status === 200 && ics.startsWith('BEGIN:VCALENDAR')
			&& ics.trimEnd().endsWith('END:VCALENDAR') && sha256(ics) === now.data?.sha256 && one.data?.file_path === path, one.text.slice(0, 300))
		const listA = (await ocs(U1, 'GET', '/backups')).data || []
		check('GET /backups nennt source und file_path', listA.some((b) => b.id === now.data?.id && b.source === 'server' && b.file_path === path))
		const again = await ocs(U1, 'POST', '/backups/now')
		const listB = (await ocs(U1, 'GET', '/backups')).data || []
		check('Kalender unverändert: keine zweite Sicherung', again.data?.id === now.data?.id && again.data?.sha256 === now.data?.sha256
			&& JSON.stringify(listA) === JSON.stringify(listB), again.text)
		const file = await dav(A, 'GET', davPath('pbadmin', path))
		check('Datei per WebDAV im Ordner von pbadmin, gleicher Inhalt', file.status === 200 && sha256(file.text) === now.data?.sha256, `HTTP ${file.status}`)
		check('pbuser1 sieht den Ordner von pbadmin nicht', (await dav(U1, 'GET', davPath('pbadmin', path))).status !== 200)

		expect('user sichert fremdes Konto', await ocs(U2, 'POST', '/backups/now', { uid: 'pbuser1' }), 403, 'forbidden')
		expect('lead sichert fremdes Konto', await ocs(L, 'POST', '/backups/now', { uid: 'pbuser1' }), 403, 'forbidden')
		expect('at-Admin sichert pb-Konto', await ocs(AA, 'POST', '/backups/now', { uid: 'pbuser1' }), 404, 'not_found')
		const byV = await ocs(V, 'POST', '/backups/now', { uid: 'pbuser1' })
		check('Verwaltung sichert pbuser1 (unverändert: dieselbe)', byV.status === 200 && byV.data?.id === now.data?.id && byV.data?.source === 'server', byV.text)
		expect('user holt fremde Sicherung', await ocs(U2, 'GET', `/backups/${now.data?.id}`), 403, 'forbidden')
		expect('at-Admin holt pb-Sicherung', await ocs(AA, 'GET', `/backups/${now.data?.id}`), 404, 'not_found')

		head('Kopie im eigenen Ordner')
		await ocs(U1, 'PUT', '/backups/consent', { consent: false })
		const en = await ocs(U1, 'PUT', '/backups/own-copy', { enabled: true })
		check('PUT /backups/own-copy → enabled true', en.status === 200 && en.data?.enabled === true, en.text)
		expect('enabled kein Wahrheitswert', await ocs(U1, 'PUT', '/backups/own-copy', { enabled: 'ja' }), 400, 'invalid')
		const adminBefore = await dav(A, 'GET', davPath('pbadmin', path))
		const onlyOwn = await ocs(U1, 'POST', '/backups/now')
		check('nur eigene Kopie: Antwort { own_copy }', onlyOwn.status === 200 && onlyOwn.data?.own_copy === ownPath && !('id' in (onlyOwn.data || {})), onlyOwn.text)
		const mine = await dav(U1, 'GET', davPath('pbuser1', ownPath))
		check('… Datei per WebDAV bei pbuser1, vollständiger Kalender', mine.status === 200 && mine.text.startsWith('BEGIN:VCALENDAR')
			&& mine.text.trimEnd().endsWith('END:VCALENDAR'), `HTTP ${mine.status}`)
		const listC = (await ocs(U1, 'GET', '/backups')).data || []
		const adminAfter = await dav(A, 'GET', davPath('pbadmin', path))
		check('… kein neuer Eintrag in /backups, nichts Neues bei pbadmin', JSON.stringify(listC) === JSON.stringify(listB)
			&& adminAfter.status === adminBefore.status && adminAfter.text === adminBefore.text)
		check('… pbadmin sieht die eigene Kopie nicht', (await dav(A, 'GET', davPath('pbuser1', ownPath))).status !== 200)

		await ocs(U1, 'PUT', '/backups/consent', { consent: true, notice: NOTICE })
		const both = await ocs(U1, 'POST', '/backups/now')
		check('beides an: Eintrag mit own_copy, beide Dateien da', both.status === 200 && Number.isInteger(both.data?.id) && both.data?.own_copy === ownPath
			&& (await dav(A, 'GET', davPath('pbadmin', both.data?.file_path || 'x'))).status === 200
			&& (await dav(U1, 'GET', davPath('pbuser1', ownPath))).status === 200, both.text)

		head('Freigabe zurückziehen')
		await ocs(U1, 'PUT', '/backups/own-copy', { enabled: false })
		const off = await ocs(U1, 'PUT', '/backups/consent', { consent: false })
		check('zurückgezogen: consent false, revoked_at, Fassung bleibt', off.status === 200 && off.data?.consent === false
			&& ISO.test(off.data?.revoked_at) && off.data?.since === null && off.data?.notice === NOTICE, off.text)
		const stopped = await ocs(U1, 'POST', '/backups/now')
		check('danach keine neue Sicherung (403)', stopped.status === 403 && stopped.data?.message === REFUSED, stopped.text)
		expect('vorhandene Sicherung bleibt lesbar', await ocs(U1, 'GET', `/backups/${now.data?.id}`), 200)
		check('Kopie beim Admin bleibt', (await dav(A, 'GET', davPath('pbadmin', path))).status === 200)
	} finally {
		await restoreConsent(U1, prior1)
		await restoreOwnCopy(U1, own1)
		// Testdateien im eigenen Ordner nur löschen, wenn es sie vorher nicht gab.
		if (ownBefore !== 200) {
			await dav(U1, 'DELETE', davPath('pbuser1', ownPath))
		}
		if (ownDirBefore !== 207) {
			await dav(U1, 'DELETE', davPath('pbuser1', 'TimeSister-Sicherungen'))
		}
		const back = (await ocs(U1, 'GET', '/backups/consent')).data
		const backOwn = (await ocs(U1, 'GET', '/backups/own-copy')).data
		check('pbuser1: Freigabe und eigene Kopie wie vorher', back?.consent === prior1?.consent && backOwn?.enabled === own1?.enabled, { prior1, back, own1, backOwn })
	}

	await testkonto()
	await testteam()
}

/** Wochenjob, fremde calendar_url, beide Kopien, Ausdünnen mit verschobener Datei. */
async function testkonto() {
	head('Wochenjob und Ausdünnen (eigenes Testkonto)')
	const uid = `bk${RUN}`
	const who = { user: uid, pass: `Test-2026-${uid}!` }
	const cal = `zeit-${uid}`
	const probe = `tsprobe-${RUN}`
	const adminDir = `TimeSister-Sicherungen/${uid} (${uid})`
	await ocs(ADMIN, 'POST', '/ocs/v2.php/cloud/users', { userid: uid, password: who.pass, groups: ['pb-team'] })
	try {
		const mk = await dav(who, 'MKCALENDAR', `calendars/${uid}/${cal}/`)
		const ev = `BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//TimeSister//Probe//DE\r\nBEGIN:VEVENT\r\nUID:${probe}\r\nDTSTAMP:20260926T080000Z\r\nDTSTART:20260926T080000Z\r\nDTEND:20260926T090000Z\r\nSUMMARY:Probe\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n`
		const put = await dav(who, 'PUT', `calendars/${uid}/${cal}/${probe}.ics`, ev, { 'Content-Type': 'text/calendar; charset=utf-8' })
		check('Testkonto mit Zeitkalender und Termin', mk.status === 201 && put.status === 201, `${mk.status} ${put.status}`)
		// calendar_url in fremdem Heim: der Server nimmt trotzdem nur den eigenen Kalender.
		await ocs(who, 'POST', '/status', { calendar_url: 'http://localhost:8081/remote.php/dav/calendars/pbuser1/zeit-pbuser1/' })

		if (!runJob('WeeklyBackup')) {
			console.log('    --  Wochenjob und Ausdünnen: ohne TS_OCC nicht geprüft')
			return
		}
		const ownFile = davPath(uid, `TimeSister-Sicherungen/${today()}.ics`)
		check('Job ohne Freigabe und ohne eigene Kopie: nichts', ((await ocs(who, 'GET', '/backups')).data || []).length === 0
			&& (await dav(who, 'GET', ownFile)).status === 404)

		await ocs(who, 'PUT', '/backups/own-copy', { enabled: true })
		runJob('WeeklyBackup')
		const own = await dav(who, 'GET', ownFile)
		check('Job nur mit eigener Kopie: Datei im eigenen Ordner mit dem Termin', own.status === 200 && own.text.includes(`UID:${probe}`), `HTTP ${own.status}`)
		check('… nichts in /backups, nichts bei pbadmin', ((await ocs(who, 'GET', '/backups')).data || []).length === 0
			&& (await dav(A, 'PROPFIND', davPath('pbadmin', adminDir), undefined, { Depth: '0' })).status === 404)

		await ocs(who, 'PUT', '/backups/consent', { consent: true, notice: NOTICE })
		runJob('WeeklyBackup')
		const made = (await ocs(who, 'GET', '/backups')).data || []
		const b = made[0]
		check('Job mit Freigabe: eine Server-Sicherung', made.length === 1 && b?.source === 'server' && b?.taken_on === today(), made)
		const got = icsOf(await ocs(who, 'GET', `/backups/${b?.id}`))
		check('nur der eigene Termin, nicht der Kalender aus der fremden calendar_url', got.includes(`UID:${probe}`)
			&& (got.match(/BEGIN:VEVENT/g) || []).length === 1, got.slice(0, 300))
		check('beides an: Kopie beim Admin und im eigenen Ordner', b?.file_path === `${adminDir}/${today()}.ics`
			&& (await dav(A, 'GET', davPath('pbadmin', b.file_path))).status === 200 && (await dav(who, 'GET', ownFile)).status === 200, b)
		runJob('WeeklyBackup')
		check('zweiter Lauf in derselben Woche: keine weitere', ((await ocs(who, 'GET', '/backups')).data || []).length === 1)

		// Ausdünnen: drei im März (älter als 4 Wochen, gleicher Monat), eine älter als 10 Jahre.
		const days = ['2026-03-02', '2026-03-10', '2026-03-20', '2015-06-01']
		for (const d of days) {
			await ocs(who, 'POST', '/backups', { taken_on: d, ics_base64: b64(`BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//TimeSister//Alt ${d}//DE\r\nEND:VCALENDAR\r\n`) })
		}
		const moved = `${adminDir}/verschoben-2026-03-10.ics`
		const mv = await dav(A, 'MOVE', davPath('pbadmin', `${adminDir}/2026-03-10.ics`), undefined, { Destination: `${NC}/remote.php/dav/${davPath('pbadmin', moved)}` })
		check('Kopie vom 10.3. beim Admin umbenannt', mv.status === 201, `HTTP ${mv.status}`)
		runJob('Retention')
		const left = ((await ocs(who, 'GET', '/backups')).data || []).map((x) => x.taken_on).sort()
		check('Ausdünnen: im März nur die jüngste, älter als 10 Jahre weg', JSON.stringify(left) === JSON.stringify(['2026-03-20', today()]), left)
		const st = async (p) => (await dav(A, 'GET', davPath('pbadmin', p))).status
		check('gelöschte Kopien weg, behaltene da', await st(`${adminDir}/2026-03-02.ics`) === 404 && await st(`${adminDir}/2015-06-01.ics`) === 404
			&& await st(`${adminDir}/2026-03-20.ics`) === 200)
		check('verschobene Datei wird nicht gelöscht', await st(moved) === 200)
		// Papierkorb-Einträge heissen <name>.d<Zeit>; nur die aus diesem Lauf.
		const trash = await dav(A, 'PROPFIND', 'trashbin/pbadmin/trash/', undefined, { Depth: '1' })
		const items = [...trash.text.matchAll(/<d:href>([^<]+)<\/d:href>/g)].map((m) => m[1]).filter((h) => {
			const m = decodeURIComponent(h).match(/\/(2026-03-02|2015-06-01)\.ics\.d(\d+)$/)
			return m !== null && Number(m[2]) >= START
		})
		check('gelöscht über den Papierkorb von pbadmin', items.length === 2, items)
		for (const h of items) {
			await dav(A, 'DELETE', h.replace(/^.*?\/remote\.php\/dav\//, ''))
		}
	} finally {
		await ocs(ADMIN, 'DELETE', `/ocs/v2.php/cloud/users/${uid}`)
		await dav(A, 'DELETE', davPath('pbadmin', adminDir))
	}
	const left = sql(`SELECT COUNT(*) FROM oc_ts_backup_consent WHERE uid = '${uid}';`)
	if (left === null) {
		console.log('    --  Freigabe nach Kontolöschung: ohne TS_SQL nicht geprüft')
	} else {
		check('Konto gelöscht: Freigabe entfernt', left === '0', left)
	}
}

/** Eigenes Testteam: Sicherungs-Konto (1.1) und Team-Einstellungen (1.2). */
async function testteam() {
	head('Team-Einstellungen in /me (Planungsbüro, nur lesen)')
	{
		const me = (await ocs(U1, 'GET', '/me')).data
		check('/me nennt settings mit zwei Wahrheitswerten', typeof me?.settings?.leads_see_calendars === 'boolean'
			&& typeof me?.settings?.backup_required === 'boolean' && Object.keys(me.settings).length === 2, me?.settings)
		const team = (await ocs(A, 'GET', '/team')).data
		check('/team nennt dieselben settings', JSON.stringify(team?.settings) === JSON.stringify(me?.settings), team?.settings)
	}
	head('Sicherungs-Konto und Team-Einstellungen (eigenes Testteam)')
	const g = { team: `bo-${RUN}-t` }
	const [first, second] = [`a${RUN}`, `z${RUN}`]
	await ocs(ADMIN, 'POST', '/ocs/v2.php/cloud/groups', { groupid: g.team })
	// Admin des Teams: Gruppenadmin der Teamgruppe.
	for (const uid of [first, second]) {
		await ocs(ADMIN, 'POST', '/ocs/v2.php/cloud/users', { userid: uid, password: `Test-2026-${uid}!`, groups: [g.team] })
		await ocs(ADMIN, 'POST', `/ocs/v2.php/cloud/users/${uid}/subadmins`, { groupid: g.team })
	}
	let id
	try {
		const base = { name: 'Sicherungs-Test', slug: `bo-${RUN}`, groups: g }
		const made = await ocs(ADMIN, 'POST', '/admin/teams', base)
		id = made.data?.id
		check('ohne Wahl: erster admin nach Kennung', made.status === 200 && made.data?.backup_owner === first, made.text)
		expect('Wahl eines Kontos ohne Rolle admin', await ocs(ADMIN, 'PUT', `/admin/teams/${id}`, { ...base, backup_owner: 'pbuser1' }), 422, 'invalid')
		const set = await ocs(ADMIN, 'PUT', `/admin/teams/${id}`, { ...base, backup_owner: second })
		check('Wahl eines admin', set.status === 200 && set.data?.backup_owner === second, set.text)
		const keep = await ocs(ADMIN, 'PUT', `/admin/teams/${id}`, base)
		check('PUT ohne backup_owner behält die Wahl', keep.data?.backup_owner === second, keep.text)
		const auto = await ocs(ADMIN, 'PUT', `/admin/teams/${id}`, { ...base, backup_owner: null })
		check('backup_owner null: wieder automatisch', auto.data?.backup_owner === first, auto.text)

		const defaults = { leads_see_calendars: true, backup_required: false }
		check('Einstellungen: Standardwerte beim Anlegen', JSON.stringify(made.data?.settings) === JSON.stringify(defaults), made.data?.settings)
		const who = { user: first, pass: `Test-2026-${first}!` }
		check('/me des Mitglieds: Standardwerte', JSON.stringify((await ocs(who, 'GET', '/me')).data?.settings) === JSON.stringify(defaults))
		const req = await ocs(ADMIN, 'PUT', `/admin/teams/${id}`, { ...base, settings: { backup_required: true } })
		check('nur backup_required setzen, leads_see_calendars bleibt', JSON.stringify(req.data?.settings) === JSON.stringify({ leads_see_calendars: true, backup_required: true }), req.text)
		const off = await ocs(ADMIN, 'PUT', `/admin/teams/${id}`, { ...base, settings: { leads_see_calendars: false } })
		check('leads_see_calendars aus, backup_required bleibt', JSON.stringify(off.data?.settings) === JSON.stringify({ leads_see_calendars: false, backup_required: true }), off.text)
		const same = await ocs(ADMIN, 'PUT', `/admin/teams/${id}`, base)
		const want = { leads_see_calendars: false, backup_required: true }
		check('PUT ohne settings: unverändert', JSON.stringify(same.data?.settings) === JSON.stringify(want), same.text)
		check('/me, /team und /admin/teams lesen sie', JSON.stringify((await ocs(who, 'GET', '/me')).data?.settings) === JSON.stringify(want)
			&& JSON.stringify((await ocs(who, 'GET', '/team')).data?.settings) === JSON.stringify(want)
			&& JSON.stringify((await ocs(ADMIN, 'GET', '/admin/teams')).data?.find?.((t) => t.id === id)?.settings) === JSON.stringify(want))
		expect('settings: kein Wahrheitswert', await ocs(ADMIN, 'PUT', `/admin/teams/${id}`, { ...base, settings: { backup_required: 1 } }), 422, 'invalid')
		expect('settings: unbekannter Schlüssel', await ocs(ADMIN, 'PUT', `/admin/teams/${id}`, { ...base, settings: { alles: true } }), 422, 'invalid')
		const madeWith = await ocs(ADMIN, 'PUT', `/admin/teams/${id}`, { ...base, settings: { leads_see_calendars: true, backup_required: false } })
		check('beide wieder auf Standard', JSON.stringify(madeWith.data?.settings) === JSON.stringify(defaults), madeWith.text)
	} finally {
		if (id) {
			await ocs(ADMIN, 'DELETE', `/admin/teams/${id}`)
		}
		for (const uid of [first, second]) {
			await ocs(ADMIN, 'DELETE', `/ocs/v2.php/cloud/users/${uid}`)
		}
		for (const gid of Object.values(g)) {
			await ocs(ADMIN, 'DELETE', `/ocs/v2.php/cloud/groups/${gid}`)
		}
	}
}
