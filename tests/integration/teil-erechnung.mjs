// SPDX-License-Identifier: AGPL-3.0-or-later
//
// E-invoices and exports (0.20.0, one renderer since 0.32.0): the tax case
// from the countries and the draft's override (frozen into
// `fixed.customer.tax_case` and handed to the app in the reservation, VAT 0
// abroad), the XRechnung XML the app sends after the close (`?kind=xml`:
// filed, hashed into the ledger, served), the QR reference of a QR-IBAN
// with the BESR id (frozen at the reservation), the exports (CSV, DATEV,
// ledger), the hand-over to an accounting system, the webhook setting, its
// queue after an issue and the status endpoint. What the server's XML and
// PDF used to be checked for (ZUGFeRD, KoSIT, tax categories) is the Mac
// app's now (core/src/rechnung/erechnung_xml.rs). Positions only, no
// bookings: no calendar needed. Leaves zb as it found it (settings record,
// team settings, project, customers); the frozen rows only with TS_SQL,
// the ledger stays.
import { ocs, runJob, sql } from './lib.mjs'
import { RUN, check, expect, festschreiben, head, isObj, me, raw, sha256, stornieren } from './harness.mjs'
import { A, L, U1 } from './konten.mjs'

const path = (key) => `/records/invoice/${encodeURIComponent(key)}`
const ipath = (key) => `/invoices/${encodeURIComponent(key)}`

/** Modulo 10 recursive (Swiss Payment Standards). */
function qrCheckDigit(digits) {
	const table = [0, 9, 4, 6, 8, 2, 7, 1, 3, 5]
	let carry = 0
	for (const d of digits) {
		carry = table[(carry + Number(d)) % 10]
	}
	return (10 - carry) % 10
}

/** Issues a draft the 0.32.0 way (reservation, the app's PDF closes); returns the reservation, the close and `fixed` as read after the close. */
async function issueAndFix(key, issuedOn) {
	const r = await festschreiben(A, key, { issued_on: issuedOn })
	if (r.close?.status !== 200) {
		return { ...r, issue: r.reserve.status === 200 ? r.close : r.reserve, fixed: null }
	}
	return { ...r, issue: r.close, reservation: r.reserve.data?.issue, fixed: (await ocs(A, 'GET', `${ipath(key)}/fixed`)).data }
}

/** A minimal CII XML as the app's XRechnung would carry it: the number, the buyer reference, the grand total. */
const cii = (number, buyerReference, total) => `<?xml version="1.0" encoding="UTF-8"?><rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100" xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100"><rsm:ExchangedDocumentContext><ram:GuidelineSpecifiedDocumentContextParameter><ram:ID>urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0</ram:ID></ram:GuidelineSpecifiedDocumentContextParameter></rsm:ExchangedDocumentContext><rsm:ExchangedDocument><ram:ID>${number}</ram:ID></rsm:ExchangedDocument><rsm:SupplyChainTradeTransaction><ram:ApplicableHeaderTradeAgreement><ram:BuyerReference>${buyerReference}</ram:BuyerReference></ram:ApplicableHeaderTradeAgreement><ram:ApplicableHeaderTradeSettlement><ram:SpecifiedTradeSettlementHeaderMonetarySummation><ram:GrandTotalAmount>${total}</ram:GrandTotalAmount></ram:SpecifiedTradeSettlementHeaderMonetarySummation></ram:ApplicableHeaderTradeSettlement></rsm:SupplyChainTradeTransaction></rsm:CrossIndustryInvoice>`

