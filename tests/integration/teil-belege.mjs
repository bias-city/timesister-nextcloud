// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Receipts, expense reports and the document tree (0.22.0, 0.23.0): the
// team's root, receipts with their three questions and server-owned
// fields, the file under Belege/ or Spesen/<person>/, approval by
// assignment (with `missing`) and through the endpoint with the rename,
// rejection, rights of members, Leads and Team Admins, recurring expenses
// (rule, approval, one receipt per period), expense reports fixed like an
// invoice (ready, fix with number, lines, sums, PDF and ledger, reverse,
// paid, the chain) and the export (CSV, DATEV), invoicing from receipts
// (lines, markup, expense percentage, billed on issue, released on
// reversal, copies in the PDF), the quote document through the server, the
// project dossier and the privacy notice. Leaves pb as it found it
// (settings, team settings, person); records and frozen rows only with
// TS_SQL, the ledger stays; the test folder in the owner's files goes.
import { execFileSync } from 'node:child_process'
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { dav, ocs, sql, ADMIN, NC, API } from './lib.mjs'
import { A, L, RUN, U1, U2, check, expect, head, isObj, me, sha256 } from './harness.mjs'

const rec = (kind, key) => `/records/${kind}/${encodeURIComponent(key)}`
const near = (a, b) => typeof a === 'number' && Math.abs(a - b) < 0.001
const auth = (who) => 'Basic ' + Buffer.from(`${who.user}:${who.pass}`).toString('base64')

/** A raw request of the app API: status, type, body bytes, text, the OCS data when JSON. */
async function raw(who, method, p, body, type) {
	const headers = { 'OCS-APIRequest': 'true', 'Accept-Language': 'en', Accept: 'application/json', Authorization: auth(who) }
	if (type) {
		headers['Content-Type'] = type
	}
	const res = await fetch(`${NC}${API}${p}`, { method, headers, body })
	const buf = Buffer.from(await res.arrayBuffer())
	let data = null
	try {
		data = JSON.parse(buf.toString('utf8'))?.ocs?.data ?? null
	} catch {
		data = null
	}
	return { status: res.status, type: res.headers.get('content-type') || '', buf, text: buf.toString('utf8'), data }
}

/** A small valid PDF (classic cross-reference) with one line of text. */
function pdf(line) {
	const content = `BT /F1 18 Tf 40 500 Td (${line}) Tj ET`
	const objs = [
		'<< /Type /Catalog /Pages 2 0 R >>',
		'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
		'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 420 595] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
		`<< /Length ${content.length} >>\nstream\n${content}\nendstream`,
		'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
	]
	let out = '%PDF-1.4\n'
	const offsets = []
	objs.forEach((o, i) => {
		offsets.push(out.length)
		out += `${i + 1} 0 obj\n${o}\nendobj\n`
	})
	const xref = out.length
	out += `xref\n0 ${objs.length + 1}\n0000000000 65535 f \n` + offsets.map((o) => `${String(o).padStart(10, '0')} 00000 n \n`).join('')
	out += `trailer\n<< /Size ${objs.length + 1} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`
	return Buffer.from(out, 'latin1')
}
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64')

/** The text of a PDF through pdftotext; null when it is not installed. */
function pdfText(buf) {
	const dir = mkdtempSync(join(tmpdir(), 'ts-belege-'))
	try {
		const file = join(dir, 'doc.pdf')
		writeFileSync(file, buf)
		return execFileSync('pdftotext', ['-layout', file, '-'], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).replace(/\s+/g, ' ')
	} catch {
		return null
	} finally {
		rmSync(dir, { recursive: true, force: true })
	}
}

/** The names in a ZIP's central directory. */
function zipNames(buf) {
	const names = []
	let i = buf.indexOf(Buffer.from([0x50, 0x4b, 0x01, 0x02]))
	while (i !== -1 && i + 46 <= buf.length) {
		const n = buf.readUInt16LE(i + 28)
		const extra = buf.readUInt16LE(i + 30)
		const comment = buf.readUInt16LE(i + 32)
		names.push(buf.subarray(i + 46, i + 46 + n).toString('utf8'))
		i = buf.indexOf(Buffer.from([0x50, 0x4b, 0x01, 0x02]), i + 46 + n + extra + comment)
	}
	return names
}

