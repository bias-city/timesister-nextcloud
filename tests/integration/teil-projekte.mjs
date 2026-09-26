// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Fassung 2: Projekte voll nur für Verwaltung, Admin und die Leitungen des
// Projekts, sonst der Buchungskatalog; die Leitung ist Admin im Projekt.
// Eigene Schlüssel je Lauf, am Ende gelöscht.
import { ADMIN, as, ocs } from './lib.mjs'
import { A, L, NOAH, RUN, U1, U2, check, ensure, expect, head, me } from './harness.mjs'

const CATALOG = ['codes', 'end', 'id', 'mapping', 'name', 'schema', 'start', 'status', 'subprojects']
const isCatalog = (d) => JSON.stringify(Object.keys(d || {}).sort()) === JSON.stringify(CATALOG)
const full = (id, leads) => ({
	schema: 1, id, name: `Projekt ${id}`, codes: ['LP'], subprojects: [{ id: 'a', name: 'A' }],
	start: '2026-01-01', end: null, status: 'aktiv', mapping: { rules: [] },
	description: 'intern', cost_center: 'KS-9', customer: 'stadt', quote: 'O-1',
	leads, budget: [], milestones: [{ date: '2026-06-30', name: 'M1' }],
	budgets: [{ from: '2026-01-01', hours: 10 }], neu_2027: 'rutscht nicht durch',
})
const path = (key) => `/records/project/${encodeURIComponent(key)}`
const inDelta = async (who, since, key) => ((await ocs(who, 'GET', `/records?since=${since}`)).data?.records || []).find((x) => x.key === key)

