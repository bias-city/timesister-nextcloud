// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Version 2: full projects only for manager, admin and the project's leads,
// otherwise the booking catalog; a lead is admin in the project.
// Own keys per run, deleted at the end.
import { ADMIN, as, ocs } from './lib.mjs'
import { A, L, NOAH, RUN, U1, U2, check, ensure, expect, head, me } from './harness.mjs'

const CATALOG = ['codes', 'end', 'id', 'mapping', 'name', 'schema', 'start', 'status', 'subprojects']
const isCatalog = (d) => JSON.stringify(Object.keys(d || {}).sort()) === JSON.stringify(CATALOG)
const full = (id, leads) => ({
	schema: 1, id, name: `Project ${id}`, codes: ['LP'], subprojects: [{ id: 'a', name: 'A' }],
	start: '2026-01-01', end: null, status: 'active', mapping: { rules: [] },
	description: 'internal', cost_center: 'KS-9', customer: 'stadt', quote: 'O-1',
	leads, budget: [], milestones: [{ date: '2026-06-30', name: 'M1' }],
	budgets: [{ from: '2026-01-01', hours: 10 }], neu_2027: 'does not slip through',
})
const path = (key) => `/records/project/${encodeURIComponent(key)}`
const inDelta = async (who, since, key) => ((await ocs(who, 'GET', `/records?since=${since}`)).data?.records || []).find((x) => x.key === key)

