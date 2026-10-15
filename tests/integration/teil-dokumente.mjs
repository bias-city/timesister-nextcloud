// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Invoice documents (0.19.0): the team setting `documents` (folders in the
// backup owner's files, checked on writing), the admin's check endpoint,
// the watermarked preview of a draft, the stored PDF after issuing (file
// in the folder, ledger entry `document` with the file's hash, chain still
// valid), rendering again, the chain head and the daily chain head file.
// Needs the booking in the book like teil-faktura.mjs; without it the
// issuing checks are skipped. Leaves pb as it found it (settings record,
// team setting, project, customer, event, marks); the files in the owner's
// folder and the ledger entries stay (append-only, test year far away).
import { ensureTimeCalendar, dav, deleteEvent, ocs, putEvent, runJob, sql, ADMIN } from './lib.mjs'
import { A, L, RUN, U1, check, expect, head, isObj, me, sha256 } from './harness.mjs'

const path = (key) => `/records/invoice/${encodeURIComponent(key)}`
const ipath = (key) => `/invoices/${encodeURIComponent(key)}`
const billingKey = (person, uid) => `${person}+${sha256(uid).slice(0, 32)}`
const HEX64 = /^[0-9a-f]{64}$/
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
	const tenant = sql("SELECT id FROM oc_ts_tenants WHERE slug = 'pb'")
	const settings = await ocs(A, 'GET', '/records/setting/settings')
	const base = settings.status === 200 ? settings.data.data : {}
	let settingsVersion = settings.status === 200 ? settings.data.version : 0
	const teamBefore = (await ocs(A, 'GET', '/team')).data
	const docsBefore = teamBefore?.settings?.documents || { invoices: null, quotes: null, per_year: true, chain_mail: null }
	let owner = teamBefore?.backup_owner || null
	const draft = (more = {}) => ({
		schema: 1, project: PF, budget: 'b1', from: '2026-10-01', to: '2026-10-31', status: 'draft', number: null,
		bookings: [{ person: 'pbuser1', uid: EV1, start: '2026-10-13T08:00:00', hours: 2, rate: 150, code: CODE }],
		positions: [{ kind: 'hours', text: 'Site visit', person: 'pbuser1', work_package: 0, hours: 2, rate: 150, amount: 300 }, { kind: 'flat', text: 'Travel', amount: 100 }],
		greeting: 'Dear team', body: 'Payable within 30 days.', footer: 'Denkstatt · Basel', vat: 8.1, contact: 'c1',
		note: null, created_by: 'pbadmin', created_on: '2026-10-15', issued_on: null, paid_on: null, ...more,
	})
	try {
		head('Documents: setup')
		{
			const cap = await ocs(A, 'GET', '/ocs/v2.php/cloud/capabilities')
			check('capability invoice_documents: 1', cap.data?.capabilities?.timesister?.invoice_documents === 1, cap.data?.capabilities?.timesister)
			check('/me.settings.documents has the four keys', JSON.stringify(Object.keys((await me(A)).settings?.documents || {}).sort()) === '["chain_mail","invoices","per_year","quotes"]', (await me(A)).settings)
			if (!owner) {
				// The backup owner is the first Team Admin by default; set pbadmin explicitly through the admin page's endpoint.
				const teams = await ocs(ADMIN, 'GET', '/admin/teams')
				const pb = (teams.data || []).find((t) => t.slug === 'pb')
				if (pb) {
					const r = await ocs(ADMIN, 'PUT', `/admin/teams/${pb.id}`, { name: pb.name, slug: pb.slug, groups: pb.groups, backup_owner: 'pbadmin' })
					owner = r.data?.backup_owner || null
				}
			}
			check('pb has a backup owner', typeof owner === 'string' && owner !== '', owner)
			const s = await ocs(A, 'PUT', '/records/setting/settings', { version: settingsVersion, data: { ...base, language: 'de',
				invoice_number: { pattern: `RD-${RUN}-{no:4}`, per_year: true, start: 1 },
				issuer: { name: 'Denkstatt sàrl', street: 'Dornacherstrasse 192', postcode: '4053', city: 'Basel', country: 'CH', vat_id: 'CHE-123.456.789 MWST', email: 'mail@example.test', qr: true, accounts: [{ currency: 'CHF', iban: 'CH9300762011623852957', bic: '' }] } } })
			expect('Team Admin sets language, pattern and issuer with a CHF account', s, 200)
			settingsVersion = s.data?.version ?? settingsVersion
			expect('customer', await ocs(A, 'PUT', `/records/customer/${CF}`, { version: 0, data: { schema: 1, id: CF, name: `Kunde ${RUN}`, street: 'Platz 1', postcode: '4001', city: 'Basel', country: 'CH', contacts: [{ id: 'c1', name: 'Lea Kontakt' }] } }), 200)
			expect('project with budget b1 in CHF, led by pblead', await ocs(A, 'PUT', `/records/project/${PF}`, { version: 0, data: { schema: 1, id: PF, name: `Dokumente ${RUN}`, codes: [CODE], status: 'active', leads: ['pblead'], customer: CF, budgets: [{ id: 'b1', name: 'Budget Dok', currency: 'CHF' }] } }), 200)
			check('pbuser1’s time calendar exists and is named', (await ensureTimeCalendar(U1)) && (await ocs(U1, 'PUT', '/me/time-calendar', { calendar_url: 'zeit-pbuser1' })).status === 200)
			check('pbuser1 books two hours on the project', (await putEvent(U1, EV1, ['DTSTART:20261013T080000Z', 'DTEND:20261013T100000Z', `SUMMARY:Site visit #${CODE}`])) === 201)
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
			expect('a leading slash', await ocs(A, 'PUT', '/team/settings', { documents: { invoices: '/abs' } }), 422, 'invalid')
			expect('a “..”', await ocs(A, 'PUT', '/team/settings', { documents: { quotes: 'a/../b' } }), 422, 'invalid')
			expect('an unknown key', await ocs(A, 'PUT', '/team/settings', { documents: { other: 1 } }), 422, 'invalid')
			expect('a bad address', await ocs(A, 'PUT', '/team/settings', { documents: { chain_mail: 'nope' } }), 422, 'invalid')
			expect('Lead sets it', await ocs(L, 'PUT', '/team/settings', { documents: { per_year: false } }), 403, 'forbidden')
			const set = await ocs(A, 'PUT', '/team/settings', { documents: { invoices: `${FOLDER}/Rechnungen/`, quotes: `${FOLDER}/Offerten`, per_year: true, chain_mail: 'treuhand@example.test' } })
			expect('Team Admin sets the folders (a trailing slash is trimmed)', set, 200)
			check('… answer carries documents', set.data?.documents?.invoices === `${FOLDER}/Rechnungen` && set.data?.documents?.quotes === `${FOLDER}/Offerten` && set.data?.documents?.per_year === true && set.data?.documents?.chain_mail === 'treuhand@example.test', set.data)
			check('… /me and /team say so', (await me(A)).settings?.documents?.invoices === `${FOLDER}/Rechnungen` && (await ocs(A, 'GET', '/team')).data?.settings?.documents?.quotes === `${FOLDER}/Offerten`)
			const partial = await ocs(A, 'PUT', '/team/settings', { documents: { chain_mail: null } })
			expect('a partial change keeps the rest', partial, 200)
			check('… chain_mail null, folders kept', partial.data?.documents?.chain_mail === null && partial.data?.documents?.invoices === `${FOLDER}/Rechnungen`, partial.data)
			// The admin side: the check endpoint and the setting through PUT /admin/teams.
			const teams = await ocs(ADMIN, 'GET', '/admin/teams')
			const pb = (teams.data || []).find((t) => t.slug === 'pb')
			check('admin lists the setting', pb?.settings?.documents?.invoices === `${FOLDER}/Rechnungen`, pb?.settings)
			if (pb) {
				const chk = await ocs(ADMIN, 'POST', `/admin/teams/${pb.id}/documents/check`, { invoices: `${FOLDER}/Rechnungen`, quotes: null })
				expect('admin checks the folders', chk, 200)
				check('… both ok with a message (the invoices folder exists or will be created)', chk.data?.invoices?.ok === true && typeof chk.data?.invoices?.message === 'string' && chk.data?.quotes?.ok === true, chk.data)
				const bad = await ocs(ADMIN, 'POST', `/admin/teams/${pb.id}/documents/check`, { invoices: '/abs' })
				expect('… a bad path', bad, 422, 'invalid')
				expect('Team Admin calls the admin check', await ocs(A, 'POST', `/admin/teams/${pb.id}/documents/check`, {}), 403)
				const put = await ocs(ADMIN, 'PUT', `/admin/teams/${pb.id}`, { name: pb.name, slug: pb.slug, groups: pb.groups, settings: { documents: { per_year: false } } })
				expect('admin sets per_year through PUT /admin/teams', put, 200)
				check('… merged over the stored setting', put.data?.settings?.documents?.per_year === false && put.data?.settings?.documents?.invoices === `${FOLDER}/Rechnungen`, put.data?.settings)
				expect('… and back', await ocs(A, 'PUT', '/team/settings', { documents: { per_year: true } }), 200)
			}
		}

		head('Documents: the draft and its preview')
		const d = await ocs(A, 'PUT', path(K1), { version: 0, data: draft() })
		expect('Team Admin writes a draft', d, 200)
		expect('preview of an unknown key', await fetchRaw(A, `${ipath(`inv-${RUN}zz`)}/preview`).then((r) => ({ status: r.status, data: null, text: '' })), 404)
		expect('Member previews', await fetchRaw(U1, `${ipath(K1)}/preview`).then((r) => ({ status: r.status, data: null, text: '' })), 403)
		expect('document of a draft', await fetchRaw(A, `${ipath(K1)}/document?kind=pdf`).then((r) => ({ status: r.status, data: null, text: '' })), 404)
		expect('chain head by a Lead', await ocs(L, 'GET', '/invoices/chain/head'), 403, 'forbidden')
		{
			const noBudget = await ocs(A, 'PUT', path(K2), { version: 0, data: draft({ budget: 'b9' }) })
			expect('a draft naming a budget not in the project', noBudget, 200)
			const p = await fetchRaw(A, `${ipath(K2)}/preview`)
			check('… its preview is 422 like issue', p.status === 422, p.status)
		}
		if (!inBook) {
			console.log('    info  the booking is not in the book (no listener, no TS_OCC) – the document checks are skipped')
			return
		}
		{
			const p = await fetchRaw(A, `${ipath(K1)}/preview`)
			check('Team Admin gets the preview as application/pdf, inline', p.status === 200 && p.type.startsWith('application/pdf') && /inline/.test(p.disposition), [p.status, p.type, p.disposition])
			check('… a PDF with the watermark VORABZUG (team language German) in it, not yet issued', p.buf.subarray(0, 5).toString() === '%PDF-' && p.buf.includes('VORABZUG') && p.buf.includes('(noch nicht gestellt)'), p.buf.length)
			const l = await fetchRaw(L, `${ipath(K1)}/preview`)
			check('the project’s Lead gets it too', l.status === 200 && l.buf.subarray(0, 5).toString() === '%PDF-', l.status)
			check('the preview is not in the ledger', !((await ocs(A, 'GET', '/invoices/ledger?limit=2000')).data?.entries || []).some((e) => e.action === 'document' && e.number === ''))
		}

		head('Documents: issue, the stored PDF, the ledger')
		let number = null
		let seq = null
		let fixed = null
		{
			const r = await ocs(A, 'POST', `${ipath(K1)}/issue`, { issued_on: ISSUED_ON })
			expect('Team Admin issues the draft', r, 200)
			number = r.data?.issue?.number ?? null
			seq = r.data?.issue?.seq ?? null
			check('issue: document_state pending in the answer (rendered after the transaction)', ['pending', 'done'].includes(r.data?.issue?.document_state), r.data?.issue)
			expect('preview of an issued invoice', await fetchRaw(A, `${ipath(K1)}/preview`).then((x) => ({ status: x.status, data: null, text: '' })), 409)
			let f = await ocs(A, 'GET', `${ipath(K1)}/fixed`)
			if (f.data?.document_state !== 'done') {
				const again = await ocs(A, 'POST', `${ipath(K1)}/render`, {})
				expect('render again', again, 200)
				f = await ocs(A, 'GET', `${ipath(K1)}/fixed`)
			}
			fixed = f.data
			check('fixed: document_state done, no error, path <folder>/<year>/<number>.pdf, sha256', fixed?.document_state === 'done' && fixed?.document_error === null && fixed?.document_path === `${FOLDER}/Rechnungen/${Y}/${number}.pdf` && HEX64.test(fixed?.document_sha256 ?? ''), fixed)
			const doc = await fetchRaw(A, `${ipath(K1)}/document?kind=pdf`)
			check('document: application/pdf, attachment <number>.pdf', doc.status === 200 && doc.type.startsWith('application/pdf') && doc.disposition.includes(`${number}.pdf`), [doc.status, doc.type, doc.disposition])
			const sha = sha256(doc.buf)
			check('… its SHA-256 is document_sha256, no watermark, the number in it', sha === fixed?.document_sha256 && !doc.buf.includes('VORABZUG') && doc.buf.includes(`Rechnung ${number}`), sha)
			check('… the chain line of the issue is in it', doc.buf.includes(`Beleg ${number} · Kette ${seq} `))
			expect('document of another kind', await fetchRaw(A, `${ipath(K1)}/document?kind=xml`).then((x) => ({ status: x.status, data: null, text: '' })), 422)
			expect('the Lead downloads it', await fetchRaw(L, `${ipath(K1)}/document`).then((x) => ({ status: x.status, data: null, text: '' })), 200)
			expect('a Member does not', await fetchRaw(U1, `${ipath(K1)}/document`).then((x) => ({ status: x.status, data: null, text: '' })), 403)
			// The file in the backup owner's files, through WebDAV as the owner.
			const ownerDav = { user: owner, pass: `Test-2026-${owner}!` }
			const dv = await dav(ownerDav, 'GET', `files/${encodeURIComponent(owner)}/${String(fixed?.document_path || 'none').split('/').map(encodeURIComponent).join('/')}`)
			check('the file lies in the owner’s files (WebDAV)', dv.status === 200 && dv.text.startsWith('%PDF-'), dv.status)
			const lg = await ocs(A, 'GET', `/invoices/ledger?since=${seq}&limit=10`)
			const e = (lg.data?.entries || []).find((x) => x.action === 'document' && x.number === number)
			check('ledger: action document with payload {key, number, kind, path, size, sha256}', e !== undefined && typeof e?.payload === 'string' && (() => { const p = JSON.parse(e.payload); return p.key === K1 && p.kind === 'pdf' && p.path === fixed.document_path && p.size === doc.buf.length && p.sha256 === sha })(), e)
			const v = await ocs(A, 'GET', '/invoices/chain/verify')
			check('the chain is still valid', v.data?.ok === true, v.data)
			const hd = await ocs(A, 'GET', '/invoices/chain/head')
			expect('chain head', hd, 200)
			check('… seq, hash, at, entries; head = verify head', hd.data?.hash === v.data?.head && hd.data?.seq >= seq && /Z$/.test(hd.data?.at ?? '') && hd.data?.entries >= hd.data?.seq - 0 && hd.data?.entries > 0, hd.data)
			const again = await ocs(A, 'POST', `${ipath(K1)}/render`, {})
			expect('render again on a stored document', again, 200)
			check('… stays done, no second ledger entry', again.data?.document_state === 'done' && ((await ocs(A, 'GET', `/invoices/ledger?since=${seq}&limit=50`)).data?.entries || []).filter((x) => x.action === 'document' && x.number === number).length === 1, again.data)
			expect('render by a Lead', await ocs(L, 'POST', `${ipath(K1)}/render`, {}), 403, 'forbidden')
			expect('render of a draft', await ocs(A, 'POST', `${ipath(K2)}/render`, {}), 404, 'not_found')
		}

		head('Documents: reversal renders too, chain head file')
		{
			const r = await ocs(A, 'POST', `${ipath(K1)}/reverse`, { kind: 'reversal', reason: 'Wrong hours', issued_on: ISSUED_ON })
			expect('Team Admin reverses', r, 200)
			const rev = r.data?.record?.key
			if (rev) {
				made.push(rev)
				let f = await ocs(A, 'GET', `${ipath(rev)}/fixed`)
				if (f.data?.document_state !== 'done') {
					await ocs(A, 'POST', `${ipath(rev)}/render`, {})
					f = await ocs(A, 'GET', `${ipath(rev)}/fixed`)
				}
				check('the reversal’s document is stored', f.data?.document_state === 'done' && f.data?.document_path === `${FOLDER}/Rechnungen/${Y}/${f.data?.number}.pdf`, f.data)
				const doc = await fetchRaw(A, `${ipath(rev)}/document`)
				check('… titled “Storno zu <original>”', doc.status === 200 && doc.buf.includes(`Storno zu ${number}`), doc.status)
			}
			if (!process.env.TS_OCC) {
				console.log('    info  TS_OCC not set – the chain head job is skipped')
			} else {
				const ran = runJob('ChainHead')
				check('the ChainHead job runs', ran)
				const day = new Date().toISOString().slice(0, 10)
				const ownerDav = { user: owner, pass: `Test-2026-${owner}!` }
				const dv = await dav(ownerDav, 'GET', `files/${encodeURIComponent(owner)}/${`${FOLDER}/Rechnungen/chain-${day}.txt`.split('/').map(encodeURIComponent).join('/')}`)
				const hd = (await ocs(A, 'GET', '/invoices/chain/head')).data
				check('chain-<day>.txt lies in the invoices folder with the head', dv.status === 200 && dv.text.includes('team: Planungsbüro') && dv.text.includes(`head hash: ${hd?.hash}`) && dv.text.includes(`head seq: ${hd?.seq}`), [dv.status, dv.text.slice(0, 200)])
				const retry = runJob('DocumentRetry')
				check('the DocumentRetry job runs', retry)
			}
		}
	} finally {
		head('Documents: cleaning up')
		await ocs(A, 'PUT', '/team/settings', { documents: { invoices: docsBefore.invoices, quotes: docsBefore.quotes, per_year: docsBefore.per_year !== false, chain_mail: docsBefore.chain_mail } })
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
		const mark = await ocs(A, 'GET', `/records/billing/${billingKey('pbuser1', EV1)}`)
		if (mark.status === 200) {
			await ocs(A, 'DELETE', `/records/billing/${billingKey('pbuser1', EV1)}?version=${mark.data.version}`)
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
		check('pb left as found', isObj(await me(A)))
	}
}