export async function erechnung() {
	const PF = `PE-${RUN}`
	const CODE = `E${RUN}`.toUpperCase()
	const customers = { ch: `CE-${RUN}-ch`, de: `CE-${RUN}-de`, fr: `CE-${RUN}-fr`, pub: `CE-${RUN}-pub` }
	const keys = { chch: `inv-${RUN}e1`, dede: `inv-${RUN}e2`, defr: `inv-${RUN}e3`, chde: `inv-${RUN}e4`, pub: `inv-${RUN}e5`, qrr: `inv-${RUN}e6`, over: `inv-${RUN}e7` }
	const made = Object.values(keys)
	const Y = 3000 + (parseInt(RUN.slice(-4), 36) % 5000) + 9
	const ISSUED_ON = `${Y}-03-10`
	const tenant = sql("SELECT id FROM oc_ts_tenants WHERE slug = 'zb'")
	const settings = await ocs(A, 'GET', '/records/setting/settings')
	const base = settings.status === 200 ? settings.data.data : {}
	let settingsVersion = settings.status === 200 ? settings.data.version : 0
	const teamBefore = (await ocs(A, 'GET', '/team')).data
	const datevBefore = teamBefore?.settings?.datev || null
	const webhooksBefore = teamBefore?.settings?.webhooks || []
	const issuer = (country, more = {}) => ({ name: 'Muster sàrl', street: 'Musterstrasse 12', postcode: country === 'DE' ? '79539' : '4000', city: country === 'DE' ? 'Lörrach' : 'Basel', country, vat_id: country === 'DE' ? 'DE123456789' : 'CHE-123.456.789 MWST', email: 'mail@example.test', phone: '+41 61 000 00 00', qr: true,
		accounts: [{ currency: 'CHF', iban: 'CH9300762011623852957', bic: '' }, { currency: 'EUR', iban: 'DE89370400440532013000', bic: 'COBADEFFXXX' }], ...more })
	const setIssuer = async (country, more = {}, vat = 8.1) => {
		const r = await ocs(A, 'PUT', '/records/setting/settings', { version: settingsVersion, data: { ...base, language: 'de', vat, invoice_number: { pattern: `RE-${RUN}-{no:4}`, per_year: true, start: 1 }, issuer: issuer(country, more) } })
		settingsVersion = r.data?.version ?? settingsVersion
		return r
	}
	const draft = (customerKey, currency, more = {}) => ({
		schema: 1, project: PF, budget: currency === 'EUR' ? 'b2' : 'b1', from: '2026-10-01', to: '2026-10-31', status: 'draft', number: null, bookings: [],
		positions: [{ kind: 'hours', text: 'Planung', hours: 10, rate: 150, amount: 1500 }, { kind: 'flat', text: 'Spesen', amount: 100 }, { kind: 'discount', text: 'Rabatt', amount: -100 }],
		greeting: 'Guten Tag', body: 'Zahlbar innert 30 Tagen.', footer: 'Muster · Basel', note: null, created_by: 'zbadmin', created_on: '2026-10-15', issued_on: null, paid_on: null, ...more,
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
			expect('project with budgets in CHF and EUR, customer CH', await ocs(A, 'PUT', `/records/project/${PF}`, { version: 0, data: { schema: 1, id: PF, name: `E-Rechnung ${RUN}`, codes: [CODE], status: 'active', leads: ['zblead'], customer: customers.ch, budgets: [{ id: 'b1', name: 'CHF', currency: 'CHF' }, { id: 'b2', name: 'EUR', currency: 'EUR' }] } }), 200)
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
			check('the reservation hands the app tax_case domestic, qr_reference null, language de', r.reservation?.tax_case === 'domestic' && r.reservation?.qr_reference === null && r.reservation?.language === 'de', r.reservation)
			check('fixed: tax_case domestic, VAT 8.1 %, customer.tax_case frozen', chch?.tax_case === 'domestic' && chch?.vat === 8.1 && chch?.customer?.tax_case === 'domestic' && chch?.vat_amount === 121.5, chch)
			check('fixed: counter_no 1, qr_reference null (plain IBAN), xml_path null', chch?.counter_no === 1 && chch?.qr_reference === null && chch?.xml_path === null && chch?.external === null, chch)
			check('fixed: issued, documents[pdf] with the app’s PDF, no document_state (0.32.0)', chch?.status === 'issued' && chch?.documents?.length === 1 && chch?.documents?.[0]?.kind === 'pdf' && chch?.documents?.[0]?.sha256 === sha256(r.pdf) && chch?.document_state === undefined, chch?.documents)
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
			check('fixed: export by override, VAT 0; the reservation says export too', over.fixed?.tax_case === 'export' && over.fixed?.vat === 0 && over.reservation?.tax_case === 'export', over.fixed)
		}
		{
			expect('Team Admin sets the Swiss issuer again', await setIssuer('CH'), 200)
			expect('draft CH→DE', await ocs(A, 'PUT', path(keys.chde), { version: 0, data: draft(customers.de, 'EUR') }), 200)
			const r = await issueAndFix(keys.chde, ISSUED_ON)
			expect('issue CH→DE', r.issue, 200)
			check('fixed: export (issuer outside the EU), VAT 0', r.fixed?.tax_case === 'export' && r.fixed?.vat === 0 && r.fixed?.gross === 1500, r.fixed)
		}

		head('E-invoices: the XRechnung the app sends (0.32.0)')
		{
			const doc = await raw(A, 'GET', `${ipath(keys.chch)}/document?kind=pdf`)
			check('the filed PDF is the app’s, as sent', doc.status === 200 && doc.buf.subarray(0, 5).toString() === '%PDF-' && sha256(doc.buf) === chch?.documents?.[0]?.sha256, [doc.status, doc.buf.length])
			check('… the chain line is in it', doc.buf.includes(`Beleg ${chch?.number} · Kette ${chch?.chain?.seq} `))
			check('… no XRechnung for a Swiss customer (none sent)', (await raw(A, `GET`, `${ipath(keys.chch)}/document?kind=xml`)).status === 404)
			const lg = await ocs(A, 'GET', `/invoices/ledger?since=${chch?.chain?.seq - 1}&limit=20`)
			const issued = (lg.data?.entries || []).find((e) => e.seq === chch?.chain?.seq)
			check('ledger: the issued entry carries the PDF’s sha256; no document entry of kind zugferd', issued?.action === 'issued' && JSON.parse(issued?.payload ?? '{}')?.document?.sha256 === chch?.documents?.[0]?.sha256 && !(lg.data?.entries || []).some((e) => e.action === 'document' && e.number === chch?.number), issued)
			expect('project → public customer', await pointProjectTo(customers.pub), 200)
			expect('draft CH→DE public', await ocs(A, 'PUT', path(keys.pub), { version: 0, data: draft(customers.pub, 'EUR') }), 200)
			const r = await issueAndFix(keys.pub, ISSUED_ON)
			expect('issue for the public customer', r.issue, 200)
			check('fixed: the Leitweg-ID frozen with the customer, xml_path null until the app sends it', r.fixed?.customer?.leitweg_id === '04011000-1234512345-06' && r.fixed?.xml_path === null && r.fixed?.documents?.length === 1, r.fixed)
			expect('document?kind=xml before the app sends it', await raw(A, 'GET', `${ipath(keys.pub)}/document?kind=xml`), 404)
			const xmlBytes = cii(r.fixed?.number, '04011000-1234512345-06', '1500.00')
			expect('a Lead sends the XML', await raw(L, 'PUT', `${ipath(keys.pub)}/document?kind=xml`, xmlBytes, 'application/xml'), 403, 'forbidden')
			const mm = await raw(A, 'PUT', `${ipath(keys.pub)}/document?kind=xml`, cii('RE-OTHER', 'x', '1'), 'application/xml')
			expect('an XML of another invoice', mm, 422, 'invalid')
			check('… document_mismatch', mm.data?.code === 'document_mismatch', mm.data)
			const x = await raw(A, 'PUT', `${ipath(keys.pub)}/document?kind=xml`, xmlBytes, 'application/xml')
			expect('Team Admin files the app’s XRechnung', x, 200)
			check('answer: document { kind xml, name <date>_<number>_<customer>.xml, sha256 }, a ledger entry, stored', x.data?.document?.kind === 'xml' && x.data?.document?.name === `${ISSUED_ON}_${r.fixed?.number}_Stadt-Beispiel-${RUN}.xml` && x.data?.document?.sha256 === sha256(xmlBytes) && Number.isInteger(x.data?.ledger?.seq) && x.data?.stored === true, x.data)
			const fx = (await ocs(A, 'GET', `${ipath(keys.pub)}/fixed`)).data
			check('fixed: xml_path <root>/Buchhaltung/<year>/Rechnungen/<date>_<number>_<customer>.xml (0.22.0) and xml_sha256, documents pdf and xml', typeof fx?.xml_path === 'string' && fx.xml_path.includes(`/Buchhaltung/${Y}/Rechnungen/${fx.issued_on}_${fx.number}_`) && fx.xml_path.endsWith('.xml') && fx?.xml_sha256 === sha256(xmlBytes) && fx?.documents?.map((d) => d.kind).join() === 'pdf,xml', fx)
			const xml = await raw(A, 'GET', `${ipath(keys.pub)}/document?kind=xml`)
			check('document?kind=xml: application/xml, <number>.xml, the bytes as sent', xml.status === 200 && xml.type.startsWith('application/xml') && xml.disposition.includes(`${r.fixed?.number}.xml`) && xml.text === xmlBytes, [xml.status, xml.type, xml.disposition])
			const lead = await raw(L, 'GET', `${ipath(keys.pub)}/document?kind=xml`)
			check('the project’s Lead reads the XML too', lead.status === 200)
			check('a Member does not', (await raw(U1, 'GET', `${ipath(keys.pub)}/document?kind=xml`)).status === 403)
			const lg2 = await ocs(A, 'GET', `/invoices/ledger?since=${x.data?.ledger?.seq - 1}&limit=1`)
			const de = lg2.data?.entries?.[0]
			check('ledger: document entry of kind xrechnung with the XML’s sha256', de?.action === 'document' && de?.number === r.fixed?.number && JSON.parse(de?.payload ?? '{}')?.kind === 'xrechnung' && JSON.parse(de?.payload ?? '{}')?.sha256 === sha256(xmlBytes), de)
			check('the same XML again: 200, stored false, no second entry', await raw(A, 'PUT', `${ipath(keys.pub)}/document?kind=xml`, xmlBytes, 'application/xml').then((a) => a.status === 200 && a.data?.stored === false && a.data?.ledger?.seq === x.data?.ledger?.seq))
			expect('another XML after the first', await raw(A, 'PUT', `${ipath(keys.pub)}/document?kind=xml`, xmlBytes + '<!-- v2 -->', 'application/xml'), 409, 'conflict')
			const v = await ocs(A, 'GET', '/invoices/chain/verify')
			check('the chain is still valid', v.data?.ok === true, v.data)
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
			check('… the reservation handed it to the app for the payment part (0.32.0)', r.reservation?.qr_reference === ref, r.reservation)
			const lg = await ocs(A, 'GET', `/invoices/ledger?since=${r.fixed?.chain?.seq - 1}&limit=3`)
			const issued = (lg.data?.entries || []).find((e) => e.action === 'issued' && e.number === r.fixed?.number)
			check('the reference is in the issue payload', issued !== undefined && JSON.parse(issued.payload).qr_reference === ref)
		}

		head('E-invoices: exports')
		{
			expect('export by a Lead', await raw(L, 'GET', '/invoices/export?kind=csv').then((x) => ({ status: x.status, data: null, text: '' })), 403)
			expect('export of an unknown kind', await raw(A, 'GET', '/invoices/export?kind=xlsx').then((x) => ({ status: x.status, data: null, text: x.text })), 422)
			expect('export with a bad day', await raw(A, 'GET', '/invoices/export?kind=csv&from=2026-13-01').then((x) => ({ status: x.status, data: null, text: x.text })), 422)
			const csv = await raw(A, 'GET', `/invoices/export?kind=csv&from=${Y}-01-01&to=${Y}-12-31`)
			check('CSV: text/csv, BOM, semicolons, header', csv.status === 200 && csv.type.startsWith('text/csv') && csv.buf.subarray(0, 3).equals(Buffer.from([0xef, 0xbb, 0xbf])) && csv.text.includes('number;status;issued_on;due_on;paid_on;customer;customer_vat_id;project;budget;currency;net;vat_rate;vat_amount;gross;tax_case;reverses;document_sha256;chain_seq;chain_hash'), [csv.status, csv.type, csv.text.slice(0, 100)])
			const lines = csv.text.split('\r\n').filter((l) => l.startsWith(`RE-${RUN}-`))
			check('… one row per issued invoice of the year, with the tax cases', lines.length === made.length && lines.some((l) => l.includes(';domestic;')) && lines.some((l) => l.includes(';eu_reverse;')) && lines.some((l) => l.includes(';export;')), lines.length)
			check('… the CH→CH row: net, VAT, gross, the PDF’s sha256 as document_sha256, chain seq', lines.some((l) => l.startsWith(`${chch?.number};issued;${ISSUED_ON};`) && l.includes(';CHF;1500.00;8.10;121.50;1621.50;domestic;;') && l.includes(`;${chch?.documents?.[0]?.sha256};${chch?.chain?.seq};${chch?.chain?.hash}`)), lines.find((l) => l.startsWith(`${chch?.number};`)))
			expect('DATEV without accounts', await raw(A, 'GET', '/invoices/export?kind=datev').then((x) => ({ status: x.status, data: null, text: x.text })), 422)
			expect('datev setting with a bad field', await ocs(A, 'PUT', '/team/settings', { datev: { consultant: '12345678' } }), 422, 'invalid')
			expect('datev setting by a Lead', await ocs(L, 'PUT', '/team/settings', { datev: { consultant: '1234567' } }), 403, 'forbidden')
			const set = await ocs(A, 'PUT', '/team/settings', { datev: { consultant: '1234567', client: '12345', fiscal_year_start: '01-01', account_length: 4, debtor_account: '10000', revenue_domestic: '8400', revenue_eu: '8336', revenue_export: '8338' } })
			expect('Team Admin sets the DATEV accounts', set, 200)
			check('… the answer and /team carry them', set.data?.datev?.revenue_eu === '8336' && (await ocs(A, 'GET', '/team')).data?.settings?.datev?.client === '12345', set.data?.datev)
			const datev = await raw(A, 'GET', `/invoices/export?kind=datev&from=${Y}-01-01&to=${Y}-12-31`)
			check('DATEV: EXTF 700 header, column names, one booking per invoice', datev.status === 200 && datev.text.startsWith('﻿EXTF;700;21;Buchungsstapel;12;') && datev.text.includes(';1234567;12345;' + Y + '0101;4;' + Y + '0101;' + Y + '1231;') && datev.text.includes('Umsatz (ohne Soll/Haben-Kz);Soll/Haben-Kennzeichen;') && datev.text.split('\r\n').filter((l) => l.includes(`;RE-${RUN}-`)).length === made.length, datev.text.slice(0, 200))
			check('… CH→CH: 1621,50 Soll on 10000 against 8400, Belegdatum 1003, number, text', datev.text.includes(`1621,50;S;CHF;;;;10000;8400;;1003;${chch?.number};;;Müller AG ${RUN} E-Rechnung ${RUN}`), datev.text.split('\r\n').find((l) => l.includes(chch?.number || '-')))
			check('… DE→DE uses the customer’s debtor account 10077; DE→FR the EU revenue account, CH→DE the export account', datev.text.includes(';10077;8400;') && datev.text.includes(';10000;8336;') && datev.text.includes(';10077;8338;'))
			const ledger = await raw(A, 'GET', `/invoices/export?kind=ledger`)
			check('ledger CSV: header and the issue entries', ledger.status === 200 && ledger.text.includes('seq;at;actor;action;number;payload_hash;prev_hash;hash') && ledger.text.includes(`;issued;${chch?.number};`), ledger.text.slice(0, 120))
		}

		head('E-invoices: hand-over to an accounting system')
		{
			expect('external with a bad system', await ocs(A, 'POST', `${ipath(keys.chch)}/external`, { system: 'Sage AG', id: '1' }), 422, 'invalid')
			expect('external of a draft', await ocs(A, 'POST', `${ipath(`inv-${RUN}zz`)}/external`, { system: 'bexio', id: '1' }), 404, 'not_found')
			expect('external by a Lead', await ocs(L, 'POST', `${ipath(keys.chch)}/external`, { system: 'bexio', id: '1' }), 403, 'forbidden')
			const r = await ocs(A, 'POST', `${ipath(keys.chch)}/external`, { system: 'bexio', id: 4711, url: 'https://office.bexio.com/kb_invoice/show/4711' })
			expect('Team Admin records the hand-over', r, 200)
			check('… fixed.external {system, id, url, at, by} and a ledger block', r.data?.external?.system === 'bexio' && r.data?.external?.id === '4711' && r.data?.external?.by === 'zbadmin' && typeof r.data?.ledger?.seq === 'number', r.data?.external)
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
			check('… the answer carries the written record with external {system, id, url, at, by}', s.data?.external?.system === 'sevdesk' && ext?.system === 'sevdesk' && ext?.id === 'SD-17' && ext?.url === 'https://my.sevdesk.de/fi/detail/type/RE/id/17' && ext?.by === 'zbadmin' && ext?.at === s.data?.external?.at && s.data?.record?.version === before?.version + 1, s.data?.record)
			const rec = (await ocs(A, 'GET', path(keys.chch))).data
			check('the invoice record carries external, the rest unchanged', rec?.data?.external?.system === 'sevdesk' && rec?.data?.external?.id === 'SD-17' && rec?.data?.status === before?.data?.status && rec?.data?.number === before?.data?.number, rec?.data?.external)
			expect('a client still may not write the issued invoice (external included)', await ocs(A, 'PUT', path(keys.chch), { version: rec?.version, data: { ...rec?.data, external: { system: 'datev', id: '1' } } }), 409, 'issued')
			// 0.24.0: a credit note is a document of its own – it does not inherit the original's hand-over.
			// In the original's year: the pattern has no {year}, and a fresh team's counter of another year starts at 1 again.
			const cr = await stornieren(A, keys.chch, { kind: 'credit', reason: 'Copy check', issued_on: ISSUED_ON })
			expect('a credit note of the handed-over invoice', cr.reserve, 200)
			expect('… closed with its PDF', cr.close, 200)
			if (cr.key) {
				made.push(cr.key)
			}
			check('… without the original’s external; the original keeps it', (cr.record?.data?.external ?? null) === null
				&& cr.original?.data?.external?.id === 'SD-17', [cr.record?.data?.external])
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
			check('the close is not blocked by the unreachable address', r.fixed?.status === 'issued' && r.fixed?.documents?.length === 1, r.fixed)
			expect('webhook status by a Lead', await ocs(L, 'GET', '/invoices/webhooks/status'), 403, 'forbidden')
			const st = await ocs(A, 'GET', '/invoices/webhooks/status')
			expect('webhook status', st, 200)
			const hook = (st.data?.hooks || []).find((h) => h.url === `https://hooks.invalid/ts-${RUN}`)
			check('… the hook with two deliveries waiting (issued, document)', hook !== undefined && hook.pending + hook.failed === 2 && hook.delivered === 0, hook)
			if (tenant !== null) {
				const n = sql(`SELECT COUNT(*) FROM oc_ts_inv_webhook_queue WHERE tenant_id = ${tenant} AND url = 'https://hooks.invalid/ts-${RUN}' AND delivered_at IS NULL`)
				check('the queue holds the two rows (TS_SQL)', n === '2', n)
				const body = sql(`SELECT body FROM oc_ts_inv_webhook_queue WHERE tenant_id = ${tenant} AND url = 'https://hooks.invalid/ts-${RUN}' AND event = 'issued'`)
				check('… body {team, event, at, invoice {key, number, status, gross, currency}, ledger {seq, hash}}', (() => { try { const b = JSON.parse(body); return b.team === 'zb' && b.event === 'issued' && b.invoice.key === K8 && b.invoice.number === r.fixed?.number && b.invoice.gross === 1621.5 && b.invoice.currency === 'CHF' && typeof b.ledger.seq === 'number' && /^[0-9a-f]{64}$/.test(b.ledger.hash) } catch { return false } })(), body)
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
		check('zb left as found', isObj(await me(A)))
	}
}
