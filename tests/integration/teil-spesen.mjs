// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Expenses as one table (0.30.0, PLAN-SPESEN 9): the expense fields of a
// receipt (A), who changes them and what a settled receipt keeps (B),
// settling a period by the person (C) with lines and sums in the team's
// currency computed as the Mac app does (D, E – checked against an
// independent copy of its arithmetic), the app's documents (F), what is
// gone (G) and the ECB rates through a local stub of Frankfurter (H, only
// with TS_OCC; never the real service). Leaves pb as it found it
// (settings, team settings, app config); records only with TS_SQL, the
// ledger stays; the test folder in the owner's files goes.
import { exec } from 'node:child_process'
import http from 'node:http'
import { promisify } from 'node:util'
import { dav, ocs, occ, sql, NC, API } from './lib.mjs'
import { A, L, RUN, U1, U2, check, expect, head, sha256 } from './harness.mjs'

const rec = (kind, key) => `/records/${kind}/${encodeURIComponent(key)}`
const auth = (who) => 'Basic ' + Buffer.from(`${who.user}:${who.pass}`).toString('base64')
/** The port of the stub, outside the test servers of the Mac app (8790–8799). */
const STUB_PORT = 18731

/** A raw GET of the app API: status, type, body. */
async function raw(who, p) {
	const res = await fetch(`${NC}${API}${p}`, { headers: { 'OCS-APIRequest': 'true', 'Accept-Language': 'en', Authorization: auth(who) } })
	return { status: res.status, type: res.headers.get('content-type') || '', buf: Buffer.from(await res.arrayBuffer()) }
}

// The Mac app's arithmetic (core/src/spesen/tabelle.rs), written once more: halves away from zero on the double.
const half = (y) => {
	const a = Math.abs(y)
	const f = Math.floor(a)
	const r = a - f >= 0.5 ? f + 1 : f
	return y < 0 ? -r : r
}
const round2 = (x) => half(x * 100) / 100 + 0
const to005 = (x) => half(x * 20) / 20 + 0
const round6 = (x) => half(x * 1e6) / 1e6 + 0

function gross(r) {
	if (Array.isArray(r.vat_lines) && r.vat_lines.length > 0) {
		return round2(r.vat_lines.reduce((s, l) => s + (l.net + l.vat), 0))
	}
	return r.gross
}
function inTeam(r, team) {
	const g = gross(r)
	const c = r.currency && r.currency.trim() !== '' ? r.currency : team
	if (c.toUpperCase() === team) {
		return { amount: g, rate: 1, source: 'team', date: null }
	}
	if (r.booked && r.booked.currency.toUpperCase() === team && r.booked.gross > 0) {
		return { amount: r.booked.gross, rate: Math.abs(g) > 0.004 ? half(r.booked.gross / g * 1e6) / 1e6 : null, source: 'booked', date: null }
	}
	if (r.rate && r.rate.value > 0) {
		return { amount: round2(g * r.rate.value), rate: r.rate.value, source: r.rate.source || 'manual', date: r.rate.date || null }
	}
	return null
}
/** The frozen line (9 D) of a receipt as the server stores it. */
function line(key, r, team) {
	const factor = r.factor > 0 ? r.factor : 1
	const t = inTeam(r, team)
	const own = Array.isArray(r.vat_lines) && r.vat_lines.length > 0
		? r.vat_lines.filter((l) => Math.abs(l.vat) > 0.004).map((l) => [l.rate, l.vat])
		: (r.vat_rate !== null && r.vat_rate !== undefined && Math.abs(r.vat) > 0.004 ? [[r.vat_rate, r.vat]] : [])
	const vatLines = Array.isArray(r.vat_lines) && r.vat_lines.length > 0 ? r.vat_lines
		: (r.vat_rate !== null && r.vat_rate !== undefined ? [{ rate: r.vat_rate, net: r.net, vat: r.vat }] : [])
	return {
		receipt: key, date: r.date, supplier: r.supplier || '', text: r.text || '', project: r.project || null,
		charged: !!r.project && (r.invoice_effect ?? 'charge') === 'charge', gross: gross(r), currency: r.currency || team,
		vat_lines: vatLines, category: r.category || null, factor,
		rate: t === null || t.rate === null ? null : { value: t.rate, source: t.source, date: t.date },
		amount: t === null ? null : round2(t.amount * factor),
		vat_amounts: t !== null && t.source === 'team' ? own.map(([rate, v]) => ({ rate, vat: v * factor })) : [],
		without_file: r.kind === 'trust' || r.kind === 'recurring',
	}
}
/** The sums (9 E). */
function sums(lines, team) {
	const cats = []
	const vat = []
	let total = 0
	for (const l of lines) {
		let c = cats.find((x) => x.id === l.category)
		if (!c) {
			c = { id: l.category, amount: 0 }
			cats.push(c)
		}
		c.amount += l.amount
		total += l.amount
		for (const x of l.vat_amounts) {
			const v = vat.find((y) => Math.abs(y.rate - x.rate) < 0.001)
			if (v) {
				v.vat += x.vat
			} else {
				vat.push({ rate: x.rate, vat: x.vat })
			}
		}
	}
	const sorted = vat.map((v) => ({ rate: v.rate, vat: round2(v.vat) })).sort((a, b) => b.rate - a.rate)
	return { currency: team, categories: cats.map((c) => ({ id: c.id, amount: round2(c.amount) })), vat: sorted, total: round2(total), payout: to005(round2(total)) }
}
/** JSON with sorted keys, to compare objects whatever their order. */
const canon = (v) => JSON.stringify(v, (k, x) => (x && typeof x === 'object' && !Array.isArray(x) ? Object.fromEntries(Object.keys(x).sort().map((y) => [y, x[y]])) : x))

