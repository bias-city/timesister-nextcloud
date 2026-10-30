// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Fee models (0.33.0, one list of levels): the capability, the team's
// levels (members read them without rates), the budget's fee fields and
// their bounds, the amount and the level per work package, the addenda in
// money, the project's `level_rates` and the catalog's `booking_levels`,
// the person's level on their own booking in a budget by category
// (`correction_lead_only`, every booking checked before any is written, a
// Lead's own booking beyond their projects), `level` and `level_name` on
// `hours` lines and `bookings[].level` on an invoice, the appendix
// `by_level`, the issue checks of invoices and quotes (`no_levels`,
// `level_rate_missing`, `amount_without_rate`), and the lock of the fee
// model while an invoice bills bookings of the budget – also in a batch and
// on restore – until the invoice is reversed. The drafts' categories are
// stored as sent and read by nobody.
// Needs the bookings in the book (listener on CalDAV PUT or the job through
// TS_OCC); without them the booking and invoice checks are skipped. Leaves
// zb as it found it: settings back, projects, customer, quote, events,
// drafts and corrections removed (issued invoices and the ledger stay).
import { deleteEvent, ensureTimeCalendar, ocs, putEvent, runJob } from './lib.mjs'
import { RUN, check, expect, festschreiben, head, sha256, stornieren } from './harness.mjs'
import { A, L, U1 } from './konten.mjs'

const rpath = (kind, key) => `/records/${kind}/${encodeURIComponent(key)}`
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))
const corrKey = (person, uid) => `${person}+${sha256(uid).slice(0, 32)}`
/** The error carries this code (and field). */
const coded = (r, code, field) => r.data?.code === code && (field === undefined || r.data?.field === field)

/** All entries of the book visible to `who`. */
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

/** The KBOB 2025 categories A–G as levels of the one list (a rate template of the Mac app). */
const KBOB = [['a', 252], ['b', 197], ['c', 170], ['d', 144], ['e', 120], ['f', 109], ['g', 105]]
	.map(([k, rate]) => ({ id: `kat-${k}`, name: `Kategorie ${k.toUpperCase()}`, rates: [{ from: '2025-01-01', rate }] }))

