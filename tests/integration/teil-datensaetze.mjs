// SPDX-License-Identifier: AGPL-3.0-or-later
import { ADMIN, as, ocs, sql } from './lib.mjs'
import { A, AA, AL, AU, C, L, NOAH, P, R, RUN, U1, U2, V, ISO, b64, check, expect, head, isObj, keysOf, me, sha256, ensure } from './harness.mjs'

export async function datensaetze() {
	let rev0 = 0
	head('Schreiben, Prüfen, Konflikt')
	{
		rev0 = (await me(A)).revision
		const data = { id: P, name: 'Planungsbüro/Ä', mapping: {}, subprojects: [], budgets: [{ from: '2026-01-01', hours: 33.6 }], nested: { empty: {} } }
		const r = await ocs(A, 'PUT', `/records/project/${P}`, { version: 0, data })
		expect('neues Projekt mit version 0', r, 200)
		check('Antwort ist der Datensatz (version 1, revision > alt)', r.data?.version === 1 && r.data?.revision > rev0 && r.data?.deleted === false
			&& r.data?.modified_by === 'pbadmin' && ISO.test(r.data?.modified_at), r.text)
		check('leere Objekte bleiben Objekte, Zahlen und Umlaute bleiben', isObj(r.data?.data?.mapping) && isObj(r.data?.data?.nested?.empty)
			&& Array.isArray(r.data?.data?.subprojects) && r.data?.data?.budgets?.[0]?.hours === 33.6 && r.data?.data?.name === 'Planungsbüro/Ä', r.text)
		const again = await ocs(A, 'PUT', `/records/project/${P}`, { version: 0, data })
		expect('version 0 auf bestehenden Datensatz', again, 409, 'conflict')
		check('Konflikt liefert current', again.data?.current?.version === 1 && again.data?.current?.key === P, again.text)
		expect('passende Fassung', await ocs(A, 'PUT', `/records/project/${P}`, { version: 1, data: { ...data, name: 'v2' } }), 200)
		const stale = await ocs(A, 'PUT', `/records/project/${P}`, { version: 1, data: { ...data, name: 'veraltet' } })
		expect('veraltete Fassung', stale, 409, 'conflict')
		check('current zeigt die neue Fassung und wer sie schrieb', stale.data?.current?.version === 2 && stale.data?.current?.data?.name === 'v2'
			&& stale.data?.current?.modified_by === 'pbadmin', stale.text)
		expect('Fassung aus der Zukunft', await ocs(A, 'PUT', `/records/project/${P}`, { version: 9, data }), 409, 'conflict')

		expect('Projekt: id ≠ Schlüssel', await ocs(A, 'PUT', `/records/project/${P}`, { version: 2, data: { id: 'anders' } }), 422, 'invalid')
		expect('Person: login ≠ Schlüssel', await ocs(A, 'PUT', `/records/person/x-${RUN}`, { version: 0, data: { login: 'y' } }), 422, 'invalid')
		expect('Kunde ohne id', await ocs(A, 'PUT', `/records/customer/x-${RUN}`, { version: 0, data: { name: 'n' } }), 422, 'invalid')
		expect('Einstellung mit fremdem Schlüssel', await ocs(A, 'PUT', '/records/setting/fremd', { version: 0, data: {} }), 422, 'invalid')
		expect('data ist eine Liste', await ocs(A, 'PUT', `/records/project/${P}`, { version: 2, data: [] }), 422, 'invalid')
		expect('data fehlt', await ocs(A, 'PUT', `/records/project/${P}`, { version: 2 }), 422, 'invalid')
		expect('accounts ist keine Liste von Kennungen', await ocs(A, 'PUT', `/records/person/x-${RUN}`, { version: 0, data: { login: `x-${RUN}`, accounts: 'pbuser1' } }), 422, 'invalid')
		expect('version fehlt', await ocs(A, 'PUT', `/records/project/${P}`, { data }), 400, 'invalid')
		expect('version negativ', await ocs(A, 'PUT', `/records/project/${P}`, { version: -1, data }), 400, 'invalid')
		expect('Schlüssel mit Leerzeichen', await ocs(A, 'PUT', '/records/project/a%20b', { version: 0, data: { id: 'a b' } }), 400, 'invalid')
		expect('unbekannte Art', await ocs(A, 'PUT', `/records/budget/${P}`, { version: 0, data: { id: P } }), 400, 'invalid')
		expect('Art im Rumpf', await ocs(A, 'PUT', `/records/project/${P}`, { version: 2, kind: 'person', data }), 400, 'invalid')
		expect('kein JSON', await ocs(A, 'PUT', `/records/project/${P}`, undefined, { raw: '{kaputt' }), 400, 'invalid')
		const big = 'x'.repeat(256 * 1024)
		expect('Datensatz über 256 KB', await ocs(A, 'PUT', `/records/project/${P}`, { version: 2, data: { id: P, pad: big } }), 413, 'too_large')
		expect('Rumpf über 1 MB', await ocs(A, 'PUT', `/records/project/${P}`, { version: 2, data: { id: P, pad: 'y'.repeat(1100 * 1024) } }), 413, 'too_large')

		const odd = `a+b@c.d-${RUN}`
		expect('Schlüssel mit @ + . -', await ocs(A, 'PUT', `/records/project/${encodeURIComponent(odd)}`, { version: 0, data: { id: odd } }), 200)
		const got = await ocs(A, 'GET', `/records/project/${encodeURIComponent(odd)}`)
		check('… und wieder lesen', got.status === 200 && got.data?.key === odd, got.text)

		expect('Kunde als Verwaltung', await ocs(V, 'PUT', `/records/customer/${C}`, { version: 0, data: { id: C, name: 'Kunde', contacts: [] } }), 200)
		expect('Standort als Verwaltung', await ocs(V, 'PUT', `/records/region/${R}`, { version: 0, data: { id: R, holidays: [] } }), 200)
		expect('Sollzeit', await ensure(V, 'setting', 'targethours', { entries: [{ from: '2026-01-01', weekly_hours: 42 }] }), 200)
		expect('Einstellungen', await ensure(V, 'setting', 'settings', { vacation_code: 'F' }), 200)
		expect('Person pbuser1 (Schlüssel = Kennung)', await ensure(A, 'person', 'pbuser1', { login: 'pbuser1', first_name: 'Mia' }), 200)
		expect('Person pblead', await ensure(A, 'person', 'pblead', { login: 'pblead' }), 200)
		expect('Person über accounts (pbuser2)', await ocs(A, 'PUT', `/records/person/${encodeURIComponent(NOAH)}`, { version: 0, data: { login: NOAH, accounts: ['pbuser2'] } }), 200)
	}

	// ---------------------------------------------------------------------------
	head('Rechte: user (pbuser1)')
	{
		const list = await ocs(U1, 'GET', '/records')
		const k = keysOf(list)
		check('liest setting, region, project', k.has(`project/${P}`) && k.has(`region/${R}`) && k.has('setting/targethours') && k.has('setting/settings'), [...k].slice(0, 20))
		check('liest die eigene Person', k.has('person/pbuser1'))
		check('keine fremde Person, keine Kunden in der Liste', !k.has('person/pblead') && !k.has(`person/${NOAH}`) && ![...k].some((x) => x.startsWith('customer/')), [...k])
		expect('eigene Person direkt', await ocs(U1, 'GET', '/records/person/pbuser1'), 200)
		expect('fremde Person direkt', await ocs(U1, 'GET', '/records/person/pblead'), 403, 'forbidden')
		expect('fremde Person, die es nicht gibt', await ocs(U1, 'GET', `/records/person/nie-${RUN}`), 403, 'forbidden')
		expect('Kunde direkt', await ocs(U1, 'GET', `/records/customer/${C}`), 403, 'forbidden')
		expect('Projekt direkt', await ocs(U1, 'GET', `/records/project/${P}`), 200)
		expect('Projekt, das es nicht gibt', await ocs(U1, 'GET', `/records/project/nie-${RUN}`), 404, 'not_found')
		expect('PUT', await ocs(U1, 'PUT', '/records/person/pbuser1', { version: 1, data: { login: 'pbuser1' } }), 403, 'forbidden')
		expect('DELETE', await ocs(U1, 'DELETE', `/records/project/${P}?version=2`), 403, 'forbidden')
		expect('Batch', await ocs(U1, 'POST', '/records/batch', { writes: [] }), 403, 'forbidden')
		expect('Restore', await ocs(U1, 'POST', `/records/project/${P}/restore`, { version: 1, current: 2 }), 403, 'forbidden')
		expect('Verlauf eigene Person', await ocs(U1, 'GET', '/records/person/pbuser1/history'), 200)
		expect('Verlauf fremde Person', await ocs(U1, 'GET', '/records/person/pblead/history'), 403, 'forbidden')
		expect('Verlauf Projekt', await ocs(U1, 'GET', `/records/project/${P}/history`), 403, 'forbidden')
		expect('GET /status', await ocs(U1, 'GET', '/status'), 403, 'forbidden')
		expect('GET /team', await ocs(U1, 'GET', '/team'), 403, 'forbidden')
		check('/me: person_key = pbuser1', (await me(U1)).person_key === 'pbuser1')
	}
	head('Rechte: user über data.accounts (pbuser2)')
	{
		// Frühere Läufe können weitere Personen mit accounts: [pbuser2] hinterlassen haben.
		const pk = (await me(U2)).person_key
		const own = pk ? await ocs(U2, 'GET', `/records/person/${encodeURIComponent(pk)}`) : null
		check('/me: person_key aus accounts', own?.status === 200 && own.data?.data?.accounts?.includes('pbuser2') && pk !== 'pbuser2', pk)
		const k = keysOf(await ocs(U2, 'GET', '/records'))
		check('liest die eigene Person (accounts), nicht pbuser1', k.has(`person/${NOAH}`) && !k.has('person/pbuser1'))
		expect('eigene Person direkt', await ocs(U2, 'GET', `/records/person/${encodeURIComponent(NOAH)}`), 200)
		expect('Verlauf eigene Person', await ocs(U2, 'GET', `/records/person/${encodeURIComponent(NOAH)}/history`), 200)
		expect('fremde Person (pbuser1)', await ocs(U2, 'GET', '/records/person/pbuser1'), 403, 'forbidden')
	}
	head('Rechte: lead (pblead)')
	{
		const k = keysOf(await ocs(L, 'GET', '/records'))
		check('liest alle Personen und Kunden', k.has('person/pbuser1') && k.has(`person/${NOAH}`) && k.has(`customer/${C}`) && k.has(`project/${P}`))
		expect('Kunde direkt', await ocs(L, 'GET', `/records/customer/${C}`), 200)
		expect('fremde Person direkt', await ocs(L, 'GET', '/records/person/pbuser1'), 200)
		expect('PUT', await ocs(L, 'PUT', `/records/customer/${C}`, { version: 1, data: { id: C } }), 403, 'forbidden')
		expect('DELETE', await ocs(L, 'DELETE', `/records/customer/${C}?version=1`), 403, 'forbidden')
		expect('Batch', await ocs(L, 'POST', '/records/batch', { writes: [{ kind: 'project', key: `L-${RUN}`, version: 0, data: { id: `L-${RUN}` } }] }), 403, 'forbidden')
		expect('Restore', await ocs(L, 'POST', `/records/project/${P}/restore`, { version: 1, current: 2 }), 403, 'forbidden')
		expect('Verlauf eigene Person', await ocs(L, 'GET', '/records/person/pblead/history'), 200)
		expect('Verlauf fremde Person', await ocs(L, 'GET', '/records/person/pbuser1/history'), 403, 'forbidden')
		expect('Verlauf Kunde', await ocs(L, 'GET', `/records/customer/${C}/history`), 403, 'forbidden')
		expect('GET /status', await ocs(L, 'GET', '/status'), 403, 'forbidden')
		expect('GET /team', await ocs(L, 'GET', '/team'), 403, 'forbidden')
		expect('Lebenszeichen melden', await ocs(L, 'POST', '/status', { app_version: '0.2.0' }), 200)
	}
	head('Rechte: subadmin (pbverw) und admin (pbadmin)')
	{
		for (const [who, name] of [[V, 'pbverw'], [A, 'pbadmin']]) {
			const k = keysOf(await ocs(who, 'GET', '/records'))
			check(`${name}: liest alles`, k.has('person/pbuser1') && k.has(`customer/${C}`) && k.has(`region/${R}`))
			expect(`${name}: Verlauf fremde Person`, await ocs(who, 'GET', '/records/person/pbuser1/history'), 200)
			expect(`${name}: Verlauf Kunde`, await ocs(who, 'GET', `/records/customer/${C}/history`), 200)
			expect(`${name}: GET /status`, await ocs(who, 'GET', '/status'), 200)
			const t = await ocs(who, 'GET', '/team')
			const roles = Object.fromEntries((t.data?.members || []).map((x) => [x.uid, x.role]))
			check(`${name}: GET /team mit members und Rolle`, t.status === 200 && t.data?.slug === 'pb'
				&& JSON.stringify(roles) === JSON.stringify({ pbadmin: 'admin', pblead: 'lead', pbuser1: 'user', pbuser2: 'user', pbverw: 'subadmin' }), t.text)
		}
	}

	// ---------------------------------------------------------------------------
	head('Teamgrenze: Atelier sieht nichts vom Planungsbüro')
	{
		const k = keysOf(await ocs(AA, 'GET', '/records'))
		check('Liste enthält keinen Datensatz von pb', ![`project/${P}`, `customer/${C}`, `region/${R}`, 'person/pbuser1', 'person/pblead', `person/${NOAH}`].some((x) => k.has(x)), [...k])
		const kd = keysOf(await ocs(AA, 'GET', `/records?since=${Math.max(0, rev0 - 1)}`))
		check('auch nicht im Delta', !kd.has(`project/${P}`) && !kd.has('person/pbuser1'))
		expect('pb-Projekt direkt', await ocs(AA, 'GET', `/records/project/${P}`), 404, 'not_found')
		expect('pb-Kunde direkt (lead at)', await ocs(AL, 'GET', `/records/customer/${C}`), 404, 'not_found')
		expect('pb-Person direkt', await ocs(AA, 'GET', '/records/person/pbuser1'), 404, 'not_found')
		expect('Verlauf eines pb-Datensatzes', await ocs(AA, 'GET', `/records/project/${P}/history`), 404, 'not_found')
		expect('Restore eines pb-Datensatzes', await ocs(AA, 'POST', `/records/project/${P}/restore`, { version: 1, current: 2 }), 404, 'not_found')
		expect('DELETE eines pb-Datensatzes', await ocs(AA, 'DELETE', `/records/project/${P}?version=2`), 404, 'not_found')
		const pbRev = (await me(A)).revision
		const atRev = (await me(AA)).revision
		const own = await ocs(AA, 'PUT', `/records/project/${P}`, { version: 0, data: { id: P, name: 'Atelier' } })
		check('gleicher Schlüssel in at ist ein eigener Datensatz (version 1)', own.status === 200 && own.data?.version === 1, own.text)
		check('Schreiben in at zählt nur die Revision von at hoch', own.data?.revision === atRev + 1 && (await me(A)).revision === pbRev, { pbRev, atRev, got: own.data?.revision })
		const pbP = await ocs(A, 'GET', `/records/project/${P}`)
		check('pb-Datensatz unverändert', pbP.data?.version === 2 && pbP.data?.data?.name === 'v2', pbP.text)
		const st = await ocs(AA, 'GET', '/status')
		const uids = (st.data || []).map((x) => x.uid).sort()
		check('GET /status: nur Mitglieder von at', JSON.stringify(uids) === JSON.stringify(['atadmin', 'atlead', 'atuser1']), uids)
		const t = await ocs(AA, 'GET', '/team')
		check('GET /team: at, keine pb-Konten', t.data?.slug === 'at' && !JSON.stringify(t.data?.members).includes('pb'), t.text)
		expect('user at liest keinen at-Kunden', await ocs(AU, 'GET', `/records/customer/${C}`), 403, 'forbidden')
	}

	// ---------------------------------------------------------------------------
	head('Löschen, Grabstein, Delta über since')
	{
		const before = (await me(A)).revision
		const stale = await ocs(A, 'DELETE', `/records/project/${P}?version=1`)
		expect('DELETE mit veralteter Fassung', stale, 409, 'conflict')
		check('… mit current', stale.data?.current?.version === 2, stale.text)
		expect('DELETE ohne version', await ocs(A, 'DELETE', `/records/project/${P}`), 400, 'invalid')
		const del = await ocs(A, 'DELETE', `/records/project/${P}?version=2`)
		expect('DELETE mit passender Fassung', del, 200)
		check('Antwort ist der Grabstein (deleted, data null, version 3)', del.data?.deleted === true && del.data?.data === null && del.data?.version === 3, del.text)
		expect('GET auf Grabstein', await ocs(A, 'GET', `/records/project/${P}`), 404, 'not_found')
		expect('DELETE auf Grabstein', await ocs(A, 'DELETE', `/records/project/${P}?version=3`), 404, 'not_found')
		const delta = await ocs(A, 'GET', `/records?since=${before}`)
		const tomb = delta.data?.records?.find((x) => x.kind === 'project' && x.key === P)
		check('Delta enthält den Grabstein', tomb?.deleted === true && tomb?.data === null, delta.text.slice(0, 400))
		check('Delta nur mit revision > since', (delta.data?.records || []).every((x) => x.revision > before))
		check('revision der Antwort = Revision des Teams', delta.data?.revision === (await me(A)).revision)
		const full = await ocs(A, 'GET', '/records?since=0')
		check('since=0 ohne Grabsteine', !keysOf(full).has(`project/${P}`) && (full.data?.records || []).every((x) => !x.deleted))
		const u = await ocs(U1, 'GET', `/records?since=${before}`)
		check('user bekommt den Grabstein eines lesbaren Projekts', u.data?.records?.some((x) => x.key === P && x.deleted))
		expect('since ungültig', await ocs(A, 'GET', '/records?since=abc'), 400, 'invalid')

		const again = await ocs(A, 'PUT', `/records/project/${P}`, { version: 0, data: { id: P, name: 'neu' } })
		check('neu anlegen auf Grabstein: version = Grabstein + 1', again.status === 200 && again.data?.version === 4, again.text)

		// Grabstein einer Person über accounts
		const NOAH2 = `noah2.${RUN}@example.test`
		await ocs(A, 'PUT', `/records/person/${encodeURIComponent(NOAH2)}`, { version: 0, data: { login: NOAH2, accounts: ['pbuser2'] } })
		const b2 = (await me(U2)).revision
		await ocs(A, 'DELETE', `/records/person/${encodeURIComponent(NOAH2)}?version=1`)
		const d2 = await ocs(U2, 'GET', `/records?since=${b2}`)
		check('Person erfährt vom Grabstein ihres Datensatzes (accounts bleiben)', d2.data?.records?.some((x) => x.key === NOAH2 && x.deleted), d2.text.slice(0, 300))
		const d1 = await ocs(U1, 'GET', `/records?since=${b2}`)
		check('andere user sehen diesen Grabstein nicht', !d1.data?.records?.some((x) => x.key === NOAH2))
	}

	// ---------------------------------------------------------------------------
	head('Verlauf und Wiederherstellen')
	{
		const h = await ocs(A, 'GET', `/records/project/${P}/history`)
		const versions = (h.data || []).map((x) => x.version)
		check('Verlauf neueste zuerst, jede Fassung', JSON.stringify(versions) === JSON.stringify([4, 3, 2, 1]), versions)
		check('Fassung 3 ist der Grabstein', h.data?.[1]?.deleted === true && h.data?.[1]?.data === null)
		check('Fassung 1 mit Inhalt, Wer und Wann', h.data?.[3]?.data?.name === 'Planungsbüro/Ä' && h.data?.[3]?.modified_by === 'pbadmin' && ISO.test(h.data?.[3]?.modified_at))
		const rs = await ocs(V, 'POST', `/records/project/${P}/restore`, { version: 1, current: 4 })
		expect('Fassung 1 wiederherstellen', rs, 200)
		check('… als neue Fassung 5 mit dem alten Inhalt', rs.data?.version === 5 && rs.data?.data?.name === 'Planungsbüro/Ä' && rs.data?.modified_by === 'pbverw', rs.text)
		expect('Restore mit veralteter current', await ocs(V, 'POST', `/records/project/${P}/restore`, { version: 2, current: 4 }), 409, 'conflict')
		expect('Restore eines Grabsteins', await ocs(V, 'POST', `/records/project/${P}/restore`, { version: 3, current: 5 }), 422, 'invalid')
		expect('Restore einer unbekannten Fassung', await ocs(V, 'POST', `/records/project/${P}/restore`, { version: 99, current: 5 }), 404, 'not_found')
		expect('Verlauf eines unbekannten Datensatzes', await ocs(A, 'GET', `/records/project/nie-${RUN}/history`), 404, 'not_found')
		const many = await ocs(A, 'GET', '/records/person/pbuser1/history')
		check('Verlauf höchstens 100', Array.isArray(many.data) && many.data.length <= 100)
	}

	// ---------------------------------------------------------------------------
	head('Batch: alles oder nichts')
	{
		const before = (await me(A)).revision
		const ok = await ocs(A, 'POST', '/records/batch', { writes: [
			{ kind: 'project', key: `B1-${RUN}`, version: 0, data: { id: `B1-${RUN}` } },
			{ kind: 'project', key: `B2-${RUN}`, version: 0, data: { id: `B2-${RUN}` } },
			{ kind: 'project', key: P, version: 5, data: { id: P, name: 'aus Batch' } },
		] })
		expect('drei Schreibungen', ok, 200)
		const revs = (ok.data?.records || []).map((x) => x.revision)
		check('revision = alt + 3, je Schreibung eine eigene', ok.data?.revision === before + 3 && JSON.stringify(revs) === JSON.stringify([before + 1, before + 2, before + 3]), { before, got: ok.data?.revision, revs })

		const mid = (await me(A)).revision
		const bad = await ocs(A, 'POST', '/records/batch', { writes: [
			{ kind: 'project', key: `B3-${RUN}`, version: 0, data: { id: `B3-${RUN}` } },
			{ kind: 'project', key: P, version: 1, data: { id: P, name: 'veraltet' } },
			{ kind: 'project', key: `B1-${RUN}`, version: 1, data: null },
		] })
		expect('ein Konflikt', bad, 409, 'conflict')
		check('conflicts nennt genau den einen, mit current', bad.data?.conflicts?.length === 1 && bad.data?.conflicts?.[0]?.key === P && bad.data?.conflicts?.[0]?.current?.version === 6, bad.text)
		expect('… und nichts geschrieben (neuer Schlüssel fehlt)', await ocs(A, 'GET', `/records/project/B3-${RUN}`), 404, 'not_found')
		expect('… und nichts gelöscht', await ocs(A, 'GET', `/records/project/B1-${RUN}`), 200)
		check('… und die Revision unverändert', (await me(A)).revision === mid)

		const del = await ocs(A, 'POST', '/records/batch', { writes: [{ kind: 'project', key: `B1-${RUN}`, version: 1, data: null }] })
		check('data: null löscht', del.status === 200 && del.data?.records?.[0]?.deleted === true, del.text)
		const gone = await ocs(A, 'POST', '/records/batch', { writes: [{ kind: 'project', key: `nie-${RUN}`, version: 0, data: null }] })
		check('Löschen von Unbekanntem ist ein Konflikt mit current null', gone.status === 409 && gone.data?.conflicts?.[0]?.current === null, gone.text)
		const empty = await ocs(A, 'POST', '/records/batch', { writes: [] })
		check('leere Liste: nichts, Revision bleibt', empty.status === 200 && empty.data?.records?.length === 0 && empty.data?.revision === (await me(A)).revision, empty.text)
		const tooMany = Array.from({ length: 501 }, (_, i) => ({ kind: 'project', key: `M${i}-${RUN}`, version: 0, data: { id: `M${i}-${RUN}` } }))
		expect('501 Schreibungen', await ocs(A, 'POST', '/records/batch', { writes: tooMany }), 413, 'too_large')
		expect('derselbe Datensatz zweimal', await ocs(A, 'POST', '/records/batch', { writes: [
			{ kind: 'project', key: `D-${RUN}`, version: 0, data: { id: `D-${RUN}` } },
			{ kind: 'project', key: `D-${RUN}`, version: 1, data: { id: `D-${RUN}` } },
		] }), 422, 'invalid')
		const inv = await ocs(A, 'POST', '/records/batch', { writes: [{ kind: 'project', key: `E-${RUN}`, version: 0, data: { id: 'falsch' } }] })
		check('ungültige Schreibung nennt ihre Nummer', inv.status === 422 && /Schreibung 1/.test(inv.data?.message || ''), inv.text)
		expect('writes fehlt', await ocs(A, 'POST', '/records/batch', {}), 400, 'invalid')
		const full = await ocs(A, 'POST', '/records/batch', { writes: tooMany.slice(0, 500) })
		check('500 Schreibungen in einem Aufruf', full.status === 200 && full.data?.records?.length === 500, full.status)
	}

	// ---------------------------------------------------------------------------
	head('Parallelität')
	{
		const puts = await Promise.all(Array.from({ length: 20 }, (_, i) =>
			ocs(V, 'PUT', `/records/project/par-${RUN}-${i}`, { version: 0, data: { id: `par-${RUN}-${i}` } })))
		const revs = puts.map((r) => r.data?.revision)
		check('20 gleichzeitige PUTs: alle 200', puts.every((r) => r.status === 200), puts.map((r) => r.status))
		check('… mit 20 verschiedenen Revisionen', new Set(revs).size === 20, revs)
		const same = await Promise.all(Array.from({ length: 10 }, () =>
			ocs(A, 'PUT', `/records/project/same-${RUN}`, { version: 0, data: { id: `same-${RUN}` } })))
		const codes = same.map((r) => r.status).sort()
		check('10 gleichzeitige Neuanlagen desselben Schlüssels: genau eine gewinnt', codes.filter((c) => c === 200).length === 1 && codes.filter((c) => c === 409).length === 9, codes)
	}

}
