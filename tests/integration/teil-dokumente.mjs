// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Invoice documents (0.19.0, one renderer since 0.32.0): the team setting
// `documents` (since 0.22.0 the root of the document tree in the backup
// owner's files, checked on writing), the admin's check endpoint, the
// preview and render gone (410), the app's PDF filed by the close (file in
// the folder, the `issued` entry with the file's hash, chain still valid),
// the same bytes again, the chain head and the daily chain head file.
// Needs the booking in the book like teil-faktura.mjs; without it the
// issuing checks are skipped. Leaves zb as it found it (settings record,
// team setting, project, customer, event, marks); the files in the owner's
// folder and the ledger entries stay (append-only, test year far away).
import { ensureTimeCalendar, dav, deleteEvent, ocs, putEvent, runJob, sql, ADMIN } from './lib.mjs'
import { RUN, check, expect, festschreiben, head, isObj, me, raw, sha256, stornieren } from './harness.mjs'
import { A, L, TEAM, U1 } from './konten.mjs'

const path = (key) => `/records/invoice/${encodeURIComponent(key)}`
const ipath = (key) => `/invoices/${encodeURIComponent(key)}`
const billingKey = (person, uid) => `${person}+${sha256(uid).slice(0, 32)}`
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

/** A raw GET of the app API as `who`: status, content type and body bytes. */
async function fetchRaw(who, p, lang = 'en') {
	const res = await fetch(`${process.env.NC_URL || 'http://localhost:8081'}/ocs/v2.php/apps/timesister/api/v1${p}`, {
		headers: { 'OCS-APIRequest': 'true', 'Accept-Language': lang, Authorization: 'Basic ' + Buffer.from(`${who.user}:${who.pass}`).toString('base64') },
	})
	const buf = Buffer.from(await res.arrayBuffer())
	return { status: res.status, type: res.headers.get('content-type') || '', disposition: res.headers.get('content-disposition') || '', buf }
}

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

