// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Test harness and shared values for the integration check.
import { createHash } from 'node:crypto'
import { API, NC, as, ocs } from './lib.mjs'

export const RUN = Date.now().toString(36)
export const stats = { passed: 0, failed: [], section: '' }

export function head(title) {
	stats.section = title
	console.log(`\n\x1b[1;34m==>\x1b[0m ${title}`)
}
export function check(name, ok, detail) {
	if (ok) {
		stats.passed++
		console.log(`    \x1b[32mok\x1b[0m  ${name}`)
	} else {
		stats.failed.push(`${stats.section}: ${name}`)
		console.log(`    \x1b[31mFAIL\x1b[0m  ${name}${detail === undefined ? '' : ' – ' + (typeof detail === 'string' ? detail : JSON.stringify(detail)).slice(0, 400)}`)
	}
}
/** Check a response's status and error code. */
export function expect(name, r, status, error) {
	const ok = r.status === status && (error === undefined || r.data?.error === error)
	check(`${name} → ${status}${error ? ' ' + error : ''}`, ok, `HTTP ${r.status} ${r.text.slice(0, 300)}`)
	return ok
}
export const isObj = (v) => v !== null && typeof v === 'object' && !Array.isArray(v)
export const ISO = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/
export const sha256 = (s) => createHash('sha256').update(s).digest('hex')
export const b64 = (s) => Buffer.from(s).toString('base64')
export const keysOf = (r) => new Set((r.data?.records || []).map((x) => `${x.kind}/${x.key}`))

export const A = as('pbadmin')
export const V = as('pbverw')
export const L = as('pblead')
export const U1 = as('pbuser1')
export const U2 = as('pbuser2')
export const AA = as('atadmin')
export const AL = as('atlead')
export const AU = as('atuser1')

export const P = `P-${RUN}`
export const C = `K-${RUN}`
export const R = `CH-${RUN}`
export const NOAH = `noah.${RUN}@example.test`

/**
 * Makes sure the record exists – but never overwrites what is already
 * there (several checks share the test environment).
 */
export async function ensure(who, kind, key, data) {
	const cur = await ocs(who, 'GET', `/records/${kind}/${encodeURIComponent(key)}`)
	if (cur.status === 200) {
		return cur
	}
	return ocs(who, 'PUT', `/records/${kind}/${encodeURIComponent(key)}`, { version: 0, data })
}
export const me = async (who) => (await ocs(who, 'GET', '/me')).data

const auth = (who) => 'Basic ' + Buffer.from(`${who.user}:${who.pass}`).toString('base64')

/**
 * A raw request of the app API as `who` – an upload (PDF, XML) or a
 * download: status, content type, disposition, body bytes, text and the
 * OCS data when the answer is JSON.
 */
export async function raw(who, method, p, body, type, lang = 'en') {
	const headers = { 'OCS-APIRequest': 'true', 'Accept-Language': lang, Accept: 'application/json', Authorization: auth(who) }
	if (type) {
		headers['Content-Type'] = type
	}
	const res = await fetch(`${NC}${API}${p}`, { method, headers, body })
	const buf = Buffer.from(await res.arrayBuffer())
	const text = buf.toString('utf8')
	let data = null
	try {
		data = JSON.parse(text)?.ocs?.data ?? null
	} catch {
		data = null
	}
	return { status: res.status, type: res.headers.get('content-type') || '', disposition: res.headers.get('content-disposition') || '', buf, text, data }
}

/**
 * A small valid PDF 1.4 written by hand (no library): one page whose text
 * and an uncompressed XMP subject carry `lines` – for an invoice the
 * number and the chain line, which the server searches for as bytes
 * (0.32.0). `salt` makes other bytes of the same document.
 */
