// SPDX-License-Identifier: AGPL-3.0-or-later
import { ADMIN, as, ocs, sql } from './lib.mjs'
import { A, AA, AL, AU, C, L, NOAH, P, R, RUN, U1, U2, V, ISO, b64, check, expect, head, isObj, keysOf, me, sha256, ensure } from './harness.mjs'

export async function datensaetze() {
	let rev0 = 0
	head('Write, validate, conflict')
	{
		rev0 = (await me(A)).revision
		const data = { id: P, name: 'Planungsbüro/Ä', mapping: {}, subprojects: [], budgets: [{ from: '2026-01-01', hours: 33.6 }], nested: { empty: {} } }
		const r = await ocs(A, 'PUT', `/records/project/${P}`, { version: 0, data })
		expect('new project with version 0', r, 200)
		check('response is the record (version 1, revision > old)', r.data?.version === 1 && r.data?.revision > rev0 && r.data?.deleted === false
			&& r.data?.modified_by === 'pbadmin' && ISO.test(r.data?.modified_at), r.text)
		check('empty objects stay objects, numbers and umlauts stay', isObj(r.data?.data?.mapping) && isObj(r.data?.data?.nested?.empty)
			&& Array.isArray(r.data?.data?.subprojects) && r.data?.data?.budgets?.[0]?.hours === 33.6 && r.data?.data?.name === 'Planungsbüro/Ä', r.text)
		const again = await ocs(A, 'PUT', `/records/project/${P}`, { version: 0, data })
		expect('version 0 on an existing record', again, 409, 'conflict')
		check('conflict returns current', again.data?.current?.version === 1 && again.data?.current?.key === P, again.text)
		expect('matching version', await ocs(A, 'PUT', `/records/project/${P}`, { version: 1, data: { ...data, name: 'v2' } }), 200)
		const stale = await ocs(A, 'PUT', `/records/project/${P}`, { version: 1, data: { ...data, name: 'stale' } })
		expect('stale version', stale, 409, 'conflict')
		check('current shows the new version and who wrote it', stale.data?.current?.version === 2 && stale.data?.current?.data?.name === 'v2'
			&& stale.data?.current?.modified_by === 'pbadmin', stale.text)
		expect('version from the future', await ocs(A, 'PUT', `/records/project/${P}`, { version: 9, data }), 409, 'conflict')

		expect('project: id ≠ key', await ocs(A, 'PUT', `/records/project/${P}`, { version: 2, data: { id: 'anders' } }), 422, 'invalid')
		expect('person: login ≠ key', await ocs(A, 'PUT', `/records/person/x-${RUN}`, { version: 0, data: { login: 'y' } }), 422, 'invalid')
		expect('customer without id', await ocs(A, 'PUT', `/records/customer/x-${RUN}`, { version: 0, data: { name: 'n' } }), 422, 'invalid')
		expect('setting with an unknown key', await ocs(A, 'PUT', '/records/setting/fremd', { version: 0, data: {} }), 422, 'invalid')
		expect('data is a list', await ocs(A, 'PUT', `/records/project/${P}`, { version: 2, data: [] }), 422, 'invalid')
		expect('data is missing', await ocs(A, 'PUT', `/records/project/${P}`, { version: 2 }), 422, 'invalid')
		expect('accounts is not a list of uids', await ocs(A, 'PUT', `/records/person/x-${RUN}`, { version: 0, data: { login: `x-${RUN}`, accounts: 'pbuser1' } }), 422, 'invalid')
		expect('version is missing', await ocs(A, 'PUT', `/records/project/${P}`, { data }), 400, 'invalid')
		expect('version is negative', await ocs(A, 'PUT', `/records/project/${P}`, { version: -1, data }), 400, 'invalid')
		expect('key with a space', await ocs(A, 'PUT', '/records/project/a%20b', { version: 0, data: { id: 'a b' } }), 400, 'invalid')
		expect('unknown kind', await ocs(A, 'PUT', `/records/budget/${P}`, { version: 0, data: { id: P } }), 400, 'invalid')
		expect('kind in the body', await ocs(A, 'PUT', `/records/project/${P}`, { version: 2, kind: 'person', data }), 400, 'invalid')
		expect('not JSON', await ocs(A, 'PUT', `/records/project/${P}`, undefined, { raw: '{kaputt' }), 400, 'invalid')
		const big = 'x'.repeat(256 * 1024)
		expect('record over 256 KB', await ocs(A, 'PUT', `/records/project/${P}`, { version: 2, data: { id: P, pad: big } }), 413, 'too_large')
		expect('body over 1 MB', await ocs(A, 'PUT', `/records/project/${P}`, { version: 2, data: { id: P, pad: 'y'.repeat(1100 * 1024) } }), 413, 'too_large')

		const odd = `a+b@c.d-${RUN}`
		expect('key with @ + . -', await ocs(A, 'PUT', `/records/project/${encodeURIComponent(odd)}`, { version: 0, data: { id: odd } }), 200)
		const got = await ocs(A, 'GET', `/records/project/${encodeURIComponent(odd)}`)
		check('… and read again', got.status === 200 && got.data?.key === odd, got.text)

		expect('customer as manager', await ocs(V, 'PUT', `/records/customer/${C}`, { version: 0, data: { id: C, name: 'Customer', contacts: [] } }), 200)
		expect('region as manager', await ocs(V, 'PUT', `/records/region/${R}`, { version: 0, data: { id: R, holidays: [] } }), 200)
		expect('target hours', await ensure(V, 'setting', 'targethours', { entries: [{ from: '2026-01-01', weekly_hours: 42 }] }), 200)
		expect('settings', await ensure(V, 'setting', 'settings', { vacation_code: 'F' }), 200)
		expect('person pbuser1 (key = uid)', await ensure(A, 'person', 'pbuser1', { login: 'pbuser1', first_name: 'Mia' }), 200)
		expect('person pblead', await ensure(A, 'person', 'pblead', { login: 'pblead' }), 200)
		expect('person via accounts (pbuser2)', await ocs(A, 'PUT', `/records/person/${encodeURIComponent(NOAH)}`, { version: 0, data: { login: NOAH, accounts: ['pbuser2'] } }), 200)
	}

	// ---------------------------------------------------------------------------
	head('Permissions: user (pbuser1)')
	{
		const list = await ocs(U1, 'GET', '/records')
		const k = keysOf(list)
		check('reads setting, region, project', k.has(`project/${P}`) && k.has(`region/${R}`) && k.has('setting/targethours') && k.has('setting/settings'), [...k].slice(0, 20))
		check('reads their own person', k.has('person/pbuser1'))
		check('no other person, no customers in the list', !k.has('person/pblead') && !k.has(`person/${NOAH}`) && ![...k].some((x) => x.startsWith('customer/')), [...k])
		expect('own person directly', await ocs(U1, 'GET', '/records/person/pbuser1'), 200)
		expect('another person directly', await ocs(U1, 'GET', '/records/person/pblead'), 403, 'forbidden')
		expect('another person that does not exist', await ocs(U1, 'GET', `/records/person/nie-${RUN}`), 403, 'forbidden')
		expect('customer directly', await ocs(U1, 'GET', `/records/customer/${C}`), 403, 'forbidden')
		expect('project directly', await ocs(U1, 'GET', `/records/project/${P}`), 200)
		expect('project that does not exist', await ocs(U1, 'GET', `/records/project/nie-${RUN}`), 404, 'not_found')
		expect('PUT', await ocs(U1, 'PUT', '/records/person/pbuser1', { version: 1, data: { login: 'pbuser1' } }), 403, 'forbidden')
		expect('DELETE', await ocs(U1, 'DELETE', `/records/project/${P}?version=2`), 403, 'forbidden')
		expect('Batch', await ocs(U1, 'POST', '/records/batch', { writes: [] }), 403, 'forbidden')
		expect('Restore', await ocs(U1, 'POST', `/records/project/${P}/restore`, { version: 1, current: 2 }), 403, 'forbidden')
		expect('history own person', await ocs(U1, 'GET', '/records/person/pbuser1/history'), 200)
		expect('history another person', await ocs(U1, 'GET', '/records/person/pblead/history'), 403, 'forbidden')
		expect('history project', await ocs(U1, 'GET', `/records/project/${P}/history`), 403, 'forbidden')
		expect('GET /status', await ocs(U1, 'GET', '/status'), 403, 'forbidden')
		expect('GET /team', await ocs(U1, 'GET', '/team'), 403, 'forbidden')
		check('/me: person_key = pbuser1', (await me(U1)).person_key === 'pbuser1')
	}
	head('Permissions: user via data.accounts (pbuser2)')
	{
		// Earlier runs may have left other persons with accounts: [pbuser2].
		const pk = (await me(U2)).person_key
		const own = pk ? await ocs(U2, 'GET', `/records/person/${encodeURIComponent(pk)}`) : null
		// A person keyed exactly "pbuser2" wins over accounts (the Mac app's test setup creates one).
		const exact = (await ocs(U2, 'GET', '/records/person/pbuser2')).status === 200
		if (exact) check('/me: person_key is the exact person pbuser2', pk === 'pbuser2', pk)
		else check('/me: person_key from accounts', own?.status === 200 && own.data?.data?.accounts?.includes('pbuser2') && pk !== 'pbuser2', pk)
		const k = keysOf(await ocs(U2, 'GET', '/records'))
		check('reads their own person (accounts), not pbuser1', k.has(`person/${NOAH}`) && !k.has('person/pbuser1'))
		expect('own person directly', await ocs(U2, 'GET', `/records/person/${encodeURIComponent(NOAH)}`), 200)
		expect('history own person', await ocs(U2, 'GET', `/records/person/${encodeURIComponent(NOAH)}/history`), 200)
		expect('another person (pbuser1)', await ocs(U2, 'GET', '/records/person/pbuser1'), 403, 'forbidden')
	}
	head('Permissions: lead (pblead)')
	{
		const k = keysOf(await ocs(L, 'GET', '/records'))
		check('reads all persons and customers', k.has('person/pbuser1') && k.has(`person/${NOAH}`) && k.has(`customer/${C}`) && k.has(`project/${P}`))
		expect('customer directly', await ocs(L, 'GET', `/records/customer/${C}`), 200)
		expect('another person directly', await ocs(L, 'GET', '/records/person/pbuser1'), 200)
		expect('PUT', await ocs(L, 'PUT', `/records/customer/${C}`, { version: 1, data: { id: C } }), 403, 'forbidden')
		expect('DELETE', await ocs(L, 'DELETE', `/records/customer/${C}?version=1`), 403, 'forbidden')
		expect('Batch', await ocs(L, 'POST', '/records/batch', { writes: [{ kind: 'project', key: `L-${RUN}`, version: 0, data: { id: `L-${RUN}` } }] }), 403, 'forbidden')
		expect('Restore', await ocs(L, 'POST', `/records/project/${P}/restore`, { version: 1, current: 2 }), 403, 'forbidden')
		expect('history own person', await ocs(L, 'GET', '/records/person/pblead/history'), 200)
		expect('history another person', await ocs(L, 'GET', '/records/person/pbuser1/history'), 403, 'forbidden')
		expect('history customer', await ocs(L, 'GET', `/records/customer/${C}/history`), 403, 'forbidden')
		expect('GET /status', await ocs(L, 'GET', '/status'), 403, 'forbidden')
		expect('GET /team', await ocs(L, 'GET', '/team'), 403, 'forbidden')
		expect('report heartbeat', await ocs(L, 'POST', '/status', { app_version: '0.2.0' }), 200)
	}
	head('Permissions: subadmin (pbverw) and admin (pbadmin)')
	{
		for (const [who, name] of [[V, 'pbverw'], [A, 'pbadmin']]) {
			const k = keysOf(await ocs(who, 'GET', '/records'))
			check(`${name}: reads everything`, k.has('person/pbuser1') && k.has(`customer/${C}`) && k.has(`region/${R}`))
			expect(`${name}: history another person`, await ocs(who, 'GET', '/records/person/pbuser1/history'), 200)
			expect(`${name}: history customer`, await ocs(who, 'GET', `/records/customer/${C}/history`), 200)
			expect(`${name}: GET /status`, await ocs(who, 'GET', '/status'), 200)
			const t = await ocs(who, 'GET', '/team')
			const roles = Object.fromEntries((t.data?.members || []).map((x) => [x.uid, x.role]))
			check(`${name}: GET /team with members and role`, t.status === 200 && t.data?.slug === 'pb'
				&& JSON.stringify(roles) === JSON.stringify({ pbadmin: 'admin', pblead: 'lead', pbuser1: 'user', pbuser2: 'user', pbverw: 'subadmin' }), t.text)
		}
	}

	// ---------------------------------------------------------------------------
	head('Team boundary: Atelier sees nothing from Planungsbüro')
	{
		const k = keysOf(await ocs(AA, 'GET', '/records'))
		check('list contains no record from pb', ![`project/${P}`, `customer/${C}`, `region/${R}`, 'person/pbuser1', 'person/pblead', `person/${NOAH}`].some((x) => k.has(x)), [...k])
		const kd = keysOf(await ocs(AA, 'GET', `/records?since=${Math.max(0, rev0 - 1)}`))
		check('not in the delta either', !kd.has(`project/${P}`) && !kd.has('person/pbuser1'))
		expect('pb project directly', await ocs(AA, 'GET', `/records/project/${P}`), 404, 'not_found')
		expect('pb customer directly (lead at)', await ocs(AL, 'GET', `/records/customer/${C}`), 404, 'not_found')
		expect('pb person directly', await ocs(AA, 'GET', '/records/person/pbuser1'), 404, 'not_found')
		expect('history of a pb record', await ocs(AA, 'GET', `/records/project/${P}/history`), 404, 'not_found')
		expect('restore of a pb record', await ocs(AA, 'POST', `/records/project/${P}/restore`, { version: 1, current: 2 }), 404, 'not_found')
		expect('DELETE of a pb record', await ocs(AA, 'DELETE', `/records/project/${P}?version=2`), 404, 'not_found')
		const pbRev = (await me(A)).revision
		const atRev = (await me(AA)).revision
		const own = await ocs(AA, 'PUT', `/records/project/${P}`, { version: 0, data: { id: P, name: 'Atelier' } })
		check('the same key in at is its own record (version 1)', own.status === 200 && own.data?.version === 1, own.text)
		check("writing in at only bumps at's revision", own.data?.revision === atRev + 1 && (await me(A)).revision === pbRev, { pbRev, atRev, got: own.data?.revision })
		const pbP = await ocs(A, 'GET', `/records/project/${P}`)
		check('pb record unchanged', pbP.data?.version === 2 && pbP.data?.data?.name === 'v2', pbP.text)
		const st = await ocs(AA, 'GET', '/status')
		const uids = (st.data || []).map((x) => x.uid).sort()
		check('GET /status: only members of at', JSON.stringify(uids) === JSON.stringify(['atadmin', 'atlead', 'atuser1']), uids)
		const t = await ocs(AA, 'GET', '/team')
		check('GET /team: at, no pb accounts', t.data?.slug === 'at' && !JSON.stringify(t.data?.members).includes('pb'), t.text)
		expect('at user reads no pb customer', await ocs(AU, 'GET', `/records/customer/${C}`), 403, 'forbidden')
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

		const again = await ocs(A, 'PUT', `/records/project/${P}`, { version: 0, data: { id: P, name: 'new' } })
		check('recreate on a tombstone: version = tombstone + 1', again.status === 200 && again.data?.version === 4, again.text)

		// Tombstone of a person via accounts
		const NOAH2 = `noah2.${RUN}@example.test`
		await ocs(A, 'PUT', `/records/person/${encodeURIComponent(NOAH2)}`, { version: 0, data: { login: NOAH2, accounts: ['pbuser2'] } })
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
		check('version 1 with content, who and when', h.data?.[3]?.data?.name === 'Planungsbüro/Ä' && h.data?.[3]?.modified_by === 'pbadmin' && ISO.test(h.data?.[3]?.modified_at))
		const rs = await ocs(V, 'POST', `/records/project/${P}/restore`, { version: 1, current: 4 })
		expect('restore version 1', rs, 200)
		check('… as new version 5 with the old content', rs.data?.version === 5 && rs.data?.data?.name === 'Planungsbüro/Ä' && rs.data?.modified_by === 'pbverw', rs.text)
		expect('restore with a stale current', await ocs(V, 'POST', `/records/project/${P}/restore`, { version: 2, current: 4 }), 409, 'conflict')
		expect('restore of a tombstone', await ocs(V, 'POST', `/records/project/${P}/restore`, { version: 3, current: 5 }), 422, 'invalid')
		expect('restore of an unknown version', await ocs(V, 'POST', `/records/project/${P}/restore`, { version: 99, current: 5 }), 404, 'not_found')
		expect('history of an unknown record', await ocs(A, 'GET', `/records/project/nie-${RUN}/history`), 404, 'not_found')
		const many = await ocs(A, 'GET', '/records/person/pbuser1/history')
		check('history at most 100', Array.isArray(many.data) && many.data.length <= 100)
	}

	// ---------------------------------------------------------------------------
	head('Batch: all or nothing')
	{
		const before = (await me(A)).revision
		const ok = await ocs(A, 'POST', '/records/batch', { writes: [
			{ kind: 'project', key: `B1-${RUN}`, version: 0, data: { id: `B1-${RUN}` } },
			{ kind: 'project', key: `B2-${RUN}`, version: 0, data: { id: `B2-${RUN}` } },
			{ kind: 'project', key: P, version: 5, data: { id: P, name: 'from batch' } },
		] })
		expect('three writes', ok, 200)
		const revs = (ok.data?.records || []).map((x) => x.revision)
		check('revision = old + 3, each write its own', ok.data?.revision === before + 3 && JSON.stringify(revs) === JSON.stringify([before + 1, before + 2, before + 3]), { before, got: ok.data?.revision, revs })

		const mid = (await me(A)).revision
		const bad = await ocs(A, 'POST', '/records/batch', { writes: [
			{ kind: 'project', key: `B3-${RUN}`, version: 0, data: { id: `B3-${RUN}` } },
			{ kind: 'project', key: P, version: 1, data: { id: P, name: 'stale' } },
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
		const tooMany = Array.from({ length: 501 }, (_, i) => ({ kind: 'project', key: `M${i}-${RUN}`, version: 0, data: { id: `M${i}-${RUN}` } }))
		expect('501 writes', await ocs(A, 'POST', '/records/batch', { writes: tooMany }), 413, 'too_large')
		expect('the same record twice', await ocs(A, 'POST', '/records/batch', { writes: [
			{ kind: 'project', key: `D-${RUN}`, version: 0, data: { id: `D-${RUN}` } },
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
			ocs(V, 'PUT', `/records/project/par-${RUN}-${i}`, { version: 0, data: { id: `par-${RUN}-${i}` } })))
		const revs = puts.map((r) => r.data?.revision)
		check('20 concurrent PUTs: all 200', puts.every((r) => r.status === 200), puts.map((r) => r.status))
		check('… with 20 different revisions', new Set(revs).size === 20, revs)
		const same = await Promise.all(Array.from({ length: 10 }, () =>
			ocs(A, 'PUT', `/records/project/same-${RUN}`, { version: 0, data: { id: `same-${RUN}` } })))
		const codes = same.map((r) => r.status).sort()
		check('10 concurrent creations of the same key: exactly one wins', codes.filter((c) => c === 200).length === 1 && codes.filter((c) => c === 409).length === 9, codes)
	}

}