export async function dokumente() {
	const PF = `PD-${RUN}`
	const CF = `CD-${RUN}`
	const CODE = `G${RUN}`.toUpperCase()
	const EV1 = `td-${RUN}-1@example.test`
	const K1 = `inv-${RUN}d1`
	const K2 = `inv-${RUN}d2`
	const made = [K1, K2]
	const Y = 3000 + (parseInt(RUN.slice(-4), 36) % 5000) + 7
	const ISSUED_ON = `${Y}-05-15`
	const FOLDER = `TimeSister Test Documents/${RUN}`
	const tenant = sql("SELECT id FROM oc_ts_tenants WHERE slug = 'zb'")
	const settings = await ocs(A, 'GET', '/records/setting/settings')
	const base = settings.status === 200 ? settings.data.data : {}
	let settingsVersion = settings.status === 200 ? settings.data.version : 0
	const teamBefore = (await ocs(A, 'GET', '/team')).data
	const docsBefore = teamBefore?.settings?.documents || { root: null, chain_mail: null }
	let owner = teamBefore?.backup_owner || null
	const draft = (more = {}) => ({
		schema: 1, project: PF, budget: 'b1', from: '2026-10-01', to: '2026-10-31', status: 'draft', number: null,
		bookings: [{ person: 'zbuser1', uid: EV1, start: '2026-10-13T08:00:00', hours: 2, rate: 150, code: CODE }],
		positions: [{ kind: 'hours', text: 'Site visit', person: 'zbuser1', work_package: 0, hours: 2, rate: 150, amount: 300 }, { kind: 'flat', text: 'Travel', amount: 100 }],
		greeting: 'Dear team', body: 'Payable within 30 days.', footer: 'Muster · Basel', vat: 8.1, contact: 'c1',
		note: null, created_by: 'zbadmin', created_on: '2026-10-15', issued_on: null, paid_on: null, ...more,
	})
	try {
		head('Documents: setup')
		{
			const cap = await ocs(A, 'GET', '/ocs/v2.php/cloud/capabilities')
			check('capability invoice_documents: 2 (0.32.0)', cap.data?.capabilities?.timesister?.invoice_documents === 2, cap.data?.capabilities?.timesister)
			check('/me.settings.documents has root and chain_mail (0.22.0)', JSON.stringify(Object.keys((await me(A)).settings?.documents || {}).sort()) === '["chain_mail","root"]', (await me(A)).settings)
			if (!owner) {
				// The backup owner is the first Team Admin by default; set zbadmin explicitly through the admin page's endpoint.
				const teams = await ocs(ADMIN, 'GET', '/admin/teams')
				const zb = (teams.data || []).find((t) => t.slug === 'zb')
				if (zb) {
					const r = await ocs(ADMIN, 'PUT', `/admin/teams/${zb.id}`, { name: zb.name, slug: zb.slug, groups: zb.groups, backup_owner: 'zbadmin' })
					owner = r.data?.backup_owner || null
				}
			}
			check('zb has a backup owner', typeof owner === 'string' && owner !== '', owner)
			const s = await ocs(A, 'PUT', '/records/setting/settings', { version: settingsVersion, data: { ...base, language: 'de',
				invoice_number: { pattern: `RD-${RUN}-{no:4}`, per_year: true, start: 1 },
				issuer: { name: 'Muster sàrl', street: 'Musterstrasse 12', postcode: '4000', city: 'Basel', country: 'CH', vat_id: 'CHE-123.456.789 MWST', email: 'mail@example.test', qr: true, accounts: [{ currency: 'CHF', iban: 'CH9300762011623852957', bic: '' }] } } })
			expect('Team Admin sets language, pattern and issuer with a CHF account', s, 200)
			settingsVersion = s.data?.version ?? settingsVersion
			// 0.22.0: the document language is null, de or en when written.
			expect('settings.language other than null, de or en', await ocs(A, 'PUT', '/records/setting/settings', { version: settingsVersion, data: { ...base, language: 'de_CH' } }), 422, 'invalid')
			expect('customer', await ocs(A, 'PUT', `/records/customer/${CF}`, { version: 0, data: { schema: 1, id: CF, name: `Kunde ${RUN}`, street: 'Platz 1', postcode: '4001', city: 'Basel', country: 'CH', contacts: [{ id: 'c1', name: 'Lea Kontakt' }] } }), 200)
			expect('project with budget b1 in CHF, led by zblead', await ocs(A, 'PUT', `/records/project/${PF}`, { version: 0, data: { schema: 1, id: PF, name: `Dokumente ${RUN}`, codes: [CODE], status: 'active', leads: ['zblead'], customer: CF, budgets: [{ id: 'b1', name: 'Budget Dok', currency: 'CHF' }] } }), 200)
			check('zbuser1’s time calendar exists and is named', (await ensureTimeCalendar(U1)) && (await ocs(U1, 'PUT', '/me/time-calendar', { calendar_url: 'zeit-zbuser1' })).status === 200)
			check('zbuser1 books two hours on the project', (await putEvent(U1, EV1, ['DTSTART:20261013T080000Z', 'DTEND:20261013T100000Z', `SUMMARY:Site visit #${CODE}`])) === 201)
			await sleep(500)
			if (!(await book(A)).some((e) => e.event_uid === EV1)) {
				runJob('EntryReader')
			}
		}
		const inBook = (await book(A)).some((e) => e.event_uid === EV1)
		check('the booking is in the book', inBook)

		head('Documents: the team setting')
		{
			expect('documents as text', await ocs(A, 'PUT', '/team/settings', { documents: 'x' }), 422, 'invalid')
			expect('a leading slash', await ocs(A, 'PUT', '/team/settings', { documents: { root: '/abs' } }), 422, 'invalid')
			expect('a “..”', await ocs(A, 'PUT', '/team/settings', { documents: { root: 'a/../b' } }), 422, 'invalid')
			expect('an unknown key', await ocs(A, 'PUT', '/team/settings', { documents: { other: 1 } }), 422, 'invalid')
			expect('a bad address', await ocs(A, 'PUT', '/team/settings', { documents: { chain_mail: 'nope' } }), 422, 'invalid')
			expect('Lead sets it', await ocs(L, 'PUT', '/team/settings', { documents: { root: 'x' } }), 403, 'forbidden')
			const set = await ocs(A, 'PUT', '/team/settings', { documents: { root: `${FOLDER}/`, invoices: 'Gone', per_year: false, chain_mail: 'treuhand@example.test' } })
			expect('Team Admin sets the root (a trailing slash is trimmed, the folders of 0.19.0 ignored)', set, 200)
			check('… answer carries documents { root, chain_mail } only', JSON.stringify(set.data?.documents) === JSON.stringify({ root: FOLDER, chain_mail: 'treuhand@example.test' }), set.data)
			check('… /me and /team say so', (await me(A)).settings?.documents?.root === FOLDER && (await ocs(A, 'GET', '/team')).data?.settings?.documents?.root === FOLDER)
			const partial = await ocs(A, 'PUT', '/team/settings', { documents: { chain_mail: null } })
			expect('a partial change keeps the rest', partial, 200)
			check('… chain_mail null, root kept', partial.data?.documents?.chain_mail === null && partial.data?.documents?.root === FOLDER, partial.data)
			// The admin side: the check endpoint and the setting through PUT /admin/teams.
			const teams = await ocs(ADMIN, 'GET', '/admin/teams')
			const zb = (teams.data || []).find((t) => t.slug === 'zb')
			check('admin lists the setting', zb?.settings?.documents?.root === FOLDER, zb?.settings)
			if (zb) {
				const chk = await ocs(ADMIN, 'POST', `/admin/teams/${zb.id}/documents/check`, { root: FOLDER })
				expect('admin checks the root', chk, 200)
				check('… ok with a message and the path', chk.data?.root?.ok === true && typeof chk.data?.root?.message === 'string' && chk.data?.root?.path === FOLDER, chk.data)
				const dflt = await ocs(ADMIN, 'POST', `/admin/teams/${zb.id}/documents/check`, {})
				check('… without a root: TimeSister/<team>', dflt.data?.root?.path === `TimeSister/${zb.name}`, dflt.data)
				const bad = await ocs(ADMIN, 'POST', `/admin/teams/${zb.id}/documents/check`, { root: '/abs' })
				expect('… a bad path', bad, 422, 'invalid')
				expect('Team Admin calls the admin check', await ocs(A, 'POST', `/admin/teams/${zb.id}/documents/check`, {}), 403)
				const put = await ocs(ADMIN, 'PUT', `/admin/teams/${zb.id}`, { name: zb.name, slug: zb.slug, groups: zb.groups, settings: { documents: { chain_mail: 'a@example.test' } } })
				expect('admin sets chain_mail through PUT /admin/teams', put, 200)
				check('… merged over the stored setting', put.data?.settings?.documents?.chain_mail === 'a@example.test' && put.data?.settings?.documents?.root === FOLDER, put.data?.settings)
				expect('… and back', await ocs(A, 'PUT', '/team/settings', { documents: { chain_mail: null } }), 200)
			}
		}

		head('Documents: the draft; preview and render are gone (0.32.0)')
		const d = await ocs(A, 'PUT', path(K1), { version: 0, data: draft() })
		expect('Team Admin writes a draft', d, 200)
		expect('preview is gone, also for an unknown key', await fetchRaw(A, `${ipath(`inv-${RUN}zz`)}/preview`).then((r) => ({ status: r.status, data: null, text: '' })), 410)
		expect('… for a Member too', await fetchRaw(U1, `${ipath(K1)}/preview`).then((r) => ({ status: r.status, data: null, text: '' })), 410)
		expect('render is gone', await ocs(A, 'POST', `${ipath(K1)}/render`, {}), 410, 'gone')
		expect('document of a draft', await fetchRaw(A, `${ipath(K1)}/document?kind=pdf`).then((r) => ({ status: r.status, data: null, text: '' })), 404)
		expect('chain head by a Lead', await ocs(L, 'GET', '/invoices/chain/head'), 403, 'forbidden')
		{
			const noBudget = await ocs(A, 'PUT', path(K2), { version: 0, data: draft({ budget: 'b9' }) })
			expect('a draft naming a budget not in the project', noBudget, 200)
			expect('… issuing it is 422 (the preview that checked this is gone)', await ocs(A, 'POST', `${ipath(K2)}/issue`, { issued_on: ISSUED_ON }), 422, 'invalid')
		}
		if (!inBook) {
			console.log('    info  the booking is not in the book (no listener, no TS_OCC) – the document checks are skipped')
			return
		}

		head('Documents: issue, the filed PDF, the ledger')
		let number = null
		let seq = null
		let fixed = null
		{
			const r = await festschreiben(A, K1, { issued_on: ISSUED_ON })
			expect('Team Admin reserves', r.reserve, 200)
			expect('… and closes with the app’s PDF', r.close, 200)
			number = r.issue?.number ?? null
			seq = r.issue?.seq ?? null
			check('the reservation: no document_state, fixed without documents, chain_line', r.reserve.data?.issue?.document_state === undefined && Array.isArray(r.fixed?.documents) && r.fixed.documents.length === 0 && typeof r.fixed?.chain_line === 'string', r.reserve.data?.issue)
			const f = await ocs(A, 'GET', `${ipath(K1)}/fixed`)
			fixed = f.data
			check('fixed: issued, documents[pdf] with path <root>/Buchhaltung/<year>/Rechnungen/<date>_<number>_<customer>.pdf and sha256, document_path the same', fixed?.status === 'issued' && fixed?.document_state === undefined && fixed?.documents?.[0]?.kind === 'pdf' && fixed?.documents?.[0]?.path === `${FOLDER}/Buchhaltung/${Y}/Rechnungen/${ISSUED_ON}_${number}_Kunde-${RUN}.pdf` && fixed?.documents?.[0]?.sha256 === sha256(r.pdf) && fixed?.document_path === fixed?.documents?.[0]?.path && fixed?.document_sha256 === sha256(r.pdf), fixed)
			const doc = await fetchRaw(A, `${ipath(K1)}/document?kind=pdf`)
			check('document: application/pdf, attachment <number>.pdf', doc.status === 200 && doc.type.startsWith('application/pdf') && doc.disposition.includes(`${number}.pdf`), [doc.status, doc.type, doc.disposition])
			const sha = sha256(doc.buf)
			check('… the bytes as sent: the SHA-256 is document_sha256, the number in it', sha === fixed?.document_sha256 && doc.buf.equals(r.pdf) && doc.buf.includes(`Invoice ${number}`), sha)
			check('… the chain line of the issue is in it', doc.buf.includes(`Beleg ${number} · Kette ${seq} `))
			expect('document of another kind', await fetchRaw(A, `${ipath(K1)}/document?kind=docx`).then((x) => ({ status: x.status, data: null, text: '' })), 422)
			expect('document?kind=xml before the app sends one (0.32.0)', await fetchRaw(A, `${ipath(K1)}/document?kind=xml`).then((x) => ({ status: x.status, data: null, text: '' })), 404)
			expect('the Lead downloads it', await fetchRaw(L, `${ipath(K1)}/document`).then((x) => ({ status: x.status, data: null, text: '' })), 200)
			expect('a Member does not', await fetchRaw(U1, `${ipath(K1)}/document`).then((x) => ({ status: x.status, data: null, text: '' })), 403)
			// The file in the backup owner's files, through WebDAV as the owner.
			const ownerDav = { user: owner, pass: `Test-2026-${owner}!` }
			const dv = await dav(ownerDav, 'GET', `files/${encodeURIComponent(owner)}/${String(fixed?.document_path || 'none').split('/').map(encodeURIComponent).join('/')}`)
			check('the file lies in the owner’s files (WebDAV)', dv.status === 200 && dv.text.startsWith('%PDF-'), dv.status)
			const lg = await ocs(A, 'GET', `/invoices/ledger?since=${seq - 1}&limit=10`)
			const e = (lg.data?.entries || []).find((x) => x.seq === seq)
			check('ledger: the issued entry carries document {kind: pdf, name, size, sha256}, no path; no separate document entry', e?.action === 'issued' && e?.number === number && (() => { const p = JSON.parse(e?.payload ?? '{}'); return p.key === K1 && p.document?.kind === 'pdf' && p.document?.name === `${ISSUED_ON}_${number}_Kunde-${RUN}.pdf` && p.document?.size === doc.buf.length && p.document?.sha256 === sha && p.document?.path === undefined })() && !(lg.data?.entries || []).some((x) => x.action === 'document' && x.number === number), e)
			const v = await ocs(A, 'GET', '/invoices/chain/verify')
			check('the chain is still valid', v.data?.ok === true, v.data)
			const hd = await ocs(A, 'GET', '/invoices/chain/head')
			expect('chain head', hd, 200)
			check('… seq, hash, at, entries; head = verify head', hd.data?.hash === v.data?.head && hd.data?.seq >= seq && /Z$/.test(hd.data?.at ?? '') && hd.data?.entries >= hd.data?.seq - 0 && hd.data?.entries > 0, hd.data)
			const again = await raw(A, 'PUT', `${ipath(K1)}/document?kind=pdf`, r.pdf, 'application/pdf')
			expect('the same bytes again (the app lost the answer)', again, 200)
			check('… stored false, no second ledger entry', again.data?.stored === false && again.data?.issue?.hash === fixed?.chain?.hash && ((await ocs(A, 'GET', `/invoices/ledger?since=${seq}&limit=50`)).data?.entries || []).filter((x) => x.number === number).length === 0, again.data)
			expect('render is gone for an issued invoice too', await ocs(A, 'POST', `${ipath(K1)}/render`, {}), 410, 'gone')
			expect('preview of an issued invoice is gone', await fetchRaw(A, `${ipath(K1)}/preview`).then((x) => ({ status: x.status, data: null, text: '' })), 410)
		}

		head('Documents: the reversal’s PDF is filed too, chain head file')
		{
			const r = await stornieren(A, K1, { kind: 'reversal', reason: 'Wrong hours', issued_on: ISSUED_ON })
			expect('Team Admin reverses', r.reserve, 200)
			expect('… and closes the reversal with its PDF', r.close, 200)
			const rev = r.key
			if (rev) {
				made.push(rev)
				const f = await ocs(A, 'GET', `${ipath(rev)}/fixed`)
				check('the reversal’s document is filed', f.data?.status === 'issued' && f.data?.documents?.[0]?.path === `${FOLDER}/Buchhaltung/${Y}/Rechnungen/${ISSUED_ON}_${f.data?.number}_Kunde-${RUN}.pdf` && f.data?.document_path === f.data?.documents?.[0]?.path, f.data)
				const doc = await fetchRaw(A, `${ipath(rev)}/document`)
				check('… the bytes as sent, with its own chain line', doc.status === 200 && doc.buf.equals(r.pdf) && doc.buf.includes(`Beleg ${f.data?.number} · Kette ${f.data?.chain?.seq} `), doc.status)
				check('the original is cancelled now', r.original?.data?.status === 'cancelled' && r.original?.data?.reversed_by === rev, r.original?.data)
			}
			if (!process.env.TS_OCC) {
				console.log('    info  TS_OCC not set – the chain head job is skipped')
			} else {
				const ran = runJob('ChainHead')
				check('the ChainHead job runs', ran)
				const day = new Date().toISOString().slice(0, 10)
				const ownerDav = { user: owner, pass: `Test-2026-${owner}!` }
				const dv = await dav(ownerDav, 'GET', `files/${encodeURIComponent(owner)}/${`${FOLDER}/Buchhaltung/${day.slice(0, 4)}/Kette/chain-${day}.txt`.split('/').map(encodeURIComponent).join('/')}`)
				const hd = (await ocs(A, 'GET', '/invoices/chain/head')).data
				check('chain-<day>.txt lies in Buchhaltung/<year>/Kette with the head', dv.status === 200 && dv.text.includes(`team: ${TEAM.name}`) && dv.text.includes(`head hash: ${hd?.hash}`) && dv.text.includes(`head seq: ${hd?.seq}`), [dv.status, dv.text.slice(0, 200)])
				const expiry = runJob('IssueExpiry')
				check('the IssueExpiry job runs (replaces DocumentRetry, 0.32.0)', expiry)
			}
		}
	} finally {
		head('Documents: cleaning up')
		await ocs(A, 'PUT', '/team/settings', { documents: { root: docsBefore.root ?? null, chain_mail: docsBefore.chain_mail ?? null } })
		if (tenant !== null) {
			const keys = made.map((k) => `'${k}'`).join(', ')
			sql(`DELETE FROM oc_ts_inv_hours WHERE tenant_id = ${tenant} AND invoice_id IN (SELECT id FROM oc_ts_inv_invoices WHERE tenant_id = ${tenant} AND rkey IN (${keys}))`)
			sql(`DELETE FROM oc_ts_inv_invoices WHERE tenant_id = ${tenant} AND rkey IN (${keys})`)
			sql(`DELETE FROM oc_ts_counters WHERE tenant_id = ${tenant} AND name = 'invoice:${Y}'`)
		}
		for (const k of made) {
			const cur = await ocs(A, 'GET', path(k))
			if (cur.status === 200) {
				await ocs(A, 'DELETE', `${path(k)}?version=${cur.data.version}`)
			}
		}
		const mark = await ocs(A, 'GET', `/records/billing/${billingKey('zbuser1', EV1)}`)
		if (mark.status === 200) {
			await ocs(A, 'DELETE', `/records/billing/${billingKey('zbuser1', EV1)}?version=${mark.data.version}`)
		}
		await deleteEvent(U1, EV1)
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
		// The test folder in the owner's files goes (WebDAV, as the owner); the ledger stays.
		if (owner) {
			await dav({ user: owner, pass: `Test-2026-${owner}!` }, 'DELETE', `files/${encodeURIComponent(owner)}/${FOLDER.split('/').map(encodeURIComponent).join('/')}`)
		}
		check('zb left as found', isObj(await me(A)))
	}
}