export function minimalPdf(lines, salt = '') {
	const esc = (t) => String(t).replace(/[\\()]/g, (c) => '\\' + c)
	const content = lines.map((l, i) => `BT /F1 10 Tf 40 ${560 - i * 14} Td (${esc(l)}) Tj ET`).join('\n')
	const xmp = `<?xpacket begin="" id="W5M0MpCehiHzreSzNTczkc9d"?><x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"><rdf:Description rdf:about="" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:subject><rdf:Bag><rdf:li>${lines.join(' ')}</rdf:li></rdf:Bag></dc:subject></rdf:Description></rdf:RDF></x:xmpmeta><?xpacket end="w"?>`
	const objs = [
		'<< /Type /Catalog /Pages 2 0 R /Metadata 6 0 R >>',
		'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
		'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
		`<< /Length ${Buffer.byteLength(content)} >>\nstream\n${content}\nendstream`,
		'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
		`<< /Type /Metadata /Subtype /XML /Length ${Buffer.byteLength(xmp)} >>\nstream\n${xmp}\nendstream`,
	]
	let out = `%PDF-1.4\n%\u00e2\u00e3\u00cf\u00d3 TimeSister integration ${salt}\n`
	const offsets = []
	objs.forEach((o, i) => {
		offsets.push(Buffer.byteLength(out))
		out += `${i + 1} 0 obj\n${o}\nendobj\n`
	})
	const xref = Buffer.byteLength(out)
	out += `xref\n0 ${objs.length + 1}\n0000000000 65535 f \n` + offsets.map((o) => `${String(o).padStart(10, '0')} 00000 n \n`).join('')
	out += `trailer\n<< /Size ${objs.length + 1} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`
	return Buffer.from(out, 'utf8')
}

/**
 * Issues an invoice the 0.32.0 way (one renderer): `POST …/issue` reserves
 * number and chain link, then the app's PDF – here a minimal one with the
 * number and the chain line – closes the issue through `PUT …/document`.
 * Returns the reservation and the close; `issue` is the reservation block
 * plus `hash` and `document` of the close, `record` the record after the
 * close (or the reservation's when the close failed), `fixed` as reserved,
 * `pdf` the bytes sent. A failed reservation leaves `close` null.
 */
export async function festschreiben(who, key, body = {}, { salt = '' } = {}) {
	const reserve = await ocs(who, 'POST', `/invoices/${encodeURIComponent(key)}/issue`, body)
	if (reserve.status !== 200) {
		return { reserve, close: null, key, record: reserve.data?.record ?? null, fixed: reserve.data?.fixed ?? null, issue: reserve.data?.issue ?? null, pdf: null, ledger: null, status: reserve.status }
	}
	const i = reserve.data.issue
	const pdf = minimalPdf([`Invoice ${i.number}`, i.chain_line], salt)
	const close = await raw(who, 'PUT', `/invoices/${encodeURIComponent(key)}/document?kind=pdf`, pdf, 'application/pdf')
	return {
		reserve, close, key, pdf,
		record: close.data?.record ?? reserve.data.record,
		fixed: reserve.data.fixed,
		issue: { ...i, ...(close.data?.issue ?? {}) },
		ledger: close.data?.ledger ?? null,
		status: close.status,
	}
}

/**
 * Reverses or credits the 0.32.0 way: `POST …/reverse` reserves the new
 * invoice (its own key, number and chain link), its PDF closes it; the
 * original changes at the close. Returns as {@link festschreiben} with
 * `key` the new invoice's and `original` the original's record after the
 * close (as read back).
 */
export async function stornieren(who, key, body) {
	const reserve = await ocs(who, 'POST', `/invoices/${encodeURIComponent(key)}/reverse`, body)
	if (reserve.status !== 200) {
		return { reserve, close: null, key: null, record: null, original: reserve.data?.original ?? null, fixed: null, issue: null, pdf: null, ledger: null, status: reserve.status }
	}
	const i = reserve.data.issue
	const newKey = reserve.data.record.key
	const pdf = minimalPdf([`Invoice ${i.number}`, i.chain_line])
	const close = await raw(who, 'PUT', `/invoices/${encodeURIComponent(newKey)}/document?kind=pdf`, pdf, 'application/pdf')
	const original = (await ocs(who, 'GET', `/records/invoice/${encodeURIComponent(key)}`)).data
	return {
		reserve, close, key: newKey, pdf, original,
		record: close.data?.record ?? reserve.data.record,
		fixed: reserve.data.fixed,
		issue: { ...i, ...(close.data?.issue ?? {}) },
		ledger: close.data?.ledger ?? null,
		status: close.status,
	}
}


/** Print the result; returns the exit code. */
export function summary() {
	console.log(`\n${stats.passed} passed, ${stats.failed.length} failed`)
	for (const f of stats.failed) {
		console.log(`  - ${f}`)
	}
	return stats.failed.length ? 1 : 0
}
