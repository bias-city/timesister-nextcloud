// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Version 2: full projects only for Team Admins and the project's leads,
// otherwise the booking catalog; a lead is admin in the project.
// Own keys per run, deleted at the end.
import { ADMIN, as, ocs } from './lib.mjs'
import { NOAH, RUN, check, ensure, expect, head, me } from './harness.mjs'
import { A, L, U1, U2 } from './konten.mjs'

// The catalog carries `led` since 0.11.0 (the project has a Lead); one code per project since 0.12.0.
const CATALOG = ['codes', 'end', 'id', 'led', 'mapping', 'name', 'schema', 'start', 'status', 'subprojects']
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
	const PQ = `PQ-${RUN}`
	const pl = `pl${RUN}`
	let tmpLead = false
	try {
		head('Projects: full for lead and admin, otherwise booking catalog')
		const rev0 = (await me(A)).revision
		expect('person via accounts (zbuser2)', await ensure(A, 'person', NOAH, { login: NOAH, accounts: ['zbuser2'] }), 200)
		expect('create project with lead zblead (admin)', await ocs(A, 'PUT', path(PL), { version: 0, data: full(PL, ['zblead']) }), 200)
		const mk = await ocs(ADMIN, 'POST', '/ocs/v2.php/cloud/users', { userid: pl, password: `Test-2026-${pl}!`, groups: ['zb-team'] })
		tmpLead = mk.status === 200
		// The second lead leads PF, not PL (0.11.0: the role follows from the projects).
		expect('another project, leads zbverw and the second lead', await ocs(A, 'PUT', path(PF), { version: 0, data: full(PF, ['zbverw', pl]) }), 200)
		const role = (await ocs(as(pl), 'GET', '/me')).data
		check('second lead: role lead derived from PF', tmpLead && role?.role === 'lead' && role?.lead_of?.[0]?.key === PF, role)

		const adm = (await ocs(A, 'GET', path(PL))).data?.data
		check('admin: full, with budgets and new fields', adm?.budgets?.[0]?.hours === 10 && adm?.neu_2027 && adm?.customer === 'stadt', adm)
		const own = (await ocs(L, 'GET', path(PL))).data?.data
		check('zblead, own project: full', own?.budgets?.[0]?.hours === 10 && JSON.stringify(own?.leads) === '["zblead"]' && own?.description === 'internal', own)
		const foreign = (await ocs(L, 'GET', path(PF))).data?.data
		check('zblead, another project: catalog only', isCatalog(foreign) && foreign.codes?.[0] === 'LP' && foreign.end === null, foreign)
		const u1 = (await ocs(U1, 'GET', path(PL))).data?.data
		check('zbuser1: catalog only (allow list, new fields missing)', isCatalog(u1), u1)
		check('zbuser1 in the delta: catalog only', isCatalog((await inDelta(U1, rev0, PL))?.data))
		check('second lead, not in PL: catalog only', isCatalog((await ocs(as(pl), 'GET', path(PL))).data?.data))
		check('zblead in the delta: own full, other catalog', (await inDelta(L, rev0, PL))?.data?.budgets?.length === 1 && isCatalog((await inDelta(L, rev0, PF))?.data))

		head('Projects: history only for Team Admins and the lead')
		expect('zbuser1, history of a project', await ocs(U1, 'GET', `${path(PL)}/history`), 403, 'forbidden')
		expect('second lead, history of another project', await ocs(as(pl), 'GET', `${path(PL)}/history`), 403, 'forbidden')
		expect('second lead, history of PF: full', await ocs(as(pl), 'GET', `${path(PF)}/history`), 200)
		expect('zblead, history of another project', await ocs(L, 'GET', `${path(PF)}/history`), 403, 'forbidden')
		const h = await ocs(L, 'GET', `${path(PL)}/history`)
		check('zblead, history of their own project: full', h.status === 200 && h.data?.[0]?.data?.budgets?.length === 1, h.text.slice(0, 200))

		head('Projects: a lead is admin in the project')
		const w = await ocs(L, 'PUT', path(PL), { version: 1, data: { ...full(PL, ['zblead', NOAH]), customer: 'kanton', codes: ['LQ'], budgets: [{ from: '2026-01-01', hours: 20 }] } })
		check('zblead changes customer, code, budgets and leads', w.status === 200 && w.data?.version === 2 && w.data?.modified_by === 'zblead'
			&& w.data?.data?.customer === 'kanton' && w.data?.data?.codes?.[0] === 'LQ', w.text.slice(0, 300))
		const u2 = (await ocs(U2, 'GET', path(PL))).data?.data
		check('zbuser2 (via their person in leads) sees the full project', u2?.budgets?.[0]?.hours === 20 && u2?.customer === 'kanton', u2)
		check('zbuser1 still catalog only', isCatalog((await ocs(U1, 'GET', path(PL))).data?.data))
		expect('stale version', await ocs(L, 'PUT', path(PL), { version: 1, data: full(PL, ['zblead']) }), 409, 'conflict')
		expect('id ≠ key', await ocs(L, 'PUT', path(PL), { version: 2, data: full('anders', ['zblead']) }), 422, 'invalid')
		expect('zblead changes another project', await ocs(L, 'PUT', path(PF), { version: 1, data: full(PF, ['zbverw', pl, 'zblead']) }), 403, 'forbidden')
		expect('zblead creates a new project', await ocs(L, 'PUT', path(`PN-${RUN}`), { version: 0, data: full(`PN-${RUN}`, ['zblead']) }), 403, 'forbidden')
		expect('zbuser1 changes the project', await ocs(U1, 'PUT', path(PL), { version: 2, data: full(PL, ['zbuser1']) }), 403, 'forbidden')
		expect('zblead deletes', await ocs(L, 'DELETE', `${path(PL)}?version=2`), 403, 'forbidden')
		expect('zblead via batch', await ocs(L, 'POST', '/records/batch', { writes: [{ kind: 'project', key: PL, version: 2, data: full(PL, ['zblead']) }] }), 403, 'forbidden')
		expect('zblead restores', await ocs(L, 'POST', `${path(PL)}/restore`, { version: 1, current: 2 }), 403, 'forbidden')
		expect('zblead writes a customer', await ocs(L, 'PUT', `/records/customer/K2-${RUN}`, { version: 0, data: { id: `K2-${RUN}` } }), 403, 'forbidden')

		head('Projects: a lead removes themself')
		const rev1 = (await me(L)).revision
		const self = await ocs(L, 'PUT', path(PL), { version: 2, data: full(PL, [NOAH]) })
		check('is allowed (checked against the current version)', self.status === 200 && self.data?.version === 3, self.text.slice(0, 200))
		check('next delta: catalog only again', isCatalog((await inDelta(L, rev1, PL))?.data))
		expect('no more writes afterwards', await ocs(L, 'PUT', path(PL), { version: 3, data: full(PL, ['zblead']) }), 403, 'forbidden')
		check('zbuser2 stays a lead: full', (await ocs(U2, 'GET', path(PL))).data?.data?.budgets?.length === 1)

		head('Projects: quotes (0.16.0) – status, team, currency, work package rates')
		const caps = await ocs(U1, 'GET', '/ocs/v1.php/cloud/capabilities?format=json')
		check('capability quotes: 2 (the statuses since 1, the kind quote since 2)', caps.data?.capabilities?.timesister?.quotes === 2, caps.data?.capabilities?.timesister)
		const budget = (rate) => [{ id: 'b1', from: '2026-01-01', hours: 40, currency: 'chf', rates: {},
			work_packages: [{ no: 1, name: 'Concept', hours: [40], rate }] }]
		const quote = (extra) => ({ ...full(PQ, ['zblead']), status: 'draft', team: ['zbuser1', 'zbuser2', 'zbuser1'], budgets: budget(180), ...extra })
		const rev2 = (await me(A)).revision
		const q = await ocs(A, 'PUT', path(PQ), { version: 0, data: quote() })
		check('admin creates a draft: team of strings as members without duplicates, currency upper case', q.status === 200 && q.data?.data?.status === 'draft'
			&& JSON.stringify(q.data?.data?.team) === '[{"user":"zbuser1","role":"member"},{"user":"zbuser2","role":"member"}]' && q.data?.data?.budgets?.[0]?.currency === 'CHF', q.text.slice(0, 300))
		const lead = (await ocs(L, 'GET', path(PQ))).data?.data
		check('zblead (lead): currency, rate and team there', lead?.budgets?.[0]?.currency === 'CHF'
			&& lead?.budgets?.[0]?.work_packages?.[0]?.rate === 180 && lead?.team?.length === 2 && lead?.team?.[1]?.role === 'member', lead)
		const hasRates = (d) => /rate|currency|team/.test(JSON.stringify(d || {}))
		const member = (await ocs(U1, 'GET', path(PQ))).data?.data
		check('zbuser1 (in the team, not a lead): catalog – no budgets, rates, currency, team', isCatalog(member) && member?.status === 'draft' && !hasRates(member), member)
		check('zbuser1 in the delta: the same', !hasRates((await inDelta(U1, rev2, PQ))?.data))
		check('zbuser1 in the list: the same', !hasRates(((await ocs(U1, 'GET', '/records?since=0')).data?.records || []).find((x) => x.key === PQ)?.data))
		expect('zbuser1: no history of a quote', await ocs(U1, 'GET', `${path(PQ)}/history`), 403, 'forbidden')
		check('zblead in the delta: full', (await inDelta(L, rev2, PQ))?.data?.budgets?.[0]?.work_packages?.[0]?.rate === 180)
		// Roles (0.16.0): missing is member, other fields dropped, the first entry of a user wins.
		const roles = [{ user: 'zbuser2', role: 'sparring', name: 'dropped' }, { user: 'zbuser1', role: 'qa' }, { user: 'zbuser1', role: 'support' }, { user: 'zbverw' }]
		const quoted = await ocs(L, 'PUT', path(PQ), { version: 1, data: quote({ status: 'quoted', team: roles }) })
		check('zblead moves it to quoted and gives the team roles', quoted.status === 200 && quoted.data?.data?.status === 'quoted'
			&& JSON.stringify(quoted.data?.data?.team) === '[{"user":"zbuser2","role":"sparring"},{"user":"zbuser1","role":"qa"},{"user":"zbverw","role":"member"}]', quoted.text.slice(0, 300))
		check('read back as objects', JSON.stringify((await ocs(A, 'GET', path(PQ))).data?.data?.team) === JSON.stringify(quoted.data?.data?.team))
		const member2 = (await ocs(U1, 'GET', path(PQ))).data?.data
		check('zbuser1 (qa in the team): still the catalog without team', isCatalog(member2) && !hasRates(member2), member2)
		expect('zblead: declined', await ocs(L, 'PUT', path(PQ), { version: 2, data: quote({ status: 'declined' }) }), 200)
		check('declined keeps the lead role', ((await me(L)).lead_of || []).some((p) => p.key === PQ))
		expect('admin: active, without team and rates (nullable)', await ocs(A, 'PUT', path(PQ), { version: 3, data: quote({ status: 'active', team: null, budgets: [{ id: 'b1', hours: 40, currency: null, rates: null, work_packages: [{ no: 1, rate: null }] }] }) }), 200)
		const bad = async (name, data, text) => {
			const r = await ocs(L, 'PUT', path(PQ), { version: 4, data: quote(data) })
			check(`422 ${name}`, r.status === 422 && r.data?.message?.includes(text), `${r.status} ${r.data?.message}`)
		}
		await bad('unknown status', { status: 'offered' }, 'Unknown project status')
		await bad('team with a stranger', { team: ['zbuser1', `nobody-${RUN}`] }, `Not members of this team: nobody-${RUN}`)
		await bad('team with a stranger as an object', { team: [{ user: 'zbuser1', role: 'qa' }, { user: `nobody-${RUN}`, role: 'support' }] }, `Not members of this team: nobody-${RUN}.`)
		await bad('team not a list', { team: 'zbuser1' }, '“team” must be a list')
		await bad('team with an unknown role', { team: [{ user: 'zbuser1', role: 'lead' }] }, '“role” in “team” is member, sparring, support or qa.')
		await bad('team entry without user', { team: [{ role: 'qa' }] }, '“user” in “team” must be a user ID.')
		await bad('negative rate', { budgets: budget(-1) }, '“rate” must be a number')
		await bad('rate as text', { budgets: budget('180') }, '“rate” must be a number')
		// Rates per person moved to the person (cost_rates): an empty default passes, content is refused.
		await bad('rates per person on a budget', { budgets: [{ id: 'b1', rates: { zbuser1: 140 } }] }, '“rates” is no longer part of a budget')
		await bad('currency two letters', { budgets: [{ id: 'b1', currency: 'CH' }] }, '“currency” must be three letters')
		// 0.24.1: progress a whole percent, addendum a bool.
		await bad('progress above 100', { budgets: [{ id: 'b1', progress: 101 }] }, '“progress” must be null or a whole number from 0 to 100.')
		await bad('progress with decimals', { budgets: [{ id: 'b1', progress: 12.5 }] }, '“progress” must be null or a whole number from 0 to 100.')
		await bad('addendum as text', { budgets: [{ id: 'b1', work_packages: [{ no: 1, addendum: 'yes' }] }] }, '“addendum” must be true, false or null.')
		// 0.25.1: a work package's group and role, the structured invoice plan.
		const groupMsg = '“group” must be null or text of 1 to 200 characters without control characters.'
		await bad('group blank', { budgets: [{ id: 'b1', work_packages: [{ no: 1, group: '   ' }] }] }, groupMsg)
		await bad('group 201 characters', { budgets: [{ id: 'b1', work_packages: [{ no: 1, group: 'g'.repeat(201) }] }] }, groupMsg)
		await bad('group with a control character', { budgets: [{ id: 'b1', work_packages: [{ no: 1, group: 'a\u0007b' }] }] }, groupMsg)
		await bad('group a number', { budgets: [{ id: 'b1', work_packages: [{ no: 1, group: 7 }] }] }, groupMsg)
		await bad('role unknown', { budgets: [{ id: 'b1', work_packages: [{ no: 1, role: 'Bauleitung' }] }] }, '“role” must be null, “gesamtleitung”, “fachplanung” or “bauleitung”.')
		const plan = (billing_plan) => ({ budgets: [{ id: 'b1', invoice_dates: ['2026-12-31'], billing_plan }] })
		await bad('billing_plan an object', plan({ date: '2026-12-31' }), '“billing_plan” must be null or a list of at most 200 entries.')
		await bad('billing_plan 201 entries', plan(Array.from({ length: 201 }, (_, i) => ({ date: new Date(Date.UTC(2026, 0, 1 + i)).toISOString().slice(0, 10) }))), '“billing_plan” must be null or a list of at most 200 entries.')
		await bad('billing_plan entry no object', plan(['2026-12-31']), 'Entry 1 of “billing_plan” must be an object with “date”.')
		await bad('billing_plan unknown field', plan([{ date: '2026-12-31', note: 'x' }]), 'Entry 1 of “billing_plan” has an unknown field “note”; allowed are date, share, amount and title.')
		await bad('billing_plan without date', plan([{ date: '2026-06-30' }, { share: 10 }]), 'Entry 2 of “billing_plan”: “date” must be a day (YYYY-MM-DD).')
		await bad('billing_plan 30 February', plan([{ date: '2026-02-30' }]), 'Entry 1 of “billing_plan”: “date” must be a day (YYYY-MM-DD).')
		await bad('billing_plan a date twice', plan([{ date: '2026-12-31' }, { date: '2026-12-31' }]), '“billing_plan” has the date 2026-12-31 twice.')
		await bad('billing_plan share 101', plan([{ date: '2026-12-31', share: 101 }]), 'Entry 1 of “billing_plan”: “share” must be null or a number from 0 to 100.')
		await bad('billing_plan negative amount', plan([{ date: '2026-12-31', amount: -1 }]), 'Entry 1 of “billing_plan”: “amount” must be null or a number ≥ 0.')
		await bad('billing_plan share and amount', plan([{ date: '2026-12-31', share: 50, amount: 1000 }]), 'Entry 1 of “billing_plan”: set “share” or “amount”, not both.')
		await bad('billing_plan title 201', plan([{ date: '2026-12-31', title: 't'.repeat(201) }]), 'Entry 1 of “billing_plan”: “title” must be null or text of at most 200 characters without control characters.')
		const b = await ocs(A, 'POST', '/records/batch', { writes: [{ kind: 'project', key: PQ, version: 4, data: quote({ team: [`nobody-${RUN}`] }) }] })
		check('422 in a batch, naming the write', b.status === 422 && b.data?.message?.startsWith('Write 1: Not members'), `${b.status} ${b.data?.message}`)
		check('nothing of it was written', (await ocs(A, 'GET', path(PQ))).data?.version === 4)
		const pa = await ocs(L, 'PUT', path(PQ), { version: 4, data: quote({ status: 'active', budgets: [{ id: 'b1', hours: 40, progress: 45, work_packages: [{ no: 1, rate: null }, { no: 2, addendum: true }] }] }) })
		check('zblead stores progress 45 and an addendum (0.24.1)', pa.status === 200 && pa.data?.data?.budgets?.[0]?.progress === 45 && pa.data?.data?.budgets?.[0]?.work_packages?.[1]?.addendum === true, [pa.status, pa.text.slice(0, 300)])
		const billing = [{ date: '2026-06-30', share: 30, title: 'Vorprojekt' }, { date: '2026-12-31', amount: 12000.5 }, { date: '2027-03-31', share: null, amount: null, title: null }]
		const gr = await ocs(L, 'PUT', path(PQ), { version: 5, data: quote({ status: 'active', budgets: [{ id: 'b1', hours: 40, invoice_dates: ['2026-12-31'], billing_plan: billing,
			work_packages: [{ no: 1, group: '  Vorprojekt  ', role: 'gesamtleitung' }, { no: 2, group: null, role: 'bauleitung' }, { no: 3, role: null }] }] }) })
		const gb = gr.data?.data?.budgets?.[0]
		check('zblead stores billing_plan, group (trimmed) and role (0.25.1)', gr.status === 200 && JSON.stringify(gb?.billing_plan) === JSON.stringify(billing)
			&& gb?.work_packages?.[0]?.group === 'Vorprojekt' && gb?.work_packages?.[0]?.role === 'gesamtleitung' && gb?.work_packages?.[1]?.role === 'bauleitung'
			&& JSON.stringify(gb?.invoice_dates) === '["2026-12-31"]', [gr.status, gr.text.slice(0, 400)])

		head('Projects: quotes – no code while a quote, from_quote stays')
		const PN = `PN-${RUN}`
		const noCode = (extra) => ({ schema: 1, id: PN, name: `Quote ${PN}`, status: 'draft', from_quote: true, leads: ['zblead'], ...extra })
		const n1 = await ocs(A, 'PUT', path(PN), { version: 0, data: noCode({ codes: [], subprojects: [] }) })
		check('draft without a code (codes [], subprojects []): 200, from_quote true', n1.status === 200 && n1.data?.data?.from_quote === true && n1.data?.data?.codes?.length === 0, n1.text.slice(0, 300))
		expect('quoted with codes missing: 200', await ocs(L, 'PUT', path(PN), { version: 1, data: noCode({ status: 'quoted' }) }), 200)
		expect('declined with codes missing: 200', await ocs(L, 'PUT', path(PN), { version: 2, data: noCode({ status: 'declined' }) }), 200)
		const n2 = await ocs(L, 'PUT', path(PN), { version: 3, data: noCode({ status: 'active', codes: [] }) })
		check('422 active without a code', n2.status === 422 && n2.data?.message?.includes('An active project needs a code.'), `${n2.status} ${n2.data?.message}`)
		const n3 = await ocs(L, 'PUT', path(PN), { version: 3, data: noCode({ status: 'active' }) })
		check('422 active with codes missing', n3.status === 422 && n3.data?.message?.includes('An active project needs a code.'), `${n3.status} ${n3.data?.message}`)
		check('still version 3, still declined', (await ocs(A, 'GET', path(PN))).data?.data?.status === 'declined')
		const n4 = await ocs(L, 'PUT', path(PN), { version: 3, data: noCode({ status: 'draft', codes: ['PN', 'PN2'] }) })
		check('422 two codes, also while a quote', n4.status === 422 && n4.data?.message?.includes('exactly one code'), `${n4.status} ${n4.data?.message}`)
		const n5 = await ocs(L, 'PUT', path(PN), { version: 3, data: noCode({ status: 'draft', from_quote: 'yes' }) })
		check('422 from_quote not a boolean', n5.status === 422 && n5.data?.message?.includes('“from_quote” must be true, false or null.'), `${n5.status} ${n5.data?.message}`)
		const n6 = await ocs(L, 'PUT', path(PN), { version: 3, data: noCode({ status: 'active', codes: ['PN'], subprojects: [] }) })
		check('active with a code: 200, from_quote stays true', n6.status === 200 && n6.data?.data?.status === 'active' && n6.data?.data?.from_quote === true, n6.text.slice(0, 300))
		const cat = (await ocs(U1, 'GET', path(PN))).data?.data
		check('zbuser1: the booking catalog – code and status, no from_quote, leads or budgets', cat?.status === 'active' && cat?.codes?.[0] === 'PN'
			&& !('from_quote' in (cat || {})) && !('leads' in (cat || {})) && !('budgets' in (cat || {})), cat)
	} finally {
		head('Projects: cleaning up')
		for (const key of [PL, PF, PQ, `PN-${RUN}`]) {
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
