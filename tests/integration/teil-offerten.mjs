// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Quotes (0.16.0, `quotes: 2`): the kind `quote` – Team Admins everything,
// a Lead the quotes naming them in `leads` (account or person key) or of a
// project they lead, before and after the write; Members nothing. Accepting
// is two ordinary writes: the budget into the project, the quote `accepted`.
// Leaves pb as it found it: quotes and projects deleted.
import { ocs } from './lib.mjs'
import { A, L, NOAH, RUN, U1, U2, V, check, ensure, expect, head, keysOf, me, sha256 } from './harness.mjs'

const path = (key) => `/records/quote/${encodeURIComponent(key)}`
const project = (id, leads, more = {}) => ({ schema: 1, id, name: `Quotes ${id}`, codes: [`${id.slice(0, 3)}${RUN}`], status: 'active', leads, ...more })
const budget = (more = {}) => ({ id: 'bq1', name: 'Study', currency: 'chf', cap: 24000, work_packages: [{ no: 1, name: 'Concept', hours: 40, rate: 180 }], ...more })
const quote = (more = {}) => ({
	schema: 1, name: `Harbour study ${RUN}`, status: 'draft', customer: null, project: null, number: null,
	date: '2026-10-14', valid_until: '2026-11-30', accepted_on: null, created_on: '2026-10-14',
	leads: [], contact: null, note: null, pdf: null, budget: budget(), budget_id: null,
	hours: null, amount: null, currency: null, created_by: 'pbadmin', ...more,
})
const quoteKeys = (r) => [...keysOf(r)].filter((k) => k.startsWith('quote/'))
const inDelta = async (who, since, key) => ((await ocs(who, 'GET', `/records?since=${since}`)).data?.records || []).find((x) => x.kind === 'quote' && x.key === key)

