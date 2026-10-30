// SPDX-License-Identifier: AGPL-3.0-or-later
import { ADMIN, as, ocs, sql } from './lib.mjs'
import { C, NOAH, P, R, RUN, ISO, b64, check, expect, head, isObj, keysOf, me, sha256, ensure } from './harness.mjs'
import { A, AA, AL, AU, L, U1, U2, V } from './konten.mjs'

export async function datensaetze() {
	// A project led by zblead and its customer (0.14.0); deleted at the end.
	const PL = `PK-${RUN}`
	const CL = `KL-${RUN}`
	let rev0 = 0
	// Bare test projects are drafts: from active on a project needs a code (0.16.0).
	const DRAFT = 'draft'
	head('Write, validate, conflict')
	{
		rev0 = (await me(A)).revision
		const data = { id: P, name: 'Probebüro/Ä', codes: [P], mapping: {}, subprojects: [], budgets: [{ from: '2026-01-01', hours: 33.6 }], nested: { empty: {} } }
		const r = await ocs(A, 'PUT', `/records/project/${P}`, { version: 0, data })
		expect('new project with version 0', r, 200)
		check('response is the record (version 1, revision > old)', r.data?.version === 1 && r.data?.revision > rev0 && r.data?.deleted === false
			&& r.data?.modified_by === 'zbadmin' && ISO.test(r.data?.modified_at), r.text)
		check('empty objects stay objects, numbers and umlauts stay', isObj(r.data?.data?.mapping) && isObj(r.data?.data?.nested?.empty)
			&& Array.isArray(r.data?.data?.subprojects) && r.data?.data?.budgets?.[0]?.hours === 33.6 && r.data?.data?.name === 'Probebüro/Ä', r.text)
		const again = await ocs(A, 'PUT', `/records/project/${P}`, { version: 0, data })
		expect('version 0 on an existing record', again, 409, 'conflict')
		check('conflict returns current', again.data?.current?.version === 1 && again.data?.current?.key === P, again.text)
		expect('matching version', await ocs(A, 'PUT', `/records/project/${P}`, { version: 1, data: { ...data, name: 'v2' } }), 200)
		const stale = await ocs(A, 'PUT', `/records/project/${P}`, { version: 1, data: { ...data, name: 'stale' } })
		expect('stale version', stale, 409, 'conflict')
		check('current shows the new version and who wrote it', stale.data?.current?.version === 2 && stale.data?.current?.data?.name === 'v2'
			&& stale.data?.current?.modified_by === 'zbadmin', stale.text)
		expect('version from the future', await ocs(A, 'PUT', `/records/project/${P}`, { version: 9, data }), 409, 'conflict')

		expect('project: id ≠ key', await ocs(A, 'PUT', `/records/project/${P}`, { version: 2, data: { id: 'anders' } }), 422, 'invalid')
		expect('person: login ≠ key', await ocs(A, 'PUT', `/records/person/x-${RUN}`, { version: 0, data: { login: 'y' } }), 422, 'invalid')
		expect('customer without id', await ocs(A, 'PUT', `/records/customer/x-${RUN}`, { version: 0, data: { name: 'n' } }), 422, 'invalid')
		expect('setting with an unknown key', await ocs(A, 'PUT', '/records/setting/fremd', { version: 0, data: {} }), 422, 'invalid')
		expect('data is a list', await ocs(A, 'PUT', `/records/project/${P}`, { version: 2, data: [] }), 422, 'invalid')
		expect('data is missing', await ocs(A, 'PUT', `/records/project/${P}`, { version: 2 }), 422, 'invalid')
		expect('accounts is not a list of uids', await ocs(A, 'PUT', `/records/person/x-${RUN}`, { version: 0, data: { login: `x-${RUN}`, accounts: 'zbuser1' } }), 422, 'invalid')
		expect('version is missing', await ocs(A, 'PUT', `/records/project/${P}`, { data }), 400, 'invalid')
		expect('version is negative', await ocs(A, 'PUT', `/records/project/${P}`, { version: -1, data }), 400, 'invalid')
		expect('key with a space', await ocs(A, 'PUT', '/records/project/a%20b', { version: 0, data: { id: 'a b' } }), 400, 'invalid')
		expect('unknown kind', await ocs(A, 'PUT', `/records/budget/${P}`, { version: 0, data: { id: P } }), 400, 'invalid')
		expect('kind in the body', await ocs(A, 'PUT', `/records/project/${P}`, { version: 2, kind: 'person', data }), 400, 'invalid')
		expect('not JSON', await ocs(A, 'PUT', `/records/project/${P}`, undefined, { raw: '{kaputt' }), 400, 'invalid')
		const big = 'x'.repeat(256 * 1024)
		expect('record over 256 KB', await ocs(A, 'PUT', `/records/project/${P}`, { version: 2, data: { id: P, status: DRAFT, pad: big } }), 413, 'too_large')
		expect('body over 1 MB', await ocs(A, 'PUT', `/records/project/${P}`, { version: 2, data: { id: P, status: DRAFT, pad: 'y'.repeat(1100 * 1024) } }), 413, 'too_large')

		const odd = `a+b@c.d-${RUN}`
		expect('key with @ + . -', await ocs(A, 'PUT', `/records/project/${encodeURIComponent(odd)}`, { version: 0, data: { id: odd, status: DRAFT } }), 200)
		const got = await ocs(A, 'GET', `/records/project/${encodeURIComponent(odd)}`)
		check('… and read again', got.status === 200 && got.data?.key === odd, got.text)

		expect('customer as second Team Admin', await ocs(V, 'PUT', `/records/customer/${C}`, { version: 0, data: { id: C, name: 'Customer', contacts: [] } }), 200)
		expect('region as second Team Admin', await ocs(V, 'PUT', `/records/region/${R}`, { version: 0, data: { id: R, holidays: [] } }), 200)
		expect('target hours', await ensure(V, 'setting', 'targethours', { entries: [{ from: '2026-01-01', weekly_hours: 42 }] }), 200)
		expect('settings', await ensure(V, 'setting', 'settings', { vacation_code: 'F' }), 200)
		// With their account in `accounts`, as the Mac app writes them: a person without one leads nothing.
		expect('person zbuser1 (key = uid)', await ensure(A, 'person', 'zbuser1', { login: 'zbuser1', first_name: 'Mila', accounts: ['zbuser1'] }), 200)
		expect('person zblead', await ensure(A, 'person', 'zblead', { login: 'zblead', accounts: ['zblead'] }), 200)
		expect('person via accounts (zbuser2)', await ocs(A, 'PUT', `/records/person/${encodeURIComponent(NOAH)}`, { version: 0, data: { login: NOAH, accounts: ['zbuser2'] } }), 200)
	}

	// ---------------------------------------------------------------------------
	head('Permissions: user (zbuser1)')
	{
		const list = await ocs(U1, 'GET', '/records')
		const k = keysOf(list)
		check('reads setting, region, project', k.has(`project/${P}`) && k.has(`region/${R}`) && k.has('setting/targethours') && k.has('setting/settings'), [...k].slice(0, 20))
		check('reads their own person', k.has('person/zbuser1'))
		check('no other person, no customers in the list', !k.has('person/zblead') && !k.has(`person/${NOAH}`) && ![...k].some((x) => x.startsWith('customer/')), [...k])
		expect('own person directly', await ocs(U1, 'GET', '/records/person/zbuser1'), 200)
		expect('another person directly', await ocs(U1, 'GET', '/records/person/zblead'), 403, 'forbidden')
		expect('another person that does not exist', await ocs(U1, 'GET', `/records/person/nie-${RUN}`), 403, 'forbidden')
		expect('customer directly', await ocs(U1, 'GET', `/records/customer/${C}`), 403, 'forbidden')
		expect('project directly', await ocs(U1, 'GET', `/records/project/${P}`), 200)
		expect('project that does not exist', await ocs(U1, 'GET', `/records/project/nie-${RUN}`), 404, 'not_found')
		expect('PUT', await ocs(U1, 'PUT', '/records/person/zbuser1', { version: 1, data: { login: 'zbuser1' } }), 403, 'forbidden')
		expect('DELETE', await ocs(U1, 'DELETE', `/records/project/${P}?version=2`), 403, 'forbidden')
		expect('Batch', await ocs(U1, 'POST', '/records/batch', { writes: [] }), 403, 'forbidden')
		expect('Restore', await ocs(U1, 'POST', `/records/project/${P}/restore`, { version: 1, current: 2 }), 403, 'forbidden')
		expect('history own person', await ocs(U1, 'GET', '/records/person/zbuser1/history'), 200)
		expect('history another person', await ocs(U1, 'GET', '/records/person/zblead/history'), 403, 'forbidden')
		expect('history project', await ocs(U1, 'GET', `/records/project/${P}/history`), 403, 'forbidden')
		expect('GET /status', await ocs(U1, 'GET', '/status'), 403, 'forbidden')
		expect('GET /team', await ocs(U1, 'GET', '/team'), 403, 'forbidden')
		check('/me: person_key = zbuser1', (await me(U1)).person_key === 'zbuser1')
	}
	head('Permissions: user via data.accounts (zbuser2)')
	{
		// Earlier runs may have left other persons with accounts: [zbuser2].
		const pk = (await me(U2)).person_key
		const own = pk ? await ocs(U2, 'GET', `/records/person/${encodeURIComponent(pk)}`) : null
		// A person keyed exactly "zbuser2" wins over accounts (the Mac app's test setup creates one).
		const exact = (await ocs(U2, 'GET', '/records/person/zbuser2')).status === 200
		if (exact) check('/me: person_key is the exact person zbuser2', pk === 'zbuser2', pk)
		else check('/me: person_key from accounts', own?.status === 200 && own.data?.data?.accounts?.includes('zbuser2') && pk !== 'zbuser2', pk)
		const k = keysOf(await ocs(U2, 'GET', '/records'))
		check('reads their own person (accounts), not zbuser1', k.has(`person/${NOAH}`) && !k.has('person/zbuser1'))
		expect('own person directly', await ocs(U2, 'GET', `/records/person/${encodeURIComponent(NOAH)}`), 200)
		expect('history own person', await ocs(U2, 'GET', `/records/person/${encodeURIComponent(NOAH)}/history`), 200)
		expect('another person (zbuser1)', await ocs(U2, 'GET', '/records/person/zbuser1'), 403, 'forbidden')
	}
	head('Permissions: lead (zblead)')
	{
		// 0.14.0: others as a name card, customers only of the Lead's projects.
		expect('customer of a project the Lead leads', await ocs(A, 'PUT', `/records/customer/${CL}`, { version: 0, data: { id: CL, name: 'Lead customer', contacts: [] } }), 200)
		expect('… and the project, led by zblead', await ocs(A, 'PUT', `/records/project/${PL}`, { version: 0, data: { id: PL, name: 'Led', codes: [PL], subprojects: [], leads: ['zblead'], customer: CL, status: 'active' } }), 200)
		const k = keysOf(await ocs(L, 'GET', '/records'))
		check('reads the persons and only the customer of their project', k.has('person/zbuser1') && k.has(`person/${NOAH}`) && k.has(`customer/${CL}`)
			&& !k.has(`customer/${C}`) && k.has(`project/${P}`), [...k].filter((x) => x.startsWith('customer/')))
		expect('customer of their project directly', await ocs(L, 'GET', `/records/customer/${CL}`), 200)
		expect('another customer directly', await ocs(L, 'GET', `/records/customer/${C}`), 403, 'forbidden')
		expect('a customer that does not exist', await ocs(L, 'GET', `/records/customer/nie-${RUN}`), 403, 'forbidden')
		const card = await ocs(L, 'GET', '/records/person/zbuser1')
		const fields = ['accounts', 'first_name', 'initials', 'last_name', 'login', 'schema']
		check('another person directly: only the name card', card.status === 200 && card.data?.data?.login === 'zbuser1'
			&& Object.keys(card.data?.data || {}).every((f) => fields.includes(f)), card.data?.data)
		const inList = ((await ocs(L, 'GET', '/records')).data?.records || []).find((x) => x.kind === 'person' && x.key === NOAH)
		check('… also in the list', !!inList && Object.keys(inList.data || {}).every((f) => fields.includes(f)), inList?.data)
		const ownP = await ocs(L, 'GET', '/records/person/zblead')
		check('own person: in full', ownP.status === 200 && ownP.data?.data?.login === 'zblead', ownP.data)
		expect('PUT', await ocs(L, 'PUT', `/records/customer/${C}`, { version: 1, data: { id: C } }), 403, 'forbidden')
		expect('DELETE', await ocs(L, 'DELETE', `/records/customer/${C}?version=1`), 403, 'forbidden')
		expect('Batch', await ocs(L, 'POST', '/records/batch', { writes: [{ kind: 'project', key: `L-${RUN}`, version: 0, data: { id: `L-${RUN}` } }] }), 403, 'forbidden')
		expect('Restore', await ocs(L, 'POST', `/records/project/${P}/restore`, { version: 1, current: 2 }), 403, 'forbidden')
		expect('history own person', await ocs(L, 'GET', '/records/person/zblead/history'), 200)
		expect('history another person', await ocs(L, 'GET', '/records/person/zbuser1/history'), 403, 'forbidden')
		expect('history customer', await ocs(L, 'GET', `/records/customer/${C}/history`), 403, 'forbidden')
		expect('GET /status', await ocs(L, 'GET', '/status'), 403, 'forbidden')
		expect('GET /team', await ocs(L, 'GET', '/team'), 403, 'forbidden')
		expect('report heartbeat', await ocs(L, 'POST', '/status', { app_version: '0.2.0' }), 200)
	}
	// ---------------------------------------------------------------------------
	head('Cost rates on the person (0.16.0): Team Admins alone')
	{
		const caps = await ocs(U1, 'GET', '/ocs/v1.php/cloud/capabilities?format=json')
		check('capability cost_rates: 1', caps.data?.capabilities?.timesister?.cost_rates === 1, caps.data?.capabilities?.timesister)
		const cur = (await ocs(A, 'GET', '/records/person/zbuser1')).data
		const rates = [{ from: '2026-01-01', rate: 95 }, { from: '2026-07-01', rate: 110.5 }]
		const rev = (await me(A)).revision
		const w = await ocs(A, 'PUT', '/records/person/zbuser1', { version: cur.version, data: { ...cur.data, cost_rates: rates } })
		check('admin writes cost_rates and reads them back', w.status === 200 && JSON.stringify(w.data?.data?.cost_rates) === JSON.stringify(rates), w.text.slice(0, 300))
		const again = await ocs(V, 'GET', '/records/person/zbuser1')
		check('second Team Admin reads them', JSON.stringify(again.data?.data?.cost_rates) === JSON.stringify(rates), again.data?.data)
		const hasRates = (d) => Object.prototype.hasOwnProperty.call(d || {}, 'cost_rates')
		const own = await ocs(U1, 'GET', '/records/person/zbuser1')
		check('zbuser1: own person without cost_rates, the rest there', own.status === 200 && own.data?.data?.first_name === 'Mila' && !hasRates(own.data?.data), own.data?.data)
		check('… not in the list', !hasRates(((await ocs(U1, 'GET', '/records')).data?.records || []).find((x) => x.kind === 'person' && x.key === 'zbuser1')?.data))
		const delta = ((await ocs(U1, 'GET', `/records?since=${rev}`)).data?.records || []).find((x) => x.kind === 'person' && x.key === 'zbuser1')
		check('… not in the delta', !!delta && !hasRates(delta.data), delta)
		check('… not in the history', ((await ocs(U1, 'GET', '/records/person/zbuser1/history')).data || []).every((h) => !hasRates(h.data)))
		check('zblead: not on the name card', !hasRates((await ocs(L, 'GET', '/records/person/zbuser1')).data?.data))
		const bad = async (name, cost_rates, text) => {
			const r = await ocs(A, 'PUT', '/records/person/zbuser1', { version: cur.version + 1, data: { ...cur.data, cost_rates } })
			check(`422 ${name}`, r.status === 422 && r.data?.message?.includes(text), `${r.status} ${r.data?.message}`)
		}
		await bad('not a list', { '2026-01-01': 95 }, '“cost_rates” must be a list')
		await bad('no day', [{ rate: 95 }], 'needs a day “from”')
		await bad('no such day', [{ from: '2026-02-30', rate: 95 }], 'needs a day “from”')
		await bad('negative', [{ from: '2026-01-01', rate: -1 }], 'needs a day “from”')
		await bad('a day twice', [{ from: '2026-01-01', rate: 95 }, { from: '2026-01-01', rate: 99 }], 'names the day 2026-01-01 twice')
		expect('null clears them', await ocs(A, 'PUT', '/records/person/zbuser1', { version: cur.version + 1, data: { ...cur.data, cost_rates: null } }), 200)
		// Back to the record as it was before this part.
		expect('zbuser1 restored', await ocs(A, 'PUT', '/records/person/zbuser1', { version: cur.version + 2, data: cur.data }), 200)
	}

	head('Permissions: both Team Admins (zbverw, zbadmin)')
	{
		for (const [who, name] of [[V, 'zbverw'], [A, 'zbadmin']]) {
			const k = keysOf(await ocs(who, 'GET', '/records'))
			check(`${name}: reads everything`, k.has('person/zbuser1') && k.has(`customer/${C}`) && k.has(`region/${R}`))
			expect(`${name}: history another person`, await ocs(who, 'GET', '/records/person/zbuser1/history'), 200)
			expect(`${name}: history customer`, await ocs(who, 'GET', `/records/customer/${C}/history`), 200)
			expect(`${name}: GET /status`, await ocs(who, 'GET', '/status'), 200)
			const t = await ocs(who, 'GET', '/team')
			const roles = Object.fromEntries((t.data?.members || []).map((x) => [x.uid, x.role]))
			check(`${name}: GET /team with members and role`, t.status === 200 && t.data?.slug === 'zb'
				&& JSON.stringify(roles) === JSON.stringify({ zbadmin: 'admin', zblead: 'lead', zbuser1: 'user', zbuser2: 'user', zbverw: 'admin' }), t.text)
		}
	}

	// ---------------------------------------------------------------------------
	head('Team boundary: Probeatelier sees nothing from Probebüro')
	{
		const k = keysOf(await ocs(AA, 'GET', '/records'))
		check('list contains no record from zb', ![`project/${P}`, `customer/${C}`, `region/${R}`, 'person/zbuser1', 'person/zblead', `person/${NOAH}`].some((x) => k.has(x)), [...k])
		const kd = keysOf(await ocs(AA, 'GET', `/records?since=${Math.max(0, rev0 - 1)}`))
		check('not in the delta either', !kd.has(`project/${P}`) && !kd.has('person/zbuser1'))
		expect('zb project directly', await ocs(AA, 'GET', `/records/project/${P}`), 404, 'not_found')
		// 0.14.0: a Lead reads no customer outside their projects – 403, existence not revealed.
		expect('zb customer directly (lead za)', await ocs(AL, 'GET', `/records/customer/${C}`), 403, 'forbidden')
		expect('zb person directly', await ocs(AA, 'GET', '/records/person/zbuser1'), 404, 'not_found')
		expect('history of a zb record', await ocs(AA, 'GET', `/records/project/${P}/history`), 404, 'not_found')
		expect('restore of a zb record', await ocs(AA, 'POST', `/records/project/${P}/restore`, { version: 1, current: 2 }), 404, 'not_found')
		expect('DELETE of a zb record', await ocs(AA, 'DELETE', `/records/project/${P}?version=2`), 404, 'not_found')
		const zbRev = (await me(A)).revision
		const zaRev = (await me(AA)).revision
		const own = await ocs(AA, 'PUT', `/records/project/${P}`, { version: 0, data: { id: P, status: DRAFT, name: 'Probeatelier' } })
		check('the same key in za is its own record (version 1)', own.status === 200 && own.data?.version === 1, own.text)
		check("writing in za only bumps za's revision", own.data?.revision === zaRev + 1 && (await me(A)).revision === zbRev, { zbRev, zaRev, got: own.data?.revision })
		const zbP = await ocs(A, 'GET', `/records/project/${P}`)
		check('zb record unchanged', zbP.data?.version === 2 && zbP.data?.data?.name === 'v2', zbP.text)
		const st = await ocs(AA, 'GET', '/status')
		const uids = (st.data || []).map((x) => x.uid).sort()
		check('GET /status: only members of za', JSON.stringify(uids) === JSON.stringify(['zaadmin', 'zalead', 'zauser1']), uids)
		const t = await ocs(AA, 'GET', '/team')
		check('GET /team: za, no zb accounts', t.data?.slug === 'za' && !JSON.stringify(t.data?.members).includes('zb'), t.text)
		expect('za user reads no zb customer', await ocs(AU, 'GET', `/records/customer/${C}`), 403, 'forbidden')
	}

	// ---------------------------------------------------------------------------
	head('Booking reports and billable marks (0.14.0)')
	{
		const id = `b1-${RUN}@example.test`
		const booking = { id, date: '2026-10-07', start: '08:15', end: '10:00', minutes: 105, code: PL, title: 'Site visit', notes: 'First line\nSecond line', billable: true, billed: false }
		const report = (project, month, bookings = [booking]) => ({ schema: 1, person: 'zbuser1', project, month, at: '2026-10-07T12:00:00Z', bookings })
		const rk = `zbuser1+${PL}+2026-10`
		const rp = `zbuser1+${P}+2026-10`
		const mk = `zbuser1+${PL}+${sha256(id).slice(0, 32)}`
		const mark = (project) => ({ schema: 1, person: 'zbuser1', project, uid: id, billable: false })
		try {
			const cat = await ocs(U1, 'GET', `/records/project/${PL}`)
			check('catalog: led true for a project with a Lead, no leads', cat.data?.data?.led === true && !('leads' in (cat.data?.data || {})), cat.data?.data)
			check('… no led without a Lead', !('led' in ((await ocs(U1, 'GET', `/records/project/${P}`)).data?.data || {})))
			check('/me.lead_of of zblead names the project', ((await me(L)).lead_of || []).some((x) => x.key === PL))
			expect('the person reports their bookings', await ocs(U1, 'PUT', `/records/booking-report/${encodeURIComponent(rk)}`, { version: 0, data: report(PL, '2026-10') }), 200)
			expect('… on a project without Lead too', await ocs(U1, 'PUT', `/records/booking-report/${encodeURIComponent(rp)}`, { version: 0, data: report(P, '2026-10', []) }), 200)
			expect('unknown field', await ocs(U1, 'PUT', `/records/booking-report/${encodeURIComponent(`zbuser1+${PL}+2026-11`)}`, { version: 0, data: { ...report(PL, '2026-11', []), x: 1 } }), 422, 'invalid')
			expect('a day outside the month', await ocs(U1, 'PUT', `/records/booking-report/${encodeURIComponent(`zbuser1+${PL}+2026-11`)}`, { version: 0, data: report(PL, '2026-11') }), 422, 'invalid')
			expect('month ≠ key', await ocs(U1, 'PUT', `/records/booking-report/${encodeURIComponent(`zbuser1+${PL}+2026-11`)}`, { version: 0, data: report(PL, '2026-10') }), 422, 'invalid')
			expect('another User writes it', await ocs(U2, 'PUT', `/records/booking-report/${encodeURIComponent(`zbuser1+${PL}+2026-09`)}`, { version: 0, data: report(PL, '2026-09', []) }), 403, 'forbidden')
			expect('a Team Admin writes it', await ocs(A, 'PUT', `/records/booking-report/${encodeURIComponent(`zbuser1+${PL}+2026-09`)}`, { version: 0, data: report(PL, '2026-09', []) }), 403, 'forbidden')
			expect('the Lead writes it', await ocs(L, 'PUT', `/records/booking-report/${encodeURIComponent(`zbuser1+${PL}+2026-09`)}`, { version: 0, data: report(PL, '2026-09', []) }), 403, 'forbidden')
			const lr = await ocs(L, 'GET', `/records/booking-report/${encodeURIComponent(rk)}`)
			check('the project\'s Lead reads it, with title and notes', lr.status === 200 && lr.data?.data?.bookings?.[0]?.notes === 'First line\nSecond line', lr.text.slice(0, 300))
			expect('… but not the report on a project they do not lead', await ocs(L, 'GET', `/records/booking-report/${encodeURIComponent(rp)}`), 403, 'forbidden')
			const lk = keysOf(await ocs(L, 'GET', '/records'))
			check('… the same in the list', lk.has(`booking-report/${rk}`) && !lk.has(`booking-report/${rp}`))
			expect('Team Admin reads it', await ocs(V, 'GET', `/records/booking-report/${encodeURIComponent(rk)}`), 200)
			expect('another User does not', await ocs(U2, 'GET', `/records/booking-report/${encodeURIComponent(rk)}`), 403, 'forbidden')
			check('… nor in the list', !keysOf(await ocs(U2, 'GET', '/records')).has(`booking-report/${rk}`))

			expect('billable: the Lead marks a booking', await ocs(L, 'PUT', `/records/billable/${encodeURIComponent(mk)}`, { version: 0, data: mark(PL) }), 200)
			expect('… hash ≠ uid', await ocs(L, 'PUT', `/records/billable/${encodeURIComponent(`zbuser1+${PL}+${'0'.repeat(32)}`)}`, { version: 0, data: mark(PL) }), 422, 'invalid')
			expect('… not on a project they do not lead', await ocs(L, 'PUT', `/records/billable/${encodeURIComponent(`zbuser1+${P}+${sha256(id).slice(0, 32)}`)}`, { version: 0, data: mark(P) }), 403, 'forbidden')
			expect('the person reads the mark', await ocs(U1, 'GET', `/records/billable/${encodeURIComponent(mk)}`), 200)
			expect('… but does not write it', await ocs(U1, 'PUT', `/records/billable/${encodeURIComponent(mk)}`, { version: 1, data: { ...mark(PL), billable: true } }), 403, 'forbidden')
			expect('another User does not read it', await ocs(U2, 'GET', `/records/billable/${encodeURIComponent(mk)}`), 403, 'forbidden')
			expect('Lead: batch with a billable mark only', await ocs(L, 'POST', '/records/batch', { writes: [{ kind: 'billable', key: mk, version: 1, data: { ...mark(PL), billable: true } }] }), 200)
			expect('Team Admin: batch with someone else\'s booking report', await ocs(A, 'POST', '/records/batch', { writes: [{ kind: 'booking-report', key: rk, version: 1, data: report(PL, '2026-10', []) }] }), 403, 'forbidden')
		} finally {
			for (const [who, kind, key] of [[L, 'billable', mk], [U1, 'booking-report', rk], [U1, 'booking-report', rp]]) {
				const cur = await ocs(who, 'GET', `/records/${kind}/${encodeURIComponent(key)}`)
				if (cur.status === 200 && !cur.data?.deleted) {
					await ocs(who, 'DELETE', `/records/${kind}/${encodeURIComponent(key)}?version=${cur.data.version}`)
				}
			}
			check('booking report and mark withdrawn', (await ocs(U1, 'GET', `/records/booking-report/${encodeURIComponent(rk)}`)).status === 404)
		}
	}

	// ---------------------------------------------------------------------------
	head('Delete, tombstone, delta via since')
	{
		const before = (await me(A)).revision
		const stale = await ocs(A, 'DELETE', `/records/project/${P}?version=1`)
		expect('DELETE with a stale version', stale, 409, 'conflict')
		check('… with current', stale.data?.current?.version === 2, stale.text)
		expect('DELETE without version', await ocs(A, 'DELETE', `/records/project/${P}`), 400, 'invalid')
		const del = await ocs(A, 'DELETE', `/records/project/${P}?version=2`)
		expect('DELETE with a matching version', del, 200)
		check('response is the tombstone (deleted, data null, version 3)', del.data?.deleted === true && del.data?.data === null && del.data?.version === 3, del.text)
		expect('GET on a tombstone', await ocs(A, 'GET', `/records/project/${P}`), 404, 'not_found')
		expect('DELETE on a tombstone', await ocs(A, 'DELETE', `/records/project/${P}?version=3`), 404, 'not_found')
		const delta = await ocs(A, 'GET', `/records?since=${before}`)
		const tomb = delta.data?.records?.find((x) => x.kind === 'project' && x.key === P)
		check('delta contains the tombstone', tomb?.deleted === true && tomb?.data === null, delta.text.slice(0, 400))
		check('delta only with revision > since', (delta.data?.records || []).every((x) => x.revision > before))
		check("the response's revision = the team's revision", delta.data?.revision === (await me(A)).revision)
		const full = await ocs(A, 'GET', '/records?since=0')
		check('since=0 without tombstones', !keysOf(full).has(`project/${P}`) && (full.data?.records || []).every((x) => !x.deleted))
		const u = await ocs(U1, 'GET', `/records?since=${before}`)
		check('user gets the tombstone of a readable project', u.data?.records?.some((x) => x.key === P && x.deleted))
		expect('since invalid', await ocs(A, 'GET', '/records?since=abc'), 400, 'invalid')

		const again = await ocs(A, 'PUT', `/records/project/${P}`, { version: 0, data: { id: P, status: DRAFT, name: 'new' } })
		check('recreate on a tombstone: version = tombstone + 1', again.status === 200 && again.data?.version === 4, again.text)

		// Tombstone of a person via accounts
		const NOAH2 = `noah2.${RUN}@example.test`
		await ocs(A, 'PUT', `/records/person/${encodeURIComponent(NOAH2)}`, { version: 0, data: { login: NOAH2, accounts: ['zbuser2'] } })
		const b2 = (await me(U2)).revision
		await ocs(A, 'DELETE', `/records/person/${encodeURIComponent(NOAH2)}?version=1`)
		const d2 = await ocs(U2, 'GET', `/records?since=${b2}`)
		check("the person learns of their record's tombstone (accounts stay)", d2.data?.records?.some((x) => x.key === NOAH2 && x.deleted), d2.text.slice(0, 300))
		const d1 = await ocs(U1, 'GET', `/records?since=${b2}`)
		check('other users do not see this tombstone', !d1.data?.records?.some((x) => x.key === NOAH2))
	}

	// ---------------------------------------------------------------------------
	head('History and restore')
	{
		const h = await ocs(A, 'GET', `/records/project/${P}/history`)
		const versions = (h.data || []).map((x) => x.version)
		check('history newest first, every version', JSON.stringify(versions) === JSON.stringify([4, 3, 2, 1]), versions)
		check('version 3 is the tombstone', h.data?.[1]?.deleted === true && h.data?.[1]?.data === null)
		check('version 1 with content, who and when', h.data?.[3]?.data?.name === 'Probebüro/Ä' && h.data?.[3]?.modified_by === 'zbadmin' && ISO.test(h.data?.[3]?.modified_at))
		const rs = await ocs(V, 'POST', `/records/project/${P}/restore`, { version: 1, current: 4 })
		expect('restore version 1', rs, 200)
		check('… as new version 5 with the old content', rs.data?.version === 5 && rs.data?.data?.name === 'Probebüro/Ä' && rs.data?.modified_by === 'zbverw', rs.text)
		expect('restore with a stale current', await ocs(V, 'POST', `/records/project/${P}/restore`, { version: 2, current: 4 }), 409, 'conflict')
		expect('restore of a tombstone', await ocs(V, 'POST', `/records/project/${P}/restore`, { version: 3, current: 5 }), 422, 'invalid')
		expect('restore of an unknown version', await ocs(V, 'POST', `/records/project/${P}/restore`, { version: 99, current: 5 }), 404, 'not_found')
		expect('history of an unknown record', await ocs(A, 'GET', `/records/project/nie-${RUN}/history`), 404, 'not_found')
		const many = await ocs(A, 'GET', '/records/person/zbuser1/history')
		check('history at most 100', Array.isArray(many.data) && many.data.length <= 100)
	}

	// ---------------------------------------------------------------------------
	head('Batch: all or nothing')
	{
		const before = (await me(A)).revision
		const ok = await ocs(A, 'POST', '/records/batch', { writes: [
			{ kind: 'project', key: `B1-${RUN}`, version: 0, data: { id: `B1-${RUN}`, status: DRAFT } },
			{ kind: 'project', key: `B2-${RUN}`, version: 0, data: { id: `B2-${RUN}`, status: DRAFT } },
			{ kind: 'project', key: P, version: 5, data: { id: P, status: DRAFT, name: 'from batch' } },
		] })
		expect('three writes', ok, 200)
		const revs = (ok.data?.records || []).map((x) => x.revision)
		// Since 0.14 a project write also touches its reports and marks (one more revision when P has a Lead).
		check('revision ≥ old + 3, each write its own', ok.data?.revision >= before + 3 && JSON.stringify(revs) === JSON.stringify([before + 1, before + 2, before + 3]), { before, got: ok.data?.revision, revs })

		const mid = (await me(A)).revision
		const bad = await ocs(A, 'POST', '/records/batch', { writes: [
			{ kind: 'project', key: `B3-${RUN}`, version: 0, data: { id: `B3-${RUN}`, status: DRAFT } },
			{ kind: 'project', key: P, version: 1, data: { id: P, status: DRAFT, name: 'stale' } },
			{ kind: 'project', key: `B1-${RUN}`, version: 1, data: null },
		] })
		expect('one conflict', bad, 409, 'conflict')
		check('conflicts names exactly that one, with current', bad.data?.conflicts?.length === 1 && bad.data?.conflicts?.[0]?.key === P && bad.data?.conflicts?.[0]?.current?.version === 6, bad.text)
		expect('… and nothing written (new key missing)', await ocs(A, 'GET', `/records/project/B3-${RUN}`), 404, 'not_found')
		expect('… and nothing deleted', await ocs(A, 'GET', `/records/project/B1-${RUN}`), 200)
		check('… and the revision unchanged', (await me(A)).revision === mid)

		const del = await ocs(A, 'POST', '/records/batch', { writes: [{ kind: 'project', key: `B1-${RUN}`, version: 1, data: null }] })
		check('data: null deletes', del.status === 200 && del.data?.records?.[0]?.deleted === true, del.text)
		const gone = await ocs(A, 'POST', '/records/batch', { writes: [{ kind: 'project', key: `nie-${RUN}`, version: 0, data: null }] })
		check('deleting something unknown is a conflict with current null', gone.status === 409 && gone.data?.conflicts?.[0]?.current === null, gone.text)
		const empty = await ocs(A, 'POST', '/records/batch', { writes: [] })
		check('empty list: nothing, revision stays', empty.status === 200 && empty.data?.records?.length === 0 && empty.data?.revision === (await me(A)).revision, empty.text)
		const tooMany = Array.from({ length: 501 }, (_, i) => ({ kind: 'project', key: `M${i}-${RUN}`, version: 0, data: { id: `M${i}-${RUN}`, status: DRAFT } }))
		expect('501 writes', await ocs(A, 'POST', '/records/batch', { writes: tooMany }), 413, 'too_large')
		expect('the same record twice', await ocs(A, 'POST', '/records/batch', { writes: [
			{ kind: 'project', key: `D-${RUN}`, version: 0, data: { id: `D-${RUN}`, status: DRAFT } },
			{ kind: 'project', key: `D-${RUN}`, version: 1, data: { id: `D-${RUN}` } },
		] }), 422, 'invalid')
		const inv = await ocs(A, 'POST', '/records/batch', { writes: [{ kind: 'project', key: `E-${RUN}`, version: 0, data: { id: 'falsch' } }] })
		check('an invalid write names its number', inv.status === 422 && /Write 1/.test(inv.data?.message || ''), inv.text)
		expect('writes is missing', await ocs(A, 'POST', '/records/batch', {}), 400, 'invalid')
		const full = await ocs(A, 'POST', '/records/batch', { writes: tooMany.slice(0, 500) })
		check('500 writes in one call', full.status === 200 && full.data?.records?.length === 500, full.status)
	}

	// ---------------------------------------------------------------------------
	head('Concurrency')
	{
		const puts = await Promise.all(Array.from({ length: 20 }, (_, i) =>
			ocs(V, 'PUT', `/records/project/par-${RUN}-${i}`, { version: 0, data: { id: `par-${RUN}-${i}`, status: DRAFT } })))
		const revs = puts.map((r) => r.data?.revision)
		check('20 concurrent PUTs: all 200', puts.every((r) => r.status === 200), puts.map((r) => r.status))
		check('… with 20 different revisions', new Set(revs).size === 20, revs)
		const same = await Promise.all(Array.from({ length: 10 }, () =>
			ocs(A, 'PUT', `/records/project/same-${RUN}`, { version: 0, data: { id: `same-${RUN}`, status: DRAFT } })))
		const codes = same.map((r) => r.status).sort()
		check('10 concurrent creations of the same key: exactly one wins', codes.filter((c) => c === 200).length === 1 && codes.filter((c) => c === 409).length === 9, codes)
	}

	// ---------------------------------------------------------------------------
	head('Lead project: cleaning up')
	{
		for (const [kind, key] of [['project', PL], ['customer', CL]]) {
			const cur = await ocs(A, 'GET', `/records/${kind}/${key}`)
			if (cur.status === 200) {
				await ocs(A, 'DELETE', `/records/${kind}/${key}?version=${cur.data.version}`)
			}
		}
		check('project and customer of the Lead removed', (await ocs(A, 'GET', `/records/project/${PL}`)).status === 404)
	}
}
