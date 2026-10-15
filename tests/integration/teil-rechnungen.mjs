// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Invoices (0.16.0): the kind `invoice` – Team Admins everything, a Lead
// drafts without a number of their projects, Members nothing – the number
// pattern in `setting/settings` and the counter of /invoices/next-number.
// Leaves pb as it found it: invoices and projects deleted, the settings
// record back; the counters only with TS_SQL (otherwise they stay, in far
// years of their own).
import { ocs, sql } from './lib.mjs'
import { A, L, RUN, U1, V, check, expect, head, keysOf, me } from './harness.mjs'

const path = (key) => `/records/invoice/${encodeURIComponent(key)}`
const project = (id, leads, status = 'active') => ({ schema: 1, id, name: `Invoices ${id}`, codes: status === 'active' ? [`I${RUN}`] : [], status, leads })
const invoice = (proj, more = {}) => ({
	schema: 1, project: proj, budget: 'b1', from: '2026-09-01', to: '2026-09-30', status: 'draft', number: null,
	bookings: [
		{ person: 'pbuser1', uid: `7f3a-${RUN}@example.test`, start: '2026-09-02T09:00:00' },
		{ person: 'pbuser2', uid: `8a1b-${RUN}@example.test`, start: '2026-09-03' },
	],
	note: null, created_by: 'pblead', created_on: '2026-10-13', issued_on: null, paid_on: null, ...more,
})
const next = (who, year) => ocs(who, 'POST', '/invoices/next-number', year === undefined ? {} : { year })
const preview = (who, year) => ocs(who, 'GET', `/invoices/next-number${year === undefined ? '' : `?year=${year}`}`)

