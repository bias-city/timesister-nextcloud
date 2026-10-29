// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Cost positions and VAT per line (0.21.0): a draft with lines at 8.1 %
// and 2.6 % and a pass-through item, issued (0.32.0: reservation, the
// app's PDF closes) – sums per rate, pass-through, total in the
// reservation, the record, `fixed` and the ledger; the reversal negates
// everything; CSV and DATEV per rate. What the server's PDF and embedded
// XML showed (both rates, the block, VATEX-EU-79-C) is the Mac app's now.
// Then `costs[]` in a budget: valid, and 422 per field; the module switch.
// Leaves pb as it found it (settings, team settings, project, customer);
// the frozen rows only with TS_SQL, the ledger stays.
import { ocs, sql } from './lib.mjs'
import { A, RUN, check, expect, festschreiben, head, isObj, me, raw, stornieren } from './harness.mjs'

const path = (key) => `/records/invoice/${encodeURIComponent(key)}`
const ipath = (key) => `/invoices/${encodeURIComponent(key)}`
const fetchRaw = (who, p) => raw(who, 'GET', p)
const near = (a, b) => typeof a === 'number' && Math.abs(a - b) < 0.001

export async function kosten() {
	const PF = `PK-${RUN}`
	const CODE = `K${RUN}`.toUpperCase()
	const CUST = `CK-${RUN}`
	const K1 = `inv-${RUN}k1`
	const made = [K1]
	const Y = 3000 + (parseInt(RUN.slice(-4), 36) % 5000) + 11
	const ISSUED_ON = `${Y}-04-15`
	const tenant = sql("SELECT id FROM oc_ts_tenants WHERE slug = 'pb'")
	const settings = await ocs(A, 'GET', '/records/setting/settings')
	const base = settings.status === 200 ? settings.data.data : {}
	let settingsVersion = settings.status === 200 ? settings.data.version : 0
	const teamBefore = (await ocs(A, 'GET', '/team')).data
	const datevBefore = teamBefore?.settings?.datev || null
	const putSettings = async (more) => {
		const r = await ocs(A, 'PUT', '/records/setting/settings', { version: settingsVersion, data: { ...base, ...more } })
		settingsVersion = r.data?.version ?? settingsVersion
		return r
	}
	const issuer = { name: 'Muster sàrl', street: 'Musterstrasse 12', postcode: '4000', city: 'Basel', country: 'CH', vat_id: 'CHE-123.456.789 MWST', email: 'mail@example.test', qr: true, accounts: [{ currency: 'CHF', iban: 'CH9300762011623852957', bic: '' }] }
	const costs = [
		{ no: 1, name: 'Nebenkosten', kind: 'expense', rule: 'percent', percent: 3, mode: 'rebill', vat: 2.6 },
		{ no: 2, name: 'Druck', kind: 'expense', rule: 'flat', amount: 200, per: 'invoice' },
		{ no: 3, name: 'Spesen', kind: 'expense', rule: 'receipts', cap: 1500, mode: 'pass_through', description: 'nach Beleg' },
		{ no: 4, name: 'Gebühren', kind: 'third_party', cap: 5000, mode: 'pass_through' },
	]
	const project = (budgetCosts) => ({ schema: 1, id: PF, name: `Kosten ${RUN}`, codes: [CODE], status: 'active', leads: ['pblead'], customer: CUST, budgets: [{ id: 'b1', name: 'Phase 1', currency: 'CHF', costs: budgetCosts }] })
	const positions = [
		{ kind: 'hours', text: 'Planung', hours: 10, rate: 150, amount: 1500 },
		{ kind: 'expense_flat', text: 'Druckkosten', amount: 200, vat: 2.6, cost: 2 },
		{ kind: 'text', text: 'Hinweis', amount: 0 },
		{ kind: 'costs', text: 'Gebühr Baubewilligung', amount: 350, vat_mode: 'pass_through', cost: 4 },
	]
	const draft = (more = {}) => ({
		schema: 1, project: PF, budget: 'b1', from: `${Y}-04-01`, to: `${Y}-04-30`, status: 'draft', number: null, bookings: [], positions,
		greeting: 'Guten Tag', body: null, footer: null, note: null, created_by: 'pbadmin', created_on: ISSUED_ON, issued_on: null, paid_on: null, ...more,
	})
	try {
		head('Costs: capabilities, module, cost positions')
		{
			const cap = (await ocs(A, 'GET', '/ocs/v2.php/cloud/capabilities')).data?.capabilities?.timesister
			check('capabilities vat_lines: 1 and costs: 1', cap?.vat_lines === 1 && cap?.costs === 1, cap)
			expect('settings with modules.costs true', await putSettings({ language: 'de', vat: 8.1, invoice_number: { pattern: `RK-${RUN}-{no:4}`, per_year: true, start: 1 }, issuer, modules: { ...(base.modules || {}), costs: true } }), 200)
			expect('modules.costs as text', await ocs(A, 'PUT', '/records/setting/settings', { version: settingsVersion, data: { ...base, modules: { costs: 'yes' } } }), 422, 'invalid')
			expect('customer CH', await ocs(A, 'PUT', `/records/customer/${CUST}`, { version: 0, data: { schema: 1, id: CUST, name: `Müller AG ${RUN}`, street: 'Platz 1', postcode: '4001', city: 'Basel', country: 'CH' } }), 200)
			const p = await ocs(A, 'PUT', `/records/project/${PF}`, { version: 0, data: project(costs) })
			expect('project with costs[] in the budget', p, 200)
			check('… stored with the defaults (third_party: receipts, mode rebill when missing)', p.data?.data?.budgets?.[0]?.costs?.[3]?.rule === 'receipts' && p.data?.data?.budgets?.[0]?.costs?.[1]?.mode === 'rebill', p.data?.data?.budgets?.[0]?.costs)
			const bad = (i, over) => project(costs.map((c, n) => (n === i ? { ...c, ...over } : c)))
			for (const [name, data, field] of [
				['no 0', bad(0, { no: 0 }), 'no'],
				['no twice', bad(1, { no: 1 }), 'no'],
				['name empty', bad(0, { name: '' }), 'name'],
				['kind unknown', bad(0, { kind: 'travel' }), 'kind'],
				['rule of third-party costs flat', bad(3, { rule: 'flat' }), 'rule'],
				['percent above 100', bad(0, { percent: 101 }), 'percent'],
				['flat without amount', bad(1, { amount: null }), 'amount'],
				['flat with per month', bad(1, { per: 'month' }), 'per'],
				['receipts without cap', bad(2, { cap: null }), 'cap'],
				['mode unknown', bad(0, { mode: 'resell' }), 'mode'],
				['markup on a pass-through item', bad(2, { markup: 10 }), 'markup'],
				['vat on a pass-through item', bad(3, { vat: 8.1 }), 'vat'],
				['description of 301 characters', bad(0, { description: 'x'.repeat(301) }), 'description'],
			]) {
				const r = await ocs(A, 'PUT', `/records/project/${PF}`, { version: p.data?.version ?? 1, data })
				check(`costs: ${name} → 422 naming “${field}”`, r.status === 422 && r.data?.error === 'invalid' && (r.data?.message || '').includes(`“${field}”`), `HTTP ${r.status} ${r.data?.message}`)
			}
		}

		head('Costs: VAT per line, issue')
		let fixed = null
		{
			expect('a position with vat 101', await ocs(A, 'PUT', path(K1), { version: 0, data: draft({ positions: [{ ...positions[0], vat: 101 }] }) }), 422, 'invalid')
			expect('a position with vat_mode exempt', await ocs(A, 'PUT', path(K1), { version: 0, data: draft({ positions: [{ ...positions[0], vat_mode: 'exempt' }] }) }), 422, 'invalid')
			expect('a position with cost 0', await ocs(A, 'PUT', path(K1), { version: 0, data: draft({ positions: [{ ...positions[0], cost: 0 }] }) }), 422, 'invalid')
			expect('draft: 8.1 % (the invoice’s), 2.6 %, a pass-through item', await ocs(A, 'PUT', path(K1), { version: 0, data: draft() }), 200)
			expect('the preview is gone (0.32.0): the app renders', await fetchRaw(A, `${ipath(K1)}/preview`), 410)
			const r = await festschreiben(A, K1, { issued_on: ISSUED_ON })
			expect('issue (reservation)', r.reserve, 200)
			expect('… closed with the app’s PDF', r.close, 200)
			const i = r.issue
			check('issue: net 1700, VAT 126.7, gross 1826.7 (taxable part), total 2176.7', near(i?.net, 1700) && near(i?.vat_amount, 126.7) && near(i?.gross, 1826.7) && near(i?.total, 2176.7), i)
			check('fixed of the reservation: vat_lines [2.6: 200 / 5.2, 8.1: 1500 / 121.5], pass_through 350, total 2176.7', JSON.stringify(r.fixed?.vat_lines) === '[{"rate":2.6,"net":200,"vat":5.2},{"rate":8.1,"net":1500,"vat":121.5}]' && near(r.fixed?.pass_through, 350) && near(r.fixed?.total, 2176.7), r.fixed)
			check('record: amount = total, vat = the rate of the largest net', near(r.record?.data?.amount, 2176.7) && r.record?.data?.vat === 8.1, r.record?.data)
			const f = await ocs(A, 'GET', `${ipath(K1)}/fixed`)
			fixed = f.data
			check('fixed: vat_lines, pass_through, total, vat 8.1', JSON.stringify(fixed?.vat_lines) === JSON.stringify(r.fixed?.vat_lines) && near(fixed?.pass_through, 350) && near(fixed?.total, 2176.7) && fixed?.vat === 8.1, fixed)
			check('fixed: each line carries its rate and mode', JSON.stringify(fixed?.positions?.map((p) => [p.vat, p.vat_mode])) === '[[8.1,"taxable"],[2.6,"taxable"],[8.1,"taxable"],[null,"pass_through"]]', fixed?.positions)
			const lg = await ocs(A, 'GET', `/invoices/ledger?since=${(fixed?.chain?.seq ?? 1) - 1}&limit=3`)
			const e = (lg.data?.entries || []).find((x) => x.action === 'issued' && x.number === fixed?.number)
			const payload = e ? JSON.parse(e.payload) : null
			check('ledger payload: vat_lines, pass_through, total', payload !== null && payload.vat_lines?.length === 2 && near(payload.pass_through, 350) && near(payload.total, 2176.7), payload)
			check('the chain is still valid', (await ocs(A, 'GET', '/invoices/chain/verify')).data?.ok === true)
			check('fixed: issued with the app’s PDF filed, no document_state', fixed?.status === 'issued' && fixed?.documents?.[0]?.kind === 'pdf' && fixed?.document_state === undefined, fixed?.documents)
			const doc = await fetchRaw(A, `${ipath(K1)}/document`)
			check('the filed document is the PDF as sent', doc.status === 200 && doc.buf.equals(r.pdf), doc.status)
		}

		head('Costs: reversal and exports')
		{
			const r = await stornieren(A, K1, { kind: 'reversal', issued_on: ISSUED_ON })
			expect('reversal (reservation)', r.reserve, 200)
			expect('… closed', r.close, 200)
			const rk = r.key
			if (rk) {
				made.push(rk)
			}
			const i = r.fixed
			check('the reversal negates every line, rate and mode: vat_lines, pass_through -350, total -2176.7', JSON.stringify(i?.vat_lines) === '[{"rate":2.6,"net":-200,"vat":-5.2},{"rate":8.1,"net":-1500,"vat":-121.5}]' && near(i?.pass_through, -350) && near(i?.total, -2176.7) && near(r.issue?.total, -2176.7) && r.record?.data?.positions?.[3]?.vat_mode === 'pass_through', i)
			const csv = await fetchRaw(A, `/invoices/export?kind=csv&from=${Y}-01-01&to=${Y}-12-31`)
			check('CSV: header ends with vat_lines;vat_by_rate;pass_through;total', csv.status === 200 && csv.text.includes(';chain_hash;vat_lines;vat_by_rate;pass_through;total\r\n'), csv.text.slice(0, 300))
			const row = csv.text.split('\r\n').find((l) => l.startsWith(`${fixed?.number};`))
			check('… the invoice: "2.6:200.00;8.1:1500.00";"2.6:5.20;8.1:121.50";350.00;2176.70', typeof row === 'string' && row.endsWith(';"2.6:200.00;8.1:1500.00";"2.6:5.20;8.1:121.50";350.00;2176.70'), row)
			const accounts = { consultant: '1234567', client: '12345', fiscal_year_start: '01-01', account_length: 4, debtor_account: '10000', revenue_domestic: '8400', revenue_eu: '8336', revenue_export: '8338' }
			expect('DATEV accounts without revenue_pass_through', await ocs(A, 'PUT', '/team/settings', { datev: accounts }), 200)
			const no = await fetchRaw(A, `/invoices/export?kind=datev&from=${Y}-01-01&to=${Y}-12-31`)
			check('DATEV with pass-through items but no account for them → 422 naming it', no.status === 422 && no.text.includes('revenue_pass_through'), [no.status, no.text.slice(0, 200)])
			const set = await ocs(A, 'PUT', '/team/settings', { datev: { ...accounts, revenue_pass_through: '1590' } })
			expect('Team Admin sets revenue_pass_through', set, 200)
			check('… shown in settings.datev', set.data?.datev?.revenue_pass_through === '1590', set.data?.datev)
			const datev = await fetchRaw(A, `/invoices/export?kind=datev&from=${Y}-01-01&to=${Y}-12-31`)
			const lines = datev.text.split('\r\n').filter((l) => l.includes(`;${fixed?.number};`))
			check('DATEV: one booking per rate and one for the pass-through items', lines.length === 3 && lines[0].startsWith('205,20;S;CHF;;;;10000;8400;') && lines[1].startsWith('1621,50;S;CHF;;;;10000;8400;') && lines[2].startsWith('350,00;S;CHF;;;;10000;1590;;'), lines)
			const rev = datev.text.split('\r\n').filter((l) => l.startsWith('350,00;H;'))
			check('… the reversal books them Haben', rev.length >= 1, rev)
		}
	} finally {
		head('Costs: cleaning up')
		await ocs(A, 'PUT', '/team/settings', { datev: datevBefore || {} })
		if (tenant !== null) {
			const list = made.map((k) => `'${k}'`).join(', ')
			sql(`DELETE FROM oc_ts_inv_hours WHERE tenant_id = ${tenant} AND invoice_id IN (SELECT id FROM oc_ts_inv_invoices WHERE tenant_id = ${tenant} AND rkey IN (${list}))`)
			sql(`DELETE FROM oc_ts_inv_invoices WHERE tenant_id = ${tenant} AND rkey IN (${list})`)
			sql(`DELETE FROM oc_ts_counters WHERE tenant_id = ${tenant} AND name = 'invoice:${Y}'`)
		}
		for (const k of made) {
			const cur = await ocs(A, 'GET', path(k))
			if (cur.status === 200) {
				await ocs(A, 'DELETE', `${path(k)}?version=${cur.data.version}`)
			}
		}
		for (const [kind, key] of [['project', PF], ['customer', CUST]]) {
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
