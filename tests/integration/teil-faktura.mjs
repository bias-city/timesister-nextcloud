// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Invoicing on the server (0.18.0): a draft with positions, texts and
// bookings is issued in one transaction – number, frozen content, hours,
// billing marks, ledger entry –, then read as `fixed`, settled, reversed
// and credited; the ledger and its chain are verified, also after a hash
// is tampered with (TS_SQL); the team setting `invoicing` switches it off.
// Needs the booking in the book (listener on CalDAV PUT or the job through
// TS_OCC); without it the issuing checks are skipped.
// Leaves pb as it found it: the settings record back, project, customer,
// events and marks removed; the frozen rows only with TS_SQL (the ledger
// itself is append-only and stays).
import { ensureTimeCalendar, deleteEvent, ocs, putEvent, runJob, sql } from './lib.mjs'
import { A, L, RUN, U1, check, expect, head, isObj, me, sha256 } from './harness.mjs'

const path = (key) => `/records/invoice/${encodeURIComponent(key)}`
const ipath = (key) => `/invoices/${encodeURIComponent(key)}`
const billingKey = (person, uid) => `${person}+${sha256(uid).slice(0, 32)}`
const HEX64 = /^[0-9a-f]{64}$/
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

/** The client's recomputation of an entry's hash (API.md, Invoicing). */
const entryHash = (e) => sha256(`${e.seq}\n${e.at}\n${e.actor}\n${e.action}\n${e.number}\n${e.payload_hash}\n${e.prev_hash}`)

/** All entries of the book visible to `who`. */
async function book(who) {
	const out = []
	let since = 0
	for (let i = 0; i < 20; i++) {
		const r = await ocs(who, 'GET', `/entries?since=${since}&limit=2000`)
		if (r.status !== 200) {
			return out
		}
		out.push(...r.data.entries)
		if (!r.data.more || r.data.entries.length === 0) {
			return out
		}
		since = r.data.entries[r.data.entries.length - 1].revision
	}
	return out
}