export async function belege() {
	const PB = `PB-${RUN}`
	const CB = `CB-${RUN}`
	const CODE = `B${RUN}`.toUpperCase()
	const Y = 3000 + (parseInt(RUN.slice(-4), 36) % 5000) + 13
	const FOLDER = `TimeSister Test Belege/${RUN}`
	const R1 = `rc-${RUN}-1`
	const R2 = `rc-${RUN}-2`
	const R3 = `rc-${RUN}-3`
	const R4 = `rc-${RUN}-4`
	const R5 = `rc-${RUN}-5`
	const R6 = `rc-${RUN}-6`
	const R7 = `rc-${RUN}-7`
	const R8 = `rc-${RUN}-8`
	const RR = `rc-${RUN}-r1`
	const RULE = `er-${RUN}`
	const K1 = `inv-${RUN}b1`
	const K2 = `inv-${RUN}b2`
	const K3 = `inv-${RUN}b3`
	const Q = `q-${sha256(RUN).slice(0, 8)}`
	const records = [[ 'receipt', R1 ], ['receipt', R2], ['receipt', R3], ['receipt', R4], ['receipt', R5], ['receipt', R6], ['receipt', R7], ['receipt', R8], ['receipt', RR], ['receipt', `rc-${RUN}-r2`], ['expense_rule', RULE], ['expense_report', `sb-${RUN}x`], ['quote', Q]]
	const invoices = [K1, K2, K3]
	const tenant = sql("SELECT id FROM oc_ts_tenants WHERE slug = 'pb'")
	const settings = await ocs(A, 'GET', '/records/setting/settings')
	const base = settings.status === 200 ? settings.data.data : {}
	let settingsVersion = settings.status === 200 ? settings.data.version : 0
	const teamBefore = (await ocs(A, 'GET', '/team')).data
	const docsBefore = teamBefore?.settings?.documents || { root: null, chain_mail: null }
	const datevBefore = teamBefore?.settings?.datev || null
	const owner = teamBefore?.backup_owner || 'pbadmin'
	const ownerDav = { user: owner, pass: `Test-2026-${owner}!` }
	const nameOf = (uid) => (teamBefore?.members || []).find((x) => x.uid === uid)?.display_name || uid
	const safe = (s) => s.replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss').replace(/[^A-Za-z0-9.-]+/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '').slice(0, 40)
	const personBefore = await ocs(A, 'GET', rec('person', 'pbuser1'))
	const davGet = (p) => dav(ownerDav, 'GET', `files/${encodeURIComponent(owner)}/${p.split('/').map(encodeURIComponent).join('/')}`)
	const davRaw = async (p) => {
		const res = await fetch(`${NC}/remote.php/dav/files/${encodeURIComponent(owner)}/${p.split('/').map(encodeURIComponent).join('/')}`, { headers: { Authorization: auth(ownerDav) } })
		return { status: res.status, buf: Buffer.from(await res.arrayBuffer()) }
	}
	const receipt = (over = {}) => ({
		schema: 1, kind: 'receipt', date: `${Y}-03-10`, supplier: 'Bahn Muster', text: 'Basel–Bern', net: 100, vat_rate: 8.1, vat: 8.1, gross: 108.1,
		project: PB, budget: 'b1', cost: 1, paid_by: 'person', person: 'pbuser1', reimburse: true, note: null, ...over,
	})
	const put = async (who, kind, key, data) => {
		const cur = await ocs(who, 'GET', rec(kind, key))
		return ocs(who, 'PUT', rec(kind, key), { version: cur.status === 200 ? cur.data.version : 0, data })
	}
	const get = async (kind, key) => (await ocs(A, 'GET', rec(kind, key))).data
	try {
		head('Receipts: setup and the document tree')
		{
			const cap = (await ocs(A, 'GET', '/ocs/v2.php/cloud/capabilities')).data?.capabilities?.timesister
			check('capabilities receipts, document_tree, expense_fixing: 1 (expense_reports gone)', cap?.receipts === 1 && cap?.document_tree === 1 && cap?.expense_fixing === 1 && cap?.expense_reports === undefined, cap)
			const set = await ocs(A, 'PUT', '/team/settings', { documents: { root: FOLDER } })
			expect('Team Admin sets documents.root', set, 200)
			check('… settings.documents is { root, chain_mail }', JSON.stringify(Object.keys(set.data?.documents || {}).sort()) === '["chain_mail","root"]' && set.data?.documents?.root === FOLDER, set.data)
			const s = await ocs(A, 'PUT', '/records/setting/settings', { version: settingsVersion, data: { ...base, language: 'de', vat: 8.1,
				invoice_number: { pattern: `RB-${RUN}-{no:4}`, per_year: true, start: 1 }, expense_number: { pattern: `SP-${RUN}-{no:4}`, per_year: true, start: 1 }, modules: { ...(base.modules || {}), costs: true },
				issuer: { name: 'Example Office Ltd', street: 'Musterweg 1', postcode: '4000', city: 'Basel', country: 'CH', email: 'office@example.test', qr: false, accounts: [] } } })
			expect('settings: costs on, language, issuer', s, 200)
			settingsVersion = s.data?.version ?? settingsVersion
			expect('customer', await ocs(A, 'PUT', rec('customer', CB), { version: 0, data: { schema: 1, id: CB, name: `Kunde Süd ${RUN}`, street: 'Platz 1', postcode: '4001', city: 'Basel', country: 'CH' } }), 200)
			const p = await ocs(A, 'PUT', rec('project', PB), { version: 0, data: { schema: 1, id: PB, name: `Belege ${RUN}`, codes: [CODE], status: 'active', leads: ['pblead'], customer: CB,
				budgets: [{ id: 'b1', name: 'Phase 1', currency: 'CHF', costs: [
					{ no: 1, name: 'Spesen', kind: 'expense', rule: 'receipts', cap: 1500, mode: 'rebill', markup: 10 },
					{ no: 2, name: 'Druck', kind: 'third_party', cap: 5000, mode: 'pass_through' },
				] }] } })
			expect('project with two cost positions, led by pblead', p, 200)
		}

		head('Receipts: content, defaults, server fields')
		{
			// Member triage: spesen, project and cost kind; amounts follow, budget and cost position are the Lead's.
			const r = await ocs(U1, 'PUT', rec('receipt', R1), { version: 0, data: receipt({ net: 0, vat: 0, gross: 0, cost_kind: 'expense', invoice_effect: 'none', status: 'approved', created_by: 'mallory', file: { path: 'x' } }) })
			expect('Member writes a receipt paid and reimbursed to them', r, 200)
			const d = r.data?.data
			check('… open, created_by the caller, file null, defaults charge and CHF', d?.status === 'open' && d?.created_by === 'pbuser1' && d?.file === null && d?.invoice_effect === 'charge' && d?.currency === 'CHF' && d?.reimburse === true && /^\d{4}-\d{2}-\d{2}$/.test(d?.created_on ?? ''), d)
			check('… the member’s budget, cost and effect are ignored, the cost kind stays', d?.budget === null && d?.cost === null && d?.cost_kind === 'expense' && d?.project === PB, d)
			for (const [name, data, status, error] of [
				['net + vat ≠ gross', receipt({ gross: 110 }), 422, 'invalid'],
				['vat not net × rate', receipt({ vat: 9, gross: 109 }), 422, 'invalid'],
				['paid by a person without person', receipt({ person: null }), 422, 'invalid'],
				['reimbursed though the company paid', receipt({ paid_by: 'company', person: null }), 422, 'invalid'],
				['a cost kind without a project', receipt({ project: null, budget: null, cost: null, cost_kind: 'expense' }), 422, 'invalid'],
				['an unknown cost kind', receipt({ cost_kind: 'travel' }), 422, 'invalid'],
				['a project that does not exist', receipt({ project: `nope-${RUN}` }), 422, 'invalid'],
				['charge without a project', receipt({ project: null, budget: null, cost: null, invoice_effect: 'charge' }), 422, 'invalid'],
				['a person outside the team', receipt({ person: 'atuser1' }), 422, 'invalid'],
			]) {
				expect(`receipt: ${name}`, await ocs(U1, 'PUT', rec('receipt', `rc-${RUN}-bad`), { version: 0, data }), status, error)
			}
			expect('Team Admin: a cost position the budget lacks', await ocs(A, 'PUT', rec('receipt', `rc-${RUN}-bad`), { version: 0, data: receipt({ cost: 9 }) }), 422, 'invalid')
			expect('Team Admin: a cost position of the other kind', await ocs(A, 'PUT', rec('receipt', `rc-${RUN}-bad`), { version: 0, data: receipt({ cost_kind: 'expense', cost: 2 }) }), 422, 'invalid')
			expect('a key without rc-', await ocs(U1, 'PUT', rec('receipt', `x-${RUN}`), { version: 0, data: receipt() }), 422, 'invalid')
			expect('another member reads it', await ocs(U2, 'GET', rec('receipt', R1)), 403, 'forbidden')
			expect('another member writes it', await ocs(U2, 'PUT', rec('receipt', R1), { version: r.data?.version ?? 1, data: receipt() }), 403, 'forbidden')
			expect('the project’s Lead reads it (project, no budget yet)', await ocs(L, 'GET', rec('receipt', R1)), 200)
			check('… and finds it in the delta', ((await ocs(L, 'GET', '/records?since=0')).data?.records || []).some((x) => x.key === R1))
			check('the member’s delta carries it, the other’s does not', ((await ocs(U1, 'GET', '/records?since=0')).data?.records || []).some((x) => x.key === R1)
				&& !((await ocs(U2, 'GET', '/records?since=0')).data?.records || []).some((x) => x.key === R1))
		}

		head('Receipts: the file')
		const person = safe(nameOf('pbuser1'))
		{
			const bad = await raw(U1, 'PUT', `/receipts/${R1}/file`, Buffer.from('hello'), 'text/plain')
			check('a text file → 422', bad.status === 422 && bad.data?.error === 'invalid', [bad.status, bad.text.slice(0, 200)])
			const other = await raw(U2, 'PUT', `/receipts/${R1}/file`, pdf('Bahn'), 'application/pdf')
			check('another member → 403', other.status === 403, other.status)
			const dir = `${FOLDER}/Buchhaltung/${Y}/Spesen/${person}`
			const first = `${dir}/${Y}-03-10_Bahn-Muster_${CODE}.pdf`
			const f = await raw(U1, 'PUT', `/receipts/${R1}/file`, pdf('Bahn Muster Basel Bern'), 'application/pdf')
			check('the member stores a PDF: under Spesen/<person>/, no amount yet, project without cost position; sha256, mime, version', f.status === 200 && f.data?.path === first && f.data?.sha256 === sha256(pdf('Bahn Muster Basel Bern')) && f.data?.mime === 'application/pdf' && f.data?.version === 2, [f.status, f.data, first])
			check('… the record carries file', (await get('receipt', R1))?.data?.file?.path === first)
			const g = await raw(U1, 'GET', `/receipts/${R1}/file`)
			check('GET file returns it', g.status === 200 && g.buf.equals(pdf('Bahn Muster Basel Bern')), g.status)
			check('… it lies in the owner’s files', (await davGet(first)).status === 200)
			// While open the file follows every change of its name.
			const amounts = await put(U1, 'receipt', R1, { ...(await get('receipt', R1)).data, net: 100, vat: 8.1, gross: 108.1 })
			const priced = `${dir}/${Y}-03-10_Bahn-Muster_108.10-CHF_${CODE}.pdf`
			check('the member enters the amounts: the file is renamed', amounts.status === 200 && amounts.data?.data?.file?.path === priced && (await davGet(priced)).status === 200 && (await davGet(first)).status === 404, amounts.data?.data?.file)
			const again = await raw(U1, 'PUT', `/receipts/${R1}/file`, pdf('Bahn Muster Basel Bern 2'), 'application/pdf')
			check('a second file replaces the first under the same name; the member’s upload leaves it open', again.status === 200 && again.data?.path === priced && again.data?.sha256 === sha256(pdf('Bahn Muster Basel Bern 2')) && again.data?.status === 'open' && again.data?.missing === undefined, again.data)
			const early = await ocs(L, 'POST', `/receipts/${R1}/approve`, {})
			check('the Lead approves before assigning budget and cost position → 422', early.status === 422 && String(early.data?.message || '').includes('budget and a cost position'), early.data)
			const wrongKind = await put(L, 'receipt', R1, { ...(await get('receipt', R1)).data, budget: 'b1', cost: 2 })
			expect('the Lead assigns a cost position of the other kind', wrongKind, 422, 'invalid')
			expect('the member marks it «please check»', await put(U1, 'receipt', R1, { ...(await get('receipt', R1)).data, review: true, review_reasons: ['Is it the right trip?'] }), 200)
			const asg = await put(L, 'receipt', R1, { ...(await get('receipt', R1)).data, budget: 'b1', cost: 1, invoice_effect: 'charge' })
			const want = `${dir}/${Y}-03-10_Bahn-Muster_108.10-CHF_${CODE}-K1.pdf`
			check('the Lead assigns budget and cost position while it is marked: open, missing review, the file follows', asg.status === 200 && asg.data?.data?.status === 'open' && JSON.stringify(asg.data?.missing) === '["review"]'
				&& asg.data?.data?.budget === 'b1' && asg.data?.data?.cost === 1 && asg.data?.data?.file?.path === want && (await davGet(want)).status === 200, asg.data)
			const keep = await put(U1, 'receipt', R1, { ...(await get('receipt', R1)).data, budget: null, cost: null, invoice_effect: 'none', text: 'Basel–Bern' })
			check('the member writes again: budget, cost position and effect stay, the name too, still open', keep.status === 200 && keep.data?.data?.status === 'open' && keep.data?.data?.budget === 'b1' && keep.data?.data?.cost === 1 && keep.data?.data?.invoice_effect === 'charge' && keep.data?.data?.file?.path === want && keep.data?.missing === undefined, keep.data)
			const byWrite = await put(L, 'receipt', R1, { ...(await get('receipt', R1)).data, review: false })
			check('the Lead marks it checked: assignment is approval, in the same write', byWrite.status === 200 && byWrite.data?.data?.status === 'approved' && byWrite.data?.data?.approved_by === 'pblead' && byWrite.data?.missing === undefined && byWrite.data?.data?.file?.path === want, byWrite.data)
		}

		head('Receipts: approval and rename, rejection')
		{
			// A general receipt with a PNG, then booked to the project before approval: the file is renamed once.
			// Marked «please check», so neither the Team Admin's write nor the file approves it.
			const g = await ocs(A, 'PUT', rec('receipt', R2), { version: 0, data: receipt({ supplier: 'Druckerei', text: 'Flyer', net: 500, vat_rate: null, vat: 0, gross: 500, project: null, budget: null, cost: null, paid_by: 'customer', person: null, reimburse: false, review: true }) })
			expect('Team Admin writes a general receipt the customer paid', g, 200)
			check('… invoice_effect none (no project); open, missing file and review', g.data?.data?.invoice_effect === 'none' && g.data?.data?.status === 'open' && JSON.stringify(g.data?.missing) === '["file","review"]', g.data)
			const f = await raw(A, 'PUT', `/receipts/${R2}/file`, PNG, 'image/png')
			check('a PNG under Belege/ without project in the name; still open, missing review', f.status === 200 && f.data?.path === `${FOLDER}/Buchhaltung/${Y}/Belege/${Y}-03-10_Druckerei_500.00-CHF.png` && f.data?.mime === 'image/png' && f.data?.status === 'open' && JSON.stringify(f.data?.missing) === '["review"]', f.data)
			const cur = await get('receipt', R2)
			const moved = await ocs(L, 'PUT', rec('receipt', R2), { version: cur.version, data: { ...cur.data, project: PB, budget: 'b1', cost: 2, invoice_effect: 'show_only', note: 'paid by the customer' } })
			expect('the Lead books it to their project before approval (show_only)', moved, 403, 'forbidden')
			const m2 = await ocs(A, 'PUT', rec('receipt', R2), { version: cur.version, data: { ...cur.data, project: PB, budget: 'b1', cost: 2, invoice_effect: 'show_only', note: 'paid by the customer' } })
			expect('Team Admin books it to the project (show_only)', m2, 200)
			check('… the open receipt’s file follows at once', m2.data?.data?.file?.path === `${FOLDER}/Buchhaltung/${Y}/Belege/${Y}-03-10_Druckerei_500.00-CHF_${CODE}-K2.png`, m2.data?.data?.file)
			expect('a member approves', await ocs(U1, 'POST', `/receipts/${R1}/approve`, {}), 403, 'forbidden')
			const a = await ocs(L, 'POST', `/receipts/${R2}/approve`, {})
			expect('the project’s Lead approves', a, 200)
			check('… approved by the Lead, renamed once with project and cost', a.data?.data?.status === 'approved' && a.data?.data?.approved_by === 'pblead' && a.data?.data?.file?.path === `${FOLDER}/Buchhaltung/${Y}/Belege/${Y}-03-10_Druckerei_500.00-CHF_${CODE}-K2.png`, a.data?.data)
			check('… the old name is gone, the new one there', (await davGet(`${FOLDER}/Buchhaltung/${Y}/Belege/${Y}-03-10_Druckerei_500.00-CHF.png`)).status === 404 && (await davGet(a.data?.data?.file?.path || 'none')).status === 200)
			check('… and clears the mark', a.data?.data?.review === false, a.data?.data)
			expect('approving again', await ocs(L, 'POST', `/receipts/${R2}/approve`, {}), 409, 'conflict')
			expect('the member’s receipt is approved already (by the assignment)', await ocs(L, 'POST', `/receipts/${R1}/approve`, {}), 409, 'conflict')
			const c1 = await get('receipt', R1)
			expect('the member changes it after approval', await ocs(U1, 'PUT', rec('receipt', R1), { version: c1.version, data: { ...c1.data, note: 'x' } }), 403, 'forbidden')
			expect('the Lead changes the amount after approval', await ocs(L, 'PUT', rec('receipt', R1), { version: c1.version, data: { ...c1.data, net: 90, vat: 7.29, gross: 97.29 } }), 409, 'conflict')
			const n = await ocs(L, 'PUT', rec('receipt', R1), { version: c1.version, data: { ...c1.data, note: 'checked', status: 'open' } })
			expect('the Lead corrects the note', n, 200)
			check('… status stays approved', n.data?.data?.status === 'approved' && n.data?.data?.note === 'checked', n.data?.data)
			expect('deleting an approved receipt', await ocs(A, 'DELETE', `${rec('receipt', R1)}?version=${n.data?.version}`), 409, 'conflict')
			expect('a file after approval by the member', await raw(U1, 'PUT', `/receipts/${R1}/file`, pdf('late'), 'application/pdf'), 403, 'forbidden')
			expect('… by a Team Admin', await raw(A, 'PUT', `/receipts/${R1}/file`, pdf('late'), 'application/pdf'), 409, 'conflict')
			// A trust receipt: no file, approved by Team Admins only; rejected and written again.
			const t = await ocs(U1, 'PUT', rec('receipt', R3), { version: 0, data: receipt({ kind: 'trust', supplier: 'Parking', text: 'Meter', net: 5, vat_rate: null, vat: 0, gross: 5 }) })
			expect('a trust receipt', t, 200)
			expect('… no file for it', await raw(U1, 'PUT', `/receipts/${R3}/file`, pdf('x'), 'application/pdf').then((x) => ({ status: x.status, data: x.data, text: x.text })), 422, 'invalid')
			expect('… the Lead may not approve it', await ocs(L, 'POST', `/receipts/${R3}/approve`, {}), 403, 'forbidden')
			expect('… rejecting without a reason', await ocs(A, 'POST', `/receipts/${R3}/reject`, {}), 422, 'invalid')
			const rj = await ocs(A, 'POST', `/receipts/${R3}/reject`, { reason: 'Please add the ticket.' })
			check('… rejected with the reason', rj.status === 200 && rj.data?.data?.status === 'rejected' && rj.data?.data?.reject_reason === 'Please add the ticket.', rj.data)
			const w = await ocs(U1, 'PUT', rec('receipt', R3), { version: rj.data?.version, data: { ...rj.data?.data, text: 'Meter, ticket lost' } })
			check('the member writes it again: open, reason cleared', w.status === 200 && w.data?.data?.status === 'open' && w.data?.data?.reject_reason === null, w.data)
			expect('… and deletes it while open', await ocs(U1, 'DELETE', `${rec('receipt', R3)}?version=${w.data?.version}`), 200)
			// A general receipt the company paid needs no approval: complete, it is internal – also from a member.
			const c = await ocs(U1, 'PUT', rec('receipt', R8), { version: 0, data: receipt({ supplier: 'Papeterie', text: 'Toner', net: 40, vat_rate: 8.1, vat: 3.24, gross: 43.24, project: null, budget: null, cost: null, paid_by: 'company', person: null, reimburse: false }) })
			check('a member records a company receipt without project: open, missing file', c.status === 200 && c.data?.data?.status === 'open' && JSON.stringify(c.data?.missing) === '["file"]', c.data)
			const cf = await raw(U1, 'PUT', `/receipts/${R8}/file`, pdf('Toner'), 'application/pdf')
			check('… the file completes it: internal, approved by the member’s upload', cf.status === 200 && cf.data?.status === 'internal' && cf.data?.missing === undefined && (await get('receipt', R8))?.data?.approved_by === 'pbuser1', cf.data)
		}

		head('Receipts: recurring expenses')
		{
			const rule = { schema: 1, person: 'pbuser1', text: 'Mobile phone', amount: 50, vat_rate: null, period: 'monthly', from: `${Y}-01-15`, to: null, project: null, budget: null, cost: null }
			const r = await ocs(U1, 'PUT', rec('expense_rule', RULE), { version: 0, data: { ...rule, approved_by: 'pbuser1' } })
			check('the member writes a rule; approved_by ignored', r.status === 200 && r.data?.data?.approved_by === null, r.data)
			expect('a rule for someone else', await ocs(U1, 'PUT', rec('expense_rule', `${RULE}x`), { version: 0, data: { ...rule, person: 'pbuser2' } }), 403, 'forbidden')
			const recurring = (period, key = RR) => ocs(U1, 'PUT', rec('receipt', key), { version: 0, data: receipt({ kind: 'recurring', rule: RULE, period, date: `${Y}-02-28`, supplier: '', text: 'Mobile phone', net: 50, vat_rate: null, vat: 0, gross: 50, project: null, budget: null, cost: null }) })
			expect('a recurring receipt of a rule not approved', await recurring(`${Y}-02`), 422, 'invalid')
			expect('the member approves their general rule', await ocs(U1, 'POST', `/expense-rules/${RULE}/approve`, {}), 403, 'forbidden')
			const ap = await ocs(A, 'POST', `/expense-rules/${RULE}/approve`, {})
			check('Team Admin approves the rule', ap.status === 200 && ap.data?.data?.approved_by === 'pbadmin', ap.data)
			const rr = await recurring(`${Y}-02`)
			check('the recurring receipt of February: internal at once (general)', rr.status === 200 && rr.data?.data?.status === 'internal' && rr.data?.data?.approved_by === 'pbuser1', rr.data?.data)
			const dup = await recurring(`${Y}-02`, `rc-${RUN}-r2`)
			expect('a second one for February', dup, 409, 'duplicate')
			expect('a period before the rule', await recurring(`${Y - 1}-12`, `rc-${RUN}-r2`), 422, 'invalid')
			expect('a quarter for a monthly rule', await recurring(`${Y}-Q1`, `rc-${RUN}-r2`), 422, 'invalid')
			const cr = await get('expense_rule', RULE)
			const ch = await ocs(U1, 'PUT', rec('expense_rule', RULE), { version: cr.version, data: { ...cr.data, amount: 60 } })
			check('a new amount clears the approval', ch.status === 200 && ch.data?.data?.approved_by === null, ch.data?.data)
		}

		head('Receipts: the expense report, fixed like an invoice')
		let NUM = null
		let FIRST = null
		{
			const today = new Date().toISOString().slice(0, 10)
			const ty = today.slice(0, 4)
			expect('another member says ready for pbuser1', await ocs(U2, 'POST', '/expense-reports/ready', { person: 'pbuser1' }), 403, 'forbidden')
			const rd = await ocs(U1, 'POST', '/expense-reports/ready', { person: 'pbuser1' })
			const SB = rd.data?.key
			check('the person says ready: the open report, schema 2, ready_on today', rd.status === 200 && /^sb-[0-9a-f]{8}$/.test(SB ?? '') && rd.data?.data?.status === 'open' && rd.data?.data?.schema === 2 && rd.data?.data?.ready_on === today && rd.data?.data?.number === null, rd.data)
			if (SB) {
				records.push(['expense_report', SB])
			}
			const rd2 = await ocs(A, 'POST', '/expense-reports/ready', { person: 'pbuser1' })
			check('… again (Team Admin): the same open report', rd2.status === 200 && rd2.data?.key === SB, rd2.data)
			expect('a second open report through /records', await ocs(U1, 'PUT', rec('expense_report', `sb-${RUN}x`), { version: 0, data: { schema: 2, person: 'pbuser1' } }), 409, 'duplicate')
			const cw = await ocs(U1, 'PUT', rec('expense_report', SB), { version: rd2.data?.version, data: { ...rd2.data?.data, status: 'fixed', number: 'X-1', lines: [] } })
			check('the person writes the open report: the server’s fields stay', cw.status === 200 && cw.data?.data?.status === 'open' && cw.data?.data?.number === null && cw.data?.data?.lines === null, cw.data)
			expect('another member reads it', await ocs(U2, 'GET', rec('expense_report', SB)), 403, 'forbidden')
			expect('the Lead reads it', await ocs(L, 'GET', rec('expense_report', SB)), 403, 'forbidden')
			expect('the member fixes it', await ocs(U1, 'POST', '/expense-reports/fix', { person: 'pbuser1' }), 403, 'forbidden')
			expect('fix with a receipt not on the list (not reimbursed)', await ocs(A, 'POST', '/expense-reports/fix', { person: 'pbuser1', receipts: [R2] }), 422, 'invalid')
			expect('fix for an account outside the team', await ocs(A, 'POST', '/expense-reports/fix', { person: 'atuser1' }), 422, 'invalid')
			const fx = await ocs(A, 'POST', '/expense-reports/fix', { person: 'pbuser1', receipts: [R1, RR] })
			const d = fx.data?.data
			NUM = d?.number ?? null
			const docPath = `${FOLDER}/Buchhaltung/${ty}/Spesen/${person}/${today}_${NUM}_Spesenblatt.pdf`
			check('Team Admin fixes: the open report, numbered, frozen lines by date, sums per rate (0 and 8.1), gross 158.10 CHF', fx.status === 200 && fx.data?.key === SB && d?.status === 'fixed' && new RegExp(`^SP-${RUN}-\\d{4}$`).test(NUM ?? '')
				&& d?.fixed_by === 'pbadmin' && d?.fixed_on === today && d?.ready_on === today && JSON.stringify((d?.lines || []).map((l) => l.receipt)) === JSON.stringify([RR, R1])
				&& near(d?.sums?.net, 150) && near(d?.sums?.gross, 158.1) && d?.sums?.currency === 'CHF' && JSON.stringify(d?.sums?.vat_lines) === '[{"rate":0,"net":50,"vat":0},{"rate":8.1,"net":100,"vat":8.1}]', fx.data)
			check('… the lines: own amounts, VAT per rate, without_file for the recurring one', d?.lines?.[0]?.without_file === true && JSON.stringify(d?.lines?.[0]?.vat_lines) === '[]' && d?.lines?.[1]?.without_file === false
				&& d?.lines?.[1]?.project === PB && d?.lines?.[1]?.cost === 1 && near(d?.lines?.[1]?.gross, 108.1) && d?.lines?.[1]?.booked === null, d?.lines)
			check('… the PDF under Spesen/<person>/ with the number, document_state done', d?.document_state === 'done' && d?.document?.path === docPath && /^[0-9a-f]{64}$/.test(d?.document?.sha256 ?? '') && (await davGet(docPath)).status === 200, [d?.document, d?.document_error, docPath])
			check('… the receipts note the report', (await get('receipt', R1))?.data?.report === SB && (await get('receipt', RR))?.data?.report === SB)
			expect('fixing them again', await ocs(A, 'POST', '/expense-reports/fix', { person: 'pbuser1', receipts: [R1] }), 409, 'conflict')
			const led = await ocs(A, 'GET', `/invoices/ledger?since=${(d?.ledger_seq ?? 1) - 1}`)
			const [ef, edoc] = led.data?.entries || []
			check('the ledger: expense_fixed (invoice 0, its payload hashed) and expense_document with the PDF’s hash', led.status === 200 && ef?.action === 'expense_fixed' && ef?.number === NUM && ef?.invoice === 0 && ef?.hash === d?.hash
				&& sha256(ef?.payload ?? '') === ef?.payload_hash && JSON.parse(ef?.payload ?? '{}')?.key === SB && JSON.parse(ef?.payload ?? '{}')?.lines?.length === 2
				&& edoc?.action === 'expense_document' && JSON.parse(edoc?.payload ?? '{}')?.sha256 === d?.document?.sha256 && edoc?.prev_hash === ef?.hash, [led.status, ef, edoc])
			const doc = await raw(U1, 'GET', `/expense-reports/${SB}/document`)
			check('the person downloads the PDF; its hash is the record’s', doc.status === 200 && doc.buf.subarray(0, 5).toString() === '%PDF-' && sha256(doc.buf) === d?.document?.sha256, doc.status)
			expect('another member downloads it', await raw(U2, 'GET', `/expense-reports/${SB}/document`).then((x) => ({ status: x.status, data: x.data, text: x.text })), 403, 'forbidden')
			const text = pdfText(doc.buf)
			if (text === null) {
				console.log('    info  pdftotext not installed – the PDF text is not checked')
			} else {
				check('the PDF: title with number, both receipts, «ohne Beleg», sums, signature lines, the receipt copy, the chain line', text.includes(`Spesenblatt ${NUM}`) && text.includes('Basel–Bern') && text.includes('ohne Beleg') && text.includes('158.10')
					&& text.includes('Unterschrift Person') && text.includes('Fixiert') && text.includes(`Beleg 10.03.${Y} Bahn Muster`) && text.includes(`Beleg ${NUM} · Kette ${d?.ledger_seq}`), text.slice(0, 600))
			}
			const cur = await get('expense_report', SB)
			expect('the member changes it after fixing', await ocs(U1, 'PUT', rec('expense_report', SB), { version: cur.version, data: cur.data }), 409, 'conflict')
			expect('Team Admin deletes it', await ocs(A, 'DELETE', `${rec('expense_report', SB)}?version=${cur.version}`), 409, 'conflict')
			expect('the member marks it paid', await ocs(U1, 'POST', `/expense-reports/${SB}/paid`, { date: `${Y}-04-05` }), 403, 'forbidden')
			expect('the member reverses it', await ocs(U1, 'POST', `/expense-reports/${SB}/reverse`, {}), 403, 'forbidden')
			const rv = await ocs(A, 'POST', `/expense-reports/${SB}/reverse`, { reason: 'Wrong month' })
			check('Team Admin reverses it: reversed, the reason, a ledger entry; the PDF stays', rv.status === 200 && rv.data?.data?.status === 'reversed' && rv.data?.data?.reverse_reason === 'Wrong month' && rv.data?.data?.reversed_on === today
				&& /^[0-9a-f]{64}$/.test(rv.data?.ledger?.hash ?? '') && rv.data?.data?.document?.path === docPath && (await davGet(docPath)).status === 200, rv.data)
			check('… the receipts are free again', (await get('receipt', R1))?.data?.report === null && (await get('receipt', RR))?.data?.report === null)
			expect('reversing again', await ocs(A, 'POST', `/expense-reports/${SB}/reverse`, {}), 409, 'conflict')
			expect('paid after the reversal', await ocs(A, 'POST', `/expense-reports/${SB}/paid`, {}), 409, 'conflict')
			// Named, so receipts other runs left on the list stay out of it.
			const fx2 = await ocs(A, 'POST', '/expense-reports/fix', { person: 'pbuser1', receipts: [RR, R1] })
			const SB2 = fx2.data?.key
			if (SB2) {
				records.push(['expense_report', SB2])
			}
			FIRST = NUM
			NUM = fx2.data?.data?.number ?? null
			// The counter runs per year across runs; the pattern carries the run.
			check('fixed again: a new report with the next number', fx2.status === 200 && SB2 !== SB && NUM === `SP-${RUN}-${String(Number(FIRST?.slice(-4)) + 1).padStart(4, '0')}` && fx2.data?.data?.lines?.length === 2 && fx2.data?.data?.ready_on === null, fx2.data)
			const paid = await ocs(A, 'POST', `/expense-reports/${SB2}/paid`, { date: `${Y}-04-05` })
			check('Team Admin marks it paid, with a ledger entry', paid.status === 200 && paid.data?.data?.status === 'paid' && paid.data?.data?.paid_on === `${Y}-04-05` && /^[0-9a-f]{64}$/.test(paid.data?.ledger?.hash ?? ''), paid.data)
			expect('a paid report is not reversed', await ocs(A, 'POST', `/expense-reports/${SB2}/reverse`, {}), 409, 'conflict')
			const tail = (await ocs(A, 'GET', `/invoices/ledger?since=${(d?.ledger_seq ?? 1) - 1}`)).data?.entries || []
			check('the ledger holds fixed, document, reversed, fixed, document, paid', JSON.stringify(tail.map((e) => e.action)) === JSON.stringify(['expense_fixed', 'expense_document', 'expense_reversed', 'expense_fixed', 'expense_document', 'expense_paid']), tail.map((e) => e.action))
			const vf = await ocs(A, 'GET', '/invoices/chain/verify')
			check('the chain verifies with the expense entries', vf.status === 200 && vf.data?.ok === true, vf.data)
		}

		head('Receipts: export of expenses')
		{
			expect('a member exports', await raw(U1, 'GET', `/exports/expenses?from=${Y}-01-01&to=${Y}-12-31`).then((x) => ({ status: x.status, data: x.data, text: x.text })), 403, 'forbidden')
			const csv = await raw(A, 'GET', `/exports/expenses?from=${Y}-01-01&to=${Y}-12-31`)
			const lines = csv.text.split('\r\n')
			check('CSV: head and two rows of the paid report (by number), none of the reversed one', csv.status === 200 && lines[0].endsWith('person;person_name;report;report_status;date;supplier;text;project;currency;net;vat_rate;vat;gross;without_receipt')
				&& lines.some((l) => l.startsWith(`pbuser1;`) && l.includes(`;${NUM};paid;${Y}-03-10;Bahn Muster;Basel–Bern;${PB};CHF;100.00;8.1;8.10;108.10;0`))
				&& lines.some((l) => l.includes(`;${NUM};paid;${Y}-02-28;;Mobile phone;;CHF;50.00;;0.00;50.00;1`)) && !lines.some((l) => l.includes(`;${FIRST};`)), lines.slice(0, 4))
			const accounts = { ...(datevBefore || {}), consultant: '1234567', client: '12345', account_length: 4 }
			delete accounts.expense_account
			await ocs(A, 'PUT', '/team/settings', { datev: accounts })
			const no = await raw(A, 'GET', `/exports/expenses?from=${Y}-01-01&to=${Y}-12-31&kind=datev`)
			check('DATEV without expense_account → 422 naming it', no.status === 422 && no.text.includes('expense_account'), [no.status, no.text.slice(0, 200)])
			expect('Team Admin sets datev.expense_account', await ocs(A, 'PUT', '/team/settings', { datev: { ...accounts, expense_account: '6300' } }), 200)
			const noCred = await raw(A, 'GET', `/exports/expenses?from=${Y}-01-01&to=${Y}-12-31&kind=datev`)
			check('DATEV without the person’s creditor account → 422 naming creditor_account', noCred.status === 422 && noCred.text.includes('creditor_account'), [noCred.status, noCred.text.slice(0, 200)])
			const pdata = personBefore.status === 200 ? { ...personBefore.data.data } : { schema: 1, login: 'pbuser1', name: nameOf('pbuser1') }
			expect('a creditor account of 10 digits', await put(A, 'person', 'pbuser1', { ...pdata, creditor_account: '1234567890' }), 422, 'invalid')
			expect('the person gets creditor_account 70001', await put(A, 'person', 'pbuser1', { ...pdata, creditor_account: '70001' }), 200)
			const dv = await raw(A, 'GET', `/exports/expenses?from=${Y}-01-01&to=${Y}-12-31&kind=datev`)
			const rows = dv.text.split('\r\n').filter((l) => l.includes(`;${NUM};`))
			check('DATEV: EXTF head, one booking per receipt, debit 6300, credit 70001', dv.status === 200 && dv.text.includes('TimeSister Spesen') && rows.length === 2 && rows.some((l) => l.startsWith('108,10;S;CHF;;;;6300;70001;;1003;')), rows)
		}

		head('Receipts: invoicing from receipts')
		{
			const positions = [
				{ kind: 'hours', text: 'Planung', hours: 10, rate: 100, amount: 1000 },
				{ kind: 'costs', text: 'Spesen nach Beleg', amount: 100, cost: 1, receipts: [R1] },
				{ kind: 'markup', text: 'Zuschlag 10 %', amount: 10, cost: 1, rate: 10 },
				{ kind: 'costs', text: 'Druck', amount: 0, cost: 2, vat_mode: 'pass_through', receipts: [R2] },
				{ kind: 'expense_percent', text: 'Nebenkosten 3 %', amount: 30, rate: 3 },
			]
			const draft = (more = {}) => ({ schema: 1, project: PB, budget: 'b1', from: `${Y}-03-01`, to: `${Y}-03-31`, status: 'draft', number: null, bookings: [], positions, created_by: 'pbadmin', created_on: `${Y}-04-01`, ...more })
			expect('receipts on a flat line', await ocs(A, 'PUT', rec('invoice', K1), { version: 0, data: draft({ positions: [{ kind: 'flat', text: 'x', amount: 1, receipts: [R1] }] }) }), 422, 'invalid')
			expect('Team Admin writes the draft', await ocs(A, 'PUT', rec('invoice', K1), { version: 0, data: draft({ positions: positions.map((p, i) => (i === 2 ? { ...p, amount: 12 } : p)) }) }), 200)
			const wrong = await raw(A, 'GET', `/invoices/${K1}/preview`)
			check('preview with a wrong markup → 422 naming the amount', wrong.status === 422 && wrong.text.includes('The amount must be 10.00'), [wrong.status, wrong.text.slice(0, 200)])
			expect('… issue too', await ocs(A, 'POST', `/invoices/${K1}/issue`, { issued_on: `${Y}-04-01` }), 422, 'invalid')
			expect('the draft corrected', await put(A, 'invoice', K1, draft()), 200)
			const pre = await raw(A, 'GET', `/invoices/${K1}/preview`)
			check('the preview renders', pre.status === 200 && pre.buf.subarray(0, 5).toString() === '%PDF-', pre.status)
			const pt = pdfText(pre.buf)
			if (pt !== null) {
				check('… with the receipt copies', pt.includes(`Beleg 10.03.${Y} Bahn Muster`) && pt.includes(`Beleg 10.03.${Y} Druckerei`), pt.slice(-400))
			}
			const is = await ocs(A, 'POST', `/invoices/${K1}/issue`, { issued_on: `${Y}-04-01` })
			expect('issue', is, 200)
			check('… total: 1140 taxable at 8.1 % plus 0 pass-through', near(is.data?.issue?.net, 1140) && near(is.data?.issue?.total, 1232.34), is.data?.issue)
			const r1 = (await get('receipt', R1))?.data
			const r2 = (await get('receipt', R2))?.data
			check('both receipts billed by the invoice, in the same transaction', r1?.status === 'billed' && r1?.invoice === K1 && r2?.status === 'billed' && r2?.invoice === K1, [r1?.status, r2?.status])
			let f = await ocs(A, 'GET', `/invoices/${K1}/fixed`)
			if (f.data?.document_state !== 'done') {
				await ocs(A, 'POST', `/invoices/${K1}/render`, {})
				f = await ocs(A, 'GET', `/invoices/${K1}/fixed`)
			}
			check('fixed: receipts[] kept; the customer-paid line at 0.00 with VAT 0', JSON.stringify(f.data?.positions?.[1]?.receipts) === JSON.stringify([R1]) && f.data?.positions?.[3]?.amount === 0 && f.data?.positions?.[3]?.vat === null && f.data?.positions?.[3]?.text?.includes('paid by the customer'), f.data?.positions)
			check('the stored PDF lies under Rechnungen/', f.data?.document_state === 'done' && f.data?.document_path === `${FOLDER}/Buchhaltung/${Y}/Rechnungen/${Y}-04-01_${f.data?.number}_Kunde-Sued-${RUN}.pdf`, [f.data?.document_path, f.data?.document_error])
			const doc = await raw(A, 'GET', `/invoices/${K1}/document`)
			const dt = pdfText(doc.buf)
			if (dt !== null) {
				check('… with the receipt copies and the chain line on them', dt.includes(`Beleg 10.03.${Y} Bahn Muster`) && dt.includes(`Beleg ${f.data?.number} · Kette`), dt.slice(-400))
			}
			expect('a second draft naming a billed receipt', await ocs(A, 'PUT', rec('invoice', K2), { version: 0, data: draft({ positions: [positions[1], { ...positions[2] }] }) }), 200)
			expect('… issuing it → 409', await ocs(A, 'POST', `/invoices/${K2}/issue`, { issued_on: `${Y}-04-02` }), 409, 'conflict')
			const c1 = await get('receipt', R1)
			expect('a billed receipt: the note changes', await ocs(A, 'PUT', rec('receipt', R1), { version: c1.version, data: { ...c1.data, note: 'billed' } }), 200)
			const c2 = await get('receipt', R1)
			expect('… the cost position does not', await ocs(A, 'PUT', rec('receipt', R1), { version: c2.version, data: { ...c2.data, cost: 2 } }), 409, 'conflict')
			const rv = await ocs(A, 'POST', `/invoices/${K1}/reverse`, { kind: 'reversal', issued_on: `${Y}-04-03` })
			expect('reversal', rv, 200)
			if (rv.data?.record?.key) {
				invoices.push(rv.data.record.key)
			}
			const after = [(await get('receipt', R1))?.data, (await get('receipt', R2))?.data]
			check('the reversal releases the receipts: approved, invoice null', after.every((x) => x?.status === 'approved' && x?.invoice === null), after.map((x) => [x?.status, x?.invoice]))
		}

		head('Receipts: the quote document')
		{
			const q = await ocs(A, 'PUT', rec('quote', Q), { version: 0, data: { schema: 1, name: `Offerte ${RUN}`, status: 'quoted', customer: CB, project: PB, number: `OF-${RUN}`, date: `${Y}-01-20`, leads: [] } })
			expect('a quote of the project', q, 200)
			expect('a member stores its PDF', await raw(U1, 'PUT', `/quotes/${Q}/document`, pdf('Offerte'), 'application/pdf').then((x) => ({ status: x.status, data: x.data, text: x.text })), 403, 'forbidden')
			expect('… a PNG', await raw(A, 'PUT', `/quotes/${Q}/document`, PNG, 'image/png').then((x) => ({ status: x.status, data: x.data, text: x.text })), 422, 'invalid')
			const d = await raw(L, 'PUT', `/quotes/${Q}/document`, pdf('Offerte'), 'application/pdf')
			check('the project’s Lead stores it under Offerten/, the record carries document', d.status === 200 && d.data?.path === `${FOLDER}/Buchhaltung/${Y}/Offerten/${Y}-01-20_OF-${RUN}_Kunde-Sued-${RUN}.pdf` && d.data?.sha256 === sha256(pdf('Offerte')) && (await get('quote', Q))?.data?.document?.path === d.data?.path, [d.status, d.data])
			const cur = await get('quote', Q)
			const { document: _, ...rest } = cur.data
			const w = await ocs(A, 'PUT', rec('quote', Q), { version: cur.version, data: { ...rest, name: `Offerte ${RUN} B` } })
			check('a client write without document keeps it', w.status === 200 && w.data?.data?.document?.path === d.data?.path, w.data?.data)
			const g = await raw(L, 'GET', `/quotes/${Q}/document`)
			check('GET returns the PDF', g.status === 200 && g.buf.equals(pdf('Offerte')), g.status)
		}

		head('Receipts: the project dossier')
		{
			expect('a member makes it', await ocs(U1, 'POST', `/projects/${PB}/dossier`, {}), 403, 'forbidden')
			const z = await ocs(L, 'POST', `/projects/${PB}/dossier`, {})
			const day = new Date().toISOString().slice(0, 10)
			const want = `${FOLDER}/Projekte/${CODE} ${safe(`Belege ${RUN}`)}/Projektakte-${day}.zip`
			check('the Lead makes it: path, size, sha256', z.status === 200 && z.data?.path === want && z.data?.size > 0 && /^[0-9a-f]{64}$/.test(z.data?.sha256 ?? ''), [z.status, z.data, want])
			const file = await davRaw(want)
			const names = zipNames(file.buf)
			check('… the ZIP holds the quote, the invoices, the receipts, hours.csv and ledger.txt', file.status === 200 && sha256(file.buf) === z.data?.sha256
				&& names.some((n) => n.startsWith('Offerten/')) && names.filter((n) => n.startsWith('Rechnungen/')).length >= 2 && names.filter((n) => n.startsWith('Belege/')).length === 2
				&& names.includes('hours.csv') && names.includes('ledger.txt'), names)
			const again = await ocs(L, 'POST', `/projects/${PB}/dossier`, {})
			check('a second call the same day replaces it', again.status === 200 && again.data?.path === want, again.data)
		}

		head('Receipts: privacy notice names the module costs')
		{
			const md = await raw(A, 'GET', '/team/privacy?format=md')
			check('the notice names costs and expenses', md.status === 200 && (md.text.includes('Costs and expenses') || md.text.includes('Kosten und Spesen')), md.text.slice(0, 200))
		}
	} finally {
		head('Receipts: recognition fields')
		{
			// Filled by the Mac app's recognition: the mark, its source, the QR reference, VAT lines for two rates.
			const lines = [{ rate: 2.6, net: 40, vat: 1.04 }, { rate: 8.1, net: 60, vat: 4.86 }]
			const two = receipt({ supplier: 'Market', text: 'Food and paper', net: 100, vat_rate: null, vat: 5.9, gross: 105.9, reimburse: false, vat_lines: lines,
				review: true, review_reasons: ['The total differs from the sum of the lines.'], recognized_by: 'qr', qr_reference: '210000000003139471430009017' })
			expect('vat_lines with a single vat_rate', await ocs(A, 'PUT', rec('receipt', R4), { version: 0, data: { ...two, vat_rate: 8.1 } }), 422, 'invalid')
			expect('vat_lines not adding up', await ocs(A, 'PUT', rec('receipt', R4), { version: 0, data: { ...two, vat: 6, gross: 106 } }), 422, 'invalid')
			expect('an unknown source', await ocs(A, 'PUT', rec('receipt', R4), { version: 0, data: { ...two, recognized_by: 'ocr' } }), 422, 'invalid')
			const w = await ocs(A, 'PUT', rec('receipt', R4), { version: 0, data: two })
			expect('Team Admin writes a receipt with two rates, marked «please check», for pbuser1', w, 200)
			check('… the fields are stored', w.data?.data?.review === true && w.data?.data?.review_reasons?.length === 1 && w.data?.data?.recognized_by === 'qr'
				&& w.data?.data?.qr_reference === '210000000003139471430009017' && w.data?.data?.vat_lines?.length === 2, w.data?.data)
			const plain = await get('receipt', R2)
			check('a receipt without them answers the defaults', plain?.data?.review === false && JSON.stringify(plain?.data?.review_reasons) === '[]' && plain?.data?.vat_lines === null, plain?.data)
			const c = await get('receipt', R4)
			expect('the person who paid (not who recorded it) clears the mark', await ocs(U1, 'PUT', rec('receipt', R4), { version: c.version, data: { ...c.data, review: false } }), 403, 'forbidden')
			const ok = await ocs(L, 'PUT', rec('receipt', R4), { version: c.version, data: { ...c.data, review: false } })
			check('the project’s Lead marks it checked: reasons go with the mark', ok.status === 200 && ok.data?.data?.review === false && JSON.stringify(ok.data?.data?.review_reasons) === '[]', ok.data)
			const again = await ocs(U1, 'PUT', rec('receipt', R4), { version: ok.data?.version, data: { ...ok.data?.data, review: true, review_reasons: ['Please check the date.'] } })
			expect('the member sets the mark again', again, 200)
			expect('… a file', await raw(A, 'PUT', `/receipts/${R4}/file`, pdf('Market'), 'application/pdf').then((x) => ({ status: x.status, data: x.data, text: x.text })), 200)
			const a = await ocs(L, 'POST', `/receipts/${R4}/approve`, {})
			check('approval clears the mark', a.status === 200 && a.data?.data?.status === 'approved' && a.data?.data?.review === false && JSON.stringify(a.data?.data?.review_reasons) === '[]', a.data)
			const draft = (positions) => ({ schema: 1, project: PB, budget: 'b1', from: `${Y}-03-01`, to: `${Y}-03-31`, status: 'draft', number: null, bookings: [], positions, created_by: 'pbadmin', created_on: `${Y}-04-05` })
			expect('a draft with the receipt on one line', await ocs(A, 'PUT', rec('invoice', K3), { version: 0, data: draft([{ kind: 'costs', text: 'Market', amount: 100, cost: 1, receipts: [R4] }]) }), 200)
			const one = await raw(A, 'GET', `/invoices/${K3}/preview`)
			check('… preview: the line must name one of its rates → 422', one.status === 422 && one.text.includes('several VAT rates'), [one.status, one.text.slice(0, 200)])
			expect('… one line per rate', await put(A, 'invoice', K3, draft([{ kind: 'costs', text: 'Market 2.6 %', amount: 40, vat: 2.6, cost: 1, receipts: [R4] }])), 200)
			const half = await raw(A, 'GET', `/invoices/${K3}/preview`)
			check('… only one of the two rates → 422', half.status === 422 && half.text.includes('the line at 8.1 % is missing'), [half.status, half.text.slice(0, 200)])
			expect('… both rates', await put(A, 'invoice', K3, draft([{ kind: 'costs', text: 'Market 2.6 %', amount: 40, vat: 2.6, cost: 1, receipts: [R4] }, { kind: 'costs', text: 'Market 8.1 %', amount: 60, vat: 8.1, cost: 1, receipts: [R4] }])), 200)
			const pre = await raw(A, 'GET', `/invoices/${K3}/preview`)
			check('… the preview renders', pre.status === 200 && pre.buf.subarray(0, 5).toString() === '%PDF-', [pre.status, pre.text.slice(0, 200)])
		}

		head('Receipts: another currency')
		{
			// A receipt in USD paid by card in the team's currency: the amount paid is needed for approval.
			const usd = receipt({ kind: 'trust', supplier: 'Jotform', text: 'Subscription', date: `${Y}-03-12`, net: 234.01, vat_rate: 7.7, vat: 18.02, gross: 252.03, currency: 'USD',
				project: null, budget: null, cost: null, paid_by: 'company', person: null, reimburse: false })
			expect('booked in another currency than the team’s', await ocs(A, 'PUT', rec('receipt', R5), { version: 0, data: { ...usd, booked: { gross: 228.4, currency: 'EUR' } } }), 422, 'invalid')
			const w = await ocs(A, 'PUT', rec('receipt', R5), { version: 0, data: usd })
			check('a receipt in USD without the amount paid: open, missing booked', w.status === 200 && w.data?.data?.status === 'open' && JSON.stringify(w.data?.missing) === '["booked"]', w.data)
			const no = await ocs(A, 'POST', `/receipts/${R5}/approve`, {})
			check('… approval → 422 asking for the amount in CHF', no.status === 422 && no.data?.message === 'Enter the amount in CHF that was paid.', no.data)
			const b = await ocs(A, 'PUT', rec('receipt', R5), { version: w.data?.version, data: { ...w.data?.data, booked: { gross: 228.4, currency: 'CHF', rate: 0.9062 } } })
			check('the amount paid is stored without the rate; the Team Admin’s write approves it (general: internal)', b.status === 200 && JSON.stringify(b.data?.data?.booked) === '{"gross":228.4,"currency":"CHF"}' && b.data?.data?.status === 'internal' && b.data?.data?.approved_by === 'pbadmin', b.data)
			const same = await ocs(A, 'PUT', rec('receipt', R2), { version: (await get('receipt', R2)).version, data: { ...(await get('receipt', R2)).data, booked: { gross: 1, currency: 'CHF' } } })
			check('in the team’s own currency booked stays null', same.status !== 200 || same.data?.data?.booked === null, [same.status, same.data?.data?.booked])
		}

		head('Receipts against a budget in another currency')
		{
			// Team CHF, budget b2 in EUR: a EUR receipt counts with its original, a USD one needs the amount in EUR.
			const pr = await get('project', PB)
			const budgets = [...(pr.data.budgets || []).filter((b) => b.id !== 'b2'), { id: 'b2', name: 'Phase EUR', currency: 'EUR', costs: [{ no: 1, name: 'Spesen', kind: 'expense', rule: 'receipts', cap: 1500, mode: 'rebill' }] }]
			expect('a second budget in EUR', await ocs(A, 'PUT', rec('project', PB), { version: pr.version, data: { ...pr.data, budgets } }), 200)
			expect('settings.currency in small letters', await ocs(A, 'PUT', '/records/setting/settings', { version: (await get('setting', 'settings')).version, data: { ...(await get('setting', 'settings')).data, currency: 'chf' } }), 422, 'invalid')
			const trust = (over) => receipt({ kind: 'trust', supplier: 'Hotel', text: 'Night', date: `${Y}-03-14`, net: 100, vat_rate: 7, vat: 7, gross: 107, budget: 'b2', cost: 1, paid_by: 'company', person: null, reimburse: false, ...over })
			const eur = await ocs(A, 'PUT', rec('receipt', R6), { version: 0, data: trust({ currency: 'EUR', booked: { gross: 101.5, currency: 'CHF' }, billed: { gross: 1, currency: 'EUR' } }) })
			check('EUR receipt in the EUR budget: billed not needed, stored as null; approved by the write without a second amount', eur.status === 200 && eur.data?.data?.billed === null && eur.data?.data?.booked?.gross === 101.5 && eur.data?.data?.status === 'approved', eur.data)
			const usd = trust({ currency: 'USD', net: 200, vat: 14, gross: 214, booked: { gross: 172.2, currency: 'CHF' } })
			expect('billed in another currency than the budget’s', await ocs(A, 'PUT', rec('receipt', R7), { version: 0, data: { ...usd, billed: { gross: 183, currency: 'CHF' } } }), 422, 'invalid')
			const w = await ocs(A, 'PUT', rec('receipt', R7), { version: 0, data: usd })
			check('USD receipt in the EUR budget without the amount in EUR: open, missing billed', w.status === 200 && w.data?.data?.status === 'open' && JSON.stringify(w.data?.missing) === '["billed"]', w.data)
			const no = await ocs(A, 'POST', `/receipts/${R7}/approve`, {})
			check('… approval → 422 asking for the amount in EUR', no.status === 422 && no.data?.message === 'Enter the amount in EUR for the budget.', no.data)
			const b = await ocs(A, 'PUT', rec('receipt', R7), { version: w.data?.version, data: { ...w.data?.data, billed: { gross: 183.4, currency: 'EUR', rate: 0.857 } } })
			check('… billed stored without the rate; now approved by the write', b.status === 200 && JSON.stringify(b.data?.data?.billed) === '{"gross":183.4,"currency":"EUR"}' && b.data?.data?.status === 'approved', b.data)
			const ok = b
			const drop = await ocs(A, 'PUT', rec('receipt', R7), { version: ok.data?.version, data: { ...ok.data?.data, billed: null } })
			check('after approval it stays required → 422', drop.status === 422 && drop.data?.message === 'Enter the amount in EUR for the budget.', drop.data)
			const moved = await ocs(A, 'PUT', rec('receipt', R7), { version: ok.data?.version, data: { ...ok.data?.data, budget: 'b1' } })
			check('moved to the CHF budget: billed becomes null', moved.status === 200 && moved.data?.data?.billed === null, moved.data)
		}

		head('Receipts: cleaning up')
		await ocs(A, 'PUT', '/team/settings', { documents: { root: docsBefore.root ?? null, chain_mail: docsBefore.chain_mail ?? null }, datev: datevBefore || {} })
		const pc = await ocs(A, 'GET', rec('person', 'pbuser1'))
		if (personBefore.status === 200 && pc.status === 200) {
			await ocs(A, 'PUT', rec('person', 'pbuser1'), { version: pc.data.version, data: personBefore.data.data })
		} else if (pc.status === 200) {
			await ocs(A, 'DELETE', `${rec('person', 'pbuser1')}?version=${pc.data.version}`)
		}
		if (tenant !== null) {
			const inv = invoices.map((k) => `'${k}'`).join(', ')
			sql(`DELETE FROM oc_ts_inv_hours WHERE tenant_id = ${tenant} AND invoice_id IN (SELECT id FROM oc_ts_inv_invoices WHERE tenant_id = ${tenant} AND rkey IN (${inv}))`)
			sql(`DELETE FROM oc_ts_inv_invoices WHERE tenant_id = ${tenant} AND rkey IN (${inv})`)
			sql(`DELETE FROM oc_ts_counters WHERE tenant_id = ${tenant} AND name = 'invoice:${Y}'`)
			// Approved receipts and reports are never deleted through the API: the test's own rows go by SQL.
			const keys = [...records.map(([, k]) => k), ...invoices].map((k) => `'${k}'`).join(', ')
			sql(`DELETE FROM oc_ts_records WHERE tenant_id = ${tenant} AND rkey IN (${keys})`)
		} else {
			for (const k of invoices) {
				const cur = await ocs(A, 'GET', rec('invoice', k))
				if (cur.status === 200) {
					await ocs(A, 'DELETE', `${rec('invoice', k)}?version=${cur.data.version}`)
				}
			}
		}
		for (const [kind, key] of [['project', PB], ['customer', CB]]) {
			const cur = await ocs(A, 'GET', rec(kind, key))
			if (cur.status === 200) {
				await ocs(A, 'DELETE', `${rec(kind, key)}?version=${cur.data.version}`)
			}
		}
		const cur = await ocs(A, 'GET', '/records/setting/settings')
		if (settings.status === 200 && cur.status === 200) {
			await ocs(A, 'PUT', '/records/setting/settings', { version: cur.data.version, data: settings.data.data })
		} else if (cur.status === 200) {
			await ocs(A, 'DELETE', `/records/setting/settings?version=${cur.data.version}`)
		}
		await dav(ownerDav, 'DELETE', `files/${encodeURIComponent(owner)}/${FOLDER.split('/').map(encodeURIComponent).join('/')}`)
		check('pb left as found', isObj(await me(A)) && ADMIN !== null)
	}
}
