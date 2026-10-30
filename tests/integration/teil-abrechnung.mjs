// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Billing marks and externals (0.7.6): the record of kind `billing` with
// its rights per person, the feed address only for those who may. Since
// 0.14.0 only Team Admins write and read others' marks and see feeds; since
// 0.17.0 there is no shares matrix (the server reads the feeds itself).
// Leaves zb as it found it: the marks deleted, the external removed.
import { createHash } from 'node:crypto'
import { ocs } from './lib.mjs'
import { RUN, check, expect, head } from './harness.mjs'
import { A, L, U1, U2, V } from './konten.mjs'

const key = (person, uid) => `${person}+${createHash('sha256').update(uid).digest('hex').slice(0, 32)}`
const mark = (person, uid, more = {}) => ({ person, uid, billed_on: '2026-09-30', ...more })

export async function abrechnung() {
	const EXT = `ext-roman-${RUN}`
	const FEED = `http://127.0.0.1:1/${RUN}.ics`
	const uid1 = `u1-${RUN}@example.test`
	const uid2 = `u2-${RUN}@example.test`
	const uidX = `ux-${RUN}@example.test`
	const k1 = key('zbuser1', uid1)
	const k2 = key('zbuser1', uid2)
	const kX = key(EXT, uidX)
	try {
		head('Capability and the external person')
		{
			const cap = await ocs(A, 'GET', '/ocs/v2.php/cloud/capabilities')
			check('capability billing: 1', cap.data?.capabilities?.timesister?.billing === 1, cap.data?.capabilities?.timesister)
			const r = await ocs(A, 'PUT', `/records/person/${EXT}`, { version: 0, data: { login: EXT, first_name: 'Roman', last_name: `Stucki ${RUN}`, feed: FEED } })
			expect('Team Admin creates an external with a feed', r, 200)
			// 0.17.0: the shares matrix is gone – no route any more.
			expect('GET /team/access is gone', await ocs(A, 'GET', '/team/access'), 404)
			expect('PUT /team/access/{viewer}/{owner} is gone', await ocs(A, 'PUT', `/team/access/zbadmin/${EXT}`, { level: 'view' }), 404)
			expect('POST /team/access/remind is gone', await ocs(A, 'POST', '/team/access/remind', {}), 404)
		}

		head('Feed only for those who may')
		{
			const asLead = await ocs(L, 'GET', `/records/person/${EXT}`)
			// 0.14.0: a Lead reads others only as a name card.
			const card = ['accounts', 'first_name', 'initials', 'last_name', 'login', 'schema']
			check('Lead: the external as a name card, without feed', asLead.status === 200 && asLead.data?.data?.first_name === 'Roman'
				&& Object.keys(asLead.data?.data || {}).every((k) => card.includes(k)), asLead.data?.data)
			check('Team Admin: with feed', (await ocs(V, 'GET', `/records/person/${EXT}`)).data?.data?.feed === FEED)
			expect('User: others’ persons not at all', await ocs(U1, 'GET', `/records/person/${EXT}`), 403, 'forbidden')
		}

		head('Billing marks: write')
		{
			expect('malformed key', await ocs(A, 'PUT', '/records/billing/zbuser1', { version: 0, data: mark('zbuser1', uid1) }), 422, 'invalid')
			expect('key does not match the data', await ocs(A, 'PUT', `/records/billing/${k1}`, { version: 0, data: mark('zbuser1', uid2) }), 422, 'invalid')
			expect('no date', await ocs(A, 'PUT', `/records/billing/${k1}`, { version: 0, data: { person: 'zbuser1', uid: uid1 } }), 422, 'invalid')
			const r = await ocs(A, 'PUT', `/records/billing/${k1}`, { version: 0, data: mark('zbuser1', uid1, { by: 'zbadmin', checksum: 'abc', source: 'manual', external_id: 'bx-17', document: 'R-2026-001' }) })
			expect('Team Admin bills an event of zbuser1', r, 200)
			check('the mark comes back with the carried-through fields', r.data?.kind === 'billing' && r.data?.data?.source === 'manual' && r.data?.data?.external_id === 'bx-17' && r.data?.data?.document === 'R-2026-001', r.data?.data)
			expect('User bills their own event', await ocs(U1, 'PUT', `/records/billing/${k2}`, { version: 0, data: mark('zbuser1', uid2) }), 403, 'forbidden')
			// 0.14.0: billing marks are written by Team Admins only.
			expect('Lead bills zbuser1', await ocs(L, 'PUT', `/records/billing/${k2}`, { version: 0, data: mark('zbuser1', uid2) }), 403, 'forbidden')
			expect('Lead bills the external', await ocs(L, 'PUT', `/records/billing/${kX}`, { version: 0, data: mark(EXT, uidX) }), 403, 'forbidden')
			expect('Lead: batch with billing marks only', await ocs(L, 'POST', '/records/batch', { writes: [{ kind: 'billing', key: k2, version: 0, data: mark('zbuser1', uid2, { by: 'zblead' }) }] }), 403, 'forbidden')
			expect('second Team Admin bills zbuser1', await ocs(V, 'PUT', `/records/billing/${k2}`, { version: 0, data: mark('zbuser1', uid2) }), 200)
			expect('Team Admin bills the external', await ocs(A, 'PUT', `/records/billing/${kX}`, { version: 0, data: mark(EXT, uidX) }), 200)
			expect('Team Admin: batch with billing marks', await ocs(V, 'POST', '/records/batch', { writes: [{ kind: 'billing', key: k2, version: 1, data: mark('zbuser1', uid2, { by: 'zbverw' }) }] }), 200)
			expect('Lead: batch with a project in it', await ocs(L, 'POST', '/records/batch', { writes: [{ kind: 'billing', key: k2, version: 2, data: mark('zbuser1', uid2) }, { kind: 'project', key: 'P', version: 0, data: { id: 'P' } }] }), 403, 'forbidden')
			expect('User: batch with a mark of someone else', await ocs(U1, 'POST', '/records/batch', { writes: [{ kind: 'billing', key: key('zbuser2', uid1), version: 0, data: mark('zbuser2', uid1) }] }), 403, 'forbidden')
		}

		head('Billing marks: read, history, undo')
		{
			expect('the person reads their own mark', await ocs(U1, 'GET', `/records/billing/${k1}`), 200)
			expect('another User does not', await ocs(U2, 'GET', `/records/billing/${k1}`), 403, 'forbidden')
			expect('the Lead does not read it (0.14.0)', await ocs(L, 'GET', `/records/billing/${k1}`), 403, 'forbidden')
			const lk = new Set(((await ocs(L, 'GET', '/records')).data?.records || []).filter((x) => x.kind === 'billing').map((x) => x.key))
			check('delta of the Lead: none of them', !lk.has(k1) && !lk.has(k2) && !lk.has(kX), [...lk].filter((k) => k.includes(RUN)))
			const all = await ocs(U1, 'GET', '/records')
			const keys = new Set((all.data?.records || []).filter((x) => x.kind === 'billing').map((x) => x.key))
			check('delta of zbuser1: own marks, not the external’s', keys.has(k1) && keys.has(k2) && !keys.has(kX), [...keys].filter((k) => k.includes(RUN)))
			const u2 = new Set(((await ocs(U2, 'GET', '/records')).data?.records || []).filter((x) => x.kind === 'billing').map((x) => x.key))
			check('delta of zbuser2: none of them', !u2.has(k1) && !u2.has(k2) && !u2.has(kX))
			const h = await ocs(U1, 'GET', `/records/billing/${k2}/history`)
			check('history for the person: both versions, who did what', h.status === 200 && h.data?.length === 2 && h.data?.[0]?.modified_by === 'zbverw', h.data)
			expect('history for another User', await ocs(U2, 'GET', `/records/billing/${k2}/history`), 403, 'forbidden')
			expect('User undoes', await ocs(U1, 'DELETE', `/records/billing/${k1}?version=1`), 403, 'forbidden')
			expect('Lead undoes', await ocs(L, 'DELETE', `/records/billing/${k1}?version=1`), 403, 'forbidden')
			expect('Team Admin undoes', await ocs(V, 'DELETE', `/records/billing/${k1}?version=1`), 200)
			const since = (await ocs(U1, 'GET', '/me')).data?.revision - 1
			const t = (await ocs(U1, 'GET', `/records?since=${since}`)).data?.records?.find((x) => x.key === k1)
			check('the person gets the tombstone', t?.deleted === true && t?.data === null, t)
			expect('restore by the Team Admin', await ocs(A, 'POST', `/records/billing/${k1}/restore`, { version: 1, current: 2 }), 200)
		}
	} finally {
		head('Billing: cleaning up')
		for (const [k, v] of [[k1, 3], [k2, 2], [kX, 1]]) {
			await ocs(A, 'DELETE', `/records/billing/${k}?version=${v}`)
		}
		const cur = await ocs(A, 'GET', `/records/person/${EXT}`)
		if (cur.status === 200) {
			await ocs(A, 'DELETE', `/records/person/${EXT}?version=${cur.data.version}`)
		}
		check('cleaned up', true)
	}
}
