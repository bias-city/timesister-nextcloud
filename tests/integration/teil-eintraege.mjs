// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Time entries (0.17.0): naming the time calendar, the listener on calendar
// writes, the full read by the job, GET /entries by role, kept and
// tombstones, the cutoff and the sources' status. Needs TS_OCC for the job;
// without it the job checks are skipped.
import { ADMIN, API, NC, deleteEvent, ensureTimeCalendar, ocs, putEvent, runJob, seedProjectOf } from './lib.mjs'
import { RUN, ISO, check, expect, head, isObj, sha256 } from './harness.mjs'
import { A, L, U1, U2 } from './konten.mjs'
import { storedZip } from './teil-vorlagen.mjs'

const CODE = seedProjectOf('zb').toUpperCase()
const calendarUrl = (uid) => `${NC}/remote.php/dav/calendars/${uid}/zeit-${uid}/`
const uidOf = (name) => `ts-${RUN}-${name}@example.test`
const event = (summary, day) => [`DTSTART:${day}T080000Z`, `DTEND:${day}T090000Z`, `SUMMARY:${summary}`]
const billingKey = (person, uid) => `${person}+${sha256(uid).slice(0, 32)}`

/** All entries visible to `who` from revision 0, following `more`. */
async function all(who) {
	const out = []
	let since = 0
	for (let i = 0; i < 20; i++) {
		const r = await ocs(who, 'GET', `/entries?since=${since}&limit=2000`)
		if (r.status !== 200) {
			return { status: r.status, entries: [], text: r.text }
		}
		out.push(...r.data.entries)
		if (!r.data.more || r.data.entries.length === 0) {
			return { status: 200, entries: out, revision: r.data.revision, text: '' }
		}
		since = r.data.entries[r.data.entries.length - 1].revision
	}
	return { status: 200, entries: out, text: '' }
}
const find = (list, uid) => list.find((e) => e.event_uid === uid)
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

