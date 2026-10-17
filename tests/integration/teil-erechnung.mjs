// SPDX-License-Identifier: AGPL-3.0-or-later
//
// E-invoices and exports (0.20.0): the tax case from the countries and the
// draft's override (frozen into `fixed.customer.tax_case`, VAT 0 abroad),
// the stored PDF with `factur-x.xml` inside, the XRechnung file of a
// customer with a Leitweg-ID, the QR reference of a QR-IBAN with the
// BESR id, the exports (CSV, DATEV, ledger), the hand-over to an
// accounting system, the webhook setting, its queue after an issue and
// the status endpoint. Positions only, no bookings: no calendar needed.
// Leaves pb as it found it (settings record, team settings, project,
// customers); the frozen rows only with TS_SQL, the ledger stays. KoSIT
// runs only with `java` and TS_KOSIT, otherwise the structure is checked.
import { execFileSync } from 'node:child_process'
import { existsSync, mkdtempSync, writeFileSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { ocs, runJob, sql } from './lib.mjs'
import { A, L, RUN, U1, check, expect, head, isObj, me } from './harness.mjs'

const path = (key) => `/records/invoice/${encodeURIComponent(key)}`
const ipath = (key) => `/invoices/${encodeURIComponent(key)}`

/** A raw GET of the app API as `who`: status, content type, disposition and body bytes. */
async function fetchRaw(who, p) {
	const res = await fetch(`${process.env.NC_URL || 'http://localhost:8081'}/ocs/v2.php/apps/timesister/api/v1${p}`, {
		headers: { 'OCS-APIRequest': 'true', 'Accept-Language': 'en', Authorization: 'Basic ' + Buffer.from(`${who.user}:${who.pass}`).toString('base64') },
	})
	const buf = Buffer.from(await res.arrayBuffer())
	return { status: res.status, type: res.headers.get('content-type') || '', disposition: res.headers.get('content-disposition') || '', buf, text: buf.toString('utf8') }
}

/** Modulo 10 recursive (Swiss Payment Standards). */
function qrCheckDigit(digits) {
	const table = [0, 9, 4, 6, 8, 2, 7, 1, 3, 5]
	let carry = 0
	for (const d of digits) {
		carry = table[(carry + Number(d)) % 10]
	}
	return (10 - carry) % 10
}

/** Issues a draft and waits for its document; returns `fixed`. */
async function issueAndFix(key, issuedOn) {
	const r = await ocs(A, 'POST', `${ipath(key)}/issue`, { issued_on: issuedOn })
	if (r.status !== 200) {
		return { issue: r, fixed: null }
	}
	let f = await ocs(A, 'GET', `${ipath(key)}/fixed`)
	if (f.data?.document_state !== 'done') {
		await ocs(A, 'POST', `${ipath(key)}/render`, {})
		f = await ocs(A, 'GET', `${ipath(key)}/fixed`)
	}
	return { issue: r, fixed: f.data }
}

/** KoSIT: `java -jar <TS_KOSIT>/validationtool-*.jar -s <scenarios.xml> -r <dir> file.xml`; null when not available. */
function kosit(xml) {
	const dir = process.env.TS_KOSIT
	if (!dir || !existsSync(dir)) {
		return null
	}
	let java = null
	try {
		java = execFileSync('which', ['java'], { encoding: 'utf8' }).trim()
	} catch {
		return null
	}
	if (!java) {
		return null
	}
	const tmp = mkdtempSync(join(tmpdir(), 'ts-kosit-'))
	try {
		const file = join(tmp, 'invoice.xml')
		writeFileSync(file, xml)
		const jars = execFileSync('sh', ['-c', `ls "${dir}"/validationtool-*.jar | head -1`], { encoding: 'utf8' }).trim()
		const scenarios = execFileSync('sh', ['-c', `ls "${dir}"/scenarios.xml "${dir}"/*/scenarios.xml 2>/dev/null | head -1`], { encoding: 'utf8' }).trim()
		if (!jars || !scenarios) {
			return null
		}
		execFileSync(java, ['-jar', jars, '-s', scenarios, '-r', dir, '-h', file], { encoding: 'utf8', stdio: 'pipe' })
		return true
	} catch (e) {
		return `${e.stdout || ''}${e.stderr || ''}`.slice(0, 600)
	} finally {
		rmSync(tmp, { recursive: true, force: true })
	}
}

export async function erechnung() {
	const PF = `PE-${RUN}`
	const CODE = `E${RUN}`.toUpperCase()
	const customers = { ch: `CE-${RUN}-ch`, de: `CE-${RUN}-de`, fr: `CE-${RUN}-fr`, pub: `CE-${RUN}-pub` }
	const keys = { chch: `inv-${RUN}e1`, dede: `inv-${RUN}e2`, defr: `inv-${RUN}e3`, chde: `inv-${RUN}e4`, pub: `inv-${RUN}e5`, qrr: `inv-${RUN}e6`, over: `inv-${RUN}e7` }
	const made = Object.values(keys)
	const Y = 3000 + (parseInt(RUN.slice(-4), 36) % 5000) + 9
	const ISSUED_ON = `${Y}-03-10`
	const tenant = sql("SELECT id FROM oc_ts_tenants WHERE slug = 'pb'")
	const settings = await ocs(A, 'GET', '/records/setting/settings')
	const base = settings.status === 200 ? settings.data.data : {}
	let settingsVersion = settings.status === 200 ? settings.data.version : 0
	const teamBefore = (await ocs(A, 'GET', '/team')).data
	const datevBefore = teamBefore?.settings?.datev || null
	const webhooksBefore = teamBefore?.settings?.webhooks || []
	const issuer = (country, more = {}) => ({ name: 'Denkstatt sàrl', street: 'Dornacherstrasse 192', postcode: country === 'DE' ? '79539' : '4053', city: country === 'DE' ? 'Lörrach' : 'Basel', country, vat_id: country === 'DE' ? 'DE123456789' : 'CHE-123.456.789 MWST', email: 'mail@example.test', phone: '+41 61 000 00 00', qr: true,
		accounts: [{ currency: 'CHF', iban: 'CH9300762011623852957', bic: '' }, { currency: 'EUR', iban: 'DE89370400440532013000', bic: 'COBADEFFXXX' }], ...more })
	const setIssuer = async (country, more = {}, vat = 8.1) => {
		const r = await ocs(A, 'PUT', '/records/setting/settings', { version: settingsVersion, data: { ...base, language: 'de', vat, invoice_number: { pattern: `RE-${RUN}-{no:4}`, per_year: true, start: 1 }, issuer: issuer(country, more) } })
		settingsVersion = r.data?.version ?? settingsVersion
		return r
	}
	const draft = (customerKey, currency, more = {}) => ({
		schema: 1, project: PF, budget: currency === 'EUR' ? 'b2' : 'b1', from: '2026-10-01', to: '2026-10-31', status: 'draft', number: null, bookings: [],
		positions: [{ kind: 'hours', text: 'Planung', hours: 10, rate: 150, amount: 1500 }, { kind: 'flat', text: 'Spesen', amount: 100 }, { kind: 'discount', text: 'Rabatt', amount: -100 }],
		greeting: 'Guten Tag', body: 'Zahlbar innert 30 Tagen.', footer: 'Denkstatt · Basel', note: null, created_by: 'pbadmin', created_on: '2026-10-15', issued_on: null, paid_on: null, ...more,
	})
	try {
		head('E-invoices: setup')
		{
			const cap = await ocs(A, 'GET', '/ocs/v2.php/cloud/capabilities')
			check('capabilities e_invoice: 1 and invoice_export: 1', cap.data?.capabilities?.timesister?.e_invoice === 1 && cap.data?.capabilities?.timesister?.invoice_export === 1, cap.data?.capabilities?.timesister)
			check('/team.settings carries datev and webhooks', isObj(teamBefore?.settings?.datev) && Array.isArray(teamBefore?.settings?.webhooks), teamBefore?.settings)
			expect('issuer with a BESR id of 5 digits', await ocs(A, 'PUT', '/records/setting/settings', { version: settingsVersion, data: { ...base, issuer: issuer('CH', { besr_id: '12345' }) } }), 422, 'invalid')
			expect('Team Admin sets a Swiss issuer', await setIssuer('CH'), 200)
			expect('customer CH', await ocs(A, 'PUT', `/records/customer/${customers.ch}`, { version: 0, data: { schema: 1, id: customers.ch, name: `Müller AG ${RUN}`, street: 'Platz 1', postcode: '4001', city: 'Basel', country: 'CH', email: 'ch@example.test' } }), 200)
			expect('customer DE', await ocs(A, 'PUT', `/records/customer/${customers.de}`, { version: 0, data: { schema: 1, id: customers.de, name: `Schmidt GmbH ${RUN}`, street: 'Weg 2', postcode: '10115', city: 'Berlin', country: 'DE', vat_id: 'DE987654321', email: 'de@example.test', datev_account: '10077' } }), 200)
			expect('customer FR with VAT id', await ocs(A, 'PUT', `/records/customer/${customers.fr}`, { version: 0, data: { schema: 1, id: customers.fr, name: `SARL Exemple ${RUN}`, street: 'Rue 3', postcode: '75001', city: 'Paris', country: 'FR', vat_id: 'FR12345678901', email: 'fr@example.test' } }), 200)
			expect('customer DE public with Leitweg-ID', await ocs(A, 'PUT', `/records/customer/${customers.pub}`, { version: 0, data: { schema: 1, id: customers.pub, name: `Stadt Beispiel ${RUN}`, street: 'Rathausplatz 1', postcode: '10115', city: 'Berlin', country: 'DE', email: 'rechnung@example.test', leitweg_id: '04011000-1234512345-06' } }), 200)
			expect('customer with e_invoice as text', await ocs(A, 'PUT', `/records/customer/${customers.pub}`, { version: 1, data: { schema: 1, id: customers.pub, name: 'x', e_invoice: 'yes' } }), 422, 'invalid')
			expect('customer with a Leitweg-ID of 47 characters', await ocs(A, 'PUT', `/records/customer/${customers.pub}`, { version: 1, data: { schema: 1, id: customers.pub, name: 'x', leitweg_id: '1'.repeat(47) } }), 422, 'invalid')
			expect('customer with datev_account as number', await ocs(A, 'PUT', `/records/customer/${customers.pub}`, { version: 1, data: { schema: 1, id: customers.pub, name: 'x', datev_account: 10001 } }), 422, 'invalid')
			expect('project with budgets in CHF and EUR, customer CH', await ocs(A, 'PUT', `/records/project/${PF}`, { version: 0, data: { schema: 1, id: PF, name: `E-Rechnung ${RUN}`, codes: [CODE], status: 'active', leads: ['pblead'], customer: customers.ch, budgets: [{ id: 'b1', name: 'CHF', currency: 'CHF' }, { id: 'b2', name: 'EUR', currency: 'EUR' }] } }), 200)
			expect('a draft with a bad tax_case', await ocs(A, 'PUT', path(keys.over), { version: 0, data: draft(customers.ch, 'CHF', { tax_case: 'abroad' }) }), 422, 'invalid')
		}
		const pointProjectTo = async (customerKey) => {
			const cur = await ocs(A, 'GET', `/records/project/${PF}`)
			return ocs(A, 'PUT', `/records/project/${PF}`, { version: cur.data.version, data: { ...cur.data.data, customer: customerKey } })
		}

		head('E-invoices: tax cases')
		let chch = null
		{
			expect('draft CH→CH', await ocs(A, 'PUT', path(keys.chch), { version: 0, data: draft(customers.ch, 'CHF') }), 200)
			const r = await issueAndFix(keys.chch, ISSUED_ON)
			expect('issue CH→CH', r.issue, 200)
			chch = r.fixed
			check('fixed: tax_case domestic, VAT 8.1 %, customer.tax_case frozen', chch?.tax_case === 'domestic' && chch?.vat === 8.1 && chch?.customer?.tax_case === 'domestic' && chch?.vat_amount === 121.5, chch)
			check('fixed: counter_no 1, qr_reference null (plain IBAN), xml_path null', chch?.counter_no === 1 && chch?.qr_reference === null && chch?.xml_path === null && chch?.external === null, chch)
			check('fixed: document done', chch?.document_state === 'done', chch?.document_error)
		}
		{
			expect('Team Admin sets a German issuer, VAT 19', await setIssuer('DE', {}, 19), 200)
			expect('project → customer DE', await pointProjectTo(customers.de), 200)
			expect('draft DE→DE', await ocs(A, 'PUT', path(keys.dede), { version: 0, data: draft(customers.de, 'EUR') }), 200)
			const r = await issueAndFix(keys.dede, ISSUED_ON)
			expect('issue DE→DE', r.issue, 200)
			check('fixed: domestic, VAT 19 %', r.fixed?.tax_case === 'domestic' && r.fixed?.vat === 19 && r.fixed?.gross === 1785, r.fixed)
			expect('project → customer FR', await pointProjectTo(customers.fr), 200)
			expect('draft DE→FR', await ocs(A, 'PUT', path(keys.defr), { version: 0, data: draft(customers.fr, 'EUR') }), 200)
			const fr = await issueAndFix(keys.defr, ISSUED_ON)
			expect('issue DE→FR', fr.issue, 200)
			check('fixed: eu_reverse (both EU, VAT id), VAT 0, gross = net', fr.fixed?.tax_case === 'eu_reverse' && fr.fixed?.vat === 0 && fr.fixed?.vat_amount === 0 && fr.fixed?.gross === 1500, fr.fixed)
			expect('project → customer DE again', await pointProjectTo(customers.de), 200)
			expect('draft DE→DE with the override export', await ocs(A, 'PUT', path(keys.over), { version: 0, data: draft(customers.de, 'EUR', { tax_case: 'export' }) }), 200)
			const over = await issueAndFix(keys.over, ISSUED_ON)
			expect('issue with the override', over.issue, 200)
			check('fixed: export by override, VAT 0', over.fixed?.tax_case === 'export' && over.fixed?.vat === 0, over.fixed)
		}
		{
			expect('Team Admin sets the Swiss issuer again', await setIssuer('CH'), 200)
			expect('draft CH→DE', await ocs(A, 'PUT', path(keys.chde), { version: 0, data: draft(customers.de, 'EUR') }), 200)
			const r = await issueAndFix(keys.chde, ISSUED_ON)
			expect('issue CH→DE', r.issue, 200)
			check('fixed: export (issuer outside the EU), VAT 0', r.fixed?.tax_case === 'export' && r.fixed?.vat === 0 && r.fixed?.gross === 1500, r.fixed)
		}

		head('E-invoices: ZUGFeRD and XRechnung')
		{
			const doc = await fetchRaw(A, `${ipath(keys.chch)}/document?kind=pdf`)
			check('the stored PDF is PDF/A-3 with factur-x.xml inside', doc.status === 200 && doc.buf.subarray(0, 5).toString() === '%PDF-' && doc.buf.includes('factur-x.xml') && doc.buf.includes('pdfaid:part>3<'), [doc.status, doc.buf.length])
			check('… the chain line is still its subject', doc.buf.includes(`Beleg ${chch?.number} · Kette ${chch?.chain?.seq} `))
			check('… no XRechnung for a Swiss customer', (await fetchRaw(A, `${ipath(keys.chch)}/document?kind=xml`)).status === 404)
			const lg = await ocs(A, 'GET', `/invoices/ledger?since=${chch?.chain?.seq}&limit=20`)
			const docs = (lg.data?.entries || []).filter((e) => e.action === 'document' && e.number === chch?.number)
			check('ledger: one document entry, kind zugferd, sha256 of the stored file', docs.length === 1 && JSON.parse(docs[0].payload).kind === 'zugferd' && JSON.parse(docs[0].payload).sha256 === chch?.document_sha256, docs)
			expect('project → public customer', await pointProjectTo(customers.pub), 200)
			expect('draft CH→DE public', await ocs(A, 'PUT', path(keys.pub), { version: 0, data: draft(customers.pub, 'EUR') }), 200)
			const r = await issueAndFix(keys.pub, ISSUED_ON)
			expect('issue for the public customer', r.issue, 200)
			check('fixed: xml_path <root>/Buchhaltung/<year>/Rechnungen/<date>_<number>_<customer>.xml (0.22.0) and xml_sha256', typeof r.fixed?.xml_path === 'string' && r.fixed.xml_path.includes(`/Buchhaltung/${Y}/Rechnungen/${r.fixed.issued_on}_${r.fixed.number}_`) && r.fixed.xml_path.endsWith('.xml') && /^[0-9a-f]{64}$/.test(r.fixed?.xml_sha256 || ''), r.fixed)
			const xml = await fetchRaw(A, `${ipath(keys.pub)}/document?kind=xml`)
			check('document?kind=xml: application/xml, <number>.xml', xml.status === 200 && xml.type.startsWith('application/xml') && xml.disposition.includes(`${r.fixed?.number}.xml`), [xml.status, xml.type, xml.disposition])
			check('… CII, profile XRechnung 3.0, the Leitweg-ID as BuyerReference, the number, the totals', xml.text.includes('<rsm:CrossIndustryInvoice') && xml.text.includes('urn:xeinkauf.de:kosit:xrechnung_3.0') && xml.text.includes('<ram:BuyerReference>04011000-1234512345-06</ram:BuyerReference>') && xml.text.includes(`<ram:ID>${r.fixed?.number}</ram:ID>`) && xml.text.includes('<ram:GrandTotalAmount>1500.00</ram:GrandTotalAmount>'), xml.text.slice(0, 300))
			check('… export: category G with VATEX-EU-G is not used inside the EU; E instead', xml.text.includes('<ram:CategoryCode>E</ram:CategoryCode>'))
			const lead = await fetchRaw(L, `${ipath(keys.pub)}/document?kind=xml`)
			check('the project’s Lead reads the XML too', lead.status === 200)
			check('a Member does not', (await fetchRaw(U1, `${ipath(keys.pub)}/document?kind=xml`)).status === 403)
			const lg2 = await ocs(A, 'GET', `/invoices/ledger?since=${r.fixed?.chain?.seq}&limit=20`)
			const kinds = (lg2.data?.entries || []).filter((e) => e.action === 'document' && e.number === r.fixed?.number).map((e) => JSON.parse(e.payload).kind).sort()
			check('ledger: document entries zugferd and xrechnung', JSON.stringify(kinds) === '["xrechnung","zugferd"]', kinds)
			const v = await ocs(A, 'GET', '/invoices/chain/verify')
			check('the chain is still valid', v.data?.ok === true, v.data)
			const k = kosit(xml.text)
			if (k === null) {
				console.log('    info  KoSIT validator not run (needs java and TS_KOSIT=<folder with validationtool-*.jar and scenarios.xml>) – structure checked instead')
			} else {
				check('KoSIT validator accepts the XRechnung', k === true, k)
			}
		}

		head('E-invoices: QR reference with a QR-IBAN')
		{
			expect('Team Admin sets a QR-IBAN with BESR id', await setIssuer('CH', { besr_id: '123456', accounts: [{ currency: 'CHF', iban: 'CH4431999123000889012', bic: '' }] }), 200)
			expect('project → customer CH', await pointProjectTo(customers.ch), 200)
			expect('draft CH→CH with QR-IBAN', await ocs(A, 'PUT', path(keys.qrr), { version: 0, data: draft(customers.ch, 'CHF') }), 200)
			const r = await issueAndFix(keys.qrr, ISSUED_ON)
			expect('issue', r.issue, 200)
			const ref = r.fixed?.qr_reference
			check('fixed: qr_reference 27 digits, BESR id first, the year before the check digit', typeof ref === 'string' && /^\d{27}$/.test(ref) && ref.startsWith('123456') && ref.slice(22, 26) === String(Y), ref)
			check('… counter zero-padded in between, valid check digit', typeof ref === 'string' && ref.slice(6, 22) === String(r.fixed?.counter_no).padStart(16, '0') && qrCheckDigit(ref.slice(0, 26)) === Number(ref[26]), ref)
			const doc = await fetchRaw(A, `${ipath(keys.qrr)}/document`)
			check('the document is stored with a payment part (two pages; 0.19.0 left it out for a QR-IBAN)', doc.status === 200 && (doc.buf.toString('latin1').match(/\/Type \/Page\b/g) || []).length === 2 && doc.buf.includes('factur-x.xml'), doc.status)
			const lg = await ocs(A, 'GET', `/invoices/ledger?since=${r.fixed?.chain?.seq - 1}&limit=3`)
			const issued = (lg.data?.entries || []).find((e) => e.action === 'issued' && e.number === r.fixed?.number)
			check('the reference is in the issue payload', issued !== undefined && JSON.parse(issued.payload).qr_reference === ref)
		}

		head('E-invoices: exports')
		{
			expect('export by a Lead', await fetchRaw(L, '/invoices/export?kind=csv').then((x) => ({ status: x.status, data: null, text: '' })), 403)
			expect('export of an unknown kind', await fetchRaw(A, '/invoices/export?kind=xlsx').then((x) => ({ status: x.status, data: null, text: x.text })), 422)
			expect('export with a bad day', await fetchRaw(A, '/invoices/export?kind=csv&from=2026-13-01').then((x) => ({ status: x.status, data: null, text: x.text })), 422)
			const csv = await fetchRaw(A, `/invoices/export?kind=csv&from=${Y}-01-01&to=${Y}-12-31`)
			check('CSV: text/csv, BOM, semicolons, header', csv.status === 200 && csv.type.startsWith('text/csv') && csv.buf.subarray(0, 3).equals(Buffer.from([0xef, 0xbb, 0xbf])) && csv.text.includes('number;status;issued_on;due_on;paid_on;customer;customer_vat_id;project;budget;currency;net;vat_rate;vat_amount;gross;tax_case;reverses;document_sha256;chain_seq;chain_hash'), [csv.status, csv.type, csv.text.slice(0, 100)])
			const lines = csv.text.split('\r\n').filter((l) => l.startsWith(`RE-${RUN}-`))
			check('… one row per issued invoice of the year, with the tax cases', lines.length === made.length && lines.some((l) => l.includes(';domestic;')) && lines.some((l) => l.includes(';eu_reverse;')) && lines.some((l) => l.includes(';export;')), lines.length)
			check('… the CH→CH row: net, VAT, gross, chain seq', lines.some((l) => l.startsWith(`${chch?.number};issued;${ISSUED_ON};`) && l.includes(';CHF;1500.00;8.10;121.50;1621.50;domestic;;') && l.includes(`;${chch?.chain?.seq};${chch?.chain?.hash}`)), lines.find((l) => l.startsWith(`${chch?.number};`)))
			expect('DATEV without accounts', await fetchRaw(A, '/invoices/export?kind=datev').then((x) => ({ status: x.status, data: null, text: x.text })), 422)
			expect('datev setting with a bad field', await ocs(A, 'PUT', '/team/settings', { datev: { consultant: '12345678' } }), 422, 'invalid')
			expect('datev setting by a Lead', await ocs(L, 'PUT', '/team/settings', { datev: { consultant: '1234567' } }), 403, 'forbidden')
			const set = await ocs(A, 'PUT', '/team/settings', { datev: { consultant: '1234567', client: '12345', fiscal_year_start: '01-01', account_length: 4, debtor_account: '10000', revenue_domestic: '8400', revenue_eu: '8336', revenue_export: '8338' } })
			expect('Team Admin sets the DATEV accounts', set, 200)
			check('… the answer and /team carry them', set.data?.datev?.revenue_eu === '8336' && (await ocs(A, 'GET', '/team')).data?.settings?.datev?.client === '12345', set.data?.datev)
			const datev = await fetchRaw(A, `/invoices/export?kind=datev&from=${Y}-01-01&to=${Y}-12-31`)
			check('DATEV: EXTF 700 header, column names, one booking per invoice', datev.status === 200 && datev.text.startsWith('﻿EXTF;700;21;Buchungsstapel;12;') && datev.text.includes(';1234567;12345;' + Y + '0101;4;' + Y + '0101;' + Y + '1231;') && datev.text.includes('Umsatz (ohne Soll/Haben-Kz);Soll/Haben-Kennzeichen;') && datev.text.split('\r\n').filter((l) => l.includes(`;RE-${RUN}-`)).length === made.length, datev.text.slice(0, 200))
			check('… CH→CH: 1621,50 Soll on 10000 against 8400, Belegdatum 1003, number, text', datev.text.includes(`1621,50;S;CHF;;;;10000;8400;;1003;${chch?.number};;;Müller AG ${RUN} E-Rechnung ${RUN}`), datev.text.split('\r\n').find((l) => l.includes(chch?.number || '-')))
			check('… DE→DE uses the customer’s debtor account 10077; DE→FR the EU revenue account, CH→DE the export account', datev.text.includes(';10077;8400;') && datev.text.includes(';10000;8336;') && datev.text.includes(';10077;8338;'))
			const ledger = await fetchRaw(A, `/invoices/export?kind=ledger`)
			check('ledger CSV: header and the issue entries', ledger.status === 200 && ledger.text.includes('seq;at;actor;action;number;payload_hash;prev_hash;hash') && ledger.text.includes(`;issued;${chch?.number};`), ledger.text.slice(0, 120))
		}

		head('E-invoices: hand-over to an accounting system')
		{
			expect('external with a bad system', await ocs(A, 'POST', `${ipath(keys.chch)}/external`, { system: 'Sage AG', id: '1' }), 422, 'invalid')
			expect('external of a draft', await ocs(A, 'POST', `${ipath(`inv-${RUN}zz`)}/external`, { system: 'bexio', id: '1' }), 404, 'not_found')
			expect('external by a Lead', await ocs(L, 'POST', `${ipath(keys.chch)}/external`, { system: 'bexio', id: '1' }), 403, 'forbidden')
			const r = await ocs(A, 'POST', `${ipath(keys.chch)}/external`, { system: 'bexio', id: 4711, url: 'https://office.bexio.com/kb_invoice/show/4711' })
			expect('Team Admin records the hand-over', r, 200)
			check('… fixed.external {system, id, url, at, by} and a ledger block', r.data?.external?.system === 'bexio' && r.data?.external?.id === '4711' && r.data?.external?.by === 'pbadmin' && typeof r.data?.ledger?.seq === 'number', r.data?.external)
			const lg = await ocs(A, 'GET', `/invoices/ledger?since=${r.data?.ledger?.seq - 1}&limit=2`)
			const e = (lg.data?.entries || []).find((x) => x.seq === r.data?.ledger?.seq)
			check('ledger: exported with payload {key, number, system, id, url}', e?.action === 'exported' && JSON.parse(e.payload).system === 'bexio' && JSON.parse(e.payload).id === '4711', e)
			check('fixed carries it', (await ocs(A, 'GET', `${ipath(keys.chch)}/fixed`)).data?.external?.id === '4711')
			// 0.23.1: any system name; the invoice record carries `external` too.
			expect('external with a system name that is not lower case', await ocs(A, 'POST', `${ipath(keys.chch)}/external`, { system: 'SevDesk', id: '1' }), 422, 'invalid')
			const before = (await ocs(A, 'GET', path(keys.chch))).data
			const s = await ocs(A, 'POST', `${ipath(keys.chch)}/external`, { system: 'sevdesk', id: 'SD-17', url: 'https://my.sevdesk.de/fi/detail/type/RE/id/17' })
			expect('Team Admin records a hand-over to sevdesk', s, 200)
			const ext = s.data?.record?.data?.external
			check('… the answer carries the written record with external {system, id, url, at, by}', s.data?.external?.system === 'sevdesk' && ext?.system === 'sevdesk' && ext?.id === 'SD-17' && ext?.url === 'https://my.sevdesk.de/fi/detail/type/RE/id/17' && ext?.by === 'pbadmin' && ext?.at === s.data?.external?.at && s.data?.record?.version === before?.version + 1, s.data?.record)
			const rec = (await ocs(A, 'GET', path(keys.chch))).data
			check('the invoice record carries external, the rest unchanged', rec?.data?.external?.system === 'sevdesk' && rec?.data?.external?.id === 'SD-17' && rec?.data?.status === before?.data?.status && rec?.data?.number === before?.data?.number, rec?.data?.external)
			expect('a client still may not write the issued invoice (external included)', await ocs(A, 'PUT', path(keys.chch), { version: rec?.version, data: { ...rec?.data, external: { system: 'datev', id: '1' } } }), 409, 'issued')
			// 0.24.0: a credit note is a document of its own – it does not inherit the original's hand-over.
			const cr = await ocs(A, 'POST', `${ipath(keys.chch)}/reverse`, { kind: 'credit', reason: 'Copy check' })
			expect('a credit note of the handed-over invoice', cr, 200)
			check('… without the original’s external; the original keeps it', (cr.data?.record?.data?.external ?? null) === null
				&& (await ocs(A, 'GET', path(keys.chch))).data?.data?.external?.id === 'SD-17', [cr.data?.record?.data?.external])
		}

		head('E-invoices: webhooks')
		{
			expect('a webhook over http', await ocs(A, 'PUT', '/team/settings', { webhooks: [{ url: 'http://hooks.example.org/ts', events: ['issued'] }] }), 422, 'invalid')
			expect('a webhook with an unknown event', await ocs(A, 'PUT', '/team/settings', { webhooks: [{ url: 'https://hooks.example.org/ts', events: ['deleted'] }] }), 422, 'invalid')
			expect('eleven webhooks', await ocs(A, 'PUT', '/team/settings', { webhooks: Array.from({ length: 11 }, (_, i) => ({ url: `https://hooks.example.org/${i}`, events: ['paid'] })) }), 422, 'invalid')
			expect('webhooks by a Lead', await ocs(L, 'PUT', '/team/settings', { webhooks: [] }), 403, 'forbidden')
			const set = await ocs(A, 'PUT', '/team/settings', { webhooks: [{ url: `https://hooks.invalid/ts-${RUN}`, events: ['issued', 'document', 'exported'], secret: 's3cret' }] })
			expect('Team Admin sets a webhook', set, 200)
			check('… shown without the secret, with secret_set', set.data?.webhooks?.[0]?.url === `https://hooks.invalid/ts-${RUN}` && set.data?.webhooks?.[0]?.secret_set === true && set.data?.webhooks?.[0]?.secret === undefined, set.data?.webhooks)
			const again = await ocs(A, 'PUT', '/team/settings', { webhooks: [{ url: `https://hooks.invalid/ts-${RUN}`, events: ['issued', 'document'] }] })
			check('a hook sent without secret keeps it', again.data?.webhooks?.[0]?.secret_set === true, again.data?.webhooks)
			expect('project → customer CH', await pointProjectTo(customers.ch), 200)
			const K8 = `inv-${RUN}e8`
			made.push(K8)
			expect('draft', await ocs(A, 'PUT', path(K8), { version: 0, data: draft(customers.ch, 'CHF') }), 200)
			const r = await issueAndFix(K8, ISSUED_ON)
			expect('issue with a webhook listening', r.issue, 200)
			check('the issue is not blocked by the unreachable address', r.fixed?.document_state === 'done', r.fixed?.document_error)
			expect('webhook status by a Lead', await ocs(L, 'GET', '/invoices/webhooks/status'), 403, 'forbidden')
			const st = await ocs(A, 'GET', '/invoices/webhooks/status')
			expect('webhook status', st, 200)
			const hook = (st.data?.hooks || []).find((h) => h.url === `https://hooks.invalid/ts-${RUN}`)
			check('… the hook with two deliveries waiting (issued, document)', hook !== undefined && hook.pending + hook.failed === 2 && hook.delivered === 0, hook)
			if (tenant !== null) {
				const n = sql(`SELECT COUNT(*) FROM oc_ts_inv_webhook_queue WHERE tenant_id = ${tenant} AND url = 'https://hooks.invalid/ts-${RUN}' AND delivered_at IS NULL`)
				check('the queue holds the two rows (TS_SQL)', n === '2', n)
				const body = sql(`SELECT body FROM oc_ts_inv_webhook_queue WHERE tenant_id = ${tenant} AND url = 'https://hooks.invalid/ts-${RUN}' AND event = 'issued'`)
				check('… body {team, event, at, invoice {key, number, status, gross, currency}, ledger {seq, hash}}', (() => { try { const b = JSON.parse(body); return b.team === 'pb' && b.event === 'issued' && b.invoice.key === K8 && b.invoice.number === r.fixed?.number && b.invoice.gross === 1621.5 && b.invoice.currency === 'CHF' && typeof b.ledger.seq === 'number' && /^[0-9a-f]{64}$/.test(b.ledger.hash) } catch { return false } })(), body)
			}
			if (!process.env.TS_OCC) {
				console.log('    info  TS_OCC not set – the delivery job is skipped')
			} else {
				check('the WebhookDelivery job runs', runJob('WebhookDelivery'))
				const after = (await ocs(A, 'GET', '/invoices/webhooks/status')).data?.hooks?.find((h) => h.url === `https://hooks.invalid/ts-${RUN}`)
				check('… the deliveries failed with an error, still pending for the next try', after !== undefined && after.delivered === 0 && typeof after.last_error === 'string' && after.last_error !== '' && after.pending + after.failed === 2, after)
			}
		}
	} finally {
		head('E-invoices: cleaning up')
		await ocs(A, 'PUT', '/team/settings', { webhooks: webhooksBefore.length ? webhooksBefore : null, datev: datevBefore || {} })
		if (tenant !== null) {
			const list = made.map((k) => `'${k}'`).join(', ')
			sql(`DELETE FROM oc_ts_inv_webhook_queue WHERE tenant_id = ${tenant} AND url LIKE 'https://hooks.invalid/ts-${RUN}%'`)
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
		for (const [kind, key] of [['project', PF], ...Object.values(customers).map((c) => ['customer', c])]) {
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