export async function offerten() {
	// `q-` and 8 lowercase hex digits; the run's hash keeps them apart from earlier runs.
	const h = sha256(`quotes-${RUN}`).slice(0, 7)
	const [Q1, Q2, Q3, Q4, Q5, Q6, Q7] = [1, 2, 3, 4, 5, 6, 7].map((i) => `q-${h}${i}`)
	const OA = `QOA-${RUN}`
	const OB = `QOB-${RUN}`
	const OC = `QOC-${RUN}`
	try {
		head('Quotes: capability and setup')
		{
			const cap = await ocs(A, 'GET', '/ocs/v2.php/cloud/capabilities')
			check('capability quotes: 2', cap.data?.capabilities?.timesister?.quotes === 2, cap.data?.capabilities?.timesister)
			expect('project led by pblead', await ocs(A, 'PUT', `/records/project/${OA}`, { version: 0, data: project(OA, ['pblead']) }), 200)
			expect('project led by pbverw', await ocs(A, 'PUT', `/records/project/${OB}`, { version: 0, data: project(OB, ['pbverw']) }), 200)
			expect('person via accounts (pbuser2)', await ensure(A, 'person', NOAH, { login: NOAH, accounts: ['pbuser2'] }), 200)
			expect('project led by pbuser2’s person', await ocs(A, 'PUT', `/records/project/${OC}`, { version: 0, data: project(OC, [NOAH]) }), 200)
			check('pblead leads the first', ((await me(L)).lead_of || []).some((p) => p.key === OA))
			check('pbuser2 is a Lead through their person', ((await me(U2)).lead_of || []).some((p) => p.key === OC))
		}

		head('Quotes: Team Admin creates and reads')
		{
			const r = await ocs(A, 'PUT', path(Q1), { version: 0, data: quote({ leads: ['pbverw'], extra: { kept: true } }) })
			expect('Team Admin creates a quote', r, 200)
			check('… stored as sent, the budget’s currency upper case', r.data?.kind === 'quote' && r.data?.version === 1
				&& r.data?.data?.budget?.currency === 'CHF' && r.data?.data?.budget?.work_packages?.[0]?.rate === 180 && r.data?.data?.extra?.kept === true, r.data)
			expect('Team Admin reads it', await ocs(A, 'GET', path(Q1)), 200)
			expect('another Team Admin reads it', await ocs(V, 'GET', path(Q1)), 200)
			expect('Team Admin reads a key that does not exist', await ocs(A, 'GET', path(Q7)), 404, 'not_found')
		}

		head('Quotes: a Lead writes the quotes naming them')
		{
			const r = await ocs(L, 'PUT', path(Q2), { version: 0, data: quote({ leads: ['pblead'], created_by: 'pblead' }) })
			expect('Lead creates a quote naming themselves', r, 200)
			expect('Lead reads it', await ocs(L, 'GET', path(Q2)), 200)
			expect('Lead reads the Team Admin’s quote', await ocs(L, 'GET', path(Q1)), 403, 'forbidden')
			expect('Lead reads a key that does not exist', await ocs(L, 'GET', path(Q7)), 403, 'forbidden')
			const other = await ocs(L, 'PUT', path(Q3), { version: 0, data: quote({ leads: ['pbverw'] }) })
			expect('Lead creates a quote for someone else', other, 403, 'forbidden')
			check('… with the message', other.data?.message === 'Only Team Admins and the quote’s or project’s Leads write quotes.', other.data)
			expect('Lead hands their quote to someone else', await ocs(L, 'PUT', path(Q2), { version: 1, data: quote({ leads: ['pbverw'] }) }), 403, 'forbidden')
			expect('Lead takes over the Team Admin’s quote', await ocs(L, 'PUT', path(Q1), { version: 1, data: quote({ leads: ['pblead'] }) }), 403, 'forbidden')
			expect('Lead deletes the Team Admin’s quote', await ocs(L, 'DELETE', `${path(Q1)}?version=1`), 403, 'forbidden')
			expect('Lead moves their quote to quoted', await ocs(L, 'PUT', path(Q2), { version: 1, data: quote({ leads: ['pblead'], status: 'quoted', number: 'OF-1' }) }), 200)
			const b = await ocs(L, 'POST', '/records/batch', { writes: [{ kind: 'quote', key: Q3, version: 0, data: quote({ leads: ['pblead'] }) }, { kind: 'project', key: OA, version: 1, data: project(OA, ['pblead']) }] })
			expect('Lead: batch with a quote and a project', b, 403, 'forbidden')
			expect('Member creates a quote naming themselves', await ocs(U1, 'PUT', path(Q3), { version: 0, data: quote({ leads: ['pbuser1'] }) }), 403, 'forbidden')
		}

		head('Quotes: content checked')
		{
			const bad = async (name, key, data, message, who = A) => {
				const r = await ocs(who, 'PUT', path(key), { version: 0, data })
				expect(name, r, 422, 'invalid')
				check(`… “${message}”`, r.data?.message === message, r.data?.message)
			}
			await bad('accepted without project', Q3, quote({ status: 'accepted' }), 'An accepted quote needs “project”: the project that took over its budget.')
			await bad('accepted without project (Lead)', Q3, quote({ status: 'accepted', leads: ['pblead'] }), 'An accepted quote needs “project”: the project that took over its budget.', L)
			await bad('a key not q- and 8 hex digits', `Q-${h}1`, quote(), 'The key of a quote is “q-” and 8 lowercase hex digits.')
			await bad('unknown status', Q3, quote({ status: 'active' }), '“status” of a quote is one of draft, quoted, accepted, declined.')
			await bad('pdf not http(s)', Q3, quote({ pdf: 'javascript:alert(1)' }), '“pdf” must be null or an http:// or https:// address of at most 2000 characters.')
			await bad('budget without id', Q3, quote({ budget: { currency: 'CHF' } }), '“budget” must be null or an object with an “id” of 1 to 128 characters.')
			await bad('rates in the budget', Q3, quote({ budget: budget({ rates: { pbuser1: 120 } }) }), '“rates” is no longer part of a budget; cost rates live on the person.')
			await bad('negative cap', Q3, quote({ budget: budget({ cap: -1 }) }), '“cap” must be null or a number ≥ 0.')
			await bad('leads not a list', Q3, quote({ leads: 'pblead' }), '“leads” must be a list of at most 20 account IDs or person keys.')
			check('nothing of it was written', (await ocs(A, 'GET', path(Q3))).status === 404)
		}

		head('Quotes: the Lead of the assigned project reads it')
		{
			expect('Team Admin creates a quote of pbverw on pblead’s project', await ocs(A, 'PUT', path(Q3), { version: 0, data: quote({ leads: ['pbverw'], project: OA }) }), 200)
			expect('pblead reads it (Lead of the project)', await ocs(L, 'GET', path(Q3)), 200)
			expect('pblead changes it', await ocs(L, 'PUT', path(Q3), { version: 1, data: quote({ leads: ['pbverw'], project: OA, note: 'Checked\nby Lea' }) }), 200)
			expect('pblead moves it off their project', await ocs(L, 'PUT', path(Q3), { version: 2, data: quote({ leads: ['pbverw'], project: OB }) }), 403, 'forbidden')
		}

		head('Quotes: accepting is two ordinary writes')
		{
			const cur = (await ocs(L, 'GET', `/records/project/${OA}`)).data
			const p = await ocs(L, 'PUT', `/records/project/${OA}`, { version: cur?.version, data: project(OA, ['pblead'], { budgets: [{ ...budget(), id: 'b7' }] }) })
			expect('Lead writes the budget into their project', p, 200)
			const r = await ocs(L, 'PUT', path(Q2), { version: 2, data: quote({ leads: ['pblead'], status: 'accepted', number: 'OF-1', project: OA,
				accepted_on: '2026-10-20', budget_id: 'b7', hours: 40, amount: 7200, currency: 'chf' }) })
			expect('Lead sets the quote accepted with the project', r, 200)
			check('… frozen as sent, currency upper case', r.data?.data?.status === 'accepted' && r.data?.data?.project === OA && r.data?.data?.currency === 'CHF' && r.data?.data?.amount === 7200, r.data)
		}

		head('Quotes: leads by person key, as a project’s')
		{
			expect('Team Admin creates a quote naming pbuser2’s person', await ocs(A, 'PUT', path(Q4), { version: 0, data: quote({ leads: [NOAH] }) }), 200)
			expect('pbuser2 (Lead) reads it through their person', await ocs(U2, 'GET', path(Q4)), 200)
			expect('pblead does not', await ocs(L, 'GET', path(Q4)), 403, 'forbidden')
		}

		head('Quotes: Members see none')
		{
			expect('Team Admin creates a quote naming pbuser1 (a Member)', await ocs(A, 'PUT', path(Q5), { version: 0, data: quote({ leads: ['pbuser1'] }) }), 200)
			expect('Member reads the quote naming them', await ocs(U1, 'GET', path(Q5)), 403, 'forbidden')
			expect('Member reads the Lead’s quote', await ocs(U1, 'GET', path(Q2)), 403, 'forbidden')
			expect('Member reads the history', await ocs(U1, 'GET', `${path(Q5)}/history`), 403, 'forbidden')
			const uk = quoteKeys(await ocs(U1, 'GET', '/records'))
			check('list of the Member: no quotes', uk.length === 0, uk)
			const lk = quoteKeys(await ocs(L, 'GET', '/records'))
			check('list of the Lead: theirs and their project’s, no others', lk.includes(`quote/${Q2}`) && lk.includes(`quote/${Q3}`)
				&& !lk.includes(`quote/${Q1}`) && !lk.includes(`quote/${Q4}`) && !lk.includes(`quote/${Q5}`), lk)
			const ak = quoteKeys(await ocs(V, 'GET', '/records'))
			check('list of a Team Admin: all', [Q1, Q2, Q3, Q4, Q5].every((k) => ak.includes(`quote/${k}`)), ak)
			const hist = await ocs(L, 'GET', `${path(Q2)}/history`)
			check('history for the Lead: every version', hist.status === 200 && hist.data?.length === 3 && hist.data?.[0]?.data?.status === 'accepted', hist.data)
		}

		head('Quotes: tombstones and gaining sight')
		{
			expect('Lead creates and deletes a quote', await ocs(L, 'PUT', path(Q6), { version: 0, data: quote({ leads: ['pblead'] }) }), 200)
			const rev = (await me(L)).revision
			expect('… deleted', await ocs(L, 'DELETE', `${path(Q6)}?version=1`), 200)
			const t = await inDelta(L, rev, Q6)
			check('the Lead gets the tombstone', t?.deleted === true && t?.data === null, t)
			check('the Member does not', (await inDelta(U1, rev, Q6)) === undefined)
			expect('the Lead reads its history', await ocs(L, 'GET', `${path(Q6)}/history`), 200)
			expect('the Lead creates it again on the tombstone', await ocs(L, 'PUT', path(Q6), { version: 0, data: quote({ leads: ['pblead'] }) }), 200)

			expect('Team Admin creates a quote on pbverw’s project', await ocs(A, 'PUT', path(Q7), { version: 0, data: quote({ project: OB }) }), 200)
			const rev2 = (await me(L)).revision
			const cur = (await ocs(A, 'GET', `/records/project/${OB}`)).data
			expect('Team Admin adds pblead and pbuser1 to that project', await ocs(A, 'PUT', `/records/project/${OB}`, { version: cur?.version, data: project(OB, ['pbverw', 'pblead', 'pbuser1']) }), 200)
			const d = await inDelta(L, rev2, Q7)
			check('the delta brings the project’s quote to the new Lead (new revision, same version)', d?.version === 1 && d?.data?.project === OB, d)
			const u = await inDelta(U1, rev2, Q5)
			check('… and the quote naming pbuser1, now a Lead', u?.version === 1 && u?.data?.leads?.[0] === 'pbuser1', u)
			expect('pbuser1 reads it now', await ocs(U1, 'GET', path(Q5)), 200)
		}
	} finally {
		head('Quotes: cleaning up')
		for (const k of [Q1, Q2, Q3, Q4, Q5, Q6, Q7]) {
			const cur = await ocs(A, 'GET', path(k))
			if (cur.status === 200) {
				await ocs(A, 'DELETE', `${path(k)}?version=${cur.data.version}`)
			}
		}
		for (const p of [OA, OB, OC]) {
			const cur = await ocs(A, 'GET', `/records/project/${p}`)
			if (cur.status === 200) {
				await ocs(A, 'DELETE', `/records/project/${p}?version=${cur.data.version}`)
			}
		}
		check('pbuser1 is a Member again', ((await me(U1)).lead_of || []).length === 0)
		check('cleaned up', true)
	}
}