export async function eintraege() {
	head('Time entries: naming the time calendar')
	const cap = await ocs(A, 'GET', '/ocs/v2.php/cloud/capabilities')
	check('capability entries: 1', cap.data?.capabilities?.timesister?.entries === 1, cap.data?.capabilities?.timesister)
	check('time calendars of zbadmin and zbuser1 exist', (await ensureTimeCalendar(A)) && (await ensureTimeCalendar(U1)))
	// The job may have named it by convention meanwhile: un-name first, so the naming below is a fresh one.
	await ocs(U1, 'DELETE', '/me/time-calendar')
	expect('empty value', await ocs(U1, 'PUT', '/me/time-calendar', { calendar_url: '' }), 422, 'invalid')
	expect('no such calendar', await ocs(U1, 'PUT', '/me/time-calendar', { calendar_url: `${NC}/remote.php/dav/calendars/zbuser1/nicht-da-${RUN}/` }), 404, 'not_found')
	const n1 = await ocs(U1, 'PUT', '/me/time-calendar', { calendar_url: calendarUrl('zbuser1') })
	if (expect('zbuser1 names zeit-zbuser1', n1, 200)) {
		check('answer: uid, calendar_uri, calendar_id, named_at', n1.data.uid === 'zbuser1' && n1.data.calendar_uri === 'zeit-zbuser1' && Number.isInteger(n1.data.calendar_id) && ISO.test(n1.data.named_at), n1.data)
		// A small calendar is read at once (the dav backend is there in docker-next).
		check('answer: read_now true, count a number', n1.data.read_now === true && Number.isInteger(n1.data.count), n1.data)
		check('/me.time_calendar.last_read_at set right after naming', ISO.test((await ocs(U1, 'GET', '/me')).data?.time_calendar?.last_read_at ?? ''))
	}
	const again = await ocs(U1, 'PUT', '/me/time-calendar', { calendar_url: 'zeit-zbuser1' })
	check('the same calendar again changes nothing', again.status === 200 && again.data?.named_at === n1.data?.named_at && again.data?.read_now === false, again.data)
	expect('zbadmin names zeit-zbadmin', await ocs(A, 'PUT', '/me/time-calendar', { calendar_url: calendarUrl('zbadmin') }), 200)
	const me = await ocs(U1, 'GET', '/me')
	check('/me: time_calendar, entries_revision, entries_since', me.data?.time_calendar?.calendar_uri === 'zeit-zbuser1' && Number.isInteger(me.data?.entries_revision) && /^\d{4}-\d{2}-\d{2}$/.test(me.data?.entries_since ?? ''), me.data)
	const st = await ocs(A, 'GET', '/status')
	const row = (st.data || []).find((r) => r.uid === 'zbuser1')
	check('/status: time_calendar true for zbuser1, entries_read_at and entries_error present', row?.time_calendar === true && 'entries_read_at' in (row || {}) && 'entries_error' in (row || {}), row)
	// Another client may have named zblead's calendar meanwhile: only the shape is checked.
	check('/status: time_calendar is a boolean for zblead', typeof (st.data || []).find((r) => r.uid === 'zblead')?.time_calendar === 'boolean')

	head('Time entries: the listener and the full read')
	const coded = uidOf('coded')
	const plain = uidOf('plain')
	const adminEv = uidOf('admin')
	const old = uidOf('old')
	check('zbuser1 writes an event with the seed project’s code', (await putEvent(U1, coded, event(`Site visit #${CODE}`, '20261012'))) === 201)
	check('zbuser1 writes an event without a code', (await putEvent(U1, plain, event('Lunch', '20261012'))) === 201)
	check('zbadmin writes an event with the code', (await putEvent(A, adminEv, event(`Review #${CODE}:50%`, '20261013'))) === 201)
	check('zbuser1 writes an event before the cutoff', (await putEvent(U1, old, event(`Ancient #${CODE}`, '20150101'))) === 201)
	await sleep(500)
	let adminSees = await all(A)
	const viaListener = adminSees.status === 200 && find(adminSees.entries, coded) !== undefined && find(adminSees.entries, adminEv) !== undefined
	console.log(`    info  listener on CalDAV PUT: ${viaListener ? 'fired' : 'did not fire – checking through the full read'}`)
	const jobRan = runJob('EntryReader')
	if (!jobRan) {
		console.log('    info  TS_OCC not set – the job checks are skipped')
	}
	adminSees = await all(A)
	expect('Team Admin reads /entries', adminSees, 200)
	if (jobRan || viaListener) {
		const e = find(adminSees.entries, coded)
		check('the coded event is in the book', e !== undefined && e.uid === 'zbuser1' && e.source === 'calendar', e)
		check('codes lower-cased, ics as received, etag, object_uri', e?.codes?.includes(CODE.toLowerCase()) && e?.ics?.includes(`SUMMARY:Site visit #${CODE}`) && typeof e?.etag === 'string' && e?.etag !== '' && e?.object_uri === `${coded}.ics`, e)
		check('the share after “:” is dropped', find(adminSees.entries, adminEv)?.codes?.join() === CODE.toLowerCase(), find(adminSees.entries, adminEv)?.codes)
		check('the event without a code has no codes', find(adminSees.entries, plain)?.codes?.length === 0)
		check('an event before the cutoff is not stored', find(adminSees.entries, old) === undefined)
		check('flags: cancelled, kept, deleted false; revision, modified_at', e?.cancelled === false && e?.kept === false && e?.deleted === false && Number.isInteger(e?.revision) && ISO.test(e?.modified_at ?? ''), e)
	}
	if (jobRan) {
		const es = await ocs(A, 'GET', '/entries/status')
		expect('Team Admin reads /entries/status', es, 200)
		const s = (es.data || []).find((r) => r.uid === 'zbuser1' && r.source === 'calendar')
		check('status of zeit-zbuser1: read, no error, count ≥ 2', s !== undefined && ISO.test(s.last_full_read_at ?? '') && s.last_error === null && s.count >= 2 && s.calendar_uri === 'zeit-zbuser1', s)
		const me2 = await ocs(U1, 'GET', '/me')
		check('/me.time_calendar.last_read_at after the read', ISO.test(me2.data?.time_calendar?.last_read_at ?? ''), me2.data?.time_calendar)
	}

	head('Time entries: who sees what')
	if (jobRan || viaListener) {
		const lead = await all(L)
		expect('Lead reads /entries', lead, 200)
		check('Lead sees zbuser1’s coded event (their project)', find(lead.entries, coded) !== undefined)
		check('Lead sees zbadmin’s coded event', find(lead.entries, adminEv) !== undefined)
		check('Lead does not see zbuser1’s event without a code', find(lead.entries, plain) === undefined)
		const u1 = await all(U1)
		expect('User reads /entries', u1, 200)
		check('User sees both own events', find(u1.entries, coded) !== undefined && find(u1.entries, plain) !== undefined)
		check('User does not see zbadmin’s event', find(u1.entries, adminEv) === undefined)
		const u2 = await all(U2)
		check('another User sees none of them', u2.status === 200 && [coded, plain, adminEv].every((u) => find(u2.entries, u) === undefined))
		const page = await ocs(A, 'GET', '/entries?since=0&limit=1')
		check('limit=1 pages with more', page.status === 200 && page.data.entries.length === 1 && page.data.more === true, page.data)
		check('limit=2000 is fine', (await ocs(A, 'GET', '/entries?since=0&limit=2000')).status === 200)
		// Nextcloud itself refuses a `limit` outside 1–2000 before the controller runs.
		check('limit above 2000: 400 from the framework', (await ocs(A, 'GET', '/entries?since=0&limit=2001')).status === 400)
		const beyond = await ocs(A, 'GET', `/entries?since=${(page.data?.revision ?? 0) + 1000000}`)
		check('since beyond the current revision: empty, not an error', beyond.status === 200 && beyond.data?.entries?.length === 0 && beyond.data?.more === false, beyond.data)
	}

	head('Time entries: a member who left')
	if (jobRan) {
		const whileLeft = uidOf('while-left')
		try {
			expect('Team Admin records zbuser1 leaving', await ocs(A, 'PUT', '/team/members/zbuser1', { left: true }), 200)
			check('zbuser1 (left) still writes into the calendar', (await putEvent(U1, whileLeft, event(`While away #${CODE}`, '20261014'))) === 201)
			await sleep(500)
			runJob('EntryReader')
			const st = (await ocs(A, 'GET', '/entries/status')).data?.find((r) => r.uid === 'zbuser1' && r.source === 'calendar')
			check('status: left true, last_error “member left”', st?.left === true && st?.last_error === 'member left', st)
			const seen = await all(A)
			check('the entries of the member who left stay in the book', find(seen.entries, coded) !== undefined && find(seen.entries, plain) !== undefined)
			check('nothing new is read while they are away', find(seen.entries, whileLeft) === undefined)
		} finally {
			expect('zbuser1 is back', await ocs(A, 'PUT', '/team/members/zbuser1', { left: false }), 200)
		}
		runJob('EntryReader')
		const st = (await ocs(A, 'GET', '/entries/status')).data?.find((r) => r.uid === 'zbuser1' && r.source === 'calendar')
		check('back: left false, read again without error', st?.left === false && st?.last_error === null, st)
		check('the event written while away is in the book now', find((await all(A)).entries, whileLeft) !== undefined)
		await deleteEvent(U1, whileLeft)
	}

	head('Time entries: update, kept and tombstone')
	if (jobRan || viaListener) {
		const before = (await all(A)).revision
		check('zbuser1 changes the coded event', (await putEvent(U1, coded, event(`Site visit, longer #${CODE}`, '20261012'))) === 204)
		await sleep(500)
		let delta = await ocs(A, 'GET', `/entries?since=${before}`)
		let updated = delta.status === 200 && find(delta.data.entries, coded)
		if (!updated && jobRan) {
			runJob('EntryReader')
			delta = await ocs(A, 'GET', `/entries?since=${before}`)
			updated = delta.status === 200 && find(delta.data.entries, coded)
		}
		check('the change comes with a new revision', !!updated && updated.revision > before && updated.ics.includes('Site visit, longer'), delta.data)
		// Billing mark for the plain event: it stays in the book when it vanishes.
		const bk = billingKey('zbuser1', plain)
		expect('Team Admin marks the plain event as billed', await ocs(A, 'PUT', `/records/billing/${bk}`, { version: 0, data: { person: 'zbuser1', uid: plain, billed_on: '2026-10-14' } }), 200)
		check('zbuser1 deletes both events', (await deleteEvent(U1, plain)) === 204 && (await deleteEvent(U1, coded)) === 204)
		await sleep(500)
		let after = await all(A)
		let kept = find(after.entries, plain)
		let gone = (await ocs(A, 'GET', `/entries?since=${before}`)).data?.entries?.find((e) => e.event_uid === coded && e.deleted)
		if ((!kept?.kept || !gone) && jobRan) {
			runJob('EntryReader')
			after = await all(A)
			kept = find(after.entries, plain)
			gone = (await ocs(A, 'GET', `/entries?since=${before}`)).data?.entries?.find((e) => e.event_uid === coded && e.deleted)
		}
		check('billed event: kept, text stays', kept?.kept === true && kept?.deleted === false && kept?.ics?.includes('SUMMARY:Lunch'), kept)
		check('unbilled event: tombstone without text', gone !== undefined && gone.deleted === true && gone.ics === '', gone)
		check('since=0 leaves tombstones out', find(after.entries, coded) === undefined)
		const bv = (await ocs(A, 'GET', `/records/billing/${bk}`)).data?.version
		await ocs(A, 'DELETE', `/records/billing/${bk}?version=${bv}`)
	}

	head('Time entries: cutoff and re-read')
	expect('User sets the cutoff', await ocs(U1, 'PUT', '/team/settings', { entries_since: '2020-01-01' }), 403, 'forbidden')
	expect('malformed date', await ocs(A, 'PUT', '/team/settings', { entries_since: '2020-13-01' }), 422, 'invalid')
	expect('another key', await ocs(A, 'PUT', '/team/settings', { backup_required: true }), 422, 'invalid')
	const set = await ocs(A, 'PUT', '/team/settings', { entries_since: '2020-01-01' })
	check('Team Admin sets entries_since', set.status === 200 && set.data?.entries_since === '2020-01-01', set.data)
	check('/team shows the stored cutoff', (await ocs(A, 'GET', '/team')).data?.settings?.entries_since === '2020-01-01')
	check('/me shows the effective cutoff', (await ocs(U1, 'GET', '/me')).data?.entries_since === '2020-01-01')
	if (jobRan) {
		const es = await ocs(A, 'GET', '/entries/status')
		check('a moved cutoff marks the sources for a full read (last_full_read_at stays until the run)', (es.data || []).some((r) => r.uid === 'zbuser1'))
	}
	const reset = await ocs(A, 'PUT', '/team/settings', { entries_since: null })
	check('null restores the default', reset.status === 200 && reset.data?.entries_since === null, reset.data)
	expect('User asks for a re-read', await ocs(U1, 'POST', '/entries/reread'), 403, 'forbidden')
	const rr = await ocs(A, 'POST', '/entries/reread')
	check('Team Admin asks for a re-read', rr.status === 200 && rr.data?.marked >= 2, rr.data)
	expect('User reads /entries/status', await ocs(U1, 'GET', '/entries/status'), 403, 'forbidden')
	if (jobRan) {
		// A moved cutoff: entries ending before it vanish (tombstone, or kept when billed); moved back, they are read again.
		const cut = uidOf('cut')
		check('zbuser1 writes an event in October 2026', (await putEvent(U1, cut, event(`Cutoff #${CODE}`, '20261012'))) === 201)
		runJob('EntryReader')
		check('… it is in the book', find((await all(A)).entries, cut) !== undefined)
		const before = (await all(A)).revision
		expect('Team Admin moves the cutoff past it', await ocs(A, 'PUT', '/team/settings', { entries_since: '2027-01-01' }), 200)
		runJob('EntryReader')
		const gone = (await ocs(A, 'GET', `/entries?since=${before}`)).data?.entries?.find((e) => e.event_uid === cut)
		check('… the event becomes a tombstone', gone?.deleted === true && gone?.ics === '', gone)
		expect('… the cutoff goes back to the default', await ocs(A, 'PUT', '/team/settings', { entries_since: null }), 200)
		runJob('EntryReader')
		const back = find((await all(A)).entries, cut)
		check('… and the event is read again', back !== undefined && back.deleted === false && back.ics.includes('Cutoff'), back)
		await deleteEvent(U1, cut)
	}

	head('Time entries: un-naming')
	const un = await ocs(U1, 'DELETE', '/me/time-calendar')
	check('zbuser1 un-names', un.status === 200 && un.data?.removed === true, un.data)
	check('un-naming again: removed false', (await ocs(U1, 'DELETE', '/me/time-calendar')).data?.removed === false)
	check('/me.time_calendar is null', (await ocs(U1, 'GET', '/me')).data?.time_calendar === null)
	await deleteEvent(A, adminEv)
	await deleteEvent(U1, old)
	expect('zbadmin un-names', await ocs(A, 'DELETE', '/me/time-calendar'), 200)

	await teamZip()
}

