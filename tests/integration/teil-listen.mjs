// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Lists of hours (0.28.0): the hours of an external person go straight
// into the book with POST /entries/list – put, the role filter of GET
// /entries, a correction that a list sent again keeps, delete, the lock
// of a billing mark, the list in the team ZIP, and the tombstones when
// the person record is deleted. Only ZZ data; everything is cleaned up.
import { inflateRawSync } from 'node:zlib'
import { ADMIN, API, NC, ocs, seedProjectOf } from './lib.mjs'
import { A, L, RUN, U1, check, expect, head, sha256 } from './harness.mjs'

const EXT = 'ext-zzliste'
const CODE = seedProjectOf('pb').toUpperCase()
const uidOf = (name) => `zzl-${RUN}-${name}@example.test`
const billingKey = (person, uid) => `${person}+${sha256(uid).slice(0, 32)}`

/** One object of the list: a VEVENT with the project code in the title. */
function object(uid, day, title, extra = []) {
	return ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//TimeSister Test//EN', 'BEGIN:VEVENT', `UID:${uid}`, 'DTSTAMP:20261001T080000Z',
		`DTSTART:${day}T080000Z`, `DTEND:${day}T100000Z`, `SUMMARY:#${CODE} ${title}`, ...extra, 'END:VEVENT', 'END:VCALENDAR', ''].join('\r\n')
}

/** All entries visible to `who` after revision `since`, following `more`. */
async function entries(who, since = 0) {
	const out = []
	for (let i = 0; i < 20; i++) {
		const r = await ocs(who, 'GET', `/entries?since=${since}&limit=2000`)
		if (r.status !== 200) {
			return { status: r.status, entries: [], revision: 0, text: r.text }
		}
		out.push(...r.data.entries)
		if (!r.data.more || r.data.entries.length === 0) {
			return { status: 200, entries: out, revision: r.data.revision, text: '' }
		}
		since = r.data.entries[r.data.entries.length - 1].revision
	}
	return { status: 200, entries: out, revision: since, text: '' }
}
const ofExt = (list, uid) => list.find((e) => e.uid === EXT && e.event_uid === uid)
const result = (r, uid) => (r.data?.results || []).find((x) => x.event_uid === uid)

/** The entries of a ZIP (stored or deflated): name → Buffer, read from the central directory. */
function unzip(buf) {
	const out = {}
	let eocd = buf.length - 22
	while (eocd >= 0 && buf.readUInt32LE(eocd) !== 0x06054b50) {
		eocd--
	}
	if (eocd < 0) {
		return out
	}
	let p = buf.readUInt32LE(eocd + 16)
	const n = buf.readUInt16LE(eocd + 10)
	for (let i = 0; i < n && buf.readUInt32LE(p) === 0x02014b50; i++) {
		const method = buf.readUInt16LE(p + 10)
		const size = buf.readUInt32LE(p + 20)
		const nameLen = buf.readUInt16LE(p + 28)
		const extraLen = buf.readUInt16LE(p + 30)
		const commentLen = buf.readUInt16LE(p + 32)
		const local = buf.readUInt32LE(p + 42)
		const name = buf.subarray(p + 46, p + 46 + nameLen).toString('utf8')
		const dataStart = local + 30 + buf.readUInt16LE(local + 26) + buf.readUInt16LE(local + 28)
		const data = buf.subarray(dataStart, dataStart + size)
		out[name] = method === 8 ? inflateRawSync(data) : data
		p += 46 + nameLen + extraLen + commentLen
	}
	return out
}

/** The person record, fresh: a tombstone from an earlier run is written over with its version. */
async function createPerson() {
	const data = { login: EXT, first_name: 'Zoe', last_name: `Liste ${RUN}` }
	const cur = await ocs(A, 'GET', `/records/person/${EXT}`)
	const version = cur.status === 200 && Number.isInteger(cur.data?.version) ? cur.data.version : 0
	return ocs(A, 'PUT', `/records/person/${EXT}`, { version, data })
}