export async function honorar() {
	const PH = `PH-${RUN}`
	const PH2 = `PH2-${RUN}`
	const KH = `KH-${RUN}`
	const CODE = `H${RUN}`.toUpperCase()
	const CODE2 = `HX${RUN}`.toUpperCase()
	const EV1 = `th-${RUN}-1@example.test`
	const EV2 = `th-${RUN}-2@example.test`
	const EV3 = `th-${RUN}-3@example.test`
	const K1 = `inv-${RUN}h1`
	const K2 = `inv-${RUN}h2`
	const Q1 = `q-${sha256(`${RUN}-honorar`).slice(0, 8)}`
	const CK = corrKey('zbuser1', EV1)
	const CK2 = corrKey('zbuser1', EV2)
	const drafts = [K1, K2]
	const Y = 3000 + (parseInt(RUN.slice(-4), 36) % 5000) + 11
	const settings = await ocs(A, 'GET', '/records/setting/settings')
	const base = settings.status === 200 ? settings.data.data : {}
	const baseLevels = Array.isArray(base.levels) ? base.levels : []
	let settingsVersion = settings.status === 200 ? settings.data.version : 0
	const putSettings = async (data) => {
		const r = await ocs(A, 'PUT', rpath('setting', 'settings'), { version: settingsVersion, data })
		if (r.status === 200) {
			settingsVersion = r.data.version
		}
		return r
	}
	let teamSettings = base
	let quoteMade = false
	const budget = (more = {}, wps = null) => ({
		id: 'b1', name: 'Vorprojekt', currency: 'CHF', start: '2026-01-01', end: '2027-12-31',
		fee_model: 'category', mean_rate: null, cap: 30000, cap_mode: 'cap', tolerance: 10,
		milestones: [{ no: 'M1', title: 'Vorprojekt', date: '2026-12-31' }],
		work_packages: wps ?? [
			{ no: '3', name: 'Projektierung', level: 'kat-c' },
			{ no: '31', parent: '3', name: 'Vorprojekt', codes: [`${CODE}.31`], level: 'kat-b', amount: [12000], hours: [60.91370558375635] },
			{ no: '32', parent: '3', name: 'Bauprojekt', codes: [`${CODE}.32`], amount: [15000], hours: [88.23529411764706] },
		],
		...more,
	})
	// A second budget by role: its bookings' level stays the Lead's.
	const B9 = { id: 'b9', name: 'Nebenleistungen', currency: 'CHF', fee_model: 'role', work_packages: [{ no: '9', name: 'Diverses', codes: [`${CODE}.9`], hours: [10], rate: 150 }] }
	const project = (budgets, more = {}) => ({ schema: 1, id: PH, name: `Honorar ${RUN}`, codes: [CODE], status: 'active', leads: ['zblead'], customer: KH, currency: 'CHF', budgets: [...budgets, B9], ...more })
	let projectVersion = 0
	const putProject = async (who, data) => {
		const r = await ocs(who, 'PUT', rpath('project', PH), { version: projectVersion, data })
		if (r.status === 200) {
			projectVersion = r.data.version
		}
		return r
	}
	try {
		head('Fee models: capability and the team’s levels')
		{
			const cap = (await ocs(A, 'GET', '/ocs/v2.php/cloud/capabilities')).data?.capabilities?.timesister
			check('capability fee_models: 1, version 0.33.0, invoice_layout 2, corrections 2', cap?.fee_models === 1 && cap?.version === '0.33.0' && cap?.invoice_layout === 2 && cap?.corrections === 2, cap)
			const bad = await ocs(A, 'PUT', rpath('setting', 'settings'), { version: settingsVersion, data: { ...base, fee_model: 'hoai' } })
			expect('settings with an unknown fee_model', bad, 422, 'invalid')
			check('… fee_model_invalid, field fee_model', coded(bad, 'fee_model_invalid', 'fee_model'), bad.data)
			expect('a level twice', await ocs(A, 'PUT', rpath('setting', 'settings'), { version: settingsVersion, data: { ...base, levels: [...baseLevels, KBOB[0], KBOB[0]] } }), 422, 'invalid')
			expect('the drafts’ category_rates is stored unchecked', await putSettings({ ...base, category_rates: { 'B B': 'x' } }), 200)
			teamSettings = { ...base, fee_model: 'category', levels: [...baseLevels.filter((l) => !String(l?.id).startsWith('kat-')), ...KBOB],
				invoice_number: { pattern: `RH-${RUN}-{no:4}`, per_year: true, start: 1 },
				quote_number: { pattern: `OH-${RUN}-{no:3}`, per_year: true, start: 1 },
				issuer: { name: 'Muster sàrl', street: 'Musterstrasse 12', postcode: '4000', city: 'Basel', country: 'CH', vat_id: 'CHE-123.456.789 MWST', qr: true, accounts: [{ currency: 'CHF', iban: 'CH9300762011623852957', bic: '' }] } }
			expect('Team Admin adds the KBOB categories as levels, the fee model, patterns and issuer', await putSettings(teamSettings), 200)
			const member = (await ocs(U1, 'GET', rpath('setting', 'settings'))).data?.data
			const kat = (s) => (s?.levels || []).filter((l) => String(l.id).startsWith('kat-'))
			check('a member reads the levels without rates (id and name stay)', kat(member).length === 7 && kat(member).every((l) => !('rates' in l) && l.id && l.name) && member.fee_model === 'category', kat(member))
			check('… and no category_rates any more', member && !('category_rates' in member), member)
			check('a Lead reads them with rates', kat((await ocs(L, 'GET', rpath('setting', 'settings'))).data?.data)[1]?.rates?.[0]?.rate === 197)
			check('Team Admin reads them with rates', kat((await ocs(A, 'GET', rpath('setting', 'settings'))).data?.data)[0]?.rates?.[0]?.rate === 252)
			expect('a member does not write the settings', await ocs(U1, 'PUT', rpath('setting', 'settings'), { version: settingsVersion, data: { ...base, levels: [] } }), 403, 'forbidden')
		}

		head('Fee models: the budget’s fields, the amounts and the levels')
		{
			expect('customer', await ocs(A, 'PUT', rpath('customer', KH), { version: 0, data: { schema: 1, id: KH, name: `Kunde ${RUN}`, street: 'Platz 1', postcode: '4001', city: 'Basel', country: 'CH', contacts: [{ id: 'c1', name: 'Lea Kontakt' }] } }), 200)
			const refused = [
				['an unknown fee_model', budget({ fee_model: 'kbob' }), 'fee_model_invalid', 'fee_model'],
				['mean_rate in the category model', budget({ mean_rate: 176 }), 'fee_model_invalid', 'mean_rate'],
				['an unknown cap_mode', budget({ cap_mode: 'pauschal' }), 'cap_mode_invalid', 'cap_mode'],
				['cap_mode without a cap', budget({ cap: null, cap_mode: 'flat' }), 'cap_mode_invalid', 'cap'],
				['tolerance above 100', budget({ tolerance: 150 }), undefined, 'tolerance'],
				['an amount with three decimals', budget({}, [{ no: '1', amount: [1.005] }]), undefined, 'amount'],
				['an amount of its own on a parent', budget({}, [{ no: '3', amount: [5] }, { no: '31', parent: '3', amount: [1] }]), undefined, 'amount'],
				['a level that is no id', budget({}, [{ no: '1', level: 'Kat B' }]), undefined, 'level'],
				['an addendum with amount and hours', budget({ addenda: [{ no: 1, date: '2026-10-20', package: '31', amount: 500, hours: 2 }] }), undefined, undefined],
			]
			for (const [name, b, code, field] of refused) {
				const r = await putProject(A, project([b]))
				expect(name, r, 422, 'invalid')
				check(`… ${code ?? 'no code'}, field ${field ?? '–'}`, r.data?.code === code && r.data?.field === field, r.data)
			}
			const leftover = budget({ addenda: [{ no: 1, date: '2026-10-20', package: '31', amount: 2500, title: 'Mehrleistung' }] })
			leftover.work_packages[2] = { ...leftover.work_packages[2], category: 'B C' }
			const ok = await putProject(A, project([leftover], { level_rates: { 'kat-d': 150 }, category_rates: { 'B B': -1 } }))
			expect('a project in the category model: amounts, levels, cap, tolerance, an addendum in money, its own rate of kat-d', ok, 200)
			const stored = ok.data?.data
			check('… stored as sent, the drafts’ category fields too (unchecked)', stored?.budgets?.[0]?.work_packages?.[1]?.amount?.[0] === 12000 && stored?.budgets?.[0]?.work_packages?.[1]?.level === 'kat-b'
				&& stored?.budgets?.[0]?.cap_mode === 'cap' && stored?.level_rates?.['kat-d'] === 150 && stored?.category_rates?.['B B'] === -1 && stored?.budgets?.[0]?.work_packages?.[2]?.category === 'B C', stored)
			const r1 = await putProject(A, project([budget({ fee_model: 'mean_rate', mean_rate: 176 })], { level_rates: { 'kat-d': 150 } }))
			expect('before anything is billed the model changes freely (mean rate)', r1, 200)
			const r2 = await putProject(A, project([budget()], { level_rates: { 'kat-d': 150 }, booking_levels: [{ code: 'X', level: 'kat-a' }] }))
			expect('… and back to the category model', r2, 200)
			check('a client’s booking_levels is dropped', r2.data?.data && !('booking_levels' in r2.data.data), r2.data?.data)
		}

		head('Fee models: the catalog and the project’s rates')
		{
			const m = (await ocs(U1, 'GET', rpath('project', PH))).data?.data
			check('a member reads the catalog: booking_levels per code of the category budget, no budgets, no level_rates',
				JSON.stringify(m?.booking_levels) === JSON.stringify([
					{ code: `${CODE}.31`, level: 'kat-b', start: '2026-01-01', end: '2027-12-31' },
					{ code: `${CODE}.32`, level: 'kat-c', start: '2026-01-01', end: '2027-12-31' },
				]) && !('budgets' in (m || {})) && !('level_rates' in (m || {})) && !('booking_categories' in (m || {})), m)
			const l = (await ocs(L, 'GET', rpath('project', PH))).data?.data
			check('the project’s Lead reads it whole with level_rates', l?.level_rates?.['kat-d'] === 150 && Array.isArray(l?.budgets), l)
			expect('a second project in the category model that zblead does not lead', await ocs(A, 'PUT', rpath('project', PH2), { version: 0, data: {
				schema: 1, id: PH2, name: `Honorar 2 ${RUN}`, codes: [CODE2], status: 'active', leads: [], customer: KH, currency: 'CHF',
				budgets: [{ id: 'c1', name: 'Studie', currency: 'CHF', fee_model: 'category', work_packages: [{ no: '1', name: 'Studie', codes: [`${CODE2}.1`], level: 'kat-c', amount: [1000], hours: [5.88] }] }] } }), 200)
		}

		head('Fee models: the level of a booking')
		check('zbuser1’s time calendar exists and is named', (await ensureTimeCalendar(U1)) && (await ocs(U1, 'PUT', '/me/time-calendar', { calendar_url: 'zeit-zbuser1' })).status === 200)
		check('zbuser1 books two hours on package 31 with the level kat-b (and a leftover category)', (await putEvent(U1, EV1, ['DTSTART:20261012T080000Z', 'DTEND:20261012T100000Z', `SUMMARY:Vorprojekt #${CODE}.31`, 'X-TIMESISTER-LEVEL:kat-b', 'X-TIMESISTER-CATEGORY:B'])) === 201)
		check('… and one hour on the budget by role', (await putEvent(U1, EV2, ['DTSTART:20261013T080000Z', 'DTEND:20261013T090000Z', `SUMMARY:Diverses #${CODE}.9`])) === 201)
		check('zblead’s time calendar exists and is named', (await ensureTimeCalendar(L)) && (await ocs(L, 'PUT', '/me/time-calendar', { calendar_url: 'zeit-zblead' })).status === 200)
		check('zblead books one hour on the project they do not lead', (await putEvent(L, EV3, ['DTSTART:20261014T080000Z', 'DTEND:20261014T090000Z', `SUMMARY:Studie #${CODE2}.1`])) === 201)
		await sleep(500)
		const all = await book(A)
		if (![EV1, EV2, EV3].every((uid) => all.some((e) => e.event_uid === uid))) {
			runJob('EntryReader')
		}
		const entry = (who, uid) => book(who).then((list) => list.find((e) => e.event_uid === uid))
		const inBook = (await entry(U1, EV1)) !== undefined && (await entry(U1, EV2)) !== undefined && (await entry(L, EV3)) !== undefined
		check('the bookings are in the book', inBook)
		if (!inBook) {
			console.log('    info  the bookings are not in the book (no listener, no TS_OCC) – the booking and invoice checks are skipped')
			return
		}
		{
			const e1 = await entry(U1, EV1)
			check('GET /entries: level kat-b, no category field', e1?.level === 'kat-b' && !('category' in e1), e1)
			const own = await ocs(U1, 'POST', '/entries/correct', { entries: [{ uid: 'zbuser1', event_uid: EV1 }], level: 'kat-d' })
			expect('the person sets the level of their own booking in the category budget', own, 200)
			check('… status ok, level kat-d', own.data?.results?.[0]?.status === 'ok' && own.data?.results?.[0]?.level === 'kat-d', own.data)
			check('… GET /entries says kat-d', (await entry(U1, EV1))?.level === 'kat-d')
			const title = await ocs(U1, 'POST', '/entries/correct', { entries: [{ uid: 'zbuser1', event_uid: EV1 }], title: 'Anders', level: 'kat-d' })
			expect('the person corrects the title', title, 403, 'forbidden')
			check('… correction_lead_only, field title', coded(title, 'correction_lead_only', 'title'), title.data)
			const other = await ocs(U1, 'POST', '/entries/correct', { entries: [{ uid: 'zbuser2', event_uid: EV1 }], level: 'kat-d' })
			expect('the person corrects someone else’s booking', other, 403, 'forbidden')
			check('… correction_lead_only, field uid', coded(other, 'correction_lead_only', 'uid'), other.data)
			const role = await ocs(U1, 'POST', '/entries/correct', { entries: [{ uid: 'zbuser1', event_uid: EV1 }, { uid: 'zbuser1', event_uid: EV2 }], level: 'kat-e' })
			expect('the person sets the level of a booking in the budget by role (with one in the category budget)', role, 403, 'forbidden')
			check('… correction_lead_only, field level', coded(role, 'correction_lead_only', 'level'), role.data)
			check('… checked before any is written: the first booking keeps kat-d', (await entry(U1, EV1))?.level === 'kat-d')
			const gone = await ocs(U1, 'POST', '/entries/correct', { entries: [{ uid: 'zbuser1', event_uid: EV1 }, { uid: 'zbuser1', event_uid: `nope-${RUN}@example.test` }], level: 'kat-d' })
			check('a booking not in the book answers not_found and does not block', gone.status === 200 && gone.data?.results?.[0]?.status === 'ok' && gone.data?.results?.[1]?.status === 'not_found', gone.data)
			const cat = await ocs(U1, 'POST', '/entries/correct', { entries: [{ uid: 'zbuser1', event_uid: EV1 }], category: 'C' })
			expect('a correction with the drafts’ category', cat, 422, 'invalid')
			check('… “category” is not a field of a correction', /“category” is not a field of a correction/.test(cat.data?.message ?? ''), cat.data)
			expect('a level that is no id', await ocs(U1, 'POST', '/entries/correct', { entries: [{ uid: 'zbuser1', event_uid: EV1 }], level: 'Kat D' }), 422, 'invalid')

			// The Lead: the level in the budget by role is the Lead's; their own booking beyond their projects is the person's.
			const leadRole = await ocs(L, 'POST', '/entries/correct', { entries: [{ uid: 'zbuser1', event_uid: EV2 }], level: 'leitung' })
			check('the Lead sets the level of a booking by role in their project', leadRole.status === 200 && leadRole.data?.results?.[0]?.status === 'ok', leadRole.data)
			const leadOwn = await ocs(L, 'POST', '/entries/correct', { entries: [{ uid: 'zblead', event_uid: EV3 }], level: 'kat-d' })
			check('the Lead sets the level of their own booking beyond their projects (category budget)', leadOwn.status === 200 && leadOwn.data?.results?.[0]?.status === 'ok' && leadOwn.data?.results?.[0]?.level === 'kat-d', leadOwn.data)
			const leadOwnTitle = await ocs(L, 'POST', '/entries/correct', { entries: [{ uid: 'zblead', event_uid: EV3 }], title: 'Anders' })
			check('… but not its title: status forbidden', leadOwnTitle.status === 200 && leadOwnTitle.data?.results?.[0]?.status === 'forbidden', leadOwnTitle.data)

			// The record: the person's write keeps the Lead's fields.
			const led = await ocs(L, 'PUT', rpath('correction', CK), { version: 0, data: { person: 'zbuser1', event_uid: EV1, title: 'Vorprojekt Lead' } })
			expect('the Lead corrects the title (record)', led, 200)
			check('… applied', led.data?.data?.status === 'applied', led.data?.data)
			const v1 = led.data?.version ?? 1
			const leadOnly = await ocs(U1, 'PUT', rpath('correction', CK), { version: v1, data: { person: 'zbuser1', event_uid: EV1, title: 'Anders', level: 'kat-e' } })
			expect('the person changes the Lead’s title in the record', leadOnly, 403, 'forbidden')
			check('… correction_lead_only, field title', coded(leadOnly, 'correction_lead_only', 'title'), leadOnly.data)
			const mine = await ocs(U1, 'PUT', rpath('correction', CK), { version: v1, data: { person: 'zbuser1', event_uid: EV1, level: 'kat-e' } })
			expect('the person writes the level into the record', mine, 200)
			check('… applied, by zbuser1, the Lead’s title kept', mine.data?.data?.status === 'applied' && mine.data?.data?.by === 'zbuser1' && mine.data?.data?.title === 'Vorprojekt Lead' && mine.data?.data?.level === 'kat-e', mine.data?.data)
			check('… GET /entries: level kat-e', (await entry(U1, EV1))?.level === 'kat-e')
			const v2 = mine.data?.version ?? 2
			const del = await ocs(U1, 'DELETE', `${rpath('correction', CK)}?version=${v2}`)
			expect('the person deletes a record with the Lead’s title', del, 403, 'forbidden')
			check('… correction_lead_only, field title', coded(del, 'correction_lead_only', 'title'), del.data)
			const foreign = await ocs(U1, 'PUT', rpath('correction', corrKey('zbuser2', EV1)), { version: 0, data: { person: 'zbuser2', event_uid: EV1, level: 'kat-d' } })
			expect('the person writes the record of someone else’s booking', foreign, 403, 'forbidden')
			check('… correction_lead_only, field person', coded(foreign, 'correction_lead_only', 'person'), foreign.data)
			const byRole = await ocs(U1, 'PUT', rpath('correction', CK2), { version: 0, data: { person: 'zbuser1', event_uid: EV2, level: 'kat-d' } })
			expect('the person writes a level for the booking by role (record)', byRole, 403, 'forbidden')
			check('… correction_lead_only, field level', coded(byRole, 'correction_lead_only', 'level'), byRole.data)
			const batch = await ocs(U1, 'POST', '/records/batch', { writes: [
				{ kind: 'correction', key: CK, version: v2, data: { person: 'zbuser1', event_uid: EV1, level: 'kat-f' } },
				{ kind: 'correction', key: CK2, version: 0, data: { person: 'zbuser1', event_uid: EV2, level: 'kat-d' } },
			] })
			expect('the same in a batch after a write the person may do', batch, 403, 'forbidden')
			check('… correction_lead_only, field level, index 1', coded(batch, 'correction_lead_only', 'level') && batch.data?.index === 1, batch.data)
			check('… nothing written: the record and the booking keep kat-e', (await ocs(U1, 'GET', rpath('correction', CK))).data?.version === v2 && (await entry(U1, EV1))?.level === 'kat-e')
			const batchTitle = await ocs(U1, 'POST', '/records/batch', { writes: [{ kind: 'correction', key: CK, version: v2, data: { person: 'zbuser1', event_uid: EV1, title: 'x', level: 'kat-f' } }] })
			expect('the Lead’s title in a batch', batchTitle, 403, 'forbidden')
			check('… correction_lead_only, field title, index 0', coded(batchTitle, 'correction_lead_only', 'title') && batchTitle.data?.index === 0, batchTitle.data)
		}

		head('Fee models: hours lines by level and the invoice issue')
		const draft = (more = {}) => ({
			schema: 1, project: PH, budget: 'b1', from: '2026-10-01', to: '2026-10-31', status: 'draft', number: null,
			bookings: [{ person: 'zbuser1', uid: EV1, start: '2026-10-12T08:00:00', hours: 2, rate: 150, code: `${CODE}.31`, level: 'kat-e' }],
			positions: [{ kind: 'hours', level: 'kat-d', level_name: 'Kategorie D', text: 'Vorprojekt', hours: 2, rate: 150, amount: 300, work_package: 0 }],
			layout: { grouping: 'flat', appendix: ['by_level'], by_level: [{ level: 'kat-d', name: 'Kategorie D', rate: 150, hours: 2, amount: 300 }] },
			greeting: 'Guten Tag', body: 'Zahlbar innert 30 Tagen.', footer: 'Muster · Basel', vat: 8.1, contact: 'c1',
			note: null, created_by: 'zbadmin', created_on: '2026-10-29', issued_on: null, paid_on: null, ...more,
		})
		let number = null
		{
			const noRate = await ocs(A, 'PUT', rpath('invoice', K2), { version: 0, data: draft({ positions: [{ kind: 'hours', level: 'kat-d', text: 'Vorprojekt', hours: 2, amount: 300 }] }) })
			expect('an hours line with a level and without rate', noRate, 422, 'invalid')
			check('… amount_without_rate, field rate', coded(noRate, 'amount_without_rate', 'rate'), noRate.data)
			const name = await ocs(A, 'PUT', rpath('invoice', K2), { version: 0, data: draft({ positions: [{ kind: 'hours', level_name: 'Kategorie D', text: 'Vorprojekt', hours: 2, rate: 150, amount: 300 }] }) })
			expect('a level_name without a level', name, 422, 'invalid')
			check('… field level_name', name.data?.field === 'level_name', name.data)
			expect('the drafts’ position kind category', await ocs(A, 'PUT', rpath('invoice', K2), { version: 0, data: draft({ positions: [{ kind: 'category', category: 'D', text: 'x', hours: 2, rate: 150, amount: 300 }] }) }), 422, 'invalid')
			expect('the drafts’ appendix categories', await ocs(A, 'PUT', rpath('invoice', K2), { version: 0, data: draft({ layout: { grouping: 'flat', appendix: ['categories'] } }) }), 422, 'invalid')
			const unknown = await ocs(A, 'PUT', rpath('invoice', K2), { version: 0, data: draft({ bookings: [], positions: [{ kind: 'hours', level: 'kat-z', text: 'Kategorie Z', hours: 1, rate: 99, amount: 99 }] }) })
			expect('a draft with the level kat-z that nobody rates', unknown, 200)
			const z = await ocs(A, 'POST', `/invoices/${K2}/issue`, { issued_on: `${Y}-02-01` })
			expect('… issuing it', z, 422, 'invalid')
			check('… level_rate_missing, field level, level kat-z', coded(z, 'level_rate_missing', 'level') && z.data?.level === 'kat-z', z.data)
			// A team without levels issues no hours of a budget by level (briefly, then the levels come back).
			expect('the team’s levels emptied', await putSettings({ ...teamSettings, levels: [] }), 200)
			const none = await ocs(A, 'POST', `/invoices/${K2}/issue`, { issued_on: `${Y}-02-01` })
			expect('… issuing hours of the category budget', none, 422, 'invalid')
			check('… no_levels, field levels, budget b1', coded(none, 'no_levels', 'levels') && none.data?.budget === 'b1', none.data)
			expect('the team’s levels back', await putSettings(teamSettings), 200)
			check('… still a draft', (await ocs(A, 'GET', rpath('invoice', K2))).data?.data?.status === 'draft')
			const d = await ocs(A, 'PUT', rpath('invoice', K1), { version: 0, data: draft() })
			expect('a draft with an hours line of kat-d (the project’s rate), bookings[].level and the appendix by_level', d, 200)
			const r = await festschreiben(A, K1, { issued_on: `${Y}-02-01` })
			expect('Team Admin reserves', r.reserve, 200)
			expect('… and closes with the PDF', r.close, 200)
			number = r.issue?.number ?? null
			check('issued with a number', r.record?.data?.status === 'issued' && typeof number === 'string', r.record?.data)
			const fixed = await ocs(A, 'GET', `/invoices/${K1}/fixed`)
			check('fixed: the booking billed in kat-e, the line with level and name, the appendix by_level',
				fixed.data?.hours?.[0]?.level === 'kat-e' && !('category' in (fixed.data?.hours?.[0] ?? {})) && fixed.data?.positions?.[0]?.level === 'kat-d'
				&& fixed.data?.positions?.[0]?.level_name === 'Kategorie D' && fixed.data?.layout?.appendix?.[0] === 'by_level' && fixed.data?.layout?.by_level?.[0]?.level === 'kat-d', fixed.data)
		}

		head('Fee models: the lock')
		{
			const locked = await putProject(A, project([budget({ fee_model: 'role' })], { level_rates: { 'kat-d': 150 } }))
			expect('the model of the billed budget changes', locked, 409, 'conflict')
			check('… fee_model_locked, field fee_model, budget b1, the invoice’s number', coded(locked, 'fee_model_locked', 'fee_model') && locked.data?.budget === 'b1' && locked.data?.invoice === number, locked.data)
			const lead = await putProject(L, project([budget({ fee_model: 'mean_rate', mean_rate: 176 })], { level_rates: { 'kat-d': 150 } }))
			expect('… also for the project’s Lead', lead, 409, 'conflict')
			const amounts = await putProject(A, project([budget({ cap: 32000 }, [
				{ no: '3', name: 'Projektierung', level: 'kat-c' },
				{ no: '31', parent: '3', name: 'Vorprojekt', codes: [`${CODE}.31`], level: 'kat-a', amount: [13000], hours: [51.58730158730159] },
				{ no: '32', parent: '3', name: 'Bauprojekt', codes: [`${CODE}.32`], amount: [15000], hours: [88.23529411764706] },
			]), { id: 'b2', name: 'Bauprojekt', currency: 'CHF', fee_model: 'mean_rate', mean_rate: 176 }], { level_rates: { 'kat-d': 155 } }))
			expect('amounts, cap, a package’s level, the rates and a new budget stay writable', amounts, 200)
			const batch = await ocs(A, 'POST', '/records/batch', { writes: [{ kind: 'project', key: PH, version: projectVersion, data: project([budget({ fee_model: 'role' })]) }] })
			expect('the model change in a batch', batch, 409, 'conflict')
			check('… fee_model_locked, index 0, budget b1', coded(batch, 'fee_model_locked', 'fee_model') && batch.data?.index === 0 && batch.data?.budget === 'b1', batch.data)
			const hist = (await ocs(A, 'GET', `${rpath('project', PH)}/history`)).data || []
			const meanVersion = hist.find((h) => h.data?.budgets?.[0]?.fee_model === 'mean_rate')?.version
			const restore = await ocs(A, 'POST', `${rpath('project', PH)}/restore`, { version: meanVersion ?? 1, current: projectVersion })
			expect('restoring the version in the mean rate model', restore, 409, 'conflict')
			check('… fee_model_locked', coded(restore, 'fee_model_locked', 'fee_model'), restore.data)
			const corr = await ocs(U1, 'PUT', rpath('correction', CK), { version: (await ocs(U1, 'GET', rpath('correction', CK))).data?.version ?? 2, data: { person: 'zbuser1', event_uid: EV1, level: 'kat-g' } })
			check('the person’s level correction of the billed booking: locked', corr.status === 200 && corr.data?.data?.status === 'locked', corr.data)

			const rv = await stornieren(A, K1, { kind: 'reversal', reason: 'Honorarmodell', issued_on: `${Y}-02-02` })
			expect('the invoice is reversed', rv.close ?? rv.reserve, 200)
			const free = await putProject(A, project([budget({ fee_model: 'role' })]))
			expect('after the reversal the model changes again', free, 200)
		}

		head('Fee models: the quote is complete in money')
		{
			const quote = (budgets) => ({
				schema: 1, name: `Offerte ${RUN}`, status: 'draft', customer: KH, project: null, project_draft: { code: `Q${RUN}`.toUpperCase(), name: 'Schulhaus Muster' }, number: null,
				date: `${Y}-01-10`, valid_until: null, accepted_on: null, created_on: '2026-10-29', leads: ['zblead'], currency: 'CHF', budgets, note: null, created_by: 'zbadmin',
			})
			const wp = (more = {}) => ({ no: '1', name: 'Konzept', amount: [5000], hours: [0], ...more })
			let v = 0
			const put = async (budgets) => {
				const r = await ocs(A, 'PUT', rpath('quote', Q1), { version: v, data: quote(budgets) })
				if (r.status === 200) {
					v = r.data.version
					quoteMade = true
				}
				return r
			}
			expect('a draft quote in the mean rate model without its rate', await put([{ id: 'bq1', name: 'Vorprojekt', currency: 'CHF', fee_model: 'mean_rate', mean_rate: null, work_packages: [wp()] }]), 200)
			const m = await ocs(L, 'POST', `/quotes/${Q1}/issue`, { date: `${Y}-01-10` })
			expect('… issuing it', m, 422, 'invalid')
			check('… amount_without_rate, field mean_rate, budget bq1', coded(m, 'amount_without_rate', 'mean_rate') && m.data?.budget === 'bq1', m.data)
			expect('the category model, a package without level (a leftover category is no level)', await put([{ id: 'bq1', name: 'Vorprojekt', currency: 'CHF', fee_model: 'category', work_packages: [wp({ category: 'B' })] }]), 200)
			const c = await ocs(L, 'POST', `/quotes/${Q1}/issue`, { date: `${Y}-01-10` })
			expect('… issuing it', c, 422, 'invalid')
			check('… amount_without_rate, field level, package 1', coded(c, 'amount_without_rate', 'level') && c.data?.package === '1', c.data)
			expect('the level kat-z that nobody rates', await put([{ id: 'bq1', name: 'Vorprojekt', currency: 'CHF', fee_model: 'category', work_packages: [wp({ level: 'kat-z' })] }]), 200)
			const z = await ocs(L, 'POST', `/quotes/${Q1}/issue`, { date: `${Y}-01-10` })
			expect('… issuing it', z, 422, 'invalid')
			check('… level_rate_missing, level kat-z, budget bq1, package 1', coded(z, 'level_rate_missing', 'level') && z.data?.level === 'kat-z' && z.data?.budget === 'bq1' && z.data?.package === '1', z.data)
			expect('the level kat-b and an hours-led package', await put([{ id: 'bq1', name: 'Vorprojekt', currency: 'CHF', fee_model: 'category', cap: 6000, cap_mode: 'flat',
				work_packages: [wp({ level: 'kat-b' }), { no: '2', name: 'Alt', hours: [10], rate: 150 }] }]), 200)
			expect('the team’s levels emptied', await putSettings({ ...teamSettings, levels: [] }), 200)
			const none = await ocs(L, 'POST', `/quotes/${Q1}/issue`, { date: `${Y}-01-10`, amount: 6000 })
			expect('… issuing the quote', none, 422, 'invalid')
			check('… no_levels, field levels, budget bq1', coded(none, 'no_levels', 'levels') && none.data?.budget === 'bq1', none.data)
			expect('the team’s levels back', await putSettings(teamSettings), 200)
			const ok = await ocs(L, 'POST', `/quotes/${Q1}/issue`, { date: `${Y}-01-10`, amount: 6000 })
			expect('… the quote’s Lead issues it', ok, 200)
			check('… quoted with a number', ok.data?.data?.status === 'quoted' && new RegExp(`^OH-${RUN}-\\d{3}$`).test(ok.data?.data?.number ?? ''), ok.data?.data)
		}
	} finally {
		head('Fee models: cleanup')
		for (const k of drafts) {
			const cur = await ocs(A, 'GET', rpath('invoice', k))
			if (cur.status === 200 && cur.data?.data?.status === 'issuing') {
				await ocs(A, 'POST', `/invoices/${k}/issue/cancel`, {})
			}
			const again = await ocs(A, 'GET', rpath('invoice', k))
			if (again.status === 200 && again.data?.data?.status === 'draft') {
				await ocs(A, 'DELETE', `${rpath('invoice', k)}?version=${again.data.version}`)
			}
		}
		for (const key of [CK, CK2]) {
			const corr = await ocs(A, 'GET', rpath('correction', key))
			if (corr.status === 200 && corr.data?.deleted !== true) {
				await ocs(A, 'DELETE', `${rpath('correction', key)}?version=${corr.data.version}`)
			}
		}
		if (quoteMade) {
			const q = await ocs(A, 'GET', rpath('quote', Q1))
			if (q.status === 200) {
				await ocs(A, 'DELETE', `${rpath('quote', Q1)}?version=${q.data.version}`)
			}
		}
		await deleteEvent(U1, EV1)
		await deleteEvent(U1, EV2)
		await deleteEvent(L, EV3)
		for (const [kind, key] of [['project', PH], ['project', PH2], ['customer', KH]]) {
			const cur = await ocs(A, 'GET', rpath(kind, key))
			if (cur.status === 200) {
				await ocs(A, 'DELETE', `${rpath(kind, key)}?version=${cur.data.version}`)
			}
		}
		const now = await ocs(A, 'GET', rpath('setting', 'settings'))
		if (now.status === 200) {
			await ocs(A, 'PUT', rpath('setting', 'settings'), { version: now.data.version, data: base })
		}
		check('cleanup done', true)
	}
}