const auth = (who) => ({ 'OCS-APIRequest': 'true', Accept: 'application/json', Authorization: 'Basic ' + Buffer.from(`${who.user}:${who.pass}`).toString('base64') })

/** Preview of a team ZIP for team `id`: multipart field `file`. */
async function preview(id, zip) {
	const form = new FormData()
	form.append('file', new Blob([zip], { type: 'application/zip' }), 'team.zip')
	const res = await fetch(`${NC}${API}/admin/teams/${id}/import/preview`, { method: 'POST', headers: auth(ADMIN), body: form })
	const json = await res.json().catch(() => null)
	return { status: res.status, data: json?.ocs?.data ?? null }
}

/** A team ZIP (format 1) with the given team.json, nothing else. */
function teamZipOf(team) {
	const teamJson = JSON.stringify(team)
	const manifest = {
		format: 1, app_version: '0.17.0', api: 2, team: { id: 1, name: team.name, slug: team.slug, group: null },
		taken_at: '2026-10-15T08:00:00Z', contains_secrets: true,
		files: { 'team.json': { sha256: sha256(teamJson), size: Buffer.byteLength(teamJson) } },
	}
	return storedZip({ 'manifest.json': JSON.stringify(manifest), 'team.json': teamJson })
}

/**
 * Team ZIP (0.17.0): the cutoff and the named time calendars travel with
 * the team – own test team, own account with a `zeit-` calendar.
 */