export async function projekte() {
	const PL = `PL-${RUN}`
	const PF = `PF-${RUN}`
	const pl = `pl${RUN}`
	let tmpLead = false
	try {
		head('Projekte: voll für Leitung und Admin, sonst Buchungskatalog')
		const rev0 = (await me(A)).revision
		expect('Person über accounts (pbuser2)', await ensure(A, 'person', NOAH, { login: NOAH, accounts: ['pbuser2'] }), 200)
		expect('Projekt mit Leitung pblead anlegen (Admin)', await ocs(A, 'PUT', path(PL), { version: 0, data: full(PL, ['pblead']) }), 200)
		expect('fremdes Projekt, Leitung pbverw', await ocs(A, 'PUT', path(PF), { version: 0, data: full(PF, ['pbverw']) }), 200)
		const mk = await ocs(ADMIN, 'POST', '/ocs/v2.php/cloud/users', { userid: pl, password: `Test-2026-${pl}!`, groups: ['pb-team'] })
		tmpLead = mk.status === 200
		const role = await ocs(A, 'PUT', `/team/members/${pl}`, { role: 'lead' })
		check('zweite Leitung ohne Zuweisung angelegt', tmpLead && role.data?.role === 'lead', role.text.slice(0, 200))

		const adm = (await ocs(A, 'GET', path(PL))).data?.data
		check('Admin: voll, mit budgets und neuen Feldern', adm?.budgets?.[0]?.hours === 10 && adm?.neu_2027 && adm?.customer === 'stadt', adm)
		const own = (await ocs(L, 'GET', path(PL))).data?.data
		check('pblead, eigenes Projekt: voll', own?.budgets?.[0]?.hours === 10 && JSON.stringify(own?.leads) === '["pblead"]' && own?.description === 'intern', own)
		const foreign = (await ocs(L, 'GET', path(PF))).data?.data
		check('pblead, fremdes Projekt: nur Katalog', isCatalog(foreign) && foreign.codes?.[0] === 'LP' && foreign.end === null, foreign)
		const u1 = (await ocs(U1, 'GET', path(PL))).data?.data
		check('pbuser1: nur Katalog (Positivliste, neue Felder fehlen)', isCatalog(u1), u1)
		check('pbuser1 im Delta: nur Katalog', isCatalog((await inDelta(U1, rev0, PL))?.data))
		check('zweite Leitung ohne Zuweisung: nur Katalog', isCatalog((await ocs(as(pl), 'GET', path(PL))).data?.data))
		check('pblead im Delta: eigenes voll, fremdes Katalog', (await inDelta(L, rev0, PL))?.data?.budgets?.length === 1 && isCatalog((await inDelta(L, rev0, PF))?.data))

		head('Projekte: Verlauf nur für Admin, Verwaltung und Leitung')
		expect('pbuser1, Verlauf eines Projekts', await ocs(U1, 'GET', `${path(PL)}/history`), 403, 'forbidden')
		expect('zweite Leitung, Verlauf eines fremden Projekts', await ocs(as(pl), 'GET', `${path(PL)}/history`), 403, 'forbidden')
		expect('pblead, Verlauf des fremden Projekts', await ocs(L, 'GET', `${path(PF)}/history`), 403, 'forbidden')
		const h = await ocs(L, 'GET', `${path(PL)}/history`)
		check('pblead, Verlauf des eigenen Projekts: voll', h.status === 200 && h.data?.[0]?.data?.budgets?.length === 1, h.text.slice(0, 200))

		head('Projekte: Leitung ist Admin im Projekt')
		const w = await ocs(L, 'PUT', path(PL), { version: 1, data: { ...full(PL, ['pblead', NOAH]), customer: 'kanton', codes: ['LP', 'LQ'], budgets: [{ from: '2026-01-01', hours: 20 }] } })
		check('pblead ändert Kunde, Code, Budgets und Leitung', w.status === 200 && w.data?.version === 2 && w.data?.modified_by === 'pblead'
			&& w.data?.data?.customer === 'kanton' && w.data?.data?.codes?.length === 2, w.text.slice(0, 300))
		const u2 = (await ocs(U2, 'GET', path(PL))).data?.data
		check('pbuser2 (über seine Person in leads) sieht das volle Projekt', u2?.budgets?.[0]?.hours === 20 && u2?.customer === 'kanton', u2)
		check('pbuser1 weiter nur Katalog', isCatalog((await ocs(U1, 'GET', path(PL))).data?.data))
		expect('veraltete Fassung', await ocs(L, 'PUT', path(PL), { version: 1, data: full(PL, ['pblead']) }), 409, 'conflict')
		expect('id ≠ Schlüssel', await ocs(L, 'PUT', path(PL), { version: 2, data: full('anders', ['pblead']) }), 422, 'invalid')
		expect('pblead ändert ein fremdes Projekt', await ocs(L, 'PUT', path(PF), { version: 1, data: full(PF, ['pbverw', 'pblead']) }), 403, 'forbidden')
		expect('pblead legt ein neues Projekt an', await ocs(L, 'PUT', path(`PN-${RUN}`), { version: 0, data: full(`PN-${RUN}`, ['pblead']) }), 403, 'forbidden')
		expect('pbuser1 ändert das Projekt', await ocs(U1, 'PUT', path(PL), { version: 2, data: full(PL, ['pbuser1']) }), 403, 'forbidden')
		expect('pblead löscht', await ocs(L, 'DELETE', `${path(PL)}?version=2`), 403, 'forbidden')
		expect('pblead per Batch', await ocs(L, 'POST', '/records/batch', { writes: [{ kind: 'project', key: PL, version: 2, data: full(PL, ['pblead']) }] }), 403, 'forbidden')
		expect('pblead stellt wieder her', await ocs(L, 'POST', `${path(PL)}/restore`, { version: 1, current: 2 }), 403, 'forbidden')
		expect('pblead schreibt einen Kunden', await ocs(L, 'PUT', `/records/customer/K2-${RUN}`, { version: 0, data: { id: `K2-${RUN}` } }), 403, 'forbidden')

		head('Projekte: Leitung nimmt sich selbst heraus')
		const rev1 = (await me(L)).revision
		const self = await ocs(L, 'PUT', path(PL), { version: 2, data: full(PL, [NOAH]) })
		check('darf es (geprüft gegen die aktuelle Fassung)', self.status === 200 && self.data?.version === 3, self.text.slice(0, 200))
		check('nächstes Delta: nur noch Katalog', isCatalog((await inDelta(L, rev1, PL))?.data))
		expect('danach kein Schreiben mehr', await ocs(L, 'PUT', path(PL), { version: 3, data: full(PL, ['pblead']) }), 403, 'forbidden')
		check('pbuser2 bleibt Leitung: voll', (await ocs(U2, 'GET', path(PL))).data?.data?.budgets?.length === 1)
	} finally {
		head('Projekte: aufräumen')
		for (const key of [PL, PF]) {
			const cur = await ocs(A, 'GET', path(key))
			if (cur.status === 200) {
				expect(`${key} löschen`, await ocs(A, 'DELETE', `${path(key)}?version=${cur.data.version}`), 200)
			}
		}
		if (tmpLead) {
			expect(`Testkonto ${pl} löschen`, await ocs(ADMIN, 'DELETE', `/ocs/v2.php/cloud/users/${pl}`), 200)
		}
	}
}