export async function projekte() {
	const PL = `PL-${RUN}`
	const PF = `PF-${RUN}`
	const pl = `pl${RUN}`
	let tmpLead = false
	try {
		head('Projects: full for lead and admin, otherwise booking catalog')
		const rev0 = (await me(A)).revision
		expect('person via accounts (pbuser2)', await ensure(A, 'person', NOAH, { login: NOAH, accounts: ['pbuser2'] }), 200)
		expect('create project with lead pblead (admin)', await ocs(A, 'PUT', path(PL), { version: 0, data: full(PL, ['pblead']) }), 200)
		expect('another project, lead pbverw', await ocs(A, 'PUT', path(PF), { version: 0, data: full(PF, ['pbverw']) }), 200)
		const mk = await ocs(ADMIN, 'POST', '/ocs/v2.php/cloud/users', { userid: pl, password: `Test-2026-${pl}!`, groups: ['pb-team'] })
		tmpLead = mk.status === 200
		const role = await ocs(A, 'PUT', `/team/members/${pl}`, { role: 'lead' })
		check('second lead created without an assignment', tmpLead && role.data?.role === 'lead', role.text.slice(0, 200))

		const adm = (await ocs(A, 'GET', path(PL))).data?.data
		check('admin: full, with budgets and new fields', adm?.budgets?.[0]?.hours === 10 && adm?.neu_2027 && adm?.customer === 'stadt', adm)
		const own = (await ocs(L, 'GET', path(PL))).data?.data
		check('pblead, own project: full', own?.budgets?.[0]?.hours === 10 && JSON.stringify(own?.leads) === '["pblead"]' && own?.description === 'internal', own)
		const foreign = (await ocs(L, 'GET', path(PF))).data?.data
		check('pblead, another project: catalog only', isCatalog(foreign) && foreign.codes?.[0] === 'LP' && foreign.end === null, foreign)
		const u1 = (await ocs(U1, 'GET', path(PL))).data?.data
		check('pbuser1: catalog only (allow list, new fields missing)', isCatalog(u1), u1)
		check('pbuser1 in the delta: catalog only', isCatalog((await inDelta(U1, rev0, PL))?.data))
		check('second lead without an assignment: catalog only', isCatalog((await ocs(as(pl), 'GET', path(PL))).data?.data))
		check('pblead in the delta: own full, other catalog', (await inDelta(L, rev0, PL))?.data?.budgets?.length === 1 && isCatalog((await inDelta(L, rev0, PF))?.data))

		head('Projects: history only for admin, manager and lead')
		expect('pbuser1, history of a project', await ocs(U1, 'GET', `${path(PL)}/history`), 403, 'forbidden')
		expect('second lead, history of another project', await ocs(as(pl), 'GET', `${path(PL)}/history`), 403, 'forbidden')
		expect('pblead, history of another project', await ocs(L, 'GET', `${path(PF)}/history`), 403, 'forbidden')
		const h = await ocs(L, 'GET', `${path(PL)}/history`)
		check('pblead, history of their own project: full', h.status === 200 && h.data?.[0]?.data?.budgets?.length === 1, h.text.slice(0, 200))

		head('Projects: a lead is admin in the project')
		const w = await ocs(L, 'PUT', path(PL), { version: 1, data: { ...full(PL, ['pblead', NOAH]), customer: 'kanton', codes: ['LP', 'LQ'], budgets: [{ from: '2026-01-01', hours: 20 }] } })
		check('pblead changes customer, code, budgets and leads', w.status === 200 && w.data?.version === 2 && w.data?.modified_by === 'pblead'
			&& w.data?.data?.customer === 'kanton' && w.data?.data?.codes?.length === 2, w.text.slice(0, 300))
		const u2 = (await ocs(U2, 'GET', path(PL))).data?.data
		check('pbuser2 (via their person in leads) sees the full project', u2?.budgets?.[0]?.hours === 20 && u2?.customer === 'kanton', u2)
		check('pbuser1 still catalog only', isCatalog((await ocs(U1, 'GET', path(PL))).data?.data))
		expect('stale version', await ocs(L, 'PUT', path(PL), { version: 1, data: full(PL, ['pblead']) }), 409, 'conflict')
		expect('id ≠ key', await ocs(L, 'PUT', path(PL), { version: 2, data: full('anders', ['pblead']) }), 422, 'invalid')
		expect('pblead changes another project', await ocs(L, 'PUT', path(PF), { version: 1, data: full(PF, ['pbverw', 'pblead']) }), 403, 'forbidden')
		expect('pblead creates a new project', await ocs(L, 'PUT', path(`PN-${RUN}`), { version: 0, data: full(`PN-${RUN}`, ['pblead']) }), 403, 'forbidden')
		expect('pbuser1 changes the project', await ocs(U1, 'PUT', path(PL), { version: 2, data: full(PL, ['pbuser1']) }), 403, 'forbidden')
		expect('pblead deletes', await ocs(L, 'DELETE', `${path(PL)}?version=2`), 403, 'forbidden')
		expect('pblead via batch', await ocs(L, 'POST', '/records/batch', { writes: [{ kind: 'project', key: PL, version: 2, data: full(PL, ['pblead']) }] }), 403, 'forbidden')
		expect('pblead restores', await ocs(L, 'POST', `${path(PL)}/restore`, { version: 1, current: 2 }), 403, 'forbidden')
		expect('pblead writes a customer', await ocs(L, 'PUT', `/records/customer/K2-${RUN}`, { version: 0, data: { id: `K2-${RUN}` } }), 403, 'forbidden')

		head('Projects: a lead removes themself')
		const rev1 = (await me(L)).revision
		const self = await ocs(L, 'PUT', path(PL), { version: 2, data: full(PL, [NOAH]) })
		check('is allowed (checked against the current version)', self.status === 200 && self.data?.version === 3, self.text.slice(0, 200))
		check('next delta: catalog only again', isCatalog((await inDelta(L, rev1, PL))?.data))
		expect('no more writes afterwards', await ocs(L, 'PUT', path(PL), { version: 3, data: full(PL, ['pblead']) }), 403, 'forbidden')
		check('pbuser2 stays a lead: full', (await ocs(U2, 'GET', path(PL))).data?.data?.budgets?.length === 1)
	} finally {
		head('Projects: cleaning up')
		for (const key of [PL, PF]) {
			const cur = await ocs(A, 'GET', path(key))
			if (cur.status === 200) {
				expect(`delete ${key}`, await ocs(A, 'DELETE', `${path(key)}?version=${cur.data.version}`), 200)
			}
		}
		if (tmpLead) {
			expect(`delete test account ${pl}`, await ocs(ADMIN, 'DELETE', `/ocs/v2.php/cloud/users/${pl}`), 200)
		}
	}
}
