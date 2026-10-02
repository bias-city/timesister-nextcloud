// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Billing marks and externals (0.7.6): the record of kind `billing` with
// its rights per person, externals as columns of the shares matrix, the
// feed address only for those who may. Leaves pb as it found it: the
// marks deleted, the fields back to default, the external removed.
import { createHash } from 'node:crypto'
import { ocs } from './lib.mjs'
import { A, L, RUN, U1, U2, V, check, expect, head } from './harness.mjs'

const key = (person, uid) => `${person}+${createHash('sha256').update(uid).digest('hex').slice(0, 32)}`
const put = (who, viewer, owner, level) => ocs(who, 'PUT', `/team/access/${viewer}/${owner}`, { level })
const field = (d, viewer, owner) => (d?.fields || []).find((f) => f.viewer === viewer && f.owner === owner)
const mark = (person, uid, more = {}) => ({ person, uid, billed_on: '2026-09-30', ...more })

export async function abrechnung() {
	const EXT = `ext-roman-${RUN}`
	const FEED = `http://127.0.0.1:1/${RUN}.ics`
	const uid1 = `u1-${RUN}@example.test`
	const uid2 = `u2-${RUN}@example.test`
	const uidX = `ux-${RUN}@example.test`
	const k1 = key('pbuser1', uid1)
	const k2 = key('pbuser1', uid2)
	const kX = key(EXT, uidX)
	try {
		head('Capability and the external person')
		{
			const cap = await ocs(A, 'GET', '/ocs/v2.php/cloud/capabilities')
			check('capability billing: 1', cap.data?.capabilities?.timesister?.billing === 1, cap.data?.capabilities?.timesister)
			const r = await ocs(A, 'PUT', `/records/person/${EXT}`, { version: 0, data: { login: EXT, first_name: 'Roman', last_name: `Stucki ${RUN}`, feed: FEED } })
			expect('Team Admin creates an external with a feed', r, 200)
			const d = (await ocs(A, 'GET', '/team/access')).data
			const m = (d?.members || []).find((x) => x.uid === EXT)
			check('the external is a column after the team', !!m && m.external === true && m.role === 'external' && m.display_name === `Roman Stucki ${RUN}`
				&& (d.members || []).findIndex((x) => x.uid === EXT) > (d.members || []).findIndex((x) => x.uid === 'pbuser2'), m)
			const fa = field(d, 'pbadmin', EXT), fl = field(d, 'pblead', EXT)
			check('Team Admin sees by default, the Lead not, nothing pending', fa?.level === 'view' && fa?.external === true && fa?.pending === false && fa?.effective === true
				&& fl?.level === 'none' && fl?.self_level === null, [fa, fl])
			check('no row for the external', !(d?.fields || []).some((f) => f.viewer === EXT))
			const l = (await ocs(L, 'GET', '/team/access')).data
			check('Lead: the own row shows the external', field(l, 'pblead', EXT)?.level === 'none' && (l?.members || []).some((x) => x.uid === EXT), l?.fields?.length)
		}

		head('Feed only for those who may')
		{
			const asLead = await ocs(L, 'GET', `/records/person/${EXT}`)
			check('Lead without view: person readable, without feed', asLead.status === 200 && asLead.data?.data?.first_name === 'Roman' && !('feed' in (asLead.data?.data || {})), asLead.data?.data)
			check('Team Admin: with feed', (await ocs(V, 'GET', `/records/person/${EXT}`)).data?.data?.feed === FEED)
			expect('User: others’ persons not at all', await ocs(U1, 'GET', `/records/person/${EXT}`), 403, 'forbidden')
			const revBefore = (await ocs(L, 'GET', '/me')).data?.revision
			expect('edit on a feed', await put(A, 'pblead', EXT, 'edit'), 422, 'invalid')
			expect('Lead sets an external column', await put(L, 'pblead', EXT, 'view'), 403, 'forbidden')
			expect('the external as a row', await put(A, EXT, 'pblead', 'view'), 404, 'not_found')
			const r = await put(A, 'pblead', EXT, 'view')
			check('Team Admin gives the Lead view', r.status === 200 && field(r.data, 'pblead', EXT)?.level === 'view' && field(r.data, 'pblead', EXT)?.changed_by === 'pbadmin', field(r.data, 'pblead', EXT))
			const now = await ocs(L, 'GET', `/records/person/${EXT}`)
			check('… now the Lead reads the feed', now.data?.data?.feed === FEED, now.data?.data)
			const delta = await ocs(L, 'GET', `/records?since=${revBefore}`)
			const p = (delta.data?.records || []).find((x) => x.kind === 'person' && x.key === EXT)
			check('… and the delta carries the person again (new revision, same version)', !!p && p.version === 1 && p.revision > revBefore && p.data?.feed === FEED, p)
			const hist = await ocs(A, 'GET', `/records/person/${EXT}/history`)
			check('history of the Team Admin keeps the feed', hist.status === 200 && hist.data?.[0]?.data?.feed === FEED, hist.data?.[0])
			const back = await put(A, 'pblead', EXT, 'default')
			check('back to default: none', field(back.data, 'pblead', EXT)?.level === 'none')
			check('… the feed is gone for the Lead', !('feed' in ((await ocs(L, 'GET', `/records/person/${EXT}`)).data?.data || {})))
		}

		head('Billing marks: write')
		{
			expect('malformed key', await ocs(A, 'PUT', '/records/billing/pbuser1', { version: 0, data: mark('pbuser1', uid1) }), 422, 'invalid')
			expect('key does not match the data', await ocs(A, 'PUT', `/records/billing/${k1}`, { version: 0, data: mark('pbuser1', uid2) }), 422, 'invalid')
			expect('no date', await ocs(A, 'PUT', `/records/billing/${k1}`, { version: 0, data: { person: 'pbuser1', uid: uid1 } }), 422, 'invalid')
			const r = await ocs(A, 'PUT', `/records/billing/${k1}`, { version: 0, data: mark('pbuser1', uid1, { by: 'pbadmin', checksum: 'abc', source: 'manual', external_id: 'bx-17', document: 'R-2026-001' }) })
			expect('Team Admin bills an event of pbuser1', r, 200)
			check('the mark comes back with the carried-through fields', r.data?.kind === 'billing' && r.data?.data?.source === 'manual' && r.data?.data?.external_id === 'bx-17' && r.data?.data?.document === 'R-2026-001', r.data?.data)
			expect('User bills their own event', await ocs(U1, 'PUT', `/records/billing/${k2}`, { version: 0, data: mark('pbuser1', uid2) }), 403, 'forbidden')
			expect('Lead without view bills pbuser1', await ocs(L, 'PUT', `/records/billing/${k2}`, { version: 0, data: mark('pbuser1', uid2) }), 403, 'forbidden')
			await put(A, 'pblead', 'pbuser1', 'view')
			expect('Lead with view bills pbuser1', await ocs(L, 'PUT', `/records/billing/${k2}`, { version: 0, data: mark('pbuser1', uid2) }), 200)
			expect('Lead without view bills the external', await ocs(L, 'PUT', `/records/billing/${kX}`, { version: 0, data: mark(EXT, uidX) }), 403, 'forbidden')
			await put(A, 'pblead', EXT, 'view')
			expect('Lead with view bills the external', await ocs(L, 'PUT', `/records/billing/${kX}`, { version: 0, data: mark(EXT, uidX) }), 200)
			expect('Lead: batch with billing marks only', await ocs(L, 'POST', '/records/batch', { writes: [{ kind: 'billing', key: k2, version: 1, data: mark('pbuser1', uid2, { by: 'pblead' }) }] }), 200)
			expect('Lead: batch with a project in it', await ocs(L, 'POST', '/records/batch', { writes: [{ kind: 'billing', key: k2, version: 2, data: mark('pbuser1', uid2) }, { kind: 'project', key: 'P', version: 0, data: { id: 'P' } }] }), 403, 'forbidden')
			expect('Lead: batch with a mark of someone unseen', await ocs(L, 'POST', '/records/batch', { writes: [{ kind: 'billing', key: key('pbuser2', uid1), version: 0, data: mark('pbuser2', uid1) }] }), 403, 'forbidden')
		}

		head('Billing marks: read, history, undo')
		{
			expect('the person reads their own mark', await ocs(U1, 'GET', `/records/billing/${k1}`), 200)
			expect('another User does not', await ocs(U2, 'GET', `/records/billing/${k1}`), 403, 'forbidden')
			expect('the Lead with view reads it', await ocs(L, 'GET', `/records/billing/${k1}`), 200)
			const all = await ocs(U1, 'GET', '/records')
			const keys = new Set((all.data?.records || []).filter((x) => x.kind === 'billing').map((x) => x.key))
			check('delta of pbuser1: own marks, not the external’s', keys.has(k1) && keys.has(k2) && !keys.has(kX), [...keys].filter((k) => k.includes(RUN)))
			const u2 = new Set(((await ocs(U2, 'GET', '/records')).data?.records || []).filter((x) => x.kind === 'billing').map((x) => x.key))
			check('delta of pbuser2: none of them', !u2.has(k1) && !u2.has(k2) && !u2.has(kX))
			const h = await ocs(U1, 'GET', `/records/billing/${k2}/history`)
			check('history for the person: both versions, who did what', h.status === 200 && h.data?.length === 2 && h.data?.[0]?.modified_by === 'pblead', h.data)
			expect('history for another User', await ocs(U2, 'GET', `/records/billing/${k2}/history`), 403, 'forbidden')
			expect('User undoes', await ocs(U1, 'DELETE', `/records/billing/${k1}?version=1`), 403, 'forbidden')
			expect('Lead undoes (view)', await ocs(L, 'DELETE', `/records/billing/${k1}?version=1`), 200)
			const since = (await ocs(U1, 'GET', '/me')).data?.revision - 1
			const t = (await ocs(U1, 'GET', `/records?since=${since}`)).data?.records?.find((x) => x.key === k1)
			check('the person gets the tombstone', t?.deleted === true && t?.data === null, t)
			await put(A, 'pblead', 'pbuser1', 'default')
			expect('Lead without view any more: undo refused', await ocs(L, 'DELETE', `/records/billing/${k2}?version=2`), 403, 'forbidden')
			expect('restore by the Team Admin', await ocs(A, 'POST', `/records/billing/${k1}/restore`, { version: 1, current: 2 }), 200)
		}
	} finally {
		head('Billing: cleaning up')
		for (const [k, v] of [[k1, 3], [k2, 2], [kX, 1]]) {
			await ocs(A, 'DELETE', `/records/billing/${k}?version=${v}`)
		}
		await ocs(A, 'PUT', '/team/access', { changes: [{ viewer: 'pblead', owner: 'pbuser1', level: 'default' }, { viewer: 'pblead', owner: EXT, level: 'default' }] })
		const cur = await ocs(A, 'GET', `/records/person/${EXT}`)
		if (cur.status === 200) {
			await ocs(A, 'DELETE', `/records/person/${EXT}?version=${cur.data.version}`)
		}
		check('cleaned up', true)
	}
}
