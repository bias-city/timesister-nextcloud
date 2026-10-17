// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Invoicing mode and hand-over (0.24.0): the team setting `invoicing_mode`
// (checked, stored, shown in /me and /team), issuing refused in the mode
// external, the hand-over of a draft with a booking and a receipt in one
// transaction (billing mark, billed receipt, record with status external,
// ledger external_issued), a second invoice with the same booking refused,
// client writes and reversal refused, the revocation freeing everything
// again, and the setting back. Needs the booking in the book (listener or
// TS_OCC); without it the hand-over checks are skipped.
// Leaves pb as it found it; approved receipts, the invoice records and
// counters go only with TS_SQL, the ledger stays (append-only).
import { ADMIN, ensureTimeCalendar, deleteEvent, ocs, putEvent, runJob, sql } from './lib.mjs'
import { A, L, RUN, U1, check, expect, head, isObj, me, sha256 } from './harness.mjs'

const rec = (kind, key) => `/records/${kind}/${encodeURIComponent(key)}`
const ipath = (key) => `/invoices/${encodeURIComponent(key)}`
const billingKey = (person, uid) => `${person}+${sha256(uid).slice(0, 32)}`
const HEX64 = /^[0-9a-f]{64}$/
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

/** Is the event in the book visible to `who`? */
async function inBook(who, uid) {
	let since = 0
	for (let i = 0; i < 20; i++) {
		const r = await ocs(who, 'GET', `/entries?since=${since}&limit=2000`)
		if (r.status !== 200) {
			return false
		}
		if (r.data.entries.some((e) => e.event_uid === uid)) {
			return true
		}
		if (!r.data.more || r.data.entries.length === 0) {
			return false
		}
		since = r.data.entries[r.data.entries.length - 1].revision
	}
	return false
}

