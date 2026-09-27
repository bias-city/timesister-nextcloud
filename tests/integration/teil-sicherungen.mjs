// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Backups, version 1.1: consent, server backup only on change,
// copy at the backup account and in the person's own folder, permissions,
// weekly job and thinning (both with TS_OCC), backup account per team.
import { ADMIN, NC, dav, occ, ocs, sql } from './lib.mjs'
import { A, AA, L, RUN, U1, U2, V, ISO, check, expect, head, sha256 } from './harness.mjs'

const REFUSED = 'The person has not agreed to backups with the Team Admin.'
const REFUSED_DE = 'Die Person hat die Sicherung beim Team Admin nicht freigegeben.'
const NOTICE = '2026-09-26.2' // version of the notice since 1.2
const today = () => new Date().toISOString().slice(0, 10)
const davPath = (owner, filePath) => `files/${encodeURIComponent(owner)}/${filePath.split('/').map(encodeURIComponent).join('/')}`
const icsOf = (r) => Buffer.from(r.data?.ics_base64 || '', 'base64').toString()
const b64 = (s) => Buffer.from(s).toString('base64')
const START = Math.floor(Date.now() / 1000) - 5

/** Consent and own copy as before (boolean and version). */
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

/** Force one run of an app job; false without TS_OCC. */
function runJob(name) {
	const list = occ('background-job:list', `--class=OCA\\TimeSister\\BackgroundJob\\${name}`, '--output=json')
	if (list === null) {
		return false
	}
	occ('background-job:execute', String(JSON.parse(list)[0]?.id), '--force-execute')
	return true
}

/**
 * Create the time calendar `zeit-<uid>` with an event, if it is missing.
 * Otherwise the Mac app creates it; in a fresh Nextcloud (CI) it is missing.
 */
async function zeitkalender(who) {
	const cal = `calendars/${encodeURIComponent(who.user)}/zeit-${encodeURIComponent(who.user)}/`
	if ((await dav(who, 'PROPFIND', cal, undefined, { Depth: '0' })).status === 207) return
	const mk = await dav(who, 'MKCALENDAR', cal)
	const ev = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//TimeSister//Integration//DE', 'BEGIN:VEVENT',
		`UID:integration-${who.user}`, 'DTSTAMP:20260901T080000Z', 'DTSTART:20260901T080000Z', 'DTEND:20260901T090000Z',
		'SUMMARY:Integration', 'END:VEVENT', 'END:VCALENDAR', ''].join('\r\n')
	const put = await dav(who, 'PUT', `${cal}integration.ics`, ev, { 'Content-Type': 'text/calendar' })
	check(`time calendar zeit-${who.user} created`, mk.status === 201 && put.status === 201, `MKCALENDAR ${mk.status}, PUT ${put.status}`)
}

