// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Races on the invoice ledger (08.11.2026): four reservations at once (one
// wins, the rest 409 issue_pending), payments on four invoices at once
// (distinct seq), five payments on one invoice at once (one wins, the rest
// 409 – already paid or version conflict, never a second entry), paid and
// unpaid interleaved (no 500, final status = last entry), and the whole
// chain recomputed afterwards. The team lock is the UPDATE of the team row
// (bumpRevision) in every ledger transaction; the unique index (tenant, seq)
// is the second net; the record version the third. Leaves the far-year
// invoices in zb like teil-faktura does (the ledger is append-only).
import { ocs } from './lib.mjs'
import { RUN, check, expect, festschreiben, head, sha256 } from './harness.mjs'
import { A } from './konten.mjs'
export async function ledgerRace() {
	const ipath = (k) => `/invoices/${encodeURIComponent(k)}`
	const entryHash = (e) => sha256(`${e.seq}\n${e.at}\n${e.actor}\n${e.action}\n${e.number}\n${e.payload_hash}\n${e.prev_hash}`)
	const Y = 3000 + (parseInt(RUN.slice(-4), 36) % 5000) + 9
	const ISSUED_ON = `${Y}-03-15`
	const CF = `CR-${RUN}`
	const keys = [1, 2, 3, 4].map((i) => `inv-${RUN}r${i}`)
	async function ledger() {
		const out = []; let since = 0
		for (let i = 0; i < 30; i++) {
			const r = await ocs(A, 'GET', `/invoices/ledger?since=${since}&limit=2000`)
			const e = r.data?.entries || []; out.push(...e)
			if (e.length < 2000) break
			since = e.at(-1).seq
		}
		return out
	}
	function chainOk(entries) {
		let prev = ''
		for (let i = 0; i < entries.length; i++) {
			const e = entries[i]
			if (e.seq !== i + 1 || e.prev_hash !== prev || entryHash(e) !== e.hash) return { ok: false, at: e.seq, why: e.seq !== i + 1 ? 'seq' : e.prev_hash !== prev ? 'prev' : 'hash' }
			prev = e.hash
		}
		return { ok: true }
	}
	const settings = await ocs(A, 'GET', '/records/setting/settings')
	const base = settings.data?.data || {}
	let settingsVersion = settings.data?.version || 0
	try {
		head('Ledger race: setup')
		const s = await ocs(A, 'PUT', '/records/setting/settings', { version: settingsVersion, data: { ...base, invoice_number: { pattern: `RL-${RUN}-{no:4}`, per_year: true, start: 1 },
			issuer: { name: 'Muster sàrl', street: 'Musterstrasse 12', postcode: '4000', city: 'Basel', country: 'CH', vat_id: 'CHE-123.456.789 MWST', qr: false, accounts: [{ currency: 'CHF', iban: 'CH9300762011623852957' }] } } })
		expect('pattern and issuer', s, 200); settingsVersion = s.data?.version ?? settingsVersion
		expect('customer', await ocs(A, 'PUT', `/records/customer/${CF}`, { version: 0, data: { schema: 1, id: CF, name: `Race ${RUN}`, street: 'Platz 1', postcode: '4001', city: 'Basel', country: 'CH' } }), 200)
		for (const k of keys) {
			expect(`draft ${k}`, await ocs(A, 'PUT', `/records/invoice/${k}`, { version: 0, data: { schema: 1, project: null, budget: null, customer: CF, from: '2026-10-01', to: '2026-10-31', status: 'draft', number: null,
				currency: 'CHF', vat: 8.1, bookings: [], positions: [{ kind: 'flat', text: `Flat ${k}`, amount: 100 }], greeting: 'Race', body: null, footer: null, note: null, created_by: 'zbadmin', created_on: '2026-10-15', issued_on: null, paid_on: null, cost_center: 'KST-RACE' } }), 200)
		}
		const before = await ledger()
		check('chain before is intact', chainOk(before).ok, chainOk(before))

		head('Race 1: four reservations at once → exactly one wins, the others 409 issue_pending')
		const res = await Promise.all(keys.map((k) => ocs(A, 'POST', `${ipath(k)}/issue`, { issued_on: ISSUED_ON })))
		const won = res.filter((r) => r.status === 200), lost = res.filter((r) => r.status !== 200)
		check('exactly one reservation', won.length === 1, res.map((r) => r.status).join(','))
		check('the others are 409 issue_pending (no 500)', lost.every((r) => r.status === 409 && r.data?.code === 'issue_pending'), lost.map((r) => `${r.status} ${r.data?.code || r.text.slice(0, 80)}`).join(' | '))
		const winner = keys[res.findIndex((r) => r.status === 200)]
		const cancel = await ocs(A, 'POST', `${ipath(winner)}/issue/cancel`, {})
		expect('cancel the pending reservation', cancel, 200)
		for (const k of keys) {
			const f = await festschreiben(A, k, { issued_on: ISSUED_ON })
			check(`issue ${k} sequentially`, f.status === 200, `${f.reserve?.status}/${f.close?.status} ${f.close?.text?.slice(0, 120) || ''}`)
		}

		head('Race 2: paid on four different invoices at once')
		const paid = await Promise.all(keys.map((k) => ocs(A, 'POST', `${ipath(k)}/paid`, { paid_on: `${Y}-04-01` })))
		check('all four 200', paid.every((r) => r.status === 200), paid.map((r) => r.status).join(','))
		const seqs = paid.map((r) => r.data?.ledger?.seq).filter(Number.isInteger)
		check('four distinct ledger seq', new Set(seqs).size === 4, seqs.join(','))

		head('Race 3: unpaid and paid again at once on the same invoice')
		const same = keys[0]
		await ocs(A, 'POST', `${ipath(same)}/unpaid`, {})
		const burst = await Promise.all([1, 2, 3, 4, 5].map(() => ocs(A, 'POST', `${ipath(same)}/paid`, { paid_on: `${Y}-04-02` })))
		const ok200 = burst.filter((r) => r.status === 200).length
		// The losers: already paid (checked under the team lock) or a stale record version – both 409, never a second entry.
		check('exactly one paid succeeds, the rest 409 (invoice_already_paid or version_conflict), no 500', ok200 === 1 && burst.filter((r) => r.status !== 200).every((r) => r.status === 409 && ['invoice_already_paid', 'version_conflict'].includes(r.data?.code)), burst.map((r) => `${r.status} ${r.data?.code || ''}`).join(' | '))
		const after = await ledger()
		const numberSame = (await ocs(A, 'GET', `${ipath(same)}/fixed`)).data?.number
		const paidEntries = after.filter((e) => e.action === 'paid' && e.number === numberSame)
		check('the ledger has exactly two paid entries for that invoice (race 2 + race 3), no double', paidEntries.length === 2, `${paidEntries.length} paid entries for ${numberSame}`)

		head('Race 4: paid and unpaid interleaved on one invoice, 10 at once')
		const mixed = await Promise.all([...Array(10).keys()].map((i) => ocs(A, 'POST', `${ipath(keys[1])}/${i % 2 ? 'paid' : 'unpaid'}`, i % 2 ? { paid_on: `${Y}-04-03` } : {})))
		check('no 500 among interleaved calls', mixed.every((r) => r.status < 500), mixed.map((r) => r.status).join(','))
		const fixed = await ocs(A, 'GET', `${ipath(keys[1])}/fixed`)
		const last = (await ledger()).filter((e) => e.number === fixed.data?.number).at(-1)
		check('final status matches the last ledger action', (fixed.data?.status === 'paid') === (last?.action === 'paid'), `${fixed.data?.status} vs ${last?.action}`)

		head('Chain after the races')
		const all = await ledger()
		const c = chainOk(all)
		check(`chain intact: ${all.length} entries, seq 1..${all.length}, every hash recomputed`, c.ok, c)
		check('ledger entries carry the cost center', all.filter((e) => e.action === 'issued' && e.number?.startsWith(`RL-${RUN}`)).every((e) => e.payload?.includes('"cost_center":"KST-RACE"')))
	} finally {
		await ocs(A, 'PUT', '/records/setting/settings', { version: settingsVersion, data: base }).catch(() => {})
		await ocs(A, 'DELETE', `/records/customer/${CF}?version=1`).catch(() => {})
	}

}