export async function uebergabe() {
	const PU = `PU-${RUN}`
	const CU = `CU-${RUN}`
	const CODE = `U${RUN}`.toUpperCase()
	const EV1 = `tu-${RUN}-1@example.test`
	const RU = `rc-${RUN}-u1`
	const K1 = `inv-${RUN}u1`
	const K2 = `inv-${RUN}u2`
	const NUMBER = `BX-${RUN}-1`
	const Y = 3000 + (parseInt(RUN.slice(-4), 36) % 5000) + 17
	const ISSUED_ON = `${Y}-03-20`
	const tenant = sql("SELECT id FROM oc_ts_tenants WHERE slug = 'pb'")
	const settings = await ocs(A, 'GET', rec('setting', 'settings'))
	const base = settings.status === 200 ? settings.data.data : {}
	const teamBefore = (await ocs(A, 'GET', '/team')).data
	const modeBefore = teamBefore?.settings?.invoicing_mode ?? null
	const invoicingBefore = teamBefore?.settings?.invoicing ?? true
	const get = async (kind, key) => ocs(A, 'GET', rec(kind, key))
	const draft = (more = {}) => ({
		schema: 1, project: PU, budget: 'b1', from: `${Y}-03-01`, to: `${Y}-03-31`, status: 'draft', number: null,
		bookings: [{ person: 'pbuser1', uid: EV1, start: `${Y}-03-12T08:00:00`, hours: 1, rate: 150, code: CODE }],
		positions: [{ kind: 'hours', text: 'Site visit', person: 'pbuser1', hours: 1, rate: 150, amount: 150 }],
		note: null, created_by: 'pbadmin', created_on: `${Y}-03-20`, issued_on: null, paid_on: null, ...more,
	})
	try {
		head('Hand-over: setup')
		{
			const cap = (await ocs(A, 'GET', '/ocs/v2.php/cloud/capabilities')).data?.capabilities?.timesister
			check('capability invoice_handover: 1', cap?.invoice_handover === 1, cap)
			check('/team.settings.invoicing_mode is there (null by default)', teamBefore?.settings && 'invoicing_mode' in teamBefore.settings, teamBefore?.settings)
			expect('invoicing on (issue answers, not 404)', await ocs(A, 'PUT', '/team/settings', { invoicing: true }), 200)
			const s = await ocs(A, 'PUT', rec('setting', 'settings'), { version: settings.status === 200 ? settings.data.version : 0, data: { ...base, modules: { ...(base.modules || {}), costs: true } } })
			expect('settings: costs on', s, 200)
			expect('customer', await ocs(A, 'PUT', rec('customer', CU), { version: 0, data: { schema: 1, id: CU, name: `Kunde Übergabe ${RUN}`, street: 'Platz 1', postcode: '4001', city: 'Basel', country: 'CH' } }), 200)
			expect('project with budget b1 and a cost position, led by pblead', await ocs(A, 'PUT', rec('project', PU), { version: 0, data: { schema: 1, id: PU, name: `Übergabe ${RUN}`, codes: [CODE], status: 'active', leads: ['pblead'], customer: CU,
				budgets: [{ id: 'b1', name: 'Phase 1', currency: 'CHF', costs: [{ no: 1, name: 'Spesen', kind: 'expense', rule: 'receipts', cap: 1500, mode: 'rebill' }] }] } }), 200)
			const r = await ocs(A, 'PUT', rec('receipt', RU), { version: 0, data: { schema: 1, kind: 'trust', date: `${Y}-03-10`, supplier: 'Hotel', text: 'Night', net: 100, vat_rate: null, vat: 0, gross: 100,
				project: PU, budget: 'b1', cost: 1, paid_by: 'company', person: null, reimburse: false, note: null } })
			check('a trust receipt on the budget, approved by the Team Admin’s write', r.status === 200 && r.data?.data?.status === 'approved', r.data)
			check('pbuser1’s time calendar exists and is named', (await ensureTimeCalendar(U1)) && (await ocs(U1, 'PUT', '/me/time-calendar', { calendar_url: 'zeit-pbuser1' })).status === 200)
			check('pbuser1 books an hour on the project', (await putEvent(U1, EV1, [`DTSTART:${Y}0312T080000Z`, `DTEND:${Y}0312T090000Z`, `SUMMARY:Site visit #${CODE}`])) === 201)
			await sleep(500)
			if (!(await inBook(A, EV1))) {
				runJob('EntryReader')
			}
			expect('draft K1: the booking and the receipt', await ocs(A, 'PUT', rec('invoice', K1), { version: 0, data: draft({ positions: [...draft().positions, { kind: 'costs', text: 'Hotel', amount: 100, cost: 1, receipts: [RU] }] }) }), 200)
			expect('draft K2: the same booking', await ocs(A, 'PUT', rec('invoice', K2), { version: 0, data: draft() }), 200)
		}

		head('Hand-over: the setting')
		{
			const put = (mode) => ocs(A, 'PUT', '/team/settings', { invoicing_mode: mode })
			const bad = async (name, mode, message) => {
				const r = await put(mode)
				check(`${name} → 422 “${message.slice(0, 60)}…”`, r.status === 422 && r.data?.error === 'invalid' && r.data?.message === message, [r.status, r.data])
			}
			await bad('external without system', { mode: 'external' }, '“invoicing_mode.system” is required for the mode “external”: name the accounting system that issues the invoices.')
			await bad('external with a copy', { mode: 'external', system: 'bexio', copy: 'auto' }, '“invoicing_mode.copy” must be “off” for the mode “external”: the accounting system issues the invoices itself.')
			await bad('a copy without system', { mode: 'timesister', copy: 'manual' }, '“invoicing_mode.copy” needs “invoicing_mode.system”: name the accounting system that gets the copies.')
			await bad('an unknown mode', { mode: 'mac' }, '“invoicing_mode.mode” must be “timesister” or “external”.')
			await bad('a system name in capitals', { mode: 'timesister', system: 'Bexio', copy: 'auto' }, '“invoicing_mode.system” must be a name of 1 to 64 characters: lowercase letters, digits, “.”, “_” or “-”, starting with a letter or digit (for example “bexio”).')
			await bad('an unknown key', { mode: 'timesister', extra: 1 }, 'Unknown team setting “invoicing_mode.extra”.')
			expect('a Lead sets it', await ocs(L, 'PUT', '/team/settings', { invoicing_mode: null }), 403, 'forbidden')
			// The Nextcloud admin through /admin/teams, as the other settings.
			const pb = ((await ocs(ADMIN, 'GET', '/admin/teams')).data || []).find((t) => t.slug === 'pb')
			if (pb) {
				const team = { name: pb.name, slug: pb.slug, groups: pb.groups }
				expect('/admin/teams: external without system', await ocs(ADMIN, 'PUT', `/admin/teams/${pb.id}`, { ...team, settings: { invoicing_mode: { mode: 'external' } } }), 422, 'invalid')
				const a = await ocs(ADMIN, 'PUT', `/admin/teams/${pb.id}`, { ...team, settings: { invoicing_mode: { mode: 'timesister', system: 'sevdesk', copy: 'manual' } } })
				check('/admin/teams: case 2 with sevdesk, shown in the answer', a.status === 200 && JSON.stringify(a.data?.settings?.invoicing_mode) === '{"mode":"timesister","system":"sevdesk","copy":"manual"}', [a.status, a.text.slice(0, 300)])
			} else {
				check('/admin/teams lists pb', false)
			}
			const h0 = await ocs(A, 'POST', `${ipath(K1)}/handover`, { system: 'bexio', id: '1', number: '1' })
			check('hand-over without the mode external → 409', h0.status === 409 && h0.data?.message === 'Handing over needs the team setting invoicing_mode external.', [h0.status, h0.data])
			const copy = await put({ mode: 'timesister', system: 'bexio', copy: 'auto' })
			check('case 2: TimeSister with a copy to bexio', copy.status === 200 && JSON.stringify(copy.data?.invoicing_mode) === '{"mode":"timesister","system":"bexio","copy":"auto"}', copy.data)
			check('… in /me', JSON.stringify((await me(A)).settings?.invoicing_mode) === '{"mode":"timesister","system":"bexio","copy":"auto"}')
			const ext = await put({ mode: 'external', system: 'bexio' })
			check('case 3: handed over to bexio, copy stored as off', ext.status === 200 && JSON.stringify(ext.data?.invoicing_mode) === '{"mode":"external","system":"bexio","copy":"off"}', ext.data)
			check('… in /team', JSON.stringify((await ocs(A, 'GET', '/team')).data?.settings?.invoicing_mode) === '{"mode":"external","system":"bexio","copy":"off"}')
			const iss = await ocs(A, 'POST', `${ipath(K1)}/issue`, { issued_on: ISSUED_ON })
			check('issue in the mode external → 409', iss.status === 409 && iss.data?.message === 'This team hands its invoices over to bexio; TimeSister does not issue them.', [iss.status, iss.data])
		}

		if (!(await inBook(A, EV1))) {
			console.log('    info  the booking is not in the book (no listener, no TS_OCC) – the hand-over checks are skipped')
			return
		}

		head('Hand-over: handover')
		let seq = null
		{
			const body = { system: 'bexio', id: 4711, number: NUMBER, url: 'https://office.bexio.com/index.php/kb_invoice/show/id/4711', issued_on: ISSUED_ON }
			expect('a Lead hands over', await ocs(L, 'POST', `${ipath(K1)}/handover`, body), 403, 'forbidden')
			expect('a member hands over', await ocs(U1, 'POST', `${ipath(K1)}/handover`, body), 403, 'forbidden')
			const other = await ocs(A, 'POST', `${ipath(K1)}/handover`, { ...body, system: 'sevdesk' })
			check('another system → 409', other.status === 409 && other.data?.message === 'This team hands its invoices over to bexio, not to sevdesk.', [other.status, other.data])
			const noNumber = await ocs(A, 'POST', `${ipath(K1)}/handover`, { ...body, number: undefined })
			check('without the number → 422', noNumber.status === 422 && noNumber.data?.message === '“number” must be text of 1 to 64 characters.', [noNumber.status, noNumber.data])
			expect('an http url', await ocs(A, 'POST', `${ipath(K1)}/handover`, { ...body, url: 'http://example.org' }), 422, 'invalid')
			expect('an unknown invoice', await ocs(A, 'POST', `${ipath(`inv-${RUN}zz`)}/handover`, body), 404, 'not_found')
			const k1 = await get('invoice', K1)
			const self = await ocs(A, 'PUT', rec('invoice', K1), { version: k1.data?.version, data: { ...k1.data?.data, status: 'external' } })
			check('a client sets the status external → 422', self.status === 422 && self.data?.message === 'Only the server sets the status “external”, when an invoice is handed over.', [self.status, self.data])
			const rev0 = (await me(A)).revision ?? 0
			const r = await ocs(A, 'POST', `${ipath(K1)}/handover`, body)
			expect('Team Admin hands K1 over', r, 200)
			const d = r.data?.record?.data
			seq = r.data?.ledger?.seq ?? null
			check('record: status external, no number, issued_on, version 2', r.data?.record?.kind === 'invoice' && d?.status === 'external' && d?.number === null && d?.issued_on === ISSUED_ON && r.data?.record?.version === k1.data?.version + 1, r.data?.record)
			check('record: external { system, id as text, number, url, at, by }', d?.external?.system === 'bexio' && d?.external?.id === '4711' && d?.external?.number === NUMBER && d?.external?.url === body.url
				&& /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(d?.external?.at ?? '') && d?.external?.by === 'pbadmin', d?.external)
			check('ledger: seq, prev_hash, hash', Number.isInteger(seq) && HEX64.test(r.data?.ledger?.hash ?? '') && typeof r.data?.ledger?.prev_hash === 'string', r.data?.ledger)
			check('billed: one booking, one receipt', r.data?.billed?.bookings === 1 && r.data?.billed?.receipts === 1, r.data?.billed)
			const delta = ((await ocs(A, 'GET', `/records?since=${rev0}`)).data?.records || []).find((x) => x.kind === 'invoice' && x.key === K1)
			check('the delta since before delivers K1 as external', r.data?.record?.revision > rev0 && delta?.data?.status === 'external', [rev0, r.data?.record?.revision, delta?.data?.status])
			const mark = await get('billing', billingKey('pbuser1', EV1))
			check('the billing mark: document = bexio’s number, source bexio, external_id, by, billed_on', mark.status === 200 && mark.data?.data?.document === NUMBER && mark.data?.data?.source === 'bexio'
				&& mark.data?.data?.external_id === '4711' && mark.data?.data?.by === 'pbadmin' && mark.data?.data?.billed_on === ISSUED_ON, mark.data?.data)
			const rc = await get('receipt', RU)
			check('the receipt is billed by K1', rc.data?.data?.status === 'billed' && rc.data?.data?.invoice === K1, rc.data?.data)
			const led = await ocs(A, 'GET', `/invoices/ledger?since=${seq - 1}&limit=5`)
			const e = led.data?.entries?.find((x) => x.seq === seq)
			const p = JSON.parse(e?.payload || '{}')
			check('ledger entry external_issued with bexio’s number and the payload', e?.action === 'external_issued' && e?.number === NUMBER && p.key === K1 && p.system === 'bexio' && p.id === '4711'
				&& p.customer_key === CU && p.currency === 'CHF' && p.budget === 'b1' && p.project === PU && p.bookings?.[0]?.event_uid === EV1 && p.bookings?.[0]?.uid === 'pbuser1' && JSON.stringify(p.receipts) === JSON.stringify([RU]), e)
			check('… the payload hash is the SHA-256 of the canonical payload', e?.payload_hash === sha256(e?.payload ?? ''), e)
			const v = await ocs(A, 'GET', '/invoices/chain/verify')
			check('the chain verifies', v.status === 200 && v.data?.ok === true, v.data)
			check('no frozen row: fixed → 404', (await ocs(A, 'GET', `${ipath(K1)}/fixed`)).status === 404)
		}

		head('Hand-over: refusals while handed over')
		{
			const again = await ocs(A, 'POST', `${ipath(K1)}/handover`, { system: 'bexio', id: '1', number: 'X' })
			check('handing K1 over again → 409', again.status === 409 && again.data?.message === 'This invoice was handed over to bexio; revoke the hand-over first.', [again.status, again.data])
			const k2 = await ocs(A, 'POST', `${ipath(K2)}/handover`, { system: 'bexio', id: '4712', number: `${NUMBER}b` })
			check('K2 with the same booking → 409 naming bexio’s number', k2.status === 409 && k2.data?.message === `Booking 1 is billed by invoice ${NUMBER}.`, [k2.status, k2.data])
			const k1 = await get('invoice', K1)
			const w = await ocs(A, 'PUT', rec('invoice', K1), { version: k1.data?.version, data: { ...k1.data?.data, note: 'changed' } })
			check('a client writes K1 → 409', w.status === 409 && w.data?.message === 'This invoice was handed over to bexio; revoke the hand-over first.', [w.status, w.data])
			expect('a client deletes K1', await ocs(A, 'DELETE', `${rec('invoice', K1)}?version=${k1.data?.version}`), 409, 'conflict')
			const rv = await ocs(A, 'POST', `${ipath(K1)}/reverse`, { kind: 'reversal' })
			check('reversing K1 → 409', rv.status === 409 && rv.data?.message === 'This invoice was handed over to bexio; revoke the hand-over first.', [rv.status, rv.data])
			expect('a Lead revokes', await ocs(L, 'POST', `${ipath(K1)}/handover/revoke`, {}), 403, 'forbidden')
			const r2 = await ocs(A, 'POST', `${ipath(K2)}/handover/revoke`, {})
			check('revoking a draft → 409', r2.status === 409 && r2.data?.message === 'Only a handed-over invoice can be revoked.', [r2.status, r2.data])
		}

		head('Hand-over: revoke')
		{
			const r = await ocs(A, 'POST', `${ipath(K1)}/handover/revoke`, { reason: 'Wrong customer' })
			expect('Team Admin revokes the hand-over of K1', r, 200)
			const d = r.data?.record?.data
			check('record: draft again, external null, issued_on null', d?.status === 'draft' && d?.external === null && d?.issued_on === null && d?.number === null, d)
			check('released: one booking, one receipt', r.data?.released?.bookings === 1 && r.data?.released?.receipts === 1, r.data?.released)
			const mark = await get('billing', billingKey('pbuser1', EV1))
			check('the billing mark is gone', mark.status === 404 || mark.data?.deleted === true, [mark.status, mark.data])
			const rc = await get('receipt', RU)
			check('the receipt is approved again, without invoice', rc.data?.data?.status === 'approved' && rc.data?.data?.invoice === null, rc.data?.data)
			const led = await ocs(A, 'GET', `/invoices/ledger?since=${(r.data?.ledger?.seq ?? 1) - 1}&limit=5`)
			const e = led.data?.entries?.find((x) => x.seq === r.data?.ledger?.seq)
			check('ledger entry external_revoked { key, system, id, number, reason }', e?.action === 'external_revoked' && e?.number === NUMBER
				&& e?.payload === JSON.stringify({ id: '4711', key: K1, number: NUMBER, reason: 'Wrong customer', system: 'bexio' }), e)
			const k2 = await ocs(A, 'POST', `${ipath(K2)}/handover`, { system: 'bexio', id: '4712', number: `${NUMBER}b`, issued_on: ISSUED_ON })
			check('the booking is free: K2 is handed over', k2.status === 200 && k2.data?.record?.data?.status === 'external' && k2.data?.billed?.bookings === 1 && k2.data?.billed?.receipts === 0, [k2.status, k2.data])
			expect('… and revoked again', await ocs(A, 'POST', `${ipath(K2)}/handover/revoke`, {}), 200)
			check('the chain still verifies', (await ocs(A, 'GET', '/invoices/chain/verify')).data?.ok === true)
		}

		head('Hand-over: the setting back')
		{
			const back = await ocs(A, 'PUT', '/team/settings', { invoicing_mode: null })
			check('invoicing_mode null again', back.status === 200 && back.data?.invoicing_mode === null, back.data)
			const h = await ocs(A, 'POST', `${ipath(K1)}/handover`, { system: 'bexio', id: '1', number: '1' })
			check('… hand-over → 409 again', h.status === 409, [h.status, h.data])
		}
	} finally {
		head('Hand-over: cleaning up')
		await ocs(A, 'PUT', '/team/settings', { invoicing_mode: modeBefore, invoicing: invoicingBefore })
		for (const k of [K1, K2]) {
			const cur = await get('invoice', k)
			if (cur.status === 200 && cur.data?.data?.status === 'external') {
				await ocs(A, 'POST', `${ipath(k)}/handover/revoke`, { reason: 'test clean-up' })
			}
		}
		for (const k of [K1, K2]) {
			const cur = await get('invoice', k)
			if (cur.status === 200 && !cur.data?.deleted) {
				await ocs(A, 'DELETE', `${rec('invoice', k)}?version=${cur.data.version}`)
			}
		}
		const mark = await get('billing', billingKey('pbuser1', EV1))
		if (mark.status === 200 && !mark.data?.deleted) {
			await ocs(A, 'DELETE', `${rec('billing', billingKey('pbuser1', EV1))}?version=${mark.data.version}`)
		}
		await deleteEvent(U1, EV1)
		for (const r of ((await ocs(U1, 'GET', '/records?since=0')).data?.records || []).filter((x) => x.kind === 'booking-report' && x.key.includes(`+${PU}+`))) {
			await ocs(U1, 'DELETE', `/records/booking-report/${encodeURIComponent(r.key)}?version=${r.version}`)
		}
		if (tenant !== null) {
			// The approved receipt and the invoice tombstones are the test's own rows; the ledger stays.
			sql(`DELETE FROM oc_ts_records WHERE tenant_id = ${tenant} AND rkey IN ('${RU}', '${K1}', '${K2}')`)
		} else {
			console.log('    info  TS_SQL not set – the approved receipt of this run stays')
		}
		for (const [kind, key] of [['project', PU], ['customer', CU]]) {
			const cur = await get(kind, key)
			if (cur.status === 200 && !cur.data?.deleted) {
				await ocs(A, 'DELETE', `${rec(kind, key)}?version=${cur.data.version}`)
			}
		}
		const cur = await get('setting', 'settings')
		if (settings.status === 200 && cur.status === 200) {
			await ocs(A, 'PUT', rec('setting', 'settings'), { version: cur.data.version, data: settings.data.data })
		}
		const after = (await ocs(A, 'GET', '/team')).data?.settings
		check('pb left as found (invoicing_mode, invoicing)', JSON.stringify(after?.invoicing_mode ?? null) === JSON.stringify(modeBefore) && after?.invoicing === invoicingBefore && isObj(await me(A)), after)
	}
}
