// SPDX-License-Identifier: AGPL-3.0-or-later
//
// One renderer (0.32.0): `issue` reserves number and chain link, the app's
// PDF sent to `PUT /invoices/{key}/document` closes the issue, the ledger
// entry carries the document's hash; `issue/cancel` releases gapless; the
// ledger is closed while an issue is pending; `preview`/`render` are 410;
// the app's XRechnung goes to `?kind=xml`; `chain/verify?files=true`
// re-hashes the files; `reverse` reserves the same way. Quotes: `texts`,
// `attachments`, `POST /quotes/{key}/issue` with the counter, the three
// document kinds. Needs the booking in the book (listener or TS_OCC) and a
// backup owner. Leaves zb as it found it where it can: settings, project,
// customer, events, drafts and the quote removed; frozen rows and the
// ledger stay (append-only).
import { ensureTimeCalendar, deleteEvent, ocs, putEvent, runJob, sql } from './lib.mjs'
import { RUN, check, expect, head, me, sha256 } from './harness.mjs'
import { A, L, U1 } from './konten.mjs'

const path = (key) => `/records/invoice/${encodeURIComponent(key)}`
const ipath = (key) => `/invoices/${encodeURIComponent(key)}`
const qpath = (key) => `/quotes/${encodeURIComponent(key)}`
const HEX64 = /^[0-9a-f]{64}$/
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))
const NC = (process.env.NC_URL || 'http://localhost:8081').replace(/\/$/, '')
const API = '/ocs/v2.php/apps/timesister/api/v1'
const auth = (who) => 'Basic ' + Buffer.from(`${who.user}:${who.pass}`).toString('base64')

/** A raw upload (PDF or XML bytes) as `who`. */
async function upload(who, method, p, bytes, type) {
	const res = await fetch(`${NC}${API}${p}`, { method, headers: { 'OCS-APIRequest': 'true', Accept: 'application/json', Authorization: auth(who), 'Content-Type': type }, body: bytes })
	const text = await res.text()
	let json = null
	try {
		json = JSON.parse(text)
	} catch {
		json = null
	}
	return { status: res.status, data: json?.ocs?.data ?? null, text }
}

/** A raw GET of the app API as `who`: status, content type and body bytes. */
async function fetchRaw(who, p) {
	const res = await fetch(`${NC}${API}${p}`, { headers: { 'OCS-APIRequest': 'true', Authorization: auth(who) } })
	return { status: res.status, type: res.headers.get('content-type') || '', buf: Buffer.from(await res.arrayBuffer()) }
}

/** What the app would render: a PDF that carries the number and the chain line (the server sniffs and searches, nothing more). */
const fakePdf = (number, chainLine, salt = '') => `%PDF-1.7\n% TimeSister ${salt}\n% ${chainLine}\n% ${number}\n%%EOF\n`

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