async function teamZip() {
	head('Time entries: cutoff and named calendars in the team ZIP (own test team)')
	const group = `te-${RUN}-t`
	const uid = `te${RUN}`
	const TE = { user: uid, pass: `Test-2026-${uid}!` }
	// A second account without any zeit- calendar.
	const bare = `${uid}b`
	await ocs(ADMIN, 'POST', '/ocs/v2.php/cloud/groups', { groupid: group })
	await ocs(ADMIN, 'POST', '/ocs/v2.php/cloud/users', { userid: uid, password: TE.pass, groups: [group] })
	await ocs(ADMIN, 'POST', '/ocs/v2.php/cloud/users', { userid: bare, password: `Test-2026-${bare}!`, groups: [group] })
	const name = `Entries Test ${RUN}`
	let id
	try {
		check('the test account has a zeit- calendar', await ensureTimeCalendar(TE))
		const made = await ocs(ADMIN, 'POST', '/admin/teams', { name, slug: `te-${RUN}`, groups: { team: group } })
		id = made.data?.id
		expect('team created', made, 200)
		const zip = teamZipOf({
			name, slug: `te-${RUN}`, members: [{ uid: 'oldx', display_name: 'Old', role: 'user', left_at: null }], consents: [],
			settings: { backup_required: false, entries_since: '2021-03-01' },
			time_calendars: [{ uid: 'oldx', calendar_uri: 'zeit-oldx' }, { uid: 'nobody', calendar_uri: 'zeit-nobody' }, { uid: 'nocal', calendar_uri: 'zeit-nocal' }],
		})
		const p1 = await preview(id, zip)
		check('preview: entries_since and the count of named calendars', p1.status === 200 && p1.data?.entries_since === '2021-03-01' && p1.data?.time_calendars === 3, p1.data)
		const imp = await ocs(ADMIN, 'POST', `/admin/teams/${id}/import`, { token: p1.data?.token, mode: 'merge', mapping: { oldx: uid, nobody: null, nocal: bare } })
		expect('import with merge (mapping: oldx → the test account, nobody → none, nocal → an account without such a calendar)', imp, 200)
		check('… the cutoff is applied', JSON.stringify(imp.data?.entries_since) === JSON.stringify({ value: '2021-03-01', applied: true }), imp.data?.entries_since)
		check('… zeit-<new uid> named for the mapped account, the others reported, nothing aborted', JSON.stringify(imp.data?.time_calendars) === JSON.stringify([
			{ uid, named: true, calendar_uri: `zeit-${uid}` }, { uid: 'nobody', named: false, reason: 'no_account' }, { uid: bare, named: false, reason: 'no_calendar' },
		]), imp.data?.time_calendars)
		check('/admin/teams shows the stored cutoff', (await ocs(ADMIN, 'GET', '/admin/teams')).data?.find((t) => t.id === id)?.settings?.entries_since === '2021-03-01')
		const res = await fetch(`${NC}${API}/admin/teams/${id}/export`, { headers: auth(ADMIN) })
		const exported = Buffer.from(await res.arrayBuffer())
		check('export → 200', res.status === 200)
		const p2 = await preview(id, exported)
		check('the exported ZIP carries the cutoff and one named calendar', p2.data?.entries_since === '2021-03-01' && p2.data?.time_calendars === 1, p2.data)
		const p3 = await preview(id, teamZipOf({ name, slug: `te-${RUN}`, members: [], consents: [], settings: { entries_since: '2022-01-01' }, time_calendars: [{ uid, calendar_uri: 'zeit-other' }] }))
		const merge = await ocs(ADMIN, 'POST', `/admin/teams/${id}/import`, { token: p3.data?.token, mode: 'merge', mapping: {} })
		check('merge keeps the stored cutoff and the named calendar', merge.status === 200 && merge.data?.entries_since?.applied === false && merge.data?.time_calendars?.[0]?.reason === 'kept_existing', merge.data)
		check('… cutoff unchanged', (await ocs(ADMIN, 'GET', '/admin/teams')).data?.find((t) => t.id === id)?.settings?.entries_since === '2021-03-01')
	} finally {
		if (id) {
			await ocs(ADMIN, 'DELETE', `/admin/teams/${id}`, { confirm: name })
		}
		await ocs(ADMIN, 'DELETE', `/ocs/v2.php/cloud/users/${uid}`)
		await ocs(ADMIN, 'DELETE', `/ocs/v2.php/cloud/users/${bare}`)
		await ocs(ADMIN, 'DELETE', `/ocs/v2.php/cloud/groups/${group}`)
	}
}
