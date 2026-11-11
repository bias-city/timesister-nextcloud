// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Invoicing mode manual (0.48.0): the setting, issuing refused (409
// invoicing_manual), the counter still usable, client writes of an issued
// invoice record, the app's PDF, Word file and XRechnung filed under a fixed
// name and served back, a second upload replacing the first, a draft
// refused, the rights, and the mode back as found. No ledger is touched.
//
//   NC_URL=http://localhost:8081 node tests/integration/teil-manuell.mjs
import { pathToFileURL } from 'node:url'
import { ADMIN, dav, ocs } from './lib.mjs'
import { RUN, check, expect, head, isObj, me, minimalPdf, raw, summary } from './harness.mjs'
import { A, L, U1 } from './konten.mjs'

const rec = (kind, key) => `/records/${kind}/${encodeURIComponent(key)}`
const ipath = (key) => `/invoices/${encodeURIComponent(key)}`
const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
/** A stand-in Word file: a ZIP header and a salt (the server checks only `PK`). */
const docx = (salt) => Buffer.concat([Buffer.from([0x50, 0x4b, 0x03, 0x04]), Buffer.from(`word/document.xml ${salt}`)])
const cii = (number) => Buffer.from(`<?xml version="1.0" encoding="UTF-8"?>\n<rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100" xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100"><rsm:ExchangedDocument><ram:ID>${number}</ram:ID></rsm:ExchangedDocument></rsm:CrossIndustryInvoice>\n`)