export async function sicherungen() {
	head('Consent for backups')
	await zeitkalender(U1)
	const prior1 = (await ocs(U1, 'GET', '/backups/consent')).data
	const prior2 = (await ocs(U2, 'GET', '/backups/consent')).data
	const own1 = (await ocs(U1, 'GET', '/backups/own-copy')).data
	const ownPath = `TimeSister Backups/${today()}.ics`
	const ownBefore = (await dav(U1, 'GET', davPath('pbuser1', ownPath))).status
	const ownDirBefore = (await dav(U1, 'PROPFIND', davPath('pbuser1', 'TimeSister Backups'), undefined, { Depth: '0' })).status
	try {
		check('GET /backups/consent: consent, since, revoked_at, notice', typeof prior1?.consent === 'boolean'
			&& ['since', 'revoked_at', 'notice'].every((k) => k in (prior1 || {})), prior1)
		check('GET /backups/own-copy: enabled', typeof own1?.enabled === 'boolean', own1)
		await ocs(U1, 'PUT', '/backups/own-copy', { enabled: false })
		await ocs(U1, 'PUT', '/backups/consent', { consent: false })
		const now0 = await ocs(U1, 'POST', '/backups/now', {})
		check('without consent: POST /backups/now → 403 with the sentence from the contract', now0.status === 403 && now0.data?.error === 'forbidden' && now0.data?.message === REFUSED, now0.text)
		// Nextcloud stores the Accept-Language of an account's first login as its
		// language and prefers it afterwards; so the account language decides here.
		const userPath = `/ocs/v2.php/cloud/users/${encodeURIComponent(U1.user)}`
		const langBefore = (await ocs(ADMIN, 'GET', userPath)).data?.language || 'en'
		try {
			for (const [lang, what] of [['de', 'account language de'], ['de_DE', 'account language de_DE (formal)']]) {
				await ocs(ADMIN, 'PUT', userPath, { key: 'language', value: lang })
				const r = await ocs(U1, 'POST', '/backups/now', {})
				check(`same call with ${what} → the German sentence (translation end to end)`, r.status === 403 && r.data?.message === REFUSED_DE, r.text)
			}
		} finally {
			await ocs(ADMIN, 'PUT', userPath, { key: 'language', value: langBefore })
		}
		const up0 = await ocs(U1, 'POST', '/backups', { taken_on: '2099-12-29', ics_base64: b64('BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n') })
		check('without consent: POST /backups → 403 with the sentence', up0.status === 403 && up0.data?.message === REFUSED, up0.text)
		expect('consent without notice', await ocs(U1, 'PUT', '/backups/consent', { consent: true }), 422, 'invalid')
		expect('notice too long', await ocs(U1, 'PUT', '/backups/consent', { consent: true, notice: 'x'.repeat(33) }), 422, 'invalid')
		expect('consent is not a boolean', await ocs(U1, 'PUT', '/backups/consent', { consent: 'ja', notice: NOTICE }), 400, 'invalid')
		expect('PUT with uid (another account)', await ocs(U1, 'PUT', '/backups/consent', { consent: true, notice: NOTICE, uid: 'pbuser2' }), 400, 'invalid')
		expect('own copy: PUT with uid', await ocs(U1, 'PUT', '/backups/own-copy', { enabled: true, uid: 'pbuser2' }), 400, 'invalid')
		const u2after = (await ocs(U2, 'GET', '/backups/consent')).data
		check('… pbuser2 unchanged', JSON.stringify(u2after) === JSON.stringify(prior2), { prior2, u2after })
		const on = await ocs(U1, 'PUT', '/backups/consent', { consent: true, notice: NOTICE })
		check('consent with notice → 200, version returned', on.status === 200 && on.data?.consent === true && on.data?.notice === NOTICE
			&& ISO.test(on.data?.since) && on.data?.revoked_at === null, on.text)
		const st = ((await ocs(V, 'GET', '/status')).data || []).find((x) => x.uid === 'pbuser1')
		check('GET /status: backup_consent, _since, _notice, without own_copy', st?.backup_consent === true && st?.backup_consent_since === on.data?.since
			&& st?.backup_consent_notice === NOTICE && !('own_copy' in st), st)

		head('Server backup and copy at the backup account')
		const team = await ocs(A, 'GET', '/team')
		check('GET /team names backup_owner (pbadmin)', team.data?.backup_owner === 'pbadmin', team.data?.backup_owner)
		const adm = (await ocs(ADMIN, 'GET', '/admin/teams')).data?.find?.((t) => t.slug === 'pb')
		check('GET /admin/teams names backup_owner', adm?.backup_owner === 'pbadmin', adm)

		const now = await ocs(U1, 'POST', '/backups/now')
		const path = `TimeSister Backups/Mia Muster (pbuser1)/${now.data?.taken_on}.ics`
		check('POST /backups/now → source server, file_path at the backup account', now.status === 200 && now.data?.source === 'server'
			&& now.data?.uid === 'pbuser1' && now.data?.file_path === path && !('own_copy' in now.data), now.text)
		const one = await ocs(U1, 'GET', `/backups/${now.data?.id}`)
		const ics = icsOf(one)
		check('GET /backups/{id}: full calendar, hash matches', one.status === 200 && ics.startsWith('BEGIN:VCALENDAR')
			&& ics.trimEnd().endsWith('END:VCALENDAR') && sha256(ics) === now.data?.sha256 && one.data?.file_path === path, one.text.slice(0, 300))
		const listA = (await ocs(U1, 'GET', '/backups')).data || []
		check('GET /backups names source and file_path', listA.some((b) => b.id === now.data?.id && b.source === 'server' && b.file_path === path))
		const again = await ocs(U1, 'POST', '/backups/now')
		const listB = (await ocs(U1, 'GET', '/backups')).data || []
		check('calendar unchanged: no second backup', again.data?.id === now.data?.id && again.data?.sha256 === now.data?.sha256
			&& JSON.stringify(listA) === JSON.stringify(listB), again.text)
		const file = await dav(A, 'GET', davPath('pbadmin', path))
		check('file via WebDAV in pbadmin\'s folder, same content', file.status === 200 && sha256(file.text) === now.data?.sha256, `HTTP ${file.status}`)
		check('pbuser1 does not see pbadmin\'s folder', (await dav(U1, 'GET', davPath('pbadmin', path))).status !== 200)

		expect('user backs up another account', await ocs(U2, 'POST', '/backups/now', { uid: 'pbuser1' }), 403, 'forbidden')
		expect('lead backs up another account', await ocs(L, 'POST', '/backups/now', { uid: 'pbuser1' }), 403, 'forbidden')
		expect('at admin backs up a pb account', await ocs(AA, 'POST', '/backups/now', { uid: 'pbuser1' }), 404, 'not_found')
		const byV = await ocs(V, 'POST', '/backups/now', { uid: 'pbuser1' })
		check('second Team Admin backs up pbuser1 (unchanged: the same)', byV.status === 200 && byV.data?.id === now.data?.id && byV.data?.source === 'server', byV.text)
		expect('user gets another backup', await ocs(U2, 'GET', `/backups/${now.data?.id}`), 403, 'forbidden')
		expect('at admin gets a pb backup', await ocs(AA, 'GET', `/backups/${now.data?.id}`), 404, 'not_found')

		head('Copy in the person\'s own folder')
		await ocs(U1, 'PUT', '/backups/consent', { consent: false })
		const en = await ocs(U1, 'PUT', '/backups/own-copy', { enabled: true })
		check('PUT /backups/own-copy → enabled true', en.status === 200 && en.data?.enabled === true, en.text)
		expect('enabled is not a boolean', await ocs(U1, 'PUT', '/backups/own-copy', { enabled: 'ja' }), 400, 'invalid')
		const adminBefore = await dav(A, 'GET', davPath('pbadmin', path))
		const onlyOwn = await ocs(U1, 'POST', '/backups/now')
		check('own copy only: response { own_copy }', onlyOwn.status === 200 && onlyOwn.data?.own_copy === ownPath && !('id' in (onlyOwn.data || {})), onlyOwn.text)
		const mine = await dav(U1, 'GET', davPath('pbuser1', ownPath))
		check('… file via WebDAV at pbuser1, full calendar', mine.status === 200 && mine.text.startsWith('BEGIN:VCALENDAR')
			&& mine.text.trimEnd().endsWith('END:VCALENDAR'), `HTTP ${mine.status}`)
		const listC = (await ocs(U1, 'GET', '/backups')).data || []
		const adminAfter = await dav(A, 'GET', davPath('pbadmin', path))
		check('… no new entry in /backups, nothing new at pbadmin', JSON.stringify(listC) === JSON.stringify(listB)
			&& adminAfter.status === adminBefore.status && adminAfter.text === adminBefore.text)
		check('… pbadmin does not see the own copy', (await dav(A, 'GET', davPath('pbuser1', ownPath))).status !== 200)

		await ocs(U1, 'PUT', '/backups/consent', { consent: true, notice: NOTICE })
		const both = await ocs(U1, 'POST', '/backups/now')
		check('both on: entry with own_copy, both files present', both.status === 200 && Number.isInteger(both.data?.id) && both.data?.own_copy === ownPath
			&& (await dav(A, 'GET', davPath('pbadmin', both.data?.file_path || 'x'))).status === 200
			&& (await dav(U1, 'GET', davPath('pbuser1', ownPath))).status === 200, both.text)

		head('Withdrawing consent')
		await ocs(U1, 'PUT', '/backups/own-copy', { enabled: false })
		const off = await ocs(U1, 'PUT', '/backups/consent', { consent: false })
		check('withdrawn: consent false, revoked_at, version stays', off.status === 200 && off.data?.consent === false
			&& ISO.test(off.data?.revoked_at) && off.data?.since === null && off.data?.notice === NOTICE, off.text)
		const stopped = await ocs(U1, 'POST', '/backups/now')
		check('no new backup afterwards (403)', stopped.status === 403 && stopped.data?.message === REFUSED, stopped.text)
		expect('existing backup stays readable', await ocs(U1, 'GET', `/backups/${now.data?.id}`), 200)
		check('the admin\'s copy stays', (await dav(A, 'GET', davPath('pbadmin', path))).status === 200)
	} finally {
		await restoreConsent(U1, prior1)
		await restoreOwnCopy(U1, own1)
		// Only delete test files in the own folder if they did not exist before.
		if (ownBefore !== 200) {
			await dav(U1, 'DELETE', davPath('pbuser1', ownPath))
		}
		if (ownDirBefore !== 207) {
			await dav(U1, 'DELETE', davPath('pbuser1', 'TimeSister Backups'))
		}
		const back = (await ocs(U1, 'GET', '/backups/consent')).data
		const backOwn = (await ocs(U1, 'GET', '/backups/own-copy')).data
		check('pbuser1: consent and own copy as before', back?.consent === prior1?.consent && backOwn?.enabled === own1?.enabled, { prior1, back, own1, backOwn })
	}

	await testkonto()
	await testteam()
}