export async function faktura() {
	const PF = `PF-${RUN}`
	const CF = `CF-${RUN}`
	const CODE = `F${RUN}`.toUpperCase()
	const EV1 = `tf-${RUN}-1@example.test`
	const K1 = `inv-${RUN}f1`
	const K2 = `inv-${RUN}f2`
	const K3 = `inv-${RUN}f3`
	const made = [K1, K2, K3]
	// A far year of its own, so the counter never touches real numbering.
	const Y = 3000 + (parseInt(RUN.slice(-4), 36) % 5000) + 7
	const ISSUED_ON = `${Y}-03-15`
	const NUMBER = new RegExp(`^RF-${RUN}-\\d{4}$`)
	const tenant = sql("SELECT id FROM oc_ts_tenants WHERE slug = 'pb'")
	const settings = await ocs(A, 'GET', '/records/setting/settings')
	const base = settings.status === 200 ? settings.data.data : {}
	let settingsVersion = settings.status === 200 ? settings.data.version : 0
	const draft = (more = {}) => ({
		schema: 1, project: PF, budget: 'b1', from: '2026-10-01', to: '2026-10-31', status: 'draft', number: null,
		bookings: [{ person: 'pbuser1', uid: EV1, start: '2026-10-12T08:00:00', hours: 1, rate: 150, code: CODE }],
		positions: [
			{ kind: 'hours', text: 'Site visit', person: 'pbuser1', work_package: 0, hours: 1, rate: 150, amount: 150 },
			{ kind: 'flat', text: 'Travel', amount: 100 },
			{ kind: 'discount', text: 'Goodwill', amount: -50 },
			{ kind: 'text', text: 'Thank you', amount: 0 },
		],
		greeting: 'Dear team', body: 'Payable within 30 days.', footer: 'Muster · Basel', vat: 8.1, contact: 'c1',
		note: null, created_by: 'pbadmin', created_on: '2026-10-15', issued_on: null, paid_on: null, ...more,
	})
	try {
		head('Invoicing: setup')
		{
			const cap = await ocs(A, 'GET', '/ocs/v2.php/cloud/capabilities')
			check('capability invoicing: 1', cap.data?.capabilities?.timesister?.invoicing === 1, cap.data?.capabilities?.timesister)
			check('/me.settings.invoicing true by default', (await me(A)).settings?.invoicing === true)
			const s = await ocs(A, 'PUT', '/records/setting/settings', { version: settingsVersion, data: { ...base,
				invoice_number: { pattern: `RF-${RUN}-{no:4}`, per_year: true, start: 1 },
				issuer: { name: 'Muster sàrl', street: 'Musterstrasse 12', postcode: '4000', city: 'Basel', country: 'CH', vat_id: 'CHE-123.456.789 MWST', qr: true, accounts: [{ currency: 'CHF', iban: 'CH9300762011623852957', bic: '' }] } } })
			expect('Team Admin sets pattern and issuer', s, 200)
			settingsVersion = s.data?.version ?? settingsVersion
			expect('customer', await ocs(A, 'PUT', `/records/customer/${CF}`, { version: 0, data: { schema: 1, id: CF, name: `Kunde ${RUN}`, street: 'Platz 1', postcode: '4001', city: 'Basel', country: 'CH', vat_id: 'CHE-999', note: 'secret', contacts: [{ id: 'c1', name: 'Lea Kontakt' }] } }), 200)
			expect('project with budget b1 in CHF, led by pblead', await ocs(A, 'PUT', `/records/project/${PF}`, { version: 0, data: { schema: 1, id: PF, name: `Faktura ${RUN}`, codes: [CODE], status: 'active', leads: ['pblead'], customer: CF, budgets: [{ id: 'b1', name: 'Budget', currency: 'CHF' }] } }), 200)
			check('pbuser1’s time calendar exists and is named', (await ensureTimeCalendar(U1)) && (await ocs(U1, 'PUT', '/me/time-calendar', { calendar_url: 'zeit-pbuser1' })).status === 200)
			check('pbuser1 books an hour on the project', (await putEvent(U1, EV1, ['DTSTART:20261012T080000Z', 'DTEND:20261012T090000Z', `SUMMARY:Site visit #${CODE}`])) === 201)
			await sleep(500)
			if (!(await book(A)).some((e) => e.event_uid === EV1)) {
				runJob('EntryReader')
			}
		}
		const inBook = (await book(A)).some((e) => e.event_uid === EV1)
		check('the booking is in the book', inBook)

		head('Invoicing: the draft')
		const d = await ocs(A, 'PUT', path(K1), { version: 0, data: draft() })
		expect('Team Admin writes a draft with positions, texts and hours per booking', d, 200)
		check('… stored as sent', d.data?.data?.positions?.length === 4 && d.data?.data?.greeting === 'Dear team' && d.data?.data?.bookings?.[0]?.rate === 150, d.data?.data)
		expect('a position without amount', await ocs(A, 'PUT', path(K2), { version: 0, data: draft({ positions: [{ kind: 'flat', text: 'x' }] }) }), 422, 'invalid')
		expect('Lead issues', await ocs(L, 'POST', `${ipath(K1)}/issue`, {}), 403, 'forbidden')
		expect('Member issues', await ocs(U1, 'POST', `${ipath(K1)}/issue`, {}), 403, 'forbidden')
		expect('fixed of a draft', await ocs(A, 'GET', `${ipath(K1)}/fixed`), 404, 'not_found')
		expect('issue of an unknown key', await ocs(A, 'POST', `${ipath(`inv-${RUN}zz`)}/issue`, {}), 404, 'not_found')
		expect('due_days out of range', await ocs(A, 'POST', `${ipath(K1)}/issue`, { due_days: 0 }), 422, 'invalid')
		const noBudget = await ocs(A, 'PUT', path(K2), { version: 0, data: draft({ budget: 'b9' }) })
		expect('a draft naming a budget not in the project', noBudget, 200)
		const nb = await ocs(A, 'POST', `${ipath(K2)}/issue`, { issued_on: ISSUED_ON })
		expect('… issuing it', nb, 422, 'invalid')
		check('… names the budget', nb.data?.message === 'The invoice’s budget is not in the project.', nb.data)

		if (!inBook) {
			console.log('    info  the booking is not in the book (no listener, no TS_OCC) – the issuing checks are skipped')
			return
		}

		head('Invoicing: issue')
		let number = null
		let seq = null
		let hash = null
		{
			const r = await ocs(A, 'POST', `${ipath(K1)}/issue`, { issued_on: ISSUED_ON })
			expect('Team Admin issues the draft', r, 200)
			const i = r.data?.issue
			number = i?.number ?? null
			seq = i?.seq ?? null
			hash = i?.hash ?? null
			check('answer: the record as issued', r.data?.kind === 'invoice' && r.data?.data?.status === 'issued' && r.data?.data?.number === number && r.data?.version === 2, r.data)
			check('number from the team’s pattern', typeof number === 'string' && NUMBER.test(number), number)
			check('issue: server-computed sums (net 200, VAT 8.1 % = 16.2, gross 216.2), CHF', i?.net === 200 && i?.vat_amount === 16.2 && i?.gross === 216.2 && i?.currency === 'CHF', i)
			check('issue: due 30 days later, document pending', i?.due_on === `${Y}-04-14` && i?.document_state === 'pending', i)
			check('issue: seq, prev_hash, hash', Number.isInteger(seq) && typeof i?.prev_hash === 'string' && HEX64.test(hash ?? ''), i)
			const data = r.data?.data
			check('record: hours 1, amount = total 216.2 (0.21.0), due_on, ledger_seq, hash, vat', data?.hours === 1 && data?.amount === 216.2 && data?.due_on === i?.due_on && data?.ledger_seq === seq && data?.hash === hash && data?.vat === 8.1 && data?.issued_on === ISSUED_ON, data)
			const mark = await ocs(A, 'GET', `/records/billing/${billingKey('pbuser1', EV1)}`)
			expect('the billing mark exists', mark, 200)
			check('… document = number, source timesister, by pbadmin, billed_on = issued_on', mark.data?.data?.document === number && mark.data?.data?.source === 'timesister' && mark.data?.data?.by === 'pbadmin' && mark.data?.data?.billed_on === ISSUED_ON, mark.data?.data)
			const again = await ocs(A, 'POST', `${ipath(K1)}/issue`, {})
			expect('issuing it again', again, 409, 'issued')
		}

		head('Invoicing: fixed, ledger, chain')
		{
			const f = await ocs(A, 'GET', `${ipath(K1)}/fixed`)
			expect('Team Admin reads fixed', f, 200)
			const x = f.data
			check('fixed: number, status, sums, currency, vat', x?.number === number && x?.status === 'issued' && x?.net === 200 && x?.vat_amount === 16.2 && x?.gross === 216.2 && x?.currency === 'CHF' && x?.vat === 8.1, x)
			check('fixed: issuer and customer frozen, contact resolved, note left out', x?.issuer?.name === 'Muster sàrl' && x?.customer?.name === `Kunde ${RUN}` && x?.customer?.vat_id === 'CHE-999' && x?.customer?.contact?.name === 'Lea Kontakt' && x?.customer?.note === undefined, x?.customer)
			check('fixed: positions as sent, hours with amount = hours × rate and code', x?.positions?.length === 4 && x?.positions?.[2]?.amount === -50 && x?.hours?.length === 1 && x?.hours?.[0]?.amount === 150 && x?.hours?.[0]?.code === CODE && x?.hours?.[0]?.start === '2026-10-12T08:00:00', [x?.positions, x?.hours])
			check('fixed: texts, chain, period, issued_by', x?.greeting === 'Dear team' && x?.footer === 'Muster · Basel' && x?.chain?.seq === seq && x?.chain?.hash === hash && x?.period?.from === '2026-10-01' && x?.issued_by === 'pbadmin' && x?.reverses === null && x?.reversed_by === null, x)
			expect('Lead of the project reads fixed', await ocs(L, 'GET', `${ipath(K1)}/fixed`), 200)
			expect('Member reads fixed', await ocs(U1, 'GET', `${ipath(K1)}/fixed`), 403, 'forbidden')
			expect('Lead reads the ledger', await ocs(L, 'GET', '/invoices/ledger'), 403, 'forbidden')
			const lg = await ocs(A, 'GET', `/invoices/ledger?since=${seq - 1}&limit=10`)
			expect('Team Admin reads the ledger from the issue on', lg, 200)
			const e = lg.data?.entries?.[0]
			check('entry: seq, at, actor, action issued, number, hashes', e?.seq === seq && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(e?.at ?? '') && e?.actor === 'pbadmin' && e?.action === 'issued' && e?.number === number && e?.hash === hash && HEX64.test(e?.payload_hash ?? ''), e)
			check('entry: payload is the canonical JSON and hashes to payload_hash', typeof e?.payload === 'string' && sha256(e.payload) === e?.payload_hash && e.payload.startsWith('{"body":"Payable within 30 days.","budget":"b1"'), e?.payload?.slice(0, 120))
			check('entry: the client recomputes the hash', e !== undefined && entryHash(e) === e.hash)
			expect('limit out of range', await ocs(A, 'GET', '/invoices/ledger?limit=0'), 422, 'invalid')
			const v = await ocs(A, 'GET', '/invoices/chain/verify')
			expect('chain verifies', v, 200)
			check('… ok, entries ≥ 1, head = last hash', v.data?.ok === true && v.data?.entries >= 1 && HEX64.test(v.data?.head ?? ''), v.data)
			expect('Lead verifies', await ocs(L, 'GET', '/invoices/chain/verify'), 403, 'forbidden')
		}

		head('Invoicing: an issued invoice does not change')
		{
			const w = await ocs(A, 'PUT', path(K1), { version: 2, data: draft({ status: 'issued', number, note: 'changed' }) })
			expect('Team Admin writes the issued record', w, 409, 'issued')
			check('… with the message', w.data?.message === 'An issued invoice does not change; reverse it.', w.data)
			expect('Team Admin deletes it', await ocs(A, 'DELETE', `${path(K1)}?version=2`), 409, 'issued')
			const b = await ocs(A, 'POST', '/records/batch', { writes: [{ kind: 'invoice', key: K1, version: 2, data: draft({ status: 'issued', number }) }] })
			expect('… in a batch', b, 409, 'issued')
			check('… naming the write', b.data?.index === 0 && /^Write 1: /.test(b.data?.message ?? ''), b.data)
			expect('Lead writes it', await ocs(L, 'PUT', path(K1), { version: 2, data: draft() }), 403, 'forbidden')
			const same = await ocs(A, 'PUT', path(K2), { version: 1, data: draft() })
			expect('a second draft with the same booking', same, 200)
			const dup = await ocs(A, 'POST', `${ipath(K2)}/issue`, { issued_on: ISSUED_ON })
			expect('… issuing it', dup, 409, 'conflict')
			check('… names the invoice', dup.data?.message === `Booking 1 is billed by invoice ${number}.`, dup.data)
			check('… nothing frozen', (await ocs(A, 'GET', `${ipath(K2)}/fixed`)).status === 404)
		}

		head('Invoicing: paid and unpaid')
		let unpaidSeq = seq
		{
			expect('paid without a date', await ocs(A, 'POST', `${ipath(K1)}/paid`, {}), 422, 'invalid')
			const p = await ocs(A, 'POST', `${ipath(K1)}/paid`, { paid_on: `${Y}-04-01`, note: 'bank' })
			expect('Team Admin marks it paid', p, 200)
			check('… record paid with the day, ledger entry', p.data?.data?.status === 'paid' && p.data?.data?.paid_on === `${Y}-04-01` && p.data?.ledger?.seq > seq && HEX64.test(p.data?.ledger?.hash ?? ''), p.data)
			check('… fixed shows paid_on and paid_by', (await ocs(A, 'GET', `${ipath(K1)}/fixed`)).data?.paid_by === 'pbadmin')
			expect('paid again', await ocs(A, 'POST', `${ipath(K1)}/paid`, { paid_on: `${Y}-04-02` }), 422, 'invalid')
			const u = await ocs(A, 'POST', `${ipath(K1)}/unpaid`, {})
			expect('… unpaid again', u, 200)
			unpaidSeq = u.data?.ledger?.seq ?? seq
			check('… record issued, no paid_on', u.data?.data?.status === 'issued' && u.data?.data?.paid_on === null && u.data?.ledger?.seq > seq + 1, u.data)
			expect('unpaid on an issued one', await ocs(A, 'POST', `${ipath(K1)}/unpaid`, {}), 422, 'invalid')
			expect('paid on a draft', await ocs(A, 'POST', `${ipath(K2)}/paid`, { paid_on: `${Y}-04-01` }), 422, 'invalid')
		}

		head('Invoicing: reversal')
		let rev = null
		{
			expect('kind missing', await ocs(A, 'POST', `${ipath(K1)}/reverse`, {}), 422, 'invalid')
			expect('reversing a draft', await ocs(A, 'POST', `${ipath(K2)}/reverse`, { kind: 'reversal' }), 422, 'invalid')
			const r = await ocs(A, 'POST', `${ipath(K1)}/reverse`, { kind: 'reversal', reason: 'Wrong hours', issued_on: ISSUED_ON })
			expect('Team Admin reverses', r, 200)
			rev = r.data?.record?.key ?? null
			if (rev) {
				made.push(rev)
			}
			const n = r.data?.record?.data
			check('the reversal: own key and number, positions negated, reverses the original', /^inv-[0-9a-f]{8}$/.test(rev ?? '') && NUMBER.test(n?.number ?? '') && n?.number !== number && n?.status === 'issued' && n?.reverses === K1
				&& JSON.stringify(n?.positions?.map((p) => p.amount)) === '[-150,-100,50,0]' && n?.amount === -216.2 && n?.bookings?.length === 0 && n?.note === 'Wrong hours', n)
			check('the issue block of the reversal: net -200, gross -216.2', r.data?.issue?.net === -200 && r.data?.issue?.gross === -216.2 && HEX64.test(r.data?.issue?.hash ?? ''), r.data?.issue)
			const o = r.data?.original
			check('the original: cancelled, reversed_by', o?.key === K1 && o?.data?.status === 'cancelled' && o?.data?.reversed_by === rev && o?.version === 5, o)
			expect('the billing mark is gone', await ocs(A, 'GET', `/records/billing/${billingKey('pbuser1', EV1)}`), 404)
			const f = await ocs(A, 'GET', `${ipath(K1)}/fixed`)
			check('fixed of the original: cancelled, reversed_by, positions kept', f.data?.status === 'cancelled' && f.data?.reversed_by === rev && f.data?.positions?.length === 4 && f.data?.hours?.length === 1, f.data)
			const g = await ocs(A, 'GET', `${ipath(rev)}/fixed`)
			check('fixed of the reversal: reverses, no hours, issuer and customer copied', g.data?.reverses === K1 && g.data?.hours?.length === 0 && g.data?.customer?.name === `Kunde ${RUN}` && g.data?.net === -200, g.data)
			// 0.19.0 appends `document` after each issue; the sequence is read without those entries.
			const lg = await ocs(A, 'GET', `/invoices/ledger?since=${unpaidSeq}`)
			const seqNoDoc = (lg.data?.entries || []).filter((e) => e.action !== 'document')
			check('ledger: reversal, then cancelled', seqNoDoc.map((e) => e.action).join() === 'reversal,cancelled' && seqNoDoc[1]?.number === number, lg.data?.entries?.map((e) => [e.seq, e.action, e.number]))
			expect('reversing it again', await ocs(A, 'POST', `${ipath(K1)}/reverse`, { kind: 'reversal' }), 422, 'invalid')
			expect('crediting a cancelled one', await ocs(A, 'POST', `${ipath(K1)}/reverse`, { kind: 'credit' }), 422, 'invalid')
			expect('reversing the reversal', await ocs(A, 'POST', `${ipath(rev)}/reverse`, { kind: 'reversal' }), 200)
			const back = await ocs(A, 'GET', `${ipath(K1)}/fixed`)
			check('the original stays cancelled', back.data?.status === 'cancelled')
		}

		head('Invoicing: the hours are billable again, credit note')
		{
			const r = await ocs(A, 'POST', `${ipath(K2)}/issue`, { issued_on: ISSUED_ON, due_days: 10 })
			expect('the second draft with the same booking is issued now', r, 200)
			check('… due in 10 days', r.data?.issue?.due_on === `${Y}-03-25`, r.data?.issue)
			expect('the billing mark is back', await ocs(A, 'GET', `/records/billing/${billingKey('pbuser1', EV1)}`), 200)
			expect('positions of a credit note checked', await ocs(A, 'POST', `${ipath(K2)}/reverse`, { kind: 'credit', positions: [{ kind: 'flat', text: 'Credit' }] }), 422, 'invalid')
			const c = await ocs(A, 'POST', `${ipath(K2)}/reverse`, { kind: 'credit', reason: 'Goodwill', positions: [{ kind: 'flat', text: 'Credit', amount: -30 }] })
			expect('Team Admin credits', c, 200)
			const ck = c.data?.record?.key
			if (ck) {
				made.push(ck)
			}
			check('the credit note: credits the original, the given positions, net -30', c.data?.record?.data?.credits === K2 && c.data?.record?.data?.reverses === undefined && c.data?.issue?.net === -30 && c.data?.record?.data?.positions?.length === 1, c.data?.record?.data)
			check('the original stays issued, with its mark', c.data?.original?.data?.status === 'issued' && (await ocs(A, 'GET', `/records/billing/${billingKey('pbuser1', EV1)}`)).status === 200, c.data?.original)
			expect('a second credit note', await ocs(A, 'POST', `${ipath(K2)}/reverse`, { kind: 'credit' }), 200)
			const l = await ocs(A, 'GET', '/invoices/ledger?limit=2000')
			const actions = (l.data?.entries || []).filter((e) => e.seq >= seq && e.action !== 'document').map((e) => e.action)
			check('ledger so far', actions.join() === 'issued,paid,unpaid,reversal,cancelled,reversal,cancelled,issued,credit,credit', actions)
			check('every entry recomputes', (l.data?.entries || []).every((e) => entryHash(e) === e.hash && sha256(e.payload) === e.payload_hash))
			const v = await ocs(A, 'GET', '/invoices/chain/verify')
			check('chain ok, head = last hash', v.data?.ok === true && v.data?.head === l.data?.entries?.at(-1)?.hash, v.data)
		}

		head('Invoicing: a tampered hash')
		if (tenant === null) {
			console.log('    info  TS_SQL not set – the tampering check is skipped')
		} else {
			const was = sql(`SELECT hash FROM oc_ts_inv_ledger WHERE tenant_id = ${tenant} AND seq = ${seq}`)
			sql(`UPDATE oc_ts_inv_ledger SET hash = '${'0'.repeat(64)}' WHERE tenant_id = ${tenant} AND seq = ${seq}`)
			const v = await ocs(A, 'GET', '/invoices/chain/verify')
			check('verify finds the break', v.data?.ok === false && v.data?.first_break === seq && v.data?.field === 'hash' && v.data?.expected === was && v.data?.found === '0'.repeat(64), v.data)
			sql(`UPDATE oc_ts_inv_ledger SET hash = '${was}' WHERE tenant_id = ${tenant} AND seq = ${seq}`)
			check('… and is fine after restoring', (await ocs(A, 'GET', '/invoices/chain/verify')).data?.ok === true)
			sql(`UPDATE oc_ts_inv_ledger SET payload = REPLACE(payload, '"net":200.0', '"net":201.0') WHERE tenant_id = ${tenant} AND seq = ${seq}`)
			const p = await ocs(A, 'GET', '/invoices/chain/verify')
			check('a changed payload breaks at payload_hash', p.data?.ok === false && p.data?.first_break === seq && p.data?.field === 'payload_hash', p.data)
			sql(`UPDATE oc_ts_inv_ledger SET payload = REPLACE(payload, '"net":201.0', '"net":200.0') WHERE tenant_id = ${tenant} AND seq = ${seq}`)
			check('… fine again', (await ocs(A, 'GET', '/invoices/chain/verify')).data?.ok === true)
		}

		head('Invoicing: the team setting')
		{
			expect('invoicing as text', await ocs(A, 'PUT', '/team/settings', { invoicing: 'off' }), 422, 'invalid')
			expect('Lead sets it', await ocs(L, 'PUT', '/team/settings', { invoicing: false }), 403, 'forbidden')
			const off = await ocs(A, 'PUT', '/team/settings', { invoicing: false })
			expect('Team Admin switches invoicing off', off, 200)
			check('… settings say so, /me and /team too', off.data?.invoicing === false && (await me(A)).settings?.invoicing === false && (await ocs(A, 'GET', '/team')).data?.settings?.invoicing === false, off.data)
			expect('fixed is 404 now', await ocs(A, 'GET', `${ipath(K1)}/fixed`), 404, 'not_found')
			expect('issue is 404 now', await ocs(A, 'POST', `${ipath(K3)}/issue`, {}), 404, 'not_found')
			expect('ledger is 404 now', await ocs(A, 'GET', '/invoices/ledger'), 404, 'not_found')
			expect('verify is 404 now', await ocs(A, 'GET', '/invoices/chain/verify'), 404, 'not_found')
			expect('the frozen record still refuses a write', await ocs(A, 'PUT', path(K1), { version: 5, data: draft({ status: 'cancelled', number }) }), 409, 'issued')
			const on = await ocs(A, 'PUT', '/team/settings', { invoicing: true, entries_since: (await me(A)).settings?.entries_since ?? null })
			expect('… and on again, together with entries_since', on, 200)
			check('… settings', on.data?.invoicing === true, on.data)
			expect('verify works again', await ocs(A, 'GET', '/invoices/chain/verify'), 200)
		}
	} finally {
		head('Invoicing: cleaning up')
		await ocs(A, 'PUT', '/team/settings', { invoicing: true })
		if (tenant !== null) {
			const keys = made.map((k) => `'${k}'`).join(', ')
			// The frozen rows of this run go (test data); the ledger is append-only and stays.
			sql(`DELETE FROM oc_ts_inv_hours WHERE tenant_id = ${tenant} AND invoice_id IN (SELECT id FROM oc_ts_inv_invoices WHERE tenant_id = ${tenant} AND rkey IN (${keys}))`)
			sql(`DELETE FROM oc_ts_inv_invoices WHERE tenant_id = ${tenant} AND rkey IN (${keys})`)
			sql(`DELETE FROM oc_ts_counters WHERE tenant_id = ${tenant} AND name = 'invoice:${Y}'`)
		} else {
			console.log('    info  TS_SQL not set – the frozen invoices of this run stay (their records cannot be deleted)')
		}
		for (const k of made) {
			const cur = await ocs(A, 'GET', path(k))
			if (cur.status === 200) {
				await ocs(A, 'DELETE', `${path(k)}?version=${cur.data.version}`)
			}
		}
		const mark = await ocs(A, 'GET', `/records/billing/${billingKey('pbuser1', EV1)}`)
		if (mark.status === 200) {
			await ocs(A, 'DELETE', `/records/billing/${billingKey('pbuser1', EV1)}?version=${mark.data.version}`)
		}
		await deleteEvent(U1, EV1)
		// A running client of pbuser1 may have reported the booking on the project; only they delete that.
		for (const r of ((await ocs(U1, 'GET', '/records?since=0')).data?.records || []).filter((x) => x.kind === 'booking-report' && x.key.includes(`+${PF}+`))) {
			await ocs(U1, 'DELETE', `/records/booking-report/${encodeURIComponent(r.key)}?version=${r.version}`)
		}
		for (const [kind, key] of [['project', PF], ['customer', CF]]) {
			const cur = await ocs(A, 'GET', `/records/${kind}/${key}`)
			if (cur.status === 200) {
				await ocs(A, 'DELETE', `/records/${kind}/${key}?version=${cur.data.version}`)
			}
		}
		const cur = await ocs(A, 'GET', '/records/setting/settings')
		if (settings.status === 200 && cur.status === 200) {
			await ocs(A, 'PUT', '/records/setting/settings', { version: cur.data.version, data: settings.data.data })
		} else if (cur.status === 200) {
			await ocs(A, 'DELETE', `/records/setting/settings?version=${cur.data.version}`)
		}
		check('pb left as found', isObj(await me(A)))
	}
}