export async function manuell() {
	const PM = `PM-${RUN}`
	const CM = `CM-${RUN}`
	const K1 = `inv-${RUN}m1`
	const K2 = `inv-${RUN}m2`
	const NUMBER = `MN-${RUN}-1`
	const Y = 3000 + (parseInt(RUN.slice(-4), 36) % 5000) + 23
	const ISSUED_ON = `${Y}-04-14`
	const FOLDER = `TimeSister Test Documents/${RUN}-m`
	const NAME = `${ISSUED_ON}_${NUMBER}_Kunde-Manuell-${RUN}`
	const PATH = `${FOLDER}/Buchhaltung/${Y}/Rechnungen/${NAME}`
	const teamBefore = (await ocs(A, 'GET', '/team')).data
	const modeBefore = teamBefore?.settings?.invoicing_mode ?? null
	const invoicingBefore = teamBefore?.settings?.invoicing ?? true
	const docsBefore = teamBefore?.settings?.documents || { root: null, chain_mail: null }
	let owner = teamBefore?.backup_owner || null
	const invoice = (more = {}) => ({
		schema: 1, project: PM, budget: 'b1', customer: CM, from: `${Y}-04-01`, to: `${Y}-04-30`, status: 'issued', number: NUMBER, bookings: [],
		positions: [{ kind: 'flat', text: 'Advice', amount: 500 }],
		note: null, created_by: 'zbadmin', created_on: ISSUED_ON, issued_on: ISSUED_ON, paid_on: null, ...more,
	})
	const put = (who, key, kind, body, type) => raw(who, 'PUT', `${ipath(key)}/document?kind=${kind}`, body, type)
	const get = (who, key, kind) => raw(who, 'GET', `${ipath(key)}/document?kind=${kind}`)
	try {
		head('Manual: setup')
		{
			const cap = (await ocs(A, 'GET', '/ocs/v2.php/cloud/capabilities')).data?.capabilities?.timesister
			check('capability invoicing_manual: 1 (0.48.0)', cap?.invoicing_manual === 1, cap)
			if (!owner) {
				const zb = ((await ocs(ADMIN, 'GET', '/admin/teams')).data || []).find((t) => t.slug === 'zb')
				if (zb) {
					owner = (await ocs(ADMIN, 'PUT', `/admin/teams/${zb.id}`, { name: zb.name, slug: zb.slug, groups: zb.groups, backup_owner: 'zbadmin' })).data?.backup_owner || null
				}
			}
			check('zb has a backup owner', typeof owner === 'string' && owner !== '', owner)
			expect('documents root for this run', await ocs(A, 'PUT', '/team/settings', { documents: { root: FOLDER, chain_mail: docsBefore.chain_mail ?? null } }), 200)
			expect('invoicing on', await ocs(A, 'PUT', '/team/settings', { invoicing: true }), 200)
			expect('customer', await ocs(A, 'PUT', rec('customer', CM), { version: 0, data: { schema: 1, id: CM, name: `Kunde Manuell ${RUN}`, street: 'Platz 1', postcode: '4001', city: 'Basel', country: 'CH' } }), 200)
			expect('project with budget b1, led by zblead', await ocs(A, 'PUT', rec('project', PM), { version: 0, data: { schema: 1, id: PM, name: `Manuell ${RUN}`, codes: [`M${RUN}`.toUpperCase()], status: 'active', leads: ['zblead'], customer: CM, budgets: [{ id: 'b1', name: 'Phase 1', currency: 'CHF' }] } }), 200)
			expect('draft K2 without a number', await ocs(A, 'PUT', rec('invoice', K2), { version: 0, data: invoice({ status: 'draft', number: null, issued_on: null }) }), 200)
		}

		head('Manual: the setting')
		{
			expect('a Lead sets it', await ocs(L, 'PUT', '/team/settings', { invoicing_mode: { mode: 'manual' } }), 403, 'forbidden')
			const bad = await ocs(A, 'PUT', '/team/settings', { invoicing_mode: { mode: 'manual', copy: 'auto' } })
			check('manual with a copy but no system → 422', bad.status === 422 && bad.data?.message === '“invoicing_mode.copy” needs “invoicing_mode.system”: name the accounting system that gets the copies.', [bad.status, bad.data])
			const r = await ocs(A, 'PUT', '/team/settings', { invoicing_mode: { mode: 'manual' } })
			check('mode manual, copy stored as off', r.status === 200 && JSON.stringify(r.data?.invoicing_mode) === '{"mode":"manual","system":null,"copy":"off"}', [r.status, r.data])
			check('… in /me', JSON.stringify((await me(A)).settings?.invoicing_mode) === '{"mode":"manual","system":null,"copy":"off"}')
		}

		head('Manual: issuing, the counter, client writes')
		{
			const iss = await ocs(A, 'POST', `${ipath(K2)}/issue`, { issued_on: ISSUED_ON })
			check('issue → 409 invoicing_manual', iss.status === 409 && iss.data?.code === 'invoicing_manual' && iss.data?.message === 'In the mode manual the app issues invoices; the server keeps no ledger.', [iss.status, iss.data])
			const ho = await ocs(A, 'POST', `${ipath(K2)}/handover`, { system: 'bexio', id: '1', number: '1' })
			check('hand-over stays refused → 409 handover_mode_not_external', ho.status === 409 && ho.data?.code === 'handover_mode_not_external', [ho.status, ho.data])
			const n = await ocs(A, 'GET', `/invoices/next-number?year=${Y}`)
			check('the team counter answers', n.status === 200 && Number.isInteger(n.data?.number) && n.data?.year === Y, [n.status, n.data])
			const w = await ocs(A, 'PUT', rec('invoice', K1), { version: 0, data: invoice() })
			check('client writes K1 issued with a number', w.status === 200 && w.data?.data?.status === 'issued' && w.data?.data?.number === NUMBER, [w.status, w.data])
		}

		head('Manual: filing')
		{
			const pdf1 = minimalPdf([`Invoice ${NUMBER}`], 'one')
			const p1 = await put(A, K1, 'pdf', pdf1, 'application/pdf')
			check('PUT pdf → { ok, path, name, size }', p1.status === 200 && p1.data?.ok === true && p1.data?.path === `${PATH}.pdf` && p1.data?.name === `${NAME}.pdf` && p1.data?.size === pdf1.length, [p1.status, p1.data])
			const g1 = await get(A, K1, 'pdf')
			check('GET pdf gives it back', g1.status === 200 && g1.type.startsWith('application/pdf') && g1.buf.equals(pdf1), [g1.status, g1.type])
			const pdf2 = minimalPdf([`Invoice ${NUMBER}`], 'two')
			const p2 = await put(A, K1, 'pdf', pdf2, 'application/pdf')
			check('a second PDF replaces the first, same path', p2.status === 200 && p2.data?.path === `${PATH}.pdf`, [p2.status, p2.data])
			check('… GET gives the second', (await get(A, K1, 'pdf')).buf.equals(pdf2))
			if (owner) {
				const twice = await dav({ user: owner, pass: `Test-2026-${owner}!` }, 'GET', `files/${encodeURIComponent(owner)}/${`${PATH}_2.pdf`.split('/').map(encodeURIComponent).join('/')}`)
				check('… no _2 file', twice.status === 404, twice.status)
				const file = await dav({ user: owner, pass: `Test-2026-${owner}!` }, 'GET', `files/${encodeURIComponent(owner)}/${`${PATH}.pdf`.split('/').map(encodeURIComponent).join('/')}`)
				check('… the file in the owner’s tree is the second', file.status === 200 && file.text === pdf2.toString('utf8'), file.status)
			}
			const d1 = docx('one')
			const pd = await put(A, K1, 'docx', d1, DOCX)
			check('PUT docx', pd.status === 200 && pd.data?.name === `${NAME}.docx`, [pd.status, pd.data])
			const d2 = docx('two')
			expect('a second docx replaces it', await put(A, K1, 'docx', d2, DOCX), 200)
			const gd = await get(A, K1, 'docx')
			check('GET docx gives the second', gd.status === 200 && gd.type.startsWith(DOCX) && gd.buf.equals(d2), [gd.status, gd.type])
			const x = cii(NUMBER)
			const px = await put(A, K1, 'xml', x, 'application/xml')
			check('PUT xml', px.status === 200 && px.data?.name === `${NAME}.xml`, [px.status, px.data])
			const gx = await get(A, K1, 'xml')
			check('GET xml', gx.status === 200 && gx.buf.equals(x), [gx.status, gx.type])
			const bd = await put(A, K1, 'docx', Buffer.from('%PDF-1.4 not a word file'), DOCX)
			check('a docx that is none → 422', bd.status === 422 && bd.data?.field === 'content', [bd.status, bd.data])
			expect('a PDF that is none → 422', await put(A, K1, 'pdf', Buffer.from('no pdf'), 'application/pdf'), 422, 'invalid')
			expect('kind zip → 422', await put(A, K1, 'zip', Buffer.from('PK'), 'application/zip'), 422, 'invalid')
			const dr = await put(A, K2, 'pdf', minimalPdf(['draft']), 'application/pdf')
			check('a draft → 409 invoice_manual_not_issued', dr.status === 409 && dr.data?.code === 'invoice_manual_not_issued', [dr.status, dr.data])
			expect('GET of the draft → 404', await get(A, K2, 'pdf'), 404, 'not_found')
			expect('an unknown invoice → 404', await put(A, `inv-${RUN}mx`, 'pdf', pdf1, 'application/pdf'), 404, 'not_found')
		}

		head('Manual: rights and status')
		{
			const lp = await put(L, K1, 'pdf', minimalPdf(['lead']), 'application/pdf')
			check('a Lead files → 403', lp.status === 403 && lp.data?.code === 'invoice_issue_admin_only', [lp.status, lp.data])
			const lg = await get(L, K1, 'pdf')
			check('the project’s Lead reads it', lg.status === 200 && lg.buf.subarray(0, 5).toString() === '%PDF-', lg.status)
			const ug = await get(U1, K1, 'pdf')
			check('a Member reads it → 403', ug.status === 403 && ug.data?.code === 'invoice_read_lead_only', [ug.status, ug.data])
			const cur = await ocs(A, 'GET', rec('invoice', K1))
			const paid = await ocs(A, 'PUT', rec('invoice', K1), { version: cur.data?.version, data: invoice({ status: 'paid', paid_on: `${Y}-05-02` }) })
			check('client sets it paid', paid.status === 200 && paid.data?.data?.status === 'paid', [paid.status, paid.data])
			expect('a paid invoice’s PDF is replaced too', await put(A, K1, 'pdf', minimalPdf([`Invoice ${NUMBER}`], 'paid'), 'application/pdf'), 200)
			const cancelled = await ocs(A, 'PUT', rec('invoice', K1), { version: paid.data?.version, data: invoice({ status: 'cancelled' }) })
			check('client cancels it', cancelled.status === 200 && cancelled.data?.data?.status === 'cancelled', [cancelled.status, cancelled.data])
			check('… its PDF is still served', (await get(A, K1, 'pdf')).status === 200)
			const cp = await put(A, K1, 'pdf', minimalPdf(['cancelled']), 'application/pdf')
			check('… but not filed again → 409', cp.status === 409 && cp.data?.code === 'invoice_manual_not_issued', [cp.status, cp.data])
		}

		head('Manual: the mode back')
		{
			const r = await ocs(A, 'PUT', '/team/settings', { invoicing_mode: { mode: 'timesister' } })
			check('mode timesister again', r.status === 200 && r.data?.invoicing_mode?.mode === 'timesister', [r.status, r.data])
			const t = await put(A, K1, 'pdf', minimalPdf([`Invoice ${NUMBER}`]), 'application/pdf')
			check('… PUT document is the close of 0.32.0 again (409, nothing reserved)', t.status === 409 && t.data?.code !== 'invoice_manual_not_issued', [t.status, t.data])
			expect('… docx is unknown there', await put(A, K1, 'docx', docx('x'), DOCX), 422, 'invalid')
		}
	} finally {
		await ocs(A, 'PUT', '/team/settings', { invoicing_mode: modeBefore, invoicing: invoicingBefore, documents: { root: docsBefore.root ?? null, chain_mail: docsBefore.chain_mail ?? null } })
		for (const [kind, key] of [['invoice', K1], ['invoice', K2], ['project', PM], ['customer', CM]]) {
			const cur = await ocs(A, 'GET', rec(kind, key))
			if (cur.status === 200) {
				await ocs(A, 'DELETE', `${rec(kind, key)}?version=${cur.data.version}`)
			}
		}
		if (owner) {
			await dav({ user: owner, pass: `Test-2026-${owner}!` }, 'DELETE', `files/${encodeURIComponent(owner)}/${FOLDER.split('/').map(encodeURIComponent).join('/')}`)
		}
		check('zb left as found', JSON.stringify((await ocs(A, 'GET', '/team')).data?.settings?.invoicing_mode ?? null) === JSON.stringify(modeBefore) && isObj(await me(A)))
	}
}

// Alone: `node tests/integration/teil-manuell.mjs`.
if (import.meta.url === pathToFileURL(process.argv[1]).href) {
	await manuell()
	process.exit(summary())
}