export async function rechnungen() {
	const PI = `PI-${RUN}`
	const PO = `PO-${RUN}`
	const K1 = `inv-${RUN}a`
	const K2 = `inv-${RUN}b`
	const K3 = `inv-${RUN}c`
	const K4 = `inv-${RUN}d`
	// Far years of their own, so the test does not touch real numbering.
	const Y = 3000 + (parseInt(RUN.slice(-4), 36) % 5000)
	const tenant = sql("SELECT id FROM oc_ts_tenants WHERE slug = 'pb'")
	const counters = [`invoice:${Y}`, `invoice:${Y + 1}`, 'invoice']
	const before = tenant === null ? null : sql(`SELECT name, last_no FROM oc_ts_counters WHERE tenant_id = ${tenant} AND name IN (${counters.map((c) => `'${c}'`).join(', ')})`)
	const settings = await ocs(A, 'GET', '/records/setting/settings')
	try {
		head('Invoices: setup and capability')
		{
			const cap = await ocs(A, 'GET', '/ocs/v2.php/cloud/capabilities')
			check('capability invoices: 1', cap.data?.capabilities?.timesister?.invoices === 1, cap.data?.capabilities?.timesister)
			expect('project led by pblead', await ocs(A, 'PUT', `/records/project/${PI}`, { version: 0, data: project(PI, ['pblead']) }), 200)
			expect('project led by pbverw only (a quote)', await ocs(A, 'PUT', `/records/project/${PO}`, { version: 0, data: project(PO, ['pbverw'], 'draft') }), 200)
			check('pblead leads the first', ((await me(L)).lead_of || []).some((p) => p.key === PI))
		}

		head('Invoices: a Lead writes drafts of their projects')
		{
			const r = await ocs(L, 'PUT', path(K1), { version: 0, data: invoice(PI) })
			expect('Lead creates a draft for their project', r, 200)
			check('… stored as sent', r.data?.kind === 'invoice' && r.data?.version === 1 && r.data?.data?.bookings?.length === 2 && r.data?.data?.status === 'draft', r.data)
			expect('a draft without budget', await ocs(L, 'PUT', path(K2), { version: 0, data: invoice(PI, { budget: '' }) }), 422)
			const other = await ocs(L, 'PUT', path(K2), { version: 0, data: invoice(PO) })
			expect('Lead creates a draft for another project', other, 403, 'forbidden')
			check('… with the message', other.data?.message === 'Only Team Admins and the project’s Leads write invoices.', other.data)
			const issue = await ocs(L, 'PUT', path(K1), { version: 1, data: invoice(PI, { status: 'issued', number: 'RE-1' }) })
			expect('Lead sets status issued', issue, 403, 'forbidden')
			check('… with the message', issue.data?.message === 'A Lead writes only drafts without a number; issuing, payment and cancelling are for Team Admins.', issue.data)
			expect('Lead sets a number on the draft', await ocs(L, 'PUT', path(K1), { version: 1, data: invoice(PI, { number: 'RE-1' }) }), 403, 'forbidden')
			expect('Lead moves the draft to another project', await ocs(L, 'PUT', path(K1), { version: 1, data: invoice(PO) }), 403, 'forbidden')
			expect('Lead changes the draft', await ocs(L, 'PUT', path(K1), { version: 1, data: invoice(PI, { note: 'Checked\nby Lea' }) }), 200)
			expect('Member creates a draft', await ocs(U1, 'PUT', path(K3), { version: 0, data: invoice(PI) }), 403, 'forbidden')
		}

		head('Invoices: content checked')
		{
			const bad = async (name, data, message) => {
				const r = await ocs(A, 'PUT', path(K3), { version: 0, data })
				expect(name, r, 422, 'invalid')
				check(`… “${message}”`, r.data?.message === message, r.data?.message)
			}
			await bad('from after to', invoice(PI, { from: '2026-10-01' }), '“from” must not be after “to”.')
			await bad('unknown status', invoice(PI, { status: 'open' }), '“status” of an invoice is one of draft, issued, paid, cancelled.')
			await bad('no project', invoice(null), '“project” must be the key of a project.')
			await bad('a booking twice', invoice(PI, { bookings: [{ person: 'a', uid: 'u', start: '2026-09-02' }, { person: 'a', uid: 'u', start: '2026-09-02' }] }),
				'Booking 2: The same booking (person, uid, start) is there twice.')
			await bad('a start with zone', invoice(PI, { bookings: [{ person: 'a', uid: 'u', start: '2026-09-02T08:00:00Z' }] }),
				'Booking 1: “start” must be a day (YYYY-MM-DD) or a local time (YYYY-MM-DDTHH:MM:SS).')
			await bad('number too long', invoice(PI, { number: 'x'.repeat(65) }), '“number” must be null or text of 1 to 64 characters.')
		}

		head('Invoices: who reads')
		{
			const r = await ocs(A, 'PUT', path(K2), { version: 0, data: invoice(PO, { status: 'issued', number: 'RE-2026-0001', issued_on: '2026-10-13', created_by: 'pbadmin' }) })
			expect('Team Admin creates an issued invoice for the other project', r, 200)
			expect('Lead reads their draft', await ocs(L, 'GET', path(K1)), 200)
			expect('Lead reads the other project’s invoice', await ocs(L, 'GET', path(K2)), 403, 'forbidden')
			expect('Lead reads a key that does not exist', await ocs(L, 'GET', path(`inv-${RUN}zz`)), 403, 'forbidden')
			expect('Team Admin reads a key that does not exist', await ocs(A, 'GET', path(`inv-${RUN}zz`)), 404, 'not_found')
			expect('Member reads the draft', await ocs(U1, 'GET', path(K1)), 403, 'forbidden')
			const lk = keysOf(await ocs(L, 'GET', '/records'))
			check('delta of the Lead: their draft, not the other', lk.has(`invoice/${K1}`) && !lk.has(`invoice/${K2}`), [...lk].filter((k) => k.startsWith('invoice/')))
			const uk = [...keysOf(await ocs(U1, 'GET', '/records'))].filter((k) => k.startsWith('invoice/'))
			check('delta of the Member: no invoices', uk.length === 0, uk)
			const ak = keysOf(await ocs(V, 'GET', '/records'))
			check('delta of a Team Admin: both', ak.has(`invoice/${K1}`) && ak.has(`invoice/${K2}`))
			const h = await ocs(L, 'GET', `${path(K1)}/history`)
			check('history for the Lead: both versions', h.status === 200 && h.data?.length === 2 && h.data?.[0]?.data?.note === 'Checked\nby Lea', h.data)
			expect('history for the Member', await ocs(U1, 'GET', `${path(K1)}/history`), 403, 'forbidden')
			expect('history of the other project for the Lead', await ocs(L, 'GET', `${path(K2)}/history`), 403, 'forbidden')
		}

		head('Invoices: Team Admin issues, the Lead keeps off')
		{
			expect('Team Admin issues the draft with a number', await ocs(A, 'PUT', path(K1), { version: 2, data: invoice(PI, { status: 'issued', number: 'RE-2026-0002', issued_on: '2026-10-13' }) }), 200)
			expect('Lead takes it back to draft', await ocs(L, 'PUT', path(K1), { version: 3, data: invoice(PI) }), 403, 'forbidden')
			expect('Lead deletes the issued invoice', await ocs(L, 'DELETE', `${path(K1)}?version=3`), 403, 'forbidden')
			expect('Lead restores the draft version', await ocs(L, 'POST', `${path(K1)}/restore`, { version: 2, current: 3 }), 403, 'forbidden')
			const b = await ocs(L, 'POST', '/records/batch', { writes: [{ kind: 'invoice', key: K3, version: 0, data: invoice(PI) }, { kind: 'invoice', key: K1, version: 3, data: invoice(PI, { note: 'x' }) }] })
			expect('Lead: batch with the issued one in it', b, 403, 'forbidden')
			check('… names the write, nothing written', b.data?.index === 1 && /^Write 2: /.test(b.data?.message || '') && (await ocs(A, 'GET', path(K3))).status === 404, b.data)
			expect('Lead: batch with a new draft', await ocs(L, 'POST', '/records/batch', { writes: [{ kind: 'invoice', key: K3, version: 0, data: invoice(PI) }] }), 200)
			expect('Lead: batch with an invoice and a project', await ocs(L, 'POST', '/records/batch', { writes: [{ kind: 'invoice', key: K4, version: 0, data: invoice(PI) }, { kind: 'project', key: PI, version: 1, data: project(PI, ['pblead']) }] }), 403, 'forbidden')
			const rev = (await me(L)).revision
			expect('Lead deletes their draft', await ocs(L, 'DELETE', `${path(K3)}?version=1`), 200)
			const t = ((await ocs(L, 'GET', `/records?since=${rev}`)).data?.records || []).find((x) => x.key === K3)
			check('… the Lead gets the tombstone', t?.deleted === true && t?.data === null, t)
			const ut = ((await ocs(U1, 'GET', `/records?since=${rev}`)).data?.records || []).find((x) => x.key === K3)
			check('… the Member does not', ut === undefined, ut)
			expect('… and reads its history', await ocs(L, 'GET', `${path(K3)}/history`), 200)
		}

		head('Invoices: a new Lead finds the project’s invoices')
		{
			const rev = (await me(L)).revision
			expect('Team Admin adds pblead to the other project', await ocs(A, 'PUT', `/records/project/${PO}`, { version: 1, data: project(PO, ['pbverw', 'pblead'], 'draft') }), 200)
			const d = ((await ocs(L, 'GET', `/records?since=${rev}`)).data?.records || []).find((x) => x.key === K2)
			check('the delta brings the invoice again (new revision, same version)', d?.version === 1 && d?.data?.number === 'RE-2026-0001', d)
			expect('the Lead reads it now', await ocs(L, 'GET', path(K2)), 200)
		}

		head('Invoice number: pattern in the settings')
		{
			const cur = settings.status === 200 ? settings.data : null
			const base = cur?.data || {}
			let version = cur?.version || 0
			const put = (inv) => ocs(A, 'PUT', '/records/setting/settings', { version, data: { ...base, invoice_number: inv } })
			const bad = async (name, inv, message) => {
				const r = await put(inv)
				expect(name, r, 422, 'invalid')
				check(`… “${message}”`, r.data?.message === message, r.data?.message)
			}
			await bad('no number placeholder', { pattern: 'RE-{year}' }, 'The invoice number pattern needs exactly one number placeholder: {no}, {no:N}, {nr}, {nr:N} (N 1–8).')
			await bad('two number placeholders', { pattern: '{no}-{nr:3}' }, 'The invoice number pattern needs exactly one number placeholder: {no}, {no:N}, {nr}, {nr:N} (N 1–8).')
			await bad('unknown placeholder', { pattern: 'RE-{month}-{no}' }, 'Unknown placeholder {month} in the invoice number pattern.')
			await bad('width 9', { pattern: 'RE-{no:9}' }, 'Unknown placeholder {no:9} in the invoice number pattern.')
			await bad('start 0', { pattern: 'RE-{no}', start: 0 }, '“invoice_number.start” must be an integer from 1 to 999999999.')
			await bad('per_year as text', { pattern: 'RE-{no}', per_year: 'yes' }, '“invoice_number.per_year” must be true or false.')
			const r = await put({ pattern: 'RE-{jahr}-{nr:4}', per_year: true, start: 1 })
			expect('Team Admin sets RE-{jahr}-{nr:4}', r, 200)
			version = r.data?.version ?? version
			expect('Lead sets the pattern', await ocs(L, 'PUT', '/records/setting/settings', { version, data: { ...base, invoice_number: { pattern: '{no}' } } }), 403, 'forbidden')

			head('Invoice number: the counter')
			expect('Member asks for a number', await next(U1, Y), 403, 'forbidden')
			const lead = await next(L, Y)
			expect('Lead asks for a number', lead, 403, 'forbidden')
			check('… with the message', lead.data?.message === 'Only Team Admins assign invoice numbers.', lead.data)
			expect('Lead asks for the preview', await preview(L, Y), 403, 'forbidden')
			expect('a year of two digits', await next(A, 26), 422, 'invalid')
			expect('a year as text in the query', await preview(A, 'abc'), 422, 'invalid')
			const p0 = await preview(A, Y)
			const n1 = await next(A, Y)
			const n2 = await next(V, Y)
			check('first number of a fresh year is 1, preview said so', p0.data?.number === 1 && n1.data?.number === 1 && n1.data?.year === Y, [p0.data, n1.data])
			check('the next call gives the next number (another Team Admin)', n2.status === 200 && n2.data?.number === n1.data?.number + 1 && n2.data?.year === Y, n2.data)
			const p1 = await preview(A, Y)
			const p2 = await preview(A, Y)
			const n3 = await next(A, Y)
			check('preview shows the next without taking it', p1.data?.number === n2.data?.number + 1 && p2.data?.number === p1.data?.number && n3.data?.number === p1.data?.number, [p1.data, p2.data, n3.data])
			const other = await next(A, Y + 1)
			check('per year: the next year starts again', other.data?.number === 1 && other.data?.year === Y + 1, other.data)
			const all = await Promise.all(Array.from({ length: 8 }, (_, i) => next(i % 2 ? V : A, Y)))
			const nums = all.map((x) => x.data?.number).sort((a, b) => a - b)
			check('8 at once: all different, without a gap', all.every((x) => x.status === 200) && new Set(nums).size === 8 && nums[0] === n3.data?.number + 1 && nums[7] === nums[0] + 7, all.map((x) => x.status + ':' + x.data?.number))

			const raised = await put({ pattern: 'RE-{jahr}-{nr:4}', per_year: true, start: 100 })
			version = raised.data?.version ?? version
			const s1 = await next(A, Y)
			check('start raised later: continues at start', raised.status === 200 && s1.data?.number === 100, s1.data)

			const flat = await put({ pattern: 'R{nr:5}', per_year: false, start: 1 })
			expect('Team Admin sets one counter for all years', flat, 200)
			version = flat.data?.version ?? version
			const f1 = await next(A, Y)
			const f2 = await next(A, Y + 1)
			const f3 = await next(A)
			check('across years: the numbers go on', f1.status === 200 && f2.data?.number === f1.data?.number + 1 && f2.data?.year === Y + 1, [f1.data, f2.data])
			check('without a year: this year, the same counter', f3.data?.number === f2.data?.number + 1 && f3.data?.year === new Date().getUTCFullYear(), f3.data)
			const fp = await preview(A, Y + 3)
			check('preview across years', fp.data?.number === f3.data?.number + 1 && fp.data?.year === Y + 3, fp.data)
		}
	} finally {
		head('Invoices: cleaning up')
		for (const k of [K1, K2, K3, K4]) {
			const cur = await ocs(A, 'GET', path(k))
			if (cur.status === 200) {
				await ocs(A, 'DELETE', `${path(k)}?version=${cur.data.version}`)
			}
		}
		for (const p of [PI, PO]) {
			const cur = await ocs(A, 'GET', `/records/project/${p}`)
			if (cur.status === 200) {
				await ocs(A, 'DELETE', `/records/project/${p}?version=${cur.data.version}`)
			}
		}
		const now = await ocs(A, 'GET', '/records/setting/settings')
		if (settings.status === 200 && now.status === 200) {
			expect('settings back as they were', await ocs(A, 'PUT', '/records/setting/settings', { version: now.data.version, data: settings.data.data }), 200)
		} else if (now.status === 200) {
			expect('settings removed again', await ocs(A, 'DELETE', `/records/setting/settings?version=${now.data.version}`), 200)
		}
		if (tenant !== null) {
			const old = new Map((before || '').split('\n').filter(Boolean).map((l) => l.split('\t')))
			for (const c of counters) {
				sql(old.has(c)
					? `UPDATE oc_ts_counters SET last_no = ${Number(old.get(c))} WHERE tenant_id = ${tenant} AND name = '${c}'`
					: `DELETE FROM oc_ts_counters WHERE tenant_id = ${tenant} AND name = '${c}'`)
			}
			check('counters back as they were (TS_SQL)', true)
		} else {
			console.log(`    (without TS_SQL the counters invoice:${Y}, invoice:${Y + 1} and invoice of pb stay)`)
		}
		check('cleaned up', true)
	}
}