export async function renderer() {
	const PF = `PR-${RUN}`
	const CF = `CR-${RUN}`
	const CODE = `R${RUN}`.toUpperCase()
	const EV1 = `tr-${RUN}-1@example.test`
	const EV2 = `tr-${RUN}-2@example.test`
	const K1 = `inv-${RUN}r1`
	const K2 = `inv-${RUN}r2`
	// Not the key of teil-belege's quote (its history rows stay after the SQL cleanup).
	const Q1 = `q-${sha256(`${RUN}-renderer`).slice(0, 8)}`
	const made = [K1, K2]
	const Y = 3000 + (parseInt(RUN.slice(-4), 36) % 5000) + 9
	const ISSUED_ON = `${Y}-06-15`
	const FOLDER = `TimeSister Test Renderer/${RUN}`
	const settings = await ocs(A, 'GET', '/records/setting/settings')
	const base = settings.status === 200 ? settings.data.data : {}
	let settingsVersion = settings.status === 200 ? settings.data.version : 0
	const teamBefore = (await ocs(A, 'GET', '/team')).data
	const docsBefore = teamBefore?.settings?.documents || { root: null, chain_mail: null }
	let owner = teamBefore?.backup_owner || null
	const layout = {
		grouping: 'flat', appendix: ['aps', 'companies', 'rates'],
		aps: [{ no: 'D196.4', name: 'Vorprojekt', hours: 2, amount: 300, before: 0, remaining: 700 }],
		companies: [{ name: 'Muster sàrl', hours: 2, amount: 300 }],
		rates: [{ level: 'Projektleitung', rate: 150, hours: 2, amount: 300 }],
	}
	const draft = (ev, more = {}) => ({
		schema: 1, project: PF, budget: 'b1', from: '2026-10-01', to: '2026-10-31', status: 'draft', number: null,
		bookings: [{ person: 'zbuser1', uid: ev, start: '2026-10-14T08:00:00', hours: 2, rate: 150, code: CODE }],
		positions: [{ kind: 'hours', text: 'Site visit', person: 'zbuser1', work_package: 0, hours: 2, rate: 150, amount: 300 }, { kind: 'flat', text: 'Travel', amount: 100 }],
		greeting: 'Dear team', body: 'Payable within 30 days.', footer: 'Muster · Basel', vat: 8.1, contact: 'c1', layout,
		note: null, created_by: 'zbadmin', created_on: '2026-10-15', issued_on: null, paid_on: null, ...more,
	})
	let quoteMade = false
	try {
		head('Renderer: setup')
		{
			const cap = (await ocs(A, 'GET', '/ocs/v2.php/cloud/capabilities')).data?.capabilities?.timesister
			check('capabilities invoice_documents 2, quotes 4, quote_documents 1, invoice_layout 2, e_invoice 1', cap?.invoice_documents === 2 && cap?.quotes === 4 && cap?.quote_documents === 1 && cap?.invoice_layout === 2 && cap?.e_invoice === 1, cap)
			if (!owner) {
				const teams = await ocs({ user: process.env.NC_ADMIN_USER || 'admin', pass: process.env.NC_ADMIN_PASS || 'admin123' }, 'GET', '/admin/teams')
				const zb = (teams.data || []).find((t) => t.slug === 'zb')
				if (zb) {
					const r = await ocs({ user: process.env.NC_ADMIN_USER || 'admin', pass: process.env.NC_ADMIN_PASS || 'admin123' }, 'PUT', `/admin/teams/${zb.id}`, { name: zb.name, slug: zb.slug, groups: zb.groups, backup_owner: 'zbadmin' })
					owner = r.data?.backup_owner || null
				}
			}
			check('zb has a backup owner', typeof owner === 'string' && owner !== '', owner)
			const s = await ocs(A, 'PUT', '/records/setting/settings', { version: settingsVersion, data: { ...base, language: 'de',
				// With the year: the reversals below take today's counter, which starts at 1 in a fresh team.
				invoice_number: { pattern: `RR-${RUN}-{year}-{no:4}`, per_year: true, start: 1 },
				quote_number: { pattern: `OR-${RUN}-{no:3}`, per_year: true, start: 1 },
				issuer: { name: 'Muster sàrl', street: 'Musterstrasse 12', postcode: '4000', city: 'Basel', country: 'CH', vat_id: 'CHE-123.456.789 MWST', qr: true, accounts: [{ currency: 'CHF', iban: 'CH9300762011623852957', bic: '' }] } } })
			expect('Team Admin sets language, patterns (invoice and quote) and issuer', s, 200)
			settingsVersion = s.data?.version ?? settingsVersion
			expect('a bad quote number pattern', await ocs(A, 'PUT', '/records/setting/settings', { version: settingsVersion, data: { ...base, quote_number: { pattern: 'OF-{nope}' } } }), 422, 'invalid')
			expect('documents root', await ocs(A, 'PUT', '/team/settings', { documents: { root: FOLDER } }), 200)
			expect('customer', await ocs(A, 'PUT', `/records/customer/${CF}`, { version: 0, data: { schema: 1, id: CF, name: `Kunde ${RUN}`, street: 'Platz 1', postcode: '4001', city: 'Basel', country: 'CH', contacts: [{ id: 'c1', name: 'Lea Kontakt' }] } }), 200)
			expect('project with budget b1 in CHF, led by zblead', await ocs(A, 'PUT', `/records/project/${PF}`, { version: 0, data: { schema: 1, id: PF, name: `Renderer ${RUN}`, codes: [CODE], status: 'active', leads: ['zblead'], customer: CF, budgets: [{ id: 'b1', name: 'Budget R', currency: 'CHF' }] } }), 200)
			check('zbuser1’s time calendar exists and is named', (await ensureTimeCalendar(U1)) && (await ocs(U1, 'PUT', '/me/time-calendar', { calendar_url: 'zeit-zbuser1' })).status === 200)
			check('zbuser1 books two hours twice', (await putEvent(U1, EV1, ['DTSTART:20261014T080000Z', 'DTEND:20261014T100000Z', `SUMMARY:Site visit #${CODE}`])) === 201
				&& (await putEvent(U1, EV2, ['DTSTART:20261015T080000Z', 'DTEND:20261015T100000Z', `SUMMARY:Second visit #${CODE}`])) === 201)
			await sleep(500)
			if (!(await book(A)).some((e) => e.event_uid === EV1)) {
				runJob('EntryReader')
			}
		}
		const inBook = (await book(A)).some((e) => e.event_uid === EV1)
		check('the booking is in the book', inBook)

		head('Renderer: gone')
		expect('preview is gone', await ocs(A, 'GET', `${ipath(K1)}/preview`), 410, 'gone')
		const render = await ocs(A, 'POST', `${ipath(K1)}/render`, {})
		expect('render is gone', render, 410, 'gone')
		check('… with code gone', render.data?.code === 'gone', render.data)

		if (!inBook) {
			console.log('    info  the booking is not in the book (no listener, no TS_OCC) – the issuing checks are skipped')
			return
		}

		head('Renderer: the reservation')
		expect('draft 1 with layout aps/companies/rates', await ocs(A, 'PUT', path(K1), { version: 0, data: draft(EV1) }), 200)
		expect('draft 2', await ocs(A, 'PUT', path(K2), { version: 0, data: draft(EV2) }), 200)
		expect('a seventh appendix', await ocs(A, 'PUT', path(K2), { version: 1, data: draft(EV2, { layout: { appendix: ['aps', 'costs'] } }) }), 422, 'invalid')
		expect('Lead issues', await ocs(L, 'POST', `${ipath(K1)}/issue`, {}), 403, 'forbidden')
		let r = await ocs(A, 'POST', `${ipath(K1)}/issue`, { issued_on: ISSUED_ON })
		if (r.status === 409 && r.data?.code === 'issue_pending' && r.data?.pending?.key) {
			// A reservation left by an earlier run: cancel it and go on.
			console.log(`    info  an earlier reservation ${r.data.pending.number} was open – cancelled`)
			await ocs(A, 'POST', `${ipath(r.data.pending.key)}/issue/cancel`, {})
			r = await ocs(A, 'POST', `${ipath(K1)}/issue`, { issued_on: ISSUED_ON })
		}
		expect('Team Admin reserves', r, 200)
		const issue = r.data?.issue
		const number = issue?.number
		const seq = issue?.seq
		check('answer: record issuing with number, ledger_seq, no hash', r.data?.record?.data?.status === 'issuing' && r.data?.record?.data?.number === number && r.data?.record?.data?.ledger_seq === seq && r.data?.record?.data?.hash === null, r.data?.record?.data)
		check('issue: number from the pattern, seq, prev_hash, position, expires_at', new RegExp(`^RR-${RUN}-${Y}-\\d{4}$`).test(number ?? '') && Number.isInteger(seq) && typeof issue?.prev_hash === 'string' && issue?.position === `${seq} of ${seq}` && /Z$/.test(issue?.expires_at ?? ''), issue)
		check('issue: chain_line in German, with number, seq and prev_hash[0:12]', issue?.chain_line === `Beleg ${number} · Kette ${seq} ← ${issue?.prev_hash === '' ? '–' : issue?.prev_hash.slice(0, 12)}`, issue?.chain_line)
		check('issue: server sums (net 400, VAT 32.4, gross 432.4), CHF, tax case, language, due 30 days', issue?.net === 400 && issue?.vat_amount === 32.4 && issue?.gross === 432.4 && issue?.total === 432.4 && issue?.currency === 'CHF' && issue?.tax_case === 'domestic' && issue?.language === 'de' && issue?.due_on === `${Y}-07-15`, issue)
		check('issue: no document_state', issue?.document_state === undefined)
		const fx = r.data?.fixed
		check('fixed: issuing, chain.hash null, expires_at, chain_line, documents empty, layout tables as sent', fx?.status === 'issuing' && fx?.chain?.seq === seq && fx?.chain?.hash === null && typeof fx?.expires_at === 'string' && fx?.chain_line === issue?.chain_line && Array.isArray(fx?.documents) && fx.documents.length === 0 && fx?.layout?.aps?.[0]?.no === 'D196.4' && fx?.layout?.rates?.[0]?.level === 'Projektleitung', fx)
		expect('GET fixed while issuing', await ocs(A, 'GET', `${ipath(K1)}/fixed`), 200)
		expect('Lead reads fixed while issuing', await ocs(L, 'GET', `${ipath(K1)}/fixed`), 200)
		const dm = await ocs(A, 'GET', `${ipath(K1)}/document`)
		expect('GET document while issuing', dm, 409, 'conflict')
		check('… code document_missing', dm.data?.code === 'document_missing')
		expect('issuing it again', await ocs(A, 'POST', `${ipath(K1)}/issue`, {}), 409, 'conflict')

		head('Renderer: the ledger is closed')
		{
			const w = await ocs(A, 'PUT', path(K1), { version: r.data?.record?.version, data: draft(EV1, { note: 'x' }) })
			expect('a write to the reserved record', w, 409, 'conflict')
			check('… issue_pending with pending { key, number, expires_at }', w.data?.code === 'issue_pending' && w.data?.pending?.key === K1 && w.data?.pending?.number === number && /Z$/.test(w.data?.pending?.expires_at ?? ''), w.data)
			const o = await ocs(A, 'POST', `${ipath(K2)}/issue`, { issued_on: ISSUED_ON })
			expect('a second reservation of the team', o, 409, 'conflict')
			check('… issue_pending', o.data?.code === 'issue_pending')
			const n = await ocs(A, 'POST', '/invoices/next-number', { year: Y })
			expect('POST next-number', n, 409, 'conflict')
			check('… issue_pending (the counter must not move)', n.data?.code === 'issue_pending')
			expect('GET next-number still reads', await ocs(A, 'GET', `/invoices/next-number?year=${Y}`), 200)
			const p = await ocs(A, 'POST', `${ipath(K1)}/paid`, { paid_on: ISSUED_ON })
			check('paid on a reserved invoice is refused', p.status === 422 || p.status === 409, p.data)
		}

		head('Renderer: the close')
		const pdf = fakePdf(number, issue?.chain_line)
		{
			expect('Lead closes', await upload(L, 'PUT', `${ipath(K1)}/document`, pdf, 'application/pdf'), 403, 'forbidden')
			const bad = await upload(A, 'PUT', `${ipath(K1)}/document`, 'hello', 'application/pdf')
			expect('not a PDF', bad, 422, 'invalid')
			check('… field content', bad.data?.field === 'content', bad.data)
			const older = await upload(A, 'PUT', `${ipath(K1)}/document`, fakePdf(number, `Beleg ${number} · Kette ${seq - 1} ← abcdefabcdef`), 'application/pdf')
			expect('an older render (other chain line)', older, 422, 'invalid')
			check('… document_mismatch', older.data?.code === 'document_mismatch' && older.data?.field === 'content', older.data)
			expect('a PDF of another invoice', await upload(A, 'PUT', `${ipath(K1)}/document`, fakePdf('RR-OTHER', `Beleg RR-OTHER · Kette ${seq} ← ${issue?.prev_hash.slice(0, 12)}`), 'application/pdf'), 422, 'invalid')
			expect('close of an unknown key', await upload(A, 'PUT', `${ipath(`inv-${RUN}zz`)}/document`, pdf, 'application/pdf'), 404, 'not_found')
			const c = await upload(A, 'PUT', `${ipath(K1)}/document`, pdf, 'application/pdf')
			expect('Team Admin closes with the PDF', c, 200)
			const ci = c.data?.issue
			check('answer: record issued with hash and documents[pdf]', c.data?.record?.data?.status === 'issued' && HEX64.test(c.data?.record?.data?.hash ?? '') && c.data?.record?.data?.documents?.[0]?.kind === 'pdf' && c.data?.record?.data?.documents?.[0]?.sha256 === sha256(pdf), c.data?.record?.data)
			check('answer: issue { number, seq, prev_hash, hash, document }', ci?.number === number && ci?.seq === seq && ci?.prev_hash === issue?.prev_hash && HEX64.test(ci?.hash ?? '') && ci?.document?.kind === 'pdf' && ci?.document?.size === Buffer.byteLength(pdf) && ci?.document?.sha256 === sha256(pdf) && /\.pdf$/.test(ci?.document?.name ?? ''), ci)
			check('answer: ledger with the reserved seq, stored true', c.data?.ledger?.seq === seq && c.data?.ledger?.hash === ci?.hash && c.data?.stored === true, c.data?.ledger)
			check('document name: <issued_on>_<number>_<customer>.pdf', ci?.document?.name === `${ISSUED_ON}_${number}_Kunde-${RUN}.pdf`, ci?.document?.name)
			const again = await upload(A, 'PUT', `${ipath(K1)}/document`, pdf, 'application/pdf')
			expect('the same bytes again', again, 200)
			check('… stored false, the same hash', again.data?.stored === false && again.data?.issue?.hash === ci?.hash, again.data)
			const other = await upload(A, 'PUT', `${ipath(K1)}/document`, fakePdf(number, issue?.chain_line, 'v2'), 'application/pdf')
			expect('other bytes after the close', other, 409, 'conflict')
			check('… document_exists', other.data?.code === 'document_exists')
			expect('cancel after the close', await ocs(A, 'POST', `${ipath(K1)}/issue/cancel`, {}), 409, 'conflict')
			const f = await ocs(A, 'GET', `${ipath(K1)}/fixed`)
			check('fixed: issued, chain hash, chain_line, documents[pdf], no expires_at', f.data?.status === 'issued' && f.data?.chain?.hash === ci?.hash && f.data?.chain_line === issue?.chain_line && f.data?.documents?.[0]?.kind === 'pdf' && f.data?.expires_at === undefined && f.data?.document_state === undefined, f.data)
			const lg = await ocs(A, 'GET', `/invoices/ledger?since=${seq - 1}&limit=1`)
			const e = lg.data?.entries?.[0]
			check('ledger: the entry issued at the reserved seq, payload with document { kind, name, size, sha256 }', e?.seq === seq && e?.action === 'issued' && e?.number === number && JSON.parse(e?.payload ?? '{}')?.document?.sha256 === sha256(pdf) && JSON.parse(e?.payload ?? '{}')?.document?.path === undefined, e)
			const v = await ocs(A, 'GET', '/invoices/chain/verify')
			check('the chain verifies', v.data?.ok === true, v.data)
			// zb's ledger carries files of earlier runs whose folders are gone: a break before this run's entries is fine.
			const filesOk = (v) => v?.ok === true ? v?.files === true : v?.field === 'document' && v?.first_break < seq
			const vf = await ocs(A, 'GET', '/invoices/chain/verify?files=true')
			check('… also with the files (none of this run’s files breaks)', filesOk(vf.data), vf.data)
			const got = await fetchRaw(A, `${ipath(K1)}/document?kind=pdf`)
			check('GET document: the filed bytes', got.status === 200 && got.buf.toString() === pdf && /pdf/.test(got.type), [got.status, got.type])
			check('… the Lead too', (await fetchRaw(L, `${ipath(K1)}/document`)).status === 200)
			expect('a write to the issued record', await ocs(A, 'PUT', path(K1), { version: c.data?.record?.version, data: draft(EV1) }), 409, 'issued')
		}

		head('Renderer: the e-invoice XML')
		{
			const cii = `<?xml version="1.0" encoding="UTF-8"?><rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100" xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100"><rsm:ExchangedDocument><ram:ID>${number}</ram:ID></rsm:ExchangedDocument></rsm:CrossIndustryInvoice>`
			expect('XML to the draft K2', await upload(A, 'PUT', `${ipath(K2)}/document?kind=xml`, cii, 'application/xml'), 404, 'not_found')
			expect('a bad kind', await upload(A, 'PUT', `${ipath(K1)}/document?kind=docx`, cii, 'application/xml'), 422, 'invalid')
			expect('not XML', await upload(A, 'PUT', `${ipath(K1)}/document?kind=xml`, 'nope', 'application/xml'), 422, 'invalid')
			expect('XML with a DOCTYPE', await upload(A, 'PUT', `${ipath(K1)}/document?kind=xml`, '<!DOCTYPE x><Invoice/>', 'application/xml'), 422, 'invalid')
			const mm = await upload(A, 'PUT', `${ipath(K1)}/document?kind=xml`, cii.replace(number, 'RR-OTHER'), 'application/xml')
			expect('XML of another invoice', mm, 422, 'invalid')
			check('… document_mismatch', mm.data?.code === 'document_mismatch')
			const x = await upload(A, 'PUT', `${ipath(K1)}/document?kind=xml`, cii, 'text/xml')
			expect('Team Admin files the XRechnung', x, 200)
			check('answer: document { kind xml, name .xml, sha256 }, ledger entry, stored', x.data?.document?.kind === 'xml' && /\.xml$/.test(x.data?.document?.name ?? '') && x.data?.document?.sha256 === sha256(cii) && Number.isInteger(x.data?.ledger?.seq) && x.data?.stored === true, x.data)
			check('record: documents pdf and xml', x.data?.record?.data?.documents?.map((d) => d.kind).join() === 'pdf,xml', x.data?.record?.data?.documents)
			const again = await upload(A, 'PUT', `${ipath(K1)}/document?kind=xml`, cii, 'application/xml')
			check('the same XML again: 200, stored false', again.status === 200 && again.data?.stored === false && again.data?.ledger?.seq === x.data?.ledger?.seq, again.data)
			expect('other XML', await upload(A, 'PUT', `${ipath(K1)}/document?kind=xml`, cii + '<!-- x -->', 'application/xml'), 409, 'conflict')
			const lg = await ocs(A, 'GET', `/invoices/ledger?since=${x.data?.ledger?.seq - 1}&limit=1`)
			check('ledger: document entry, kind xrechnung', lg.data?.entries?.[0]?.action === 'document' && JSON.parse(lg.data?.entries?.[0]?.payload ?? '{}')?.kind === 'xrechnung', lg.data?.entries?.[0])
			const got = await fetchRaw(A, `${ipath(K1)}/document?kind=xml`)
			check('GET document?kind=xml: the filed bytes', got.status === 200 && got.buf.toString() === cii, got.status)
			const vf = await ocs(A, 'GET', '/invoices/chain/verify?files=true')
			check('the chain verifies with both files', vf.data?.ok === true || (vf.data?.field === 'document' && vf.data?.first_break < seq), vf.data)
			expect('the second draft reserves now', await ocs(A, 'POST', `${ipath(K2)}/issue`, { issued_on: ISSUED_ON }), 200)
			const dm2 = await upload(A, 'PUT', `${ipath(K2)}/document?kind=xml`, cii, 'application/xml')
			expect('XML to the reserved K2', dm2, 409, 'conflict')
			check('… document_missing (the PDF comes first)', dm2.data?.code === 'document_missing', dm2.data)
		}

		head('Renderer: cancel and gapless numbers')
		{
			const k2 = await ocs(A, 'GET', path(K2))
			const n2 = k2.data?.data?.number
			check('K2 is issuing with the next number', k2.data?.data?.status === 'issuing' && typeof n2 === 'string', k2.data?.data)
			expect('Lead cancels', await ocs(L, 'POST', `${ipath(K2)}/issue/cancel`, {}), 403, 'forbidden')
			const c = await ocs(A, 'POST', `${ipath(K2)}/issue/cancel`, {})
			expect('Team Admin cancels', c, 200)
			check('answer: record draft without number, released { number, seq }', c.data?.record?.data?.status === 'draft' && c.data?.record?.data?.number === null && c.data?.record?.data?.ledger_seq === null && c.data?.released?.number === n2 && Number.isInteger(c.data?.released?.seq), c.data)
			expect('fixed of the released draft', await ocs(A, 'GET', `${ipath(K2)}/fixed`), 404, 'not_found')
			const late = await upload(A, 'PUT', `${ipath(K2)}/document`, fakePdf(n2, 'x'), 'application/pdf')
			expect('a PDF arriving after the release', late, 409, 'conflict')
			check('… not_issuing', late.data?.code === 'not_issuing')
			expect('cancel again', await ocs(A, 'POST', `${ipath(K2)}/issue/cancel`, {}), 409, 'conflict')
			const mark = await ocs(A, 'GET', `/records/billing/zbuser1+${sha256(EV2).slice(0, 32)}`)
			check('the billing mark of the reservation is gone', mark.status === 404 || mark.data?.deleted === true, mark.status)
			expect('the draft is writable again', await ocs(A, 'PUT', path(K2), { version: c.data?.record?.version, data: draft(EV2, { note: 'again' }) }), 200)
			const again = await ocs(A, 'POST', `${ipath(K2)}/issue`, { issued_on: ISSUED_ON })
			expect('reserving K2 again', again, 200)
			check('… gives the released number again (gapless)', again.data?.issue?.number === n2 && again.data?.issue?.seq === c.data?.released?.seq, again.data?.issue)
			const close = await upload(A, 'PUT', `${ipath(K2)}/document`, fakePdf(n2, again.data?.issue?.chain_line), 'application/pdf')
			expect('… and closes', close, 200)
			const nn = await ocs(A, 'GET', `/invoices/next-number?year=${Y}`)
			check('the counter continues after it', nn.data?.number === parseInt(n2.slice(-4), 10) + 1, nn.data)
		}

		head('Renderer: reversal as a reservation')
		{
			const rv = await ocs(A, 'POST', `${ipath(K1)}/reverse`, { kind: 'reversal', reason: 'Test' })
			expect('Team Admin reserves the reversal', rv, 200)
			const rk = rv.data?.record?.key
			check('answer: record issuing (reverses K1), original still issued, fixed and issue', rv.data?.record?.data?.status === 'issuing' && rv.data?.record?.data?.reverses === K1 && rv.data?.original?.data?.status === 'issued' && rv.data?.fixed?.status === 'issuing' && typeof rv.data?.issue?.chain_line === 'string', rv.data)
			check('the original’s fixed stays issued', (await ocs(A, 'GET', `${ipath(K1)}/fixed`)).data?.status === 'issued')
			const c = await ocs(A, 'POST', `${ipath(rk)}/issue/cancel`, {})
			expect('cancel the reversal', c, 200)
			check('… the server-created record is a tombstone, the original untouched', c.data?.record?.deleted === true && (await ocs(A, 'GET', `${ipath(K1)}/fixed`)).data?.reversed_by === null, c.data?.record)
			const rv2 = await ocs(A, 'POST', `${ipath(K1)}/reverse`, { kind: 'reversal', reason: 'Test' })
			expect('reserve the reversal again', rv2, 200)
			const rk2 = rv2.data?.record?.key
			check('… the same number again', rv2.data?.issue?.number === rv.data?.issue?.number, [rv.data?.issue?.number, rv2.data?.issue?.number])
			const close = await upload(A, 'PUT', `${ipath(rk2)}/document`, fakePdf(rv2.data?.issue?.number, rv2.data?.issue?.chain_line), 'application/pdf')
			expect('close the reversal with its PDF', close, 200)
			check('the original is cancelled now, reversed_by the reversal', (await ocs(A, 'GET', `${ipath(K1)}/fixed`)).data?.status === 'cancelled' && (await ocs(A, 'GET', path(K1))).data?.data?.reversed_by === rk2)
			const lg = await ocs(A, 'GET', `/invoices/ledger?since=${rv2.data?.issue?.seq - 1}&limit=2`)
			check('ledger: reversal with document hash, then cancelled', lg.data?.entries?.map((e) => e.action).join() === 'reversal,cancelled' && JSON.parse(lg.data?.entries?.[0]?.payload ?? '{}')?.document?.kind === 'pdf', lg.data?.entries?.map((e) => [e.seq, e.action]))
			const v = await ocs(A, 'GET', '/invoices/chain/verify?files=true')
			check('the chain verifies with the files', v.data?.ok === true || (v.data?.field === 'document' && v.data?.first_break < seq), v.data)
		}

		head('Renderer: expiry')
		{
			const tenant = sql("SELECT id FROM oc_ts_tenants WHERE slug = 'zb'")
			const rv = await ocs(A, 'POST', `${ipath(K2)}/reverse`, { kind: 'credit', reason: 'Expiry test' })
			expect('reserve a credit note', rv, 200)
			const rk = rv.data?.record?.key
			if (tenant && rk) {
				sql(`UPDATE oc_ts_inv_invoices SET issue_expires_at = 1 WHERE tenant_id = ${tenant} AND rkey = '${rk}'`)
				const nn = await ocs(A, 'POST', '/invoices/next-number', { year: Y })
				check('the next ledger touch releases the expired reservation', nn.status === 200, nn.data)
				check('… the credit note record is gone', (await ocs(A, 'GET', path(rk))).status === 404 || (await ocs(A, 'GET', path(rk))).data?.deleted === true)
				const rv2 = await ocs(A, 'POST', `${ipath(K2)}/reverse`, { kind: 'credit', reason: 'Expiry test' })
				expect('reserve again', rv2, 200)
				sql(`UPDATE oc_ts_inv_invoices SET issue_expires_at = 1 WHERE tenant_id = ${tenant} AND rkey = '${rv2.data?.record?.key}'`)
				if (runJob('IssueExpiry')) {
					check('the job IssueExpiry releases it', (await ocs(A, 'GET', path(rv2.data?.record?.key))).status === 404 || (await ocs(A, 'GET', path(rv2.data?.record?.key))).data?.deleted === true)
				} else {
					await ocs(A, 'POST', `${ipath(rv2.data?.record?.key)}/issue/cancel`, {})
					console.log('    info  no TS_OCC – the job IssueExpiry is not run')
				}
			} else {
				console.log('    info  no TS_SQL – the expiry is not forced; the reservation is cancelled')
				expect('cancel', await ocs(A, 'POST', `${ipath(rk)}/issue/cancel`, {}), 200)
			}
			check('the counter did not move (the credit note’s numbers were given back)', (await ocs(A, 'GET', `/invoices/next-number?year=${Y}`)).data?.number === 4)
		}

		head('Renderer: quotes with texts, number and documents')
		{
			const quote = (more = {}) => ({
				schema: 1, name: `Offerte ${RUN}`, status: 'draft', customer: CF, project: null, project_draft: { code: `N${RUN}`.toUpperCase(), name: 'Schulhaus Muster' }, number: null,
				date: '2026-10-14', valid_until: '2026-11-30', accepted_on: null, created_on: '2026-10-14', leads: ['zblead'], currency: 'CHF',
				budgets: [{ id: 'bq1', name: 'Vorprojekt', currency: 'CHF', cap: 24000, work_packages: [{ no: 1, name: 'Konzept', hours: 40, rate: 180 }] }],
				texts: { greeting: 'Sehr geehrte Damen und Herren', intro: 'Gerne offerieren wir', closing: null, footer: 'Muster sàrl' }, attachments: 'Honorarofferte\nTerminplan',
				note: null, created_by: 'zbadmin', ...more,
			})
			const q = await ocs(A, 'PUT', `/records/quote/${Q1}`, { version: 0, data: quote() })
			expect('a draft quote with texts and attachments', q, 200)
			quoteMade = q.status === 200
			check('… stored as sent, no server fields', q.data?.data?.texts?.intro === 'Gerne offerieren wir' && q.data?.data?.attachments === 'Honorarofferte\nTerminplan' && q.data?.data?.issued === undefined && q.data?.data?.documents === undefined, q.data?.data)
			expect('an unknown text', await ocs(A, 'PUT', `/records/quote/${Q1}`, { version: q.data?.version, data: quote({ texts: { subject: 'x' } }) }), 422, 'invalid')
			const fr = await ocs(A, 'PUT', `/records/quote/${Q1}`, { version: q.data?.version, data: quote({ issued: { number: 'x' } }) })
			expect('issued set by the client', fr, 409, 'conflict')
			check('… quote_issued_frozen, field issued', fr.data?.code === 'quote_issued_frozen' && fr.data?.field === 'issued', fr.data)
			const nd = await upload(A, 'PUT', `${qpath(Q1)}/document`, fakePdf('OF', 'q'), 'application/pdf')
			expect('the TimeSister PDF before the issue', nd, 409, 'conflict')
			check('… quote_not_issued', nd.data?.code === 'quote_not_issued')
			// E1: a draft may be unnamed; the issue and every other status need the name.
			const unnamed = await ocs(A, 'PUT', `/records/quote/${Q1}`, { version: q.data?.version, data: quote({ name: '' }) })
			expect('a draft without a name', unnamed, 200)
			const noName = await ocs(L, 'POST', `${qpath(Q1)}/issue`, {})
			expect('issuing it without a name', noName, 422, 'invalid')
			check('… quote_name_required, field name', noName.data?.code === 'quote_name_required' && noName.data?.field === 'name', noName.data)
			const quotedUnnamed = await ocs(A, 'PUT', `/records/quote/${Q1}`, { version: unnamed.data?.version, data: quote({ name: null, status: 'quoted' }) })
			expect('a quoted quote without a name', quotedUnnamed, 422, 'invalid')
			check('… quote_name_required, field name', quotedUnnamed.data?.code === 'quote_name_required' && quotedUnnamed.data?.field === 'name', quotedUnnamed.data)
			expect('the name is back', await ocs(A, 'PUT', `/records/quote/${Q1}`, { version: unnamed.data?.version, data: quote() }), 200)
			expect('Member issues', await ocs(U1, 'POST', `${qpath(Q1)}/issue`, {}), 403, 'forbidden')
			expect('a bad date', await ocs(L, 'POST', `${qpath(Q1)}/issue`, { date: 'today' }), 422, 'invalid')
			const i = await ocs(L, 'POST', `${qpath(Q1)}/issue`, { date: '2026-10-29', valid_until: '2026-12-15', hours: 40, amount: 7200 })
			expect('the quote’s Lead issues', i, 200)
			const qn = i.data?.data?.number
			check('record: number from the quote pattern, status quoted, date', new RegExp(`^OR-${RUN}-\\d{3}$`).test(qn ?? '') && i.data?.data?.status === 'quoted' && i.data?.data?.date === '2026-10-29' && i.data?.data?.valid_until === '2026-12-15', i.data?.data)
			const iss = i.data?.data?.issued
			check('issued: at, by zblead, number, sums, customer { key, name }, project_draft, budgets, texts, attachments', /Z$/.test(iss?.at ?? '') && iss?.by === 'zblead' && iss?.number === qn && iss?.hours === 40 && iss?.amount === 7200 && iss?.customer?.key === CF && iss?.customer?.name === `Kunde ${RUN}` && iss?.project_draft?.name === 'Schulhaus Muster' && iss?.budgets?.[0]?.id === 'bq1' && iss?.texts?.greeting === 'Sehr geehrte Damen und Herren' && iss?.attachments === 'Honorarofferte\nTerminplan' && iss?.currency === 'CHF', iss)
			const twice = await ocs(A, 'POST', `${qpath(Q1)}/issue`, {})
			expect('issuing it again', twice, 409, 'conflict')
			check('… quote_not_draft', twice.data?.code === 'quote_not_draft')
			let v = i.data?.version
			const chg = await ocs(A, 'PUT', `/records/quote/${Q1}`, { version: v, data: quote({ number: 'OTHER', status: 'quoted', date: '2026-10-29' }) })
			expect('changing the number', chg, 409, 'conflict')
			check('… quote_issued_frozen, field number', chg.data?.code === 'quote_issued_frozen' && chg.data?.field === 'number', chg.data)
			const neg = await ocs(L, 'PUT', `/records/quote/${Q1}`, { version: v, data: quote({ number: qn, status: 'negotiation', date: '2026-10-29', budgets: [{ id: 'bq1', name: 'Vorprojekt', currency: 'CHF', cap: 20000 }] }) })
			expect('the Lead negotiates: status and live budgets change', neg, 200)
			check('… issued kept by the server, the offered budget unchanged', neg.data?.data?.issued?.budgets?.[0]?.cap === 24000 && neg.data?.data?.budgets?.[0]?.cap === 20000 && neg.data?.data?.status === 'negotiation', neg.data?.data)
			v = neg.data?.version
			const ts = await upload(L, 'PUT', `${qpath(Q1)}/document`, fakePdf(qn, 'TimeSister'), 'application/pdf')
			expect('the Lead files the TimeSister PDF', ts, 200)
			check('… document and documents[timesister]', ts.data?.document?.kind === 'timesister' && ts.data?.document?.name === `2026-10-29_${qn}_Kunde-${RUN}.pdf` && ts.data?.record?.data?.document?.sha256 === sha256(fakePdf(qn, 'TimeSister')) && ts.data?.record?.data?.documents?.length === 1, ts.data)
			expect('Member adds a sent copy', await upload(U1, 'POST', `${qpath(Q1)}/documents?kind=sent`, fakePdf(qn, 'sent'), 'application/pdf'), 403, 'forbidden')
			const bk = await upload(A, 'POST', `${qpath(Q1)}/documents?kind=timesister`, fakePdf(qn, 'sent'), 'application/pdf')
			expect('kind timesister on POST', bk, 422, 'invalid')
			check('… field kind', bk.data?.field === 'kind')
			expect('not a PDF', await upload(A, 'POST', `${qpath(Q1)}/documents?kind=sent`, 'hello', 'application/pdf'), 422, 'invalid')
			const s1 = await upload(A, 'POST', `${qpath(Q1)}/documents?kind=sent`, fakePdf(qn, 'sent'), 'application/pdf')
			expect('the sent copy', s1, 200)
			check('… name _gesendet.pdf, stored true, by zbadmin', s1.data?.document?.name === `2026-10-29_${qn}_Kunde-${RUN}_gesendet.pdf` && s1.data?.stored === true && s1.data?.document?.by === 'zbadmin', s1.data?.document)
			const s1b = await upload(A, 'POST', `${qpath(Q1)}/documents?kind=sent`, fakePdf(qn, 'sent'), 'application/pdf')
			check('the same sent copy again: 200, stored false', s1b.status === 200 && s1b.data?.stored === false, s1b.data)
			const s2 = await upload(A, 'POST', `${qpath(Q1)}/documents?kind=sent`, fakePdf(qn, 'sent v2'), 'application/pdf')
			check('a second sent version is added as _2', s2.status === 200 && s2.data?.document?.name === `2026-10-29_${qn}_Kunde-${RUN}_gesendet_2.pdf`, s2.data?.document)
			const sg = await upload(L, 'POST', `${qpath(Q1)}/documents?kind=signed`, fakePdf(qn, 'signed'), 'application/pdf')
			check('the signed copy: _unterschrieben.pdf', sg.status === 200 && sg.data?.document?.name === `2026-10-29_${qn}_Kunde-${RUN}_unterschrieben.pdf`, sg.data?.document)
			const list = await ocs(L, 'GET', `${qpath(Q1)}/documents`)
			expect('the list', list, 200)
			check('… four documents in filing order with kind, name, path, sha256, size, at, by', list.data?.documents?.map((d) => d.kind).join() === 'timesister,sent,sent,signed' && list.data?.documents?.every((d) => d.path && HEX64.test(d.sha256) && d.size > 0 && /Z$/.test(d.at) && d.by), list.data)
			expect('Member lists', await ocs(U1, 'GET', `${qpath(Q1)}/documents`), 403, 'forbidden')
			check('GET document (timesister by default)', (await fetchRaw(A, `${qpath(Q1)}/document`)).buf.toString() === fakePdf(qn, 'TimeSister'))
			check('GET document?kind=sent gives the latest', (await fetchRaw(A, `${qpath(Q1)}/document?kind=sent`)).buf.toString() === fakePdf(qn, 'sent v2'))
			check('GET document?kind=sent&name= gives that one', (await fetchRaw(A, `${qpath(Q1)}/document?kind=sent&name=${encodeURIComponent(s1.data?.document?.name)}`)).buf.toString() === fakePdf(qn, 'sent'))
			check('GET document?kind=signed', (await fetchRaw(L, `${qpath(Q1)}/document?kind=signed`)).buf.toString() === fakePdf(qn, 'signed'))
			check('an unknown name is 404', (await fetchRaw(A, `${qpath(Q1)}/document?kind=signed&name=nope.pdf`)).status === 404)
			const rec = await ocs(A, 'GET', `/records/quote/${Q1}`)
			check('the record carries documents[] (server field) and keeps them on a client write', rec.data?.data?.documents?.length === 4
				&& (await ocs(A, 'PUT', `/records/quote/${Q1}`, { version: rec.data?.version, data: { ...rec.data.data, documents: [], note: 'kept?' } })).data?.data?.documents?.length === 4)
			for (let n = 0; n < 6; n++) {
				await upload(A, 'POST', `${qpath(Q1)}/documents?kind=sent`, fakePdf(qn, `fill ${n}`), 'application/pdf')
			}
			const full = await upload(A, 'POST', `${qpath(Q1)}/documents?kind=sent`, fakePdf(qn, 'one too many'), 'application/pdf')
			expect('the eleventh document', full, 409, 'conflict')
			check('… quote_documents_max', full.data?.code === 'quote_documents_max')
		}
	} finally {
		head('Renderer: cleanup')
		for (const k of made) {
			const cur = await ocs(A, 'GET', path(k))
			if (cur.status === 200 && cur.data?.data?.status === 'issuing') {
				await ocs(A, 'POST', `${ipath(k)}/issue/cancel`, {})
			}
			const again = await ocs(A, 'GET', path(k))
			if (again.status === 200 && again.data?.data?.status === 'draft') {
				await ocs(A, 'DELETE', `${path(k)}?version=${again.data.version}`)
			}
		}
		if (quoteMade) {
			const q = await ocs(A, 'GET', `/records/quote/${Q1}`)
			if (q.status === 200) {
				await ocs(A, 'DELETE', `/records/quote/${Q1}?version=${q.data.version}`)
			}
		}
		await deleteEvent(U1, EV1)
		await deleteEvent(U1, EV2)
		for (const [kind, key] of [['project', PF], ['customer', CF]]) {
			const cur = await ocs(A, 'GET', `/records/${kind}/${key}`)
			if (cur.status === 200) {
				await ocs(A, 'DELETE', `/records/${kind}/${key}?version=${cur.data.version}`)
			}
		}
		await ocs(A, 'PUT', '/team/settings', { documents: docsBefore })
		const now = await ocs(A, 'GET', '/records/setting/settings')
		if (now.status === 200) {
			await ocs(A, 'PUT', '/records/setting/settings', { version: now.data.version, data: base })
		}
		check('cleanup done', true)
	}
}