export async function listen() {
	head('Lists of hours: capability and the external person')
	const cap = await ocs(A, 'GET', '/ocs/v2.php/cloud/capabilities')
	check('capability entry_lists: 1', cap.data?.capabilities?.timesister?.entry_lists === 1, cap.data?.capabilities?.timesister)
	const e1 = uidOf('one')
	const e2 = uidOf('two')
	const bk = billingKey(EXT, e1)
	let personVersion = 0
	try {
		const made = await createPerson()
		expect('Team Admin creates the external person', made, 200)
		personVersion = made.data?.version ?? 0
		expect('a member is no external person', await ocs(A, 'POST', '/entries/list', { uid: 'pbuser1', put: [object(e1, '20261005', 'x')] }), 422, 'invalid')
		expect('a Lead may not send lists', await ocs(L, 'POST', '/entries/list', { uid: EXT, put: [object(e1, '20261005', 'x')] }), 403, 'forbidden')
		const two = object(e1, '20261005', 'a').replace('END:VCALENDAR', `BEGIN:VEVENT\r\nUID:${e2}\r\nDTSTAMP:20261001T080000Z\r\nDTSTART:20261006T080000Z\r\nDTEND:20261006T090000Z\r\nSUMMARY:b\r\nEND:VEVENT\r\nEND:VCALENDAR`)
		expect('an object with two UIDs is refused', await ocs(A, 'POST', '/entries/list', { uid: EXT, put: [two] }), 422, 'invalid')

		head('Lists of hours: put and the role filter')
		const before = (await entries(A)).revision
		const put = await ocs(A, 'POST', '/entries/list', { uid: EXT, put: [object(e1, '20261005', 'Montage'), object(e2, '20261006', 'Montage')] })
		expect('two objects go into the book', put, 200)
		const r1 = result(put, e1)
		check('… each with status ok, etag and revision', r1?.status === 'ok' && typeof r1.etag === 'string' && r1.etag !== '' && Number.isInteger(r1.revision) && result(put, e2)?.status === 'ok', put.data)
		const admin = await entries(A, before)
		const a1 = ofExt(admin.entries, e1)
		check('Team Admin: GET /entries delivers them with source list and the text', a1?.source === 'list' && a1.ics.includes('Montage') && a1.codes.includes(CODE.toLowerCase()) && ofExt(admin.entries, e2)?.source === 'list', a1)
		check('Lead of the project: sees them', ofExt((await entries(L, before)).entries, e1)?.source === 'list')
		check('User: does not see them', ofExt((await entries(U1, before)).entries, e1) === undefined)
		const same = await ocs(A, 'POST', '/entries/list', { uid: EXT, put: [object(e1, '20261005', 'Montage')] })
		check('the same object again: ok, same etag', result(same, e1)?.status === 'ok' && result(same, e1)?.etag === r1?.etag, same.data)

		head('Lists of hours: a correction that a list sent again keeps')
		const corr = await ocs(L, 'POST', '/entries/correct', { entries: [{ uid: EXT, event_uid: e1 }], title: 'Montage korrigiert' })
		expect('the Lead corrects the title', corr, 200)
		check('… status ok, the text carries the new title', result(corr, e1)?.status === 'ok' && result(corr, e1)?.ics?.includes('Montage korrigiert'), corr.data)
		const again = await ocs(A, 'POST', '/entries/list', { uid: EXT, put: [object(e1, '20261005', 'Montage')] })
		check('the list sent again: ok', result(again, e1)?.status === 'ok', again.data)
		const kept = ofExt((await entries(A, before)).entries, e1)
		check('… the correction stays (foldInto)', kept?.ics?.includes('Montage korrigiert') === true && !kept.ics.includes('#' + CODE + ' Montage\r'), kept?.ics)
		const log = await ocs(A, 'GET', `/entries/corrections?uid=${EXT}&event_uid=${encodeURIComponent(e1)}`)
		check('the protocol notes the correction as applied', log.status === 200 && (log.data?.corrections || log.data || []).length >= 1, log.data)

		head('Lists of hours: delete and the lock of a billing mark')
		const mark = (await entries(A)).revision
		const del = await ocs(A, 'POST', '/entries/list', { uid: EXT, delete: [e2] })
		check('delete tombstones the second object', result(del, e2)?.status === 'ok' && result(del, e2)?.deleted === true, del.data)
		const gone = ofExt((await entries(A, mark)).entries, e2)
		check('… GET /entries delivers the tombstone', gone?.deleted === true && gone?.ics === '', gone)
		check('delete again: ok, deleted', result(await ocs(A, 'POST', '/entries/list', { uid: EXT, delete: [e2] }), e2)?.deleted === true)
		const billed = await ocs(A, 'PUT', `/records/billing/${bk}`, { version: 0, data: { person: EXT, uid: e1, billed_on: '2026-10-31' } })
		expect('Team Admin marks the first object billed', billed, 200)
		const locked = await ocs(A, 'POST', '/entries/list', { uid: EXT, put: [object(e1, '20261005', 'Montage neu')], delete: [e1] })
		check('… put of the billed object: locked', result(locked, e1)?.status === 'locked' && /billed/.test(result(locked, e1)?.message || ''), locked.data)
		check('… delete of the billed object: locked', (locked.data?.results || []).filter((x) => x.event_uid === e1).every((x) => x.status === 'locked'), locked.data)
		check('… the text is untouched', ofExt((await entries(A)).entries, e1)?.ics?.includes('Montage korrigiert') === true)
		const corrLocked = await ocs(L, 'POST', '/entries/correct', { entries: [{ uid: EXT, event_uid: e1 }], title: 'x' })
		check('… a correction: locked', result(corrLocked, e1)?.status === 'locked', corrLocked.data)
		expect('the mark is taken back', await ocs(A, 'DELETE', `/records/billing/${bk}?version=${billed.data?.version ?? 1}`), 200)

		head('Lists of hours: the team ZIP')
		const team = (await ocs(ADMIN, 'GET', '/admin/teams')).data?.find((t) => t.slug === 'pb')
		check('the team pb is known', Number.isInteger(team?.id), team)
		if (Number.isInteger(team?.id)) {
			const res = await fetch(`${NC}${API}/admin/teams/${team.id}/export`, { headers: { 'OCS-APIRequest': 'true', Authorization: 'Basic ' + Buffer.from(`${ADMIN.user}:${ADMIN.pass}`).toString('base64') } })
			const zip = unzip(Buffer.from(await res.arrayBuffer()))
			check('export → 200 with a manifest', res.status === 200 && zip['manifest.json'] !== undefined, res.status)
			const manifest = JSON.parse(zip['manifest.json']?.toString('utf8') || '{}')
			const path = `calendars/list-${EXT}.ics`
			check(`the manifest lists ${path} with list: true and the uid`, manifest.files?.[path]?.list === true && manifest.files?.[path]?.uid === EXT, manifest.files?.[path])
			const ics = zip[path]?.toString('utf8') || ''
			check('… the file holds the live object, not the deleted one', ics.includes(`UID:${e1}`) && ics.includes('Montage korrigiert') && !ics.includes(`UID:${e2}`), ics.slice(0, 200))
			check('… never under the path of a member calendar', manifest.files?.[`calendars/${EXT}.ics`] === undefined)
		}

		head('Lists of hours: the person record deleted')
		const last = (await entries(A)).revision
		const cur = await ocs(A, 'GET', `/records/person/${EXT}`)
		personVersion = cur.data?.version ?? personVersion
		expect('Team Admin deletes the person', await ocs(A, 'DELETE', `/records/person/${EXT}?version=${personVersion}`), 200)
		personVersion = 0
		const stone = ofExt((await entries(A, last)).entries, e1)
		check('… GET /entries delivers the tombstone of the list entry', stone?.deleted === true && stone?.ics === '', stone)
		check('… nothing of the person is live any more', (await entries(A)).entries.every((e) => e.uid !== EXT))
		expect('a list for a deleted person is refused', await ocs(A, 'POST', '/entries/list', { uid: EXT, put: [object(e1, '20261005', 'x')] }), 422, 'invalid')
	} finally {
		await ocs(A, 'DELETE', `/records/billing/${bk}?version=1`)
		const cur = await ocs(A, 'GET', `/records/person/${EXT}`)
		if (cur.status === 200 && cur.data?.deleted !== true) {
			await ocs(A, 'DELETE', `/records/person/${EXT}?version=${cur.data.version}`)
		}
	}
}