/** Weekly job, foreign calendar_url, both copies, thinning with a moved file. */
async function testkonto() {
	head('Weekly job and thinning (own test account)')
	const uid = `bk${RUN}`
	const who = { user: uid, pass: `Test-2026-${uid}!` }
	const cal = `zeit-${uid}`
	const probe = `tsprobe-${RUN}`
	const adminDir = `TimeSister Backups/${uid} (${uid})`
	await ocs(ADMIN, 'POST', '/ocs/v2.php/cloud/users', { userid: uid, password: who.pass, groups: ['pb-team'] })
	try {
		const mk = await dav(who, 'MKCALENDAR', `calendars/${uid}/${cal}/`)
		const ev = `BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//TimeSister//Probe//DE\r\nBEGIN:VEVENT\r\nUID:${probe}\r\nDTSTAMP:20260926T080000Z\r\nDTSTART:20260926T080000Z\r\nDTEND:20260926T090000Z\r\nSUMMARY:Probe\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n`
		const put = await dav(who, 'PUT', `calendars/${uid}/${cal}/${probe}.ics`, ev, { 'Content-Type': 'text/calendar; charset=utf-8' })
		check('test account with a time calendar and an event', mk.status === 201 && put.status === 201, `${mk.status} ${put.status}`)
		// calendar_url in a foreign home: the server still only takes its own calendar.
		await ocs(who, 'POST', '/status', { calendar_url: 'http://localhost:8081/remote.php/dav/calendars/pbuser1/zeit-pbuser1/' })

		if (!runJob('WeeklyBackup')) {
			console.log('    --  weekly job and thinning: not checked without TS_OCC')
			return
		}
		const ownFile = davPath(uid, `TimeSister Backups/${today()}.ics`)
		check('job without consent and without an own copy: nothing', ((await ocs(who, 'GET', '/backups')).data || []).length === 0
			&& (await dav(who, 'GET', ownFile)).status === 404)

		await ocs(who, 'PUT', '/backups/own-copy', { enabled: true })
		runJob('WeeklyBackup')
		const own = await dav(who, 'GET', ownFile)
		check('job with only an own copy: file in the own folder with the event', own.status === 200 && own.text.includes(`UID:${probe}`), `HTTP ${own.status}`)
		check('… nothing in /backups, nothing at pbadmin', ((await ocs(who, 'GET', '/backups')).data || []).length === 0
			&& (await dav(A, 'PROPFIND', davPath('pbadmin', adminDir), undefined, { Depth: '0' })).status === 404)

		await ocs(who, 'PUT', '/backups/consent', { consent: true, notice: NOTICE })
		runJob('WeeklyBackup')
		const made = (await ocs(who, 'GET', '/backups')).data || []
		const b = made[0]
		check('job with consent: one server backup', made.length === 1 && b?.source === 'server' && b?.taken_on === today(), made)
		const got = icsOf(await ocs(who, 'GET', `/backups/${b?.id}`))
		check('only the own event, not the calendar from the foreign calendar_url', got.includes(`UID:${probe}`)
			&& (got.match(/BEGIN:VEVENT/g) || []).length === 1, got.slice(0, 300))
		check('both on: copy at the admin and in the own folder', b?.file_path === `${adminDir}/${today()}.ics`
			&& (await dav(A, 'GET', davPath('pbadmin', b.file_path))).status === 200 && (await dav(who, 'GET', ownFile)).status === 200, b)
		runJob('WeeklyBackup')
		check('second run in the same week: no further backup', ((await ocs(who, 'GET', '/backups')).data || []).length === 1)

		// Thinning: three in March (older than 4 weeks, same month), one older than 10 years.
		const days = ['2026-03-02', '2026-03-10', '2026-03-20', '2015-06-01']
		for (const d of days) {
			await ocs(who, 'POST', '/backups', { taken_on: d, ics_base64: b64(`BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//TimeSister//Old ${d}//DE\r\nEND:VCALENDAR\r\n`) })
		}
		const moved = `${adminDir}/moved-2026-03-10.ics`
		const mv = await dav(A, 'MOVE', davPath('pbadmin', `${adminDir}/2026-03-10.ics`), undefined, { Destination: `${NC}/remote.php/dav/${davPath('pbadmin', moved)}` })
		check('the March 10 copy at the admin renamed', mv.status === 201, `HTTP ${mv.status}`)
		runJob('Retention')
		const left = ((await ocs(who, 'GET', '/backups')).data || []).map((x) => x.taken_on).sort()
		check('thinning: only the newest in March, older than 10 years gone', JSON.stringify(left) === JSON.stringify(['2026-03-20', today()]), left)
		const st = async (p) => (await dav(A, 'GET', davPath('pbadmin', p))).status
		check('deleted copies gone, kept ones present', await st(`${adminDir}/2026-03-02.ics`) === 404 && await st(`${adminDir}/2015-06-01.ics`) === 404
			&& await st(`${adminDir}/2026-03-20.ics`) === 200)
		check('the moved file is not deleted', await st(moved) === 200)
		// Trash entries are named <name>.d<time>; only the ones from this run.
		const trash = await dav(A, 'PROPFIND', 'trashbin/pbadmin/trash/', undefined, { Depth: '1' })
		const items = [...trash.text.matchAll(/<d:href>([^<]+)<\/d:href>/g)].map((m) => m[1]).filter((h) => {
			const m = decodeURIComponent(h).match(/\/(2026-03-02|2015-06-01)\.ics\.d(\d+)$/)
			return m !== null && Number(m[2]) >= START
		})
		check('deleted via pbadmin\'s trash', items.length === 2, items)
		for (const h of items) {
			await dav(A, 'DELETE', h.replace(/^.*?\/remote\.php\/dav\//, ''))
		}
	} finally {
		await ocs(ADMIN, 'DELETE', `/ocs/v2.php/cloud/users/${uid}`)
		await dav(A, 'DELETE', davPath('pbadmin', adminDir))
	}
	const left = sql(`SELECT COUNT(*) FROM oc_ts_backup_consent WHERE uid = '${uid}';`)
	if (left === null) {
		console.log('    --  consent after account deletion: not checked without TS_SQL')
	} else {
		check('account deleted: consent removed', left === '0', left)
	}
}

/** Own test team: backup account (1.1) and team settings (1.2). */
async function testteam() {
	head('Team settings in /me (Planungsbüro, read only)')
	{
		const me = (await ocs(U1, 'GET', '/me')).data
		check('/me names settings with two booleans', typeof me?.settings?.leads_see_calendars === 'boolean'
			&& typeof me?.settings?.backup_required === 'boolean' && Object.keys(me.settings).length === 2, me?.settings)
		const team = (await ocs(A, 'GET', '/team')).data
		check('/team names the same settings', JSON.stringify(team?.settings) === JSON.stringify(me?.settings), team?.settings)
	}
	head('Backup account and team settings (own test team)')
	const g = { team: `bo-${RUN}-t` }
	const [first, second] = [`a${RUN}`, `z${RUN}`]
	await ocs(ADMIN, 'POST', '/ocs/v2.php/cloud/groups', { groupid: g.team })
	// The team's admin: the team group's group admin.
	for (const uid of [first, second]) {
		await ocs(ADMIN, 'POST', '/ocs/v2.php/cloud/users', { userid: uid, password: `Test-2026-${uid}!`, groups: [g.team] })
		await ocs(ADMIN, 'POST', `/ocs/v2.php/cloud/users/${uid}/subadmins`, { groupid: g.team })
	}
	let id
	try {
		const base = { name: 'Backup Test', slug: `bo-${RUN}`, groups: g }
		const made = await ocs(ADMIN, 'POST', '/admin/teams', base)
		id = made.data?.id
		check('without a choice: first admin by uid', made.status === 200 && made.data?.backup_owner === first, made.text)
		expect('choosing an account without the admin role', await ocs(ADMIN, 'PUT', `/admin/teams/${id}`, { ...base, backup_owner: 'pbuser1' }), 422, 'invalid')
		const set = await ocs(ADMIN, 'PUT', `/admin/teams/${id}`, { ...base, backup_owner: second })
		check('choosing an admin', set.status === 200 && set.data?.backup_owner === second, set.text)
		const keep = await ocs(ADMIN, 'PUT', `/admin/teams/${id}`, base)
		check('PUT without backup_owner keeps the choice', keep.data?.backup_owner === second, keep.text)
		const auto = await ocs(ADMIN, 'PUT', `/admin/teams/${id}`, { ...base, backup_owner: null })
		check('backup_owner null: automatic again', auto.data?.backup_owner === first, auto.text)

		const defaults = { leads_see_calendars: true, backup_required: false }
		check('settings: default values on creation', JSON.stringify(made.data?.settings) === JSON.stringify(defaults), made.data?.settings)
		const who = { user: first, pass: `Test-2026-${first}!` }
		check('member\'s /me: default values', JSON.stringify((await ocs(who, 'GET', '/me')).data?.settings) === JSON.stringify(defaults))
		const req = await ocs(ADMIN, 'PUT', `/admin/teams/${id}`, { ...base, settings: { backup_required: true } })
		check('set only backup_required, leads_see_calendars stays', JSON.stringify(req.data?.settings) === JSON.stringify({ leads_see_calendars: true, backup_required: true }), req.text)
		const off = await ocs(ADMIN, 'PUT', `/admin/teams/${id}`, { ...base, settings: { leads_see_calendars: false } })
		check('leads_see_calendars off, backup_required stays', JSON.stringify(off.data?.settings) === JSON.stringify({ leads_see_calendars: false, backup_required: true }), off.text)
		const same = await ocs(ADMIN, 'PUT', `/admin/teams/${id}`, base)
		const want = { leads_see_calendars: false, backup_required: true }
		check('PUT without settings: unchanged', JSON.stringify(same.data?.settings) === JSON.stringify(want), same.text)
		check('/me, /team and /admin/teams read them', JSON.stringify((await ocs(who, 'GET', '/me')).data?.settings) === JSON.stringify(want)
			&& JSON.stringify((await ocs(who, 'GET', '/team')).data?.settings) === JSON.stringify(want)
			&& JSON.stringify((await ocs(ADMIN, 'GET', '/admin/teams')).data?.find?.((t) => t.id === id)?.settings) === JSON.stringify(want))
		expect('settings: not a boolean', await ocs(ADMIN, 'PUT', `/admin/teams/${id}`, { ...base, settings: { backup_required: 1 } }), 422, 'invalid')
		expect('settings: unknown key', await ocs(ADMIN, 'PUT', `/admin/teams/${id}`, { ...base, settings: { unknown: true } }), 422, 'invalid')
		const madeWith = await ocs(ADMIN, 'PUT', `/admin/teams/${id}`, { ...base, settings: { leads_see_calendars: true, backup_required: false } })
		check('both back to default', JSON.stringify(madeWith.data?.settings) === JSON.stringify(defaults), madeWith.text)
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