/**
 * Forces one run of an app job like lib.mjs's runJob, but without blocking
 * this process: the stub of Frankfurter in it must answer meanwhile.
 */
async function runJob(name) {
	const sh = promisify(exec)
	const q = (a) => `'${String(a).replace(/'/g, `'\\''`)}'`
	const list = (await sh(`${process.env.TS_OCC} ${q('background-job:list')} ${q(`--class=OCA\\TimeSister\\BackgroundJob\\${name}`)} ${q('--output=json')}`)).stdout
	const id = JSON.parse(list)[0]?.id
	if (id !== undefined) {
		await sh(`${process.env.TS_OCC} ${q('background-job:execute')} ${q(String(id))} ${q('--force-execute')}`)
	}
	return id !== undefined
}

/** The app config `exchange_rates_url` through occ; null when unset or without TS_OCC. */
function configValue() {
	try {
		return occ('config:app:get', 'timesister', 'exchange_rates_url')
	} catch {
		// occ answers 1 for a key that is not set.
		return null
	}
}

/** A minimal PDF. */
const pdf = (text) => Buffer.from(`%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n% ${text}\ntrailer << /Root 1 0 R >>\n%%EOF\n`, 'latin1')

/** The stub of Frankfurter: `GET /v1/<day>?base=CHF&symbols=…`; weekends answer the Friday before. */
function stub() {
	const seen = []
	const rates = { EUR: 1.0624, USD: 1.25, GBP: 0.9311 }
	const server = http.createServer((req, res) => {
		const u = new URL(req.url, 'http://stub')
		const m = u.pathname.match(/^\/v1\/(\d{4}-\d{2}-\d{2})$/)
		seen.push({ path: u.pathname, base: u.searchParams.get('base'), symbols: (u.searchParams.get('symbols') || '').split(',').filter(Boolean) })
		if (!m) {
			res.writeHead(404, { 'Content-Type': 'application/json' })
			res.end('{"message":"not found"}')
			return
		}
		const day = new Date(m[1] + 'T00:00:00Z')
		const back = { 0: 2, 6: 1 }[day.getUTCDay()] ?? 0
		const ecb = new Date(day.getTime() - back * 86400000).toISOString().slice(0, 10)
		const want = Object.fromEntries((u.searchParams.get('symbols') || '').split(',').filter((c) => c in rates).map((c) => [c, rates[c]]))
		res.writeHead(200, { 'Content-Type': 'application/json' })
		res.end(JSON.stringify({ amount: 1.0, base: u.searchParams.get('base'), date: ecb, rates: want }))
	})
	return { seen, rates, server }
}

export async function spesen() {
	const PS = `PS-${RUN}`
	const CODE = `S${RUN}`.toUpperCase()
	// Years far from the other parts' (teil-belege: 3013–8012), so no period holds their receipts.
	const Y = 8100 + (parseInt(RUN.slice(-4), 36) % 1800)
	const FOLDER = `TimeSister Test Spesen/${RUN}`
	const K = (n) => `rc-${RUN}s${n}`
	const RULE = `er-${RUN}-sp`
	const records = []
	const tenant = sql("SELECT id FROM oc_ts_tenants WHERE slug = 'pb'")
	const settings = await ocs(A, 'GET', '/records/setting/settings')
	const base = settings.status === 200 ? settings.data.data : {}
	const teamBefore = (await ocs(A, 'GET', '/team')).data
	const docsBefore = teamBefore?.settings?.documents || { root: null, chain_mail: null }
	const owner = teamBefore?.backup_owner || 'pbadmin'
	const ownerDav = { user: owner, pass: `Test-2026-${owner}!` }
	const nameOf = (uid) => (teamBefore?.members || []).find((x) => x.uid === uid)?.display_name || uid
	const safe = (s) => s.replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss').replace(/[^A-Za-z0-9.-]+/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '').slice(0, 40)
	const davGet = (p) => dav(ownerDav, 'GET', `files/${encodeURIComponent(owner)}/${p.split('/').map(encodeURIComponent).join('/')}`)
	const get = async (kind, key) => (await ocs(A, 'GET', rec(kind, key))).data
	const put = async (who, kind, key, data) => {
		const cur = await ocs(A, 'GET', rec(kind, key))
		return ocs(who, 'PUT', rec(kind, key), { version: cur.status === 200 ? cur.data.version : 0, data })
	}
	const receipt = (over = {}) => ({
		schema: 1, kind: 'trust', date: `${Y}-05-02`, supplier: 'Bahn Muster', text: 'Basel–Bern', net: 100, vat_rate: 8.1, vat: 8.1, gross: 108.1, currency: 'CHF',
		project: null, budget: null, cost: null, paid_by: 'person', person: 'pbuser2', reimburse: true, note: null, ...over,
	})
	const newReceipt = async (who, n, over) => {
		records.push(['receipt', K(n)])
		return ocs(who, 'PUT', rec('receipt', K(n)), { version: 0, data: receipt(over) })
	}
	let stubbed = null
	let configured = false
	/** The exchange rates of this run: removed with their history (their keys are days, not the run's). */
	const rates = []
	const forget = () => {
		if (tenant !== null && rates.length > 0) {
			const keys = rates.map((k) => `'${k}'`).join(', ')
			sql(`DELETE FROM oc_ts_records WHERE tenant_id = ${tenant} AND kind = 'exchange_rate' AND rkey IN (${keys})`)
			sql(`DELETE FROM oc_ts_history WHERE tenant_id = ${tenant} AND kind = 'exchange_rate' AND rkey IN (${keys})`)
		}
	}
	try {
		head('Expenses 0.30.0: setup')
		{
			const cap = (await ocs(A, 'GET', '/ocs/v2.php/cloud/capabilities')).data?.capabilities?.timesister
			check('capability expense_table: 2 (0.31.0)', cap?.expense_table === 2 && cap?.version === '0.31.0', cap)
			expect('documents.root for the test', await ocs(A, 'PUT', '/team/settings', { documents: { root: FOLDER } }), 200)
			expect('settings: costs on, CHF, an own expense number', await ocs(A, 'PUT', '/records/setting/settings', { version: settings.status === 200 ? settings.data.version : 0, data: { ...base,
				currency: 'CHF', modules: { ...(base.modules || {}), costs: true }, expense_number: { pattern: `SQ-${RUN}-{no:4}`, per_year: true, start: 1 } } }), 200)
			expect('a project led by pblead', await ocs(A, 'PUT', rec('project', PS), { version: 0, data: { schema: 1, id: PS, name: `Spesen ${RUN}`, codes: [CODE], status: 'active', leads: ['pblead'],
				budgets: [{ id: 'b1', name: 'Phase 1', currency: 'CHF', costs: [{ no: 1, name: 'Spesen', kind: 'expense', rule: 'receipts', cap: 1500, mode: 'rebill' }] }] } }), 200)
		}

		head('Expenses 0.30.0: the fields of a receipt (A)')
		{
			for (const [name, over, message] of [
				['a category in capitals', { category: 'Mobility' }, '“category” must be null or a key of 1 to 40 lower-case letters, digits or dashes.'],
				['a template with a space', { template: 'km car' }, '“template” must be null or a key of 1 to 40 lower-case letters, digits or dashes.'],
				['a factor of 0', { factor: 0 }, '“factor” must be null or a number above 0 and at most 100.'],
				['a factor above 100', { factor: 101 }, '“factor” must be null or a number above 0 and at most 100.'],
				['a quantity as text', { quantity: '160' }, '“quantity” must be null or a number above 0 and at most 1000000.'],
				['a rate from the bank', { rate: { value: 0.94, date: `${Y}-05-02`, source: 'bank' } }, '“rate” must be null or { “value” above 0 and below 1000000, “date” as YYYY-MM-DD, “source” “ecb” or “manual” }.'],
				['a rate without a day', { rate: { value: 0.94, date: '', source: 'manual' } }, '“rate” must be null or { “value” above 0 and below 1000000, “date” as YYYY-MM-DD, “source” “ecb” or “manual” }.'],
			]) {
				const r = await ocs(U2, 'PUT', rec('receipt', K('x')), { version: 0, data: receipt(over) })
				check(`${name} → 422 naming the field`, r.status === 422 && r.data?.error === 'invalid' && r.data?.message === message, r.data)
			}
			const w = await newReceipt(U2, 0, { kind: 'trust', date: `${Y}-05-01`, supplier: 'Garage', text: '160 km × 0.70', net: 112, vat_rate: null, vat: 0, gross: 112,
				category: 'mobility', factor: 1, template: 'km-own-car', quantity: 160, rate: null })
			check('the five fields are stored as sent', w.status === 200 && w.data?.data?.category === 'mobility' && w.data?.data?.factor === 1 && w.data?.data?.template === 'km-own-car'
				&& w.data?.data?.quantity === 160 && w.data?.data?.rate === null && w.data?.data?.status === 'open', w.data)
		}

		head('Expenses 0.30.0: who sets category, factor and rate (B 1)')
		const R = {}
		{
			// S1: a project receipt the member records (open, charged); S3: a general one the Team Admin approves.
			R[1] = receipt({ kind: 'trust', date: `${Y}-05-02`, project: PS, category: 'travel' })
			expect('S1 by the person, for the project', await newReceipt(U2, 1, R[1]), 200)
			const lead = await ocs(L, 'PUT', rec('receipt', K(1)), { version: 1, data: { ...R[1], category: 'food' } })
			check('the project’s Lead sets the category of another’s expense → 403', lead.status === 403 && lead.data?.message === 'Only the person and Team Admins set category, factor and rate of an expense.', lead.data)
			R[3] = receipt({ kind: 'trust', date: `${Y}-05-03`, supplier: 'Cloud Ltd', text: 'Storage', net: 50, vat_rate: null, vat: 0, gross: 50, currency: 'USD',
				booked: { gross: 43.17, currency: 'CHF' }, category: 'it' })
			const s3 = await newReceipt(A, 3, R[3])
			check('S3 by a Team Admin: approved by the write (internal)', s3.status === 200 && s3.data?.data?.status === 'internal', s3.data)
			R[3] = { ...R[3], factor: 0.5, category: 'office' }
			const own = await ocs(U2, 'PUT', rec('receipt', K(3)), { version: s3.data?.version, data: { ...s3.data?.data, factor: 0.5, category: 'office' } })
			check('the person sets factor and category after the approval', own.status === 200 && own.data?.data?.factor === 0.5 && own.data?.data?.category === 'office' && own.data?.data?.status === 'internal', own.data)
			const amount = await ocs(U2, 'PUT', rec('receipt', K(3)), { version: own.data?.version, data: { ...own.data?.data, gross: 60, net: 60 } })
			check('… but not the amount → 403', amount.status === 403, amount.data)
		}

		head('Expenses 0.30.0: settling a period (C, D, E)')
		let SB = null
		let NUM = null
		let fixed = null
		{
			// S2: EUR at an ECB rate, half of it; S4: two VAT rates, half; S5: a rule's receipt; S6: EUR without rate first.
			R[2] = receipt({ date: `${Y}-05-03`, supplier: 'Hotel', text: 'Night', net: 84.03, vat_rate: 19, vat: 15.97, gross: 100, currency: 'EUR', factor: 0.5, category: 'misc',
				rate: { value: 0.95, date: `${Y}-05-03`, source: 'ecb' } })
			R[4] = receipt({ date: `${Y}-05-04`, supplier: 'Market', text: 'Food and paper', net: 100, vat_rate: null, vat: 5.35, gross: 105.35, factor: 0.5,
				vat_lines: [{ rate: 2.6, net: 50, vat: 1.3 }, { rate: 8.1, net: 50, vat: 4.05 }] })
			R[6] = receipt({ date: `${Y}-05-06`, supplier: 'Bistro', text: 'Lunch', net: 20, vat_rate: null, vat: 0, gross: 20, currency: 'EUR', category: 'misc' })
			for (const n of [2, 4, 6]) {
				expect(`S${n} by the person`, await newReceipt(U2, n, R[n]), 200)
			}
			const rule = await ocs(U2, 'PUT', rec('expense_rule', RULE), { version: 0, data: { schema: 1, person: 'pbuser2', text: 'Phone', amount: 30, vat_rate: null, period: 'monthly',
				from: `${Y}-05-01`, to: null, project: null, budget: null, cost: null } })
			records.push(['expense_rule', RULE])
			check('a rule of the person, without approval', rule.status === 200 && rule.data?.data?.approved_by === null, rule.data)
			R[5] = receipt({ kind: 'recurring', rule: RULE, period: `${Y}-05`, date: `${Y}-05-01`, supplier: '', text: 'Phone', net: 30, vat_rate: null, vat: 0, gross: 30, category: 'phone', factor: 0.5 })
			const s5 = await newReceipt(U2, 5, R[5])
			check('its receipt for May: internal at once', s5.status === 200 && s5.data?.data?.status === 'internal' && s5.data?.data?.approved_by === 'pbuser2', s5.data)
			// Not settled: a later month, a rejected one, one the company paid.
			expect('S7 in June', await newReceipt(U2, 7, receipt({ date: `${Y}-06-01`, category: 'travel' })), 200)
			expect('S8, to be rejected', await newReceipt(U2, 8, receipt({ date: `${Y}-05-08`, category: 'travel' })), 200)
			expect('… rejected by a Team Admin', await ocs(A, 'POST', `/receipts/${K(8)}/reject`, { reason: 'Twice' }), 200)
			expect('S9 the company paid', await newReceipt(A, 9, receipt({ date: `${Y}-05-09`, paid_by: 'company', person: null, reimburse: false, category: 'travel' })), 200)

			const period = { person: 'pbuser2', from: `${Y}-05-01`, to: `${Y}-05-31` }
			expect('another member settles for pbuser2', await ocs(U1, 'POST', '/expense-reports/fix', period), 403, 'forbidden')
			expect('the Lead settles for pbuser2', await ocs(L, 'POST', '/expense-reports/fix', period), 403, 'forbidden')
			const bad = await ocs(U2, 'POST', '/expense-reports/fix', { ...period, from: `${Y}-06-01` })
			check('“from” after “to” → 422', bad.status === 422 && bad.data?.message === '“from” and “to” are days (YYYY-MM-DD), “from” not after “to”.', bad.data)
			expect('a day that is none', await ocs(U2, 'POST', '/expense-reports/fix', { ...period, to: `${Y}-05-32` }), 422, 'invalid')
			const none = await ocs(U2, 'POST', '/expense-reports/fix', { ...period, from: `${Y}-04-01`, to: `${Y}-04-30` })
			check('an empty period → 422', none.status === 422 && none.data?.message === 'No open expenses of this person in this period.', none.data)
			const noCat = await ocs(U2, 'POST', '/expense-reports/fix', period)
			check('the first expense by date and key without a category → 422', noCat.status === 422 && noCat.data?.message === `The expense ${K(4)} of ${Y}-05-04 has no category.`, noCat.data)
			R[4] = { ...R[4], category: 'food' }
			expect('S4 gets its category', await put(U2, 'receipt', K(4), { ...(await get('receipt', K(4))).data, category: 'food' }), 200)
			const noRate = await ocs(U2, 'POST', '/expense-reports/fix', period)
			check('… then the first without an amount in CHF → 422', noRate.status === 422 && noRate.data?.message === `The expense ${K(6)} of ${Y}-05-06 has no rate to CHF.`, noRate.data)
			R[6] = { ...R[6], rate: { value: 0.9412, date: `${Y}-05-06`, source: 'manual' } }
			expect('S6 gets a manual rate', await put(U2, 'receipt', K(6), { ...(await get('receipt', K(6))).data, rate: R[6].rate }), 200)

			const fx = await ocs(U2, 'POST', '/expense-reports/fix', period)
			fixed = fx.data?.data
			SB = fx.data?.key
			NUM = fixed?.number ?? null
			if (SB) {
				records.push(['expense_report', SB])
			}
			const today = new Date().toISOString().slice(0, 10)
			const order = [0, 5, 1, 2, 3, 4, 6]
			check('the person settles May: a new record, fixed, numbered, the period, who and when', fx.status === 200 && /^sb-[0-9a-f]{8}$/.test(SB ?? '')
				&& new RegExp(`^SQ-${RUN}-\\d{4}$`).test(NUM ?? '') && fixed?.status === 'fixed' && fixed?.from === period.from && fixed?.to === period.to
				&& fixed?.fixed_by === 'pbuser2' && fixed?.fixed_on === today && JSON.stringify(fixed?.documents) === '[]' && fixed?.document_state === undefined, fx.data)
			check('… the lines by date and key: S0, S5, S1, S2, S3, S4, S6 (not June, rejected or the company’s)', JSON.stringify((fixed?.lines || []).map((l) => l.receipt)) === JSON.stringify(order.map(K)), (fixed?.lines || []).map((l) => l.receipt))
			const want = order.map((n) => line(K(n), n === 0 ? receipt({ kind: 'trust', date: `${Y}-05-01`, supplier: 'Garage', text: '160 km × 0.70', net: 112, vat_rate: null, vat: 0, gross: 112,
				category: 'mobility', factor: 1, template: 'km-own-car', quantity: 160, rate: null }) : R[n], 'CHF'))
			check('… every line as the Mac app computes it (9 D)', canon(fixed?.lines) === canon(want), { got: fixed?.lines, want })
			check('… S2: 100 EUR × 0.95 × 0.5 = 47.50, no input tax in EUR', fixed?.lines?.[3]?.amount === 47.5 && canon(fixed?.lines?.[3]?.rate) === canon({ value: 0.95, source: 'ecb', date: `${Y}-05-03` })
				&& JSON.stringify(fixed?.lines?.[3]?.vat_amounts) === '[]', fixed?.lines?.[3])
			check('… S3: booked 43.17 CHF for 50 USD, rate 0.8634, half of it', fixed?.lines?.[4]?.amount === 21.59 && fixed?.lines?.[4]?.rate?.value === 0.8634 && fixed?.lines?.[4]?.rate?.source === 'booked', fixed?.lines?.[4])
			check('… S4: two rates, VAT per line unrounded', fixed?.lines?.[5]?.amount === 52.68 && JSON.stringify(fixed?.lines?.[5]?.vat_amounts) === '[{"rate":2.6,"vat":0.65},{"rate":8.1,"vat":2.025}]', fixed?.lines?.[5])
			check('… S1 charged to its project, S5 without file', fixed?.lines?.[2]?.charged === true && fixed?.lines?.[2]?.project === PS && fixed?.lines?.[1]?.without_file === true && fixed?.lines?.[1]?.amount === 15, fixed?.lines)
			const wantSums = sums(want, 'CHF')
			check('the sums as the Mac app computes them (9 E)', canon(fixed?.sums) === canon(wantSums), { got: fixed?.sums, want: wantSums })
			check('… per category in the order of first mention, VAT per rate highest first, payout on 0.05', JSON.stringify((fixed?.sums?.categories || []).map((c) => c.id)) === '["mobility","phone","travel","misc","office","food"]'
				&& JSON.stringify(fixed?.sums?.vat) === '[{"rate":8.1,"vat":10.13},{"rate":2.6,"vat":0.65}]' && fixed?.sums?.total === 375.69 && fixed?.sums?.payout === 375.7, fixed?.sums)
			const marks = await Promise.all(order.map(async (n) => (await get('receipt', K(n)))?.data?.report))
			check('every receipt notes the report; June and the rejected one do not', marks.every((x) => x === SB) && (await get('receipt', K(7)))?.data?.report === null
				&& (await get('receipt', K(8)))?.data?.report === null, marks)
			const led = await ocs(A, 'GET', `/invoices/ledger?since=${(fixed?.ledger_seq ?? 1) - 1}`)
			const ef = led.data?.entries?.[0]
			const payload = JSON.parse(ef?.payload ?? '{}')
			check('ledger expense_fixed: { key, number, person, from, to, fixed_on, fixed_by, lines, sums }, keys sorted, hashed', ef?.action === 'expense_fixed' && ef?.hash === fixed?.hash && sha256(ef?.payload ?? '') === ef?.payload_hash
				&& JSON.stringify(Object.keys(payload)) === '["fixed_by","fixed_on","from","key","lines","number","person","sums","to"]'
				&& canon(payload) === canon({ key: SB, number: NUM, person: 'pbuser2', from: period.from, to: period.to, fixed_on: today, fixed_by: 'pbuser2', lines: fixed?.lines, sums: fixed?.sums }), ef)
			const june = await ocs(A, 'POST', '/expense-reports/fix', { person: 'pbuser2', from: `${Y}-06-01`, to: `${Y}-06-30` })
			if (june.data?.key) {
				records.push(['expense_report', june.data.key])
			}
			check('a Team Admin settles June for the person: the next number', june.status === 200 && june.data?.data?.fixed_by === 'pbadmin' && june.data?.data?.lines?.length === 1
				&& june.data?.data?.number === `SQ-${RUN}-${String(Number(NUM?.slice(-4)) + 1).padStart(4, '0')}`, june.data)
		}

		head('Expenses 0.30.0: a settled receipt (B 2)')
		{
			const c1 = await get('receipt', K(1))
			for (const [field, over] of [['category', { category: 'food' }], ['factor', { factor: 0.5 }], ['net', { net: 200, vat: 16.2, gross: 216.2 }], ['text', { text: 'x' }], ['date', { date: `${Y}-05-09` }]]) {
				const r = await ocs(A, 'PUT', rec('receipt', K(1)), { version: c1.version, data: { ...c1.data, ...over } })
				check(`“${field}” stays → 409 expense_settled, field`, r.status === 409 && r.data?.message === `This expense is settled; reverse the expense report first. “${field}” stays.` && r.data?.code === 'expense_settled' && r.data?.field === field, r.data)
			}
			const note = await ocs(U2, 'PUT', rec('receipt', K(1)), { version: c1.version, data: { ...c1.data, note: 'mine' } })
			check('the person changes the note → 403', note.status === 403 && note.data?.message === 'Only Team Admins and the project’s Leads change a settled expense.' && note.data?.code === 'expense_settled_lead_only', note.data)
			const lead = await ocs(L, 'PUT', rec('receipt', K(1)), { version: c1.version, data: { ...c1.data, budget: 'b1', cost: 1, note: 'assigned' } })
			check('the project’s Lead assigns the open, settled receipt (a trust one stays open for a Team Admin)', lead.status === 200 && lead.data?.data?.status === 'open' && lead.data?.data?.cost === 1
				&& lead.data?.data?.note === 'assigned' && lead.data?.data?.report === SB, lead.data)
			const adm = await ocs(A, 'POST', `/receipts/${K(1)}/approve`, {})
			check('… a Team Admin approves it; it stays settled', adm.status === 200 && adm.data?.data?.status === 'approved' && adm.data?.data?.report === SB, adm.data)
			const del = await ocs(A, 'DELETE', `${rec('receipt', K(2))}?version=${(await get('receipt', K(2))).version}`)
			check('a settled open receipt is not deleted → 409 expense_settled', del.status === 409 && del.data?.message === 'This expense is settled; reverse the expense report first.' && del.data?.code === 'expense_settled', del.data)
			const rj = await ocs(A, 'POST', `/receipts/${K(2)}/reject`, { reason: 'No' })
			check('… nor rejected → 409 expense_settled', rj.status === 409 && rj.data?.message === 'This expense is settled; reverse the expense report first.' && rj.data?.code === 'expense_settled', rj.data)
			expect('… nor reopened', await ocs(A, 'POST', `/receipts/${K(3)}/reopen`, {}), 409, 'conflict')
		}

		head('Expenses 0.30.0: the documents of the app (F)')
		{
			const ty = (fixed?.fixed_on || '').slice(0, 4)
			const dir = `${FOLDER}/Buchhaltung/${ty}/Spesen/${safe(nameOf('pbuser2'))}`
			const name = `Spesenabrechnung_${NUM}.pdf`
			const body = pdf(name)
			const send = (who, n, content) => ocs(who, 'POST', `/expense-reports/${SB}/documents`, { name: n, content: typeof content === 'string' ? content : content.toString('base64') })
			expect('another member stores one', await send(U1, name, body), 403, 'forbidden')
			expect('the Lead stores one', await send(L, name, body), 403, 'forbidden')
			for (const [what, n, content, message] of [
				['a name with a slash', 'a/b.pdf', body, '“name” is a file name ending in .pdf or .csv.'],
				['a ZIP', 'Spesen.zip', body, '“name” is a file name ending in .pdf or .csv.'],
				['no Base64', name, '%%%', '“content” is the file in Base64.'],
				['a PDF that is none', name, Buffer.from('hello'), 'The file does not fit its name (PDF or UTF-8 CSV, at most 32 MiB).'],
				['a CSV that is no UTF-8', `Spesenabrechnung_${NUM}.csv`, Buffer.from([0xff, 0xfe, 0x41]), 'The file does not fit its name (PDF or UTF-8 CSV, at most 32 MiB).'],
			]) {
				// As the Team Admin: the route takes 10 per account and minute (0.31.0), the person's calls below stay under it.
				const r = await send(A, n, content)
				check(`${what} → 422`, r.status === 422 && r.data?.message === message, r.data)
			}
			const up = await send(U2, name, body)
			const path = `${dir}/${name}`
			check('the person stores the PDF: the record with documents [{ name, path, sha256, size }]', up.status === 200 && up.data?.key === SB
				&& JSON.stringify(up.data?.data?.documents) === JSON.stringify([{ name, path, sha256: sha256(body), size: body.length }]) && (await davGet(path)).status === 200, up.data)
			const led = (await ocs(A, 'GET', `/invoices/ledger?since=${(fixed?.ledger_seq ?? 1) - 1}`)).data?.entries || []
			const ed = led.find((e) => e.action === 'expense_document')
			check('ledger expense_document { key, number, name, sha256 }', ed?.number === NUM && ed?.payload === JSON.stringify({ key: SB, name, number: NUM, sha256: sha256(body) }), ed)
			const again = await send(U2, name, body)
			const count = ((await ocs(A, 'GET', `/invoices/ledger?since=${(fixed?.ledger_seq ?? 1) - 1}`)).data?.entries || []).length
			check('the same file again: 200, unchanged, no ledger entry', again.status === 200 && again.data?.version === up.data?.version && count === led.length, [again.status, again.data?.version, up.data?.version, count, led.length])
			const other = await send(U2, name, pdf('other'))
			check('the same name with other content → 409 report_document_exists', other.status === 409 && other.data?.message === 'A document of this name is stored already.' && other.data?.code === 'report_document_exists', other.data)
			const csv = Buffer.from(`Nr.;Datum;Was\r\n1;${Y}-05-01;160 km × 0.70\r\n`, 'utf8')
			expect('a Team Admin stores the CSV', await send(A, `Spesenabrechnung_${NUM}.csv`, csv), 200)
			// 0.31.0: the raw form – the body is the file, the name in the query.
			const sendRaw = (who, n, buf, type) => fetch(`${NC}${API}/expense-reports/${SB}/documents?name=${encodeURIComponent(n)}`, {
				method: 'POST', body: buf,
				headers: { 'OCS-APIRequest': 'true', Accept: 'application/json', 'Content-Type': type, Authorization: 'Basic ' + Buffer.from(`${who.user}:${who.pass}`).toString('base64') },
			}).then(async (res) => { const text = await res.text(); let j = null; try { j = JSON.parse(text) } catch { j = null } return { status: res.status, data: j?.ocs?.data ?? null, text } })
			const belege = pdf('Belege')
			const rawUp = await sendRaw(U2, `Belege_${NUM}.pdf`, belege, 'application/pdf')
			check('… and the receipts as PDF, raw with ?name= (0.31.0)', rawUp.status === 200 && (rawUp.data?.data?.documents || []).some((d) => d.name === `Belege_${NUM}.pdf` && d.sha256 === sha256(belege) && d.size === belege.length), rawUp.data)
			const rawBad = await sendRaw(U2, `z_${NUM}.pdf`, Buffer.from('hello'), 'application/octet-stream')
			check('raw: a PDF that is none → 422 with field content', rawBad.status === 422 && rawBad.data?.field === 'content' && rawBad.data?.code === undefined, rawBad.data)
			const rawName = await sendRaw(U2, 'a/b.pdf', belege, 'application/pdf')
			check('raw: a bad name → 422 with field name', rawName.status === 422 && rawName.data?.field === 'name', rawName.data)
			const byName = await raw(U2, `/expense-reports/${SB}/document?name=${encodeURIComponent(`Spesenabrechnung_${NUM}.csv`)}`)
			check('GET by name: the CSV as text/csv', byName.status === 200 && byName.buf.equals(csv) && byName.type.startsWith('text/csv'), [byName.status, byName.type])
			const first = await raw(A, `/expense-reports/${SB}/document`)
			check('GET without a name: the first PDF', first.status === 200 && first.buf.equals(body) && first.type.startsWith('application/pdf'), [first.status, first.type])
			const missing = await raw(U2, `/expense-reports/${SB}/document?name=nope.pdf`)
			check('GET of a name not stored → 404', missing.status === 404, missing.status)
			expect('another member reads one', await raw(U1, `/expense-reports/${SB}/document`).then((x) => ({ status: x.status, data: null, text: '' })), 403)
			expect('a fourth', await send(U2, `x4_${NUM}.pdf`, pdf('4')), 200)
			expect('a fifth', await send(U2, `x5_${NUM}.pdf`, pdf('5')), 200)
			const sixth = await send(U2, `x6_${NUM}.pdf`, pdf('6'))
			check('a sixth → 409 report_documents_max', sixth.status === 409 && sixth.data?.message === 'An expense report holds at most 5 documents.' && sixth.data?.code === 'report_documents_max', sixth.data)
		}

		head('Expenses 0.30.0: what is gone, what stays (G)')
		{
			// 0.31.0: the removed routes answer 410 gone with the code, so an older app learns the server is newer.
			const ready = await ocs(U2, 'POST', '/expense-reports/ready', { person: 'pbuser2' })
			check('POST /expense-reports/ready → 410 gone', ready.status === 410 && ready.data?.error === 'gone' && ready.data?.code === 'gone', ready.data)
			expect('POST /expense-reports/{key}/paid', await ocs(A, 'POST', `/expense-reports/${SB}/paid`, {}), 410, 'gone')
			expect('POST /expense-rules/{key}/approve', await ocs(A, 'POST', `/expense-rules/${RULE}/approve`, {}), 410, 'gone')
			const del = await ocs(U2, 'DELETE', `${rec('expense_rule', RULE)}?version=${(await get('expense_rule', RULE)).version}`)
			check('a rule with a receipt is not deleted → 409', del.status === 409 && del.data?.message === 'An expense rule with receipts is not deleted; end it by setting “to”.' && del.data?.code === 'rule_has_receipts', del.data)
			const cur = await get('expense_report', SB)
			const w = await ocs(A, 'PUT', rec('expense_report', SB), { version: cur.version, data: { ...cur.data, number: 'X' } })
			check('a client writes no report → 409', w.status === 409 && w.data?.message === 'An expense report changes only through its actions (fix, documents, reverse).' && w.data?.code === 'report_not_writable', w.data)
			expect('the person reverses', await ocs(U2, 'POST', `/expense-reports/${SB}/reverse`, {}), 403, 'forbidden')
			const rv = await ocs(A, 'POST', `/expense-reports/${SB}/reverse`, { reason: 'Test' })
			check('a Team Admin reverses: reversed, the documents stay, the receipts are open again', rv.status === 200 && rv.data?.data?.status === 'reversed' && rv.data?.data?.documents?.length === 5
				&& (await get('receipt', K(2)))?.data?.report === null, rv.data)
			const c2 = await get('receipt', K(2))
			expect('… and their expense fields change again', await ocs(U2, 'PUT', rec('receipt', K(2)), { version: c2.version, data: { ...c2.data, factor: 1 } }), 200)
			const rd = await ocs(A, 'POST', `/expense-reports/${SB}/documents`, { name: `y_${NUM}.pdf`, content: pdf('y').toString('base64') })
			check('documents for a reversed report → 409 report_not_fixed', rd.status === 409 && rd.data?.code === 'report_not_fixed', rd.data)
		}

		head('Expenses 0.30.0: ECB rates (H)')
		{
			expect('exchange_rates must be a boolean', await put(A, 'setting', 'settings', { ...(await get('setting', 'settings')).data, exchange_rates: 'yes' }), 422, 'invalid')
			for (const [what, r] of [
				['PUT', await ocs(A, 'PUT', rec('exchange_rate', `CHF-${Y}-05-01`), { version: 0, data: { base: 'CHF', date: `${Y}-05-01`, ecb_date: `${Y}-05-01`, rates: { EUR: 0.9 } } })],
				['a batch', await ocs(A, 'POST', '/records/batch', { writes: [{ kind: 'exchange_rate', key: `CHF-${Y}-05-01`, version: 0, data: { base: 'CHF', date: `${Y}-05-01`, ecb_date: `${Y}-05-01`, rates: { EUR: 0.9 } } }] })],
				['a member’s PUT', await ocs(U1, 'PUT', rec('exchange_rate', `CHF-${Y}-05-01`), { version: 0, data: {} })],
			]) {
				check(`a client writes an exchange rate (${what}) → 403`, r.status === 403 && String(r.data?.message || '').includes('Exchange rates are written by the server only.'), r.data)
			}
			if (!process.env.TS_OCC) {
				console.log('    info  TS_OCC not set – the job with the stub of Frankfurter is skipped')
			} else {
				stubbed = stub()
				await new Promise((ok) => stubbed.server.listen(STUB_PORT, '::', ok))
				// The stub first: with the switch on, nothing may reach the real service.
				occ('config:app:set', 'timesister', 'exchange_rates_url', `--value=http://host.docker.internal:${STUB_PORT}`)
				configured = true
				const day = (back) => new Date(Date.now() - back * 86400000).toISOString().slice(0, 10)
				// Days of this run (fake rates of the stub; with TS_SQL they go again, records and history).
				const ago = 2 + (parseInt(RUN.slice(-5), 36) % 300)
				const [d1, d2] = [day(ago), day(ago + 7)]
				rates.push(`CHF-${d1}`, `CHF-${d2}`)
				forget()
				const recent = (n, over) => newReceipt(U2, n, receipt({ date: d1, currency: 'EUR', net: 50, vat_rate: null, vat: 0, gross: 50, category: 'misc', ...over }))
				expect('a EUR expense of a past day without rate', await recent(10, {}), 200)
				expect('a USD one a week earlier', await recent(11, { date: d2, currency: 'USD' }), 200)
				expect('a EUR one with its own rate (not asked)', await recent(12, { date: d2, rate: { value: 0.93, date: d2, source: 'manual' } }), 200)
				expect('one of today (the ECB has not published yet)', await recent(13, { date: day(0), currency: 'GBP' }), 200)
				const on = await put(A, 'setting', 'settings', { ...(await get('setting', 'settings')).data, exchange_rates: true })
				check('the Team Admin switches the rates on', on.status === 200 && on.data?.data?.exchange_rates === true, on.data)
				await runJob('ExchangeRates')
				const asked = stubbed.seen.filter((s) => s.path === `/v1/${d1}` || s.path === `/v1/${d2}`)
				check('the job asked the stub for both days, base CHF, only the currencies needed', asked.length >= 2 && asked.every((s) => s.base === 'CHF')
					&& asked.some((s) => s.path === `/v1/${d1}` && s.symbols.join() === 'EUR') && asked.some((s) => s.path === `/v1/${d2}` && s.symbols.join() === 'USD')
					&& !stubbed.seen.some((s) => s.path === `/v1/${day(0)}`), stubbed.seen)
				for (const [d, cur] of [[d1, 'EUR'], [d2, 'USD']]) {
					const r = await ocs(U1, 'GET', rec('exchange_rate', `CHF-${d}`))
					const back = { 0: 2, 6: 1 }[new Date(d + 'T00:00:00Z').getUTCDay()] ?? 0
					const ecb = new Date(new Date(d + 'T00:00:00Z').getTime() - back * 86400000).toISOString().slice(0, 10)
					check(`a member reads CHF-${d}: CHF per one ${cur} on six places, the ECB day`, r.status === 200 && r.data?.modified_by === 'system'
						&& canon(r.data?.data) === canon({ base: 'CHF', date: d, ecb_date: ecb, rates: { [cur]: round6(1 / stubbed.rates[cur]) } }), r.data)
				}
				const before = stubbed.seen.length
				await runJob('ExchangeRates')
				check('a second run asks nothing for these days', !stubbed.seen.slice(before).some((s) => s.path === `/v1/${d1}` || s.path === `/v1/${d2}`), stubbed.seen.slice(before))
				expect('a GBP expense on the first day', await recent(14, { currency: 'GBP' }), 200)
				await runJob('ExchangeRates')
				const more = (await ocs(U2, 'GET', rec('exchange_rate', `CHF-${d1}`))).data?.data
				check('… the day is fetched again with every currency it needs', stubbed.seen.slice(before).some((s) => s.path === `/v1/${d1}` && s.symbols.join() === 'EUR,GBP')
					&& canon(more?.rates) === canon({ EUR: round6(1 / stubbed.rates.EUR), GBP: round6(1 / stubbed.rates.GBP) }), [stubbed.seen.slice(before), more])
				// The web server caches the app config for three seconds: until then it may still name the default.
				let md = await raw(A, '/team/privacy?format=md')
				for (let i = 0; i < 8 && !md.buf.toString('utf8').includes('host.docker.internal'); i++) {
					await new Promise((ok) => setTimeout(ok, 1000))
					md = await raw(A, '/team/privacy?format=md')
				}
				const text = md.buf.toString('utf8')
				check('the privacy notice names the service, the currencies and the days', md.status === 200 && (text.includes('Exchange rates (switched on by the team)') || text.includes('Wechselkurse (vom Team eingeschaltet)')) && text.includes('host.docker.internal')
					&& /EUR, [A-Z, ]*GBP/.test(text), text.slice(text.indexOf('Exchange rates'), text.indexOf('Exchange rates') + 400))
			}
		}
	} finally {
		head('Expenses 0.30.0: cleaning up')
		// The switch off first, then the stub's address: nothing ever reaches the real service.
		const cur = await ocs(A, 'GET', '/records/setting/settings')
		if (settings.status === 200 && cur.status === 200) {
			await ocs(A, 'PUT', '/records/setting/settings', { version: cur.data.version, data: settings.data.data })
		}
		if (configured) {
			occ('config:app:delete', 'timesister', 'exchange_rates_url')
		}
		if (stubbed) {
			stubbed.server.close()
		}
		await ocs(A, 'PUT', '/team/settings', { documents: { root: docsBefore.root ?? null, chain_mail: docsBefore.chain_mail ?? null } })
		if (tenant !== null) {
			const keys = records.map(([, k]) => `'${k}'`).join(', ')
			sql(`DELETE FROM oc_ts_records WHERE tenant_id = ${tenant} AND rkey IN (${keys})`)
		}
		forget()
		const p = await ocs(A, 'GET', rec('project', PS))
		if (p.status === 200) {
			await ocs(A, 'DELETE', `${rec('project', PS)}?version=${p.data.version}`)
		}
		await dav(ownerDav, 'DELETE', `files/${encodeURIComponent(owner)}/${FOLDER.split('/').map(encodeURIComponent).join('/')}`)
		const after = await ocs(A, 'GET', '/records/setting/settings')
		check('pb left as found (settings, no exchange rates, app config)', after.data?.data?.exchange_rates === base.exchange_rates && configValue() === null, after.data?.data?.exchange_rates)
	}
}
