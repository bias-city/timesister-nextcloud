// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Jobs (0.6.0, market 0.7.0, trimmed for recipients 0.7.1): offer, rights, volume, overlap, who sees what
// (records and delta), counter-proposal and its answer, accept – first
// wins –, decline, the market (all decline, a counter-proposal lapses or is
// taken, races), return, Done by hand and by the reported hours, paid,
// change, delete, notifications. Own project and keys per run; everything
// deleted at the end.
import { ADMIN, ocs } from './lib.mjs'
import { A, AA, L, RUN, U1, U2, check, expect, head, me } from './harness.mjs'

const PJ = `PJ-${RUN}`
/** A project of the team that pblead does not lead (made here, not assumed). */
const PX = `PX-${RUN}`
const key = (n) => `j${n}-${RUN}`
const path = (k) => `/jobs/${encodeURIComponent(k)}`
const get = (who, k) => ocs(who, 'GET', `/records/job/${encodeURIComponent(k)}`)
const inDelta = async (who, since, k) => ((await ocs(who, 'GET', `/records?since=${since}`)).data?.records || []).find((x) => x.kind === 'job' && x.key === k)
const notes = async (who) => ((await ocs(who, 'GET', '/ocs/v2.php/apps/notifications/api/v2/notifications')).data || [])
	.filter((n) => n.app === 'timesister' && n.object_type === 'job')
const noteFor = async (who, k) => (await notes(who)).find((n) => n.object_id === k)
const clearNotes = async (who) => {
	for (const n of await notes(who)) {
		await ocs(who, 'DELETE', `/ocs/v2.php/apps/notifications/api/v2/notifications/${n.notification_id}`)
	}
}
const offer = (over = {}) => ({
	project: PJ, code: 'PJ.1', budget: 'b1', work_package: 'AP1', hours: 30,
	start: '2026-10-01', end: '2026-10-31', description: 'Basics', recipients: ['pbuser1'], ...over,
})
const project = {
	schema: 1, id: PJ, name: `Jobs ${RUN}`, codes: ['PJ'],
	subprojects: [{ code: 'PJ.1', name: 'Analysis' }, { code: 'PJ.2', name: 'Talks' }],
	leads: ['pblead'],
	budgets: [{ id: 'b1', name: 'Base', milestones: [{ no: 'M1', date: '2026-12-31' }], work_packages: [
		{ no: 'AP1', name: 'Basics', codes: ['PJ.1'], hours: [40] },
		{ no: 'AP2', name: 'Talks', codes: ['PJ.2'], hours: [30] },
	] }],
}

export async function jobs() {
	const made = []
	try {
		head('Jobs: capability, project, rights to offer')
		const caps = await ocs(U1, 'GET', '/ocs/v1.php/cloud/capabilities?format=json')
		check('capability jobs: 4', caps.data?.capabilities?.timesister?.jobs === 4, caps.data?.capabilities?.timesister)
		expect('project with lead pblead and a budget', await ocs(A, 'PUT', `/records/project/${PJ}`, { version: 0, data: project }), 200)
		expect('a project without pblead', await ocs(A, 'PUT', `/records/project/${PX}`, { version: 0, data: {
			schema: 1, id: PX, name: `Other ${RUN}`, codes: ['PX'], subprojects: [{ code: 'PX.1', name: 'One' }], leads: [] } }), 200)
		for (const u of [L, U1, U2]) {
			await clearNotes(u)
		}
		const rev0 = (await me(U1)).revision
		expect('a User offers', await ocs(U1, 'PUT', path(key(0)), offer()), 403, 'forbidden')
		expect('a Lead offers for a project they do not lead', await ocs(L, 'PUT', path(key(0)), offer({ project: PX, code: 'PX.1', budget: null, work_package: null })), 403, 'forbidden')
		expect('to an account of another team', await ocs(L, 'PUT', path(key(0)), offer({ recipients: ['atuser1'] })), 422, 'invalid')
		expect('unknown project', await ocs(L, 'PUT', path(key(0)), offer({ project: `nope-${RUN}` })), 422, 'invalid')
		const wrongCode = await ocs(L, 'PUT', path(key(0)), offer({ code: 'PJ.2' }))
		check('code outside the work package → 422 rule code', wrongCode.status === 422 && wrongCode.data?.rule === 'code', wrongCode.text.slice(0, 200))
		expect('end before start', await ocs(L, 'PUT', path(key(0)), offer({ end: '2026-09-01' })), 422, 'invalid')
		expect('key too long', await ocs(L, 'PUT', path('k'.repeat(65)), offer()), 400, 'invalid')

		head('Jobs: offer and who sees it')
		const j1 = await ocs(L, 'PUT', path(key(1)), offer())
		made.push(key(1))
		check('pblead offers J1 to pbuser1', j1.status === 200 && j1.data?.kind === 'job' && j1.data?.version === 1
			&& j1.data?.data?.state === 'offered' && j1.data?.data?.sender === 'pblead' && JSON.stringify(j1.data?.data?.codes) === '["PJ.1"]'
			&& j1.data?.data?.title === 'Basics' && j1.data?.data?.names?.pbuser1 === 'Mia Muster', j1.text.slice(0, 300))
		const again = await ocs(L, 'PUT', path(key(1)), offer())
		check('the same offer again: as it is', again.status === 200 && again.data?.version === 1 && again.data?.revision === j1.data?.revision, again.text.slice(0, 200))
		expect('another offer with the same key', await ocs(L, 'PUT', path(key(1)), offer({ hours: 31 })), 409, 'conflict')
		check('pbuser1 reads it', (await get(U1, key(1))).data?.data?.hours === 30)
		check('… and has it in the delta', (await inDelta(U1, rev0, key(1)))?.data?.state === 'offered')
		expect('pbuser2 does not see it', await get(U2, key(1)), 404, 'not_found')
		check('… not even in the delta', !(await inDelta(U2, rev0, key(1))))
		check('Team Admin sees it', (await get(A, key(1))).status === 200)
		expect('another team does not', await get(AA, key(1)), 404, 'not_found')
		expect('as a record: PUT', await ocs(A, 'PUT', `/records/job/${key(1)}`, { version: 1, data: { id: key(1) } }), 400, 'invalid')
		expect('as a record: batch', await ocs(A, 'POST', '/records/batch', { writes: [{ kind: 'job', key: key(1), version: 1, data: null }] }), 400, 'invalid')
		expect('as a record: DELETE', await ocs(A, 'DELETE', `/records/job/${key(1)}?version=1`), 400, 'invalid')
		const n1 = await noteFor(U1, key(1))
		check('pbuser1 is notified: new offer', n1?.subject === 'Lea Planer offers you a Job: Basics', n1)

		head('Jobs: volume and overlap')
		const vol = await ocs(L, 'PUT', path(key(2)), offer({ hours: 20, recipients: ['pbuser2'] }))
		check('AP1 has 10 h left → 422 rule volume', vol.status === 422 && vol.data?.rule === 'volume' && vol.data?.free === 10, vol.text.slice(0, 300))
		expect('10 h fit', await ocs(L, 'PUT', path(key(2)), offer({ hours: 10, recipients: ['pbuser2'] })), 200)
		made.push(key(2))
		const dup = await ocs(L, 'PUT', path(key(3)), offer({ budget: null, work_package: null, hours: 5, start: '2026-10-20', end: '2026-11-10' }))
		check('same code, overlapping period, same person → 422 rule overlap', dup.status === 422 && dup.data?.rule === 'overlap'
			&& dup.data?.user === 'pbuser1' && /Mia Muster/.test(dup.data?.message || ''), dup.text.slice(0, 300))
		expect('without the overlap it is fine', await ocs(L, 'PUT', path(key(3)), offer({ budget: null, work_package: null, hours: 5, start: '2026-11-01', end: '2026-11-10' })), 200)
		made.push(key(3))

		head('Jobs: counter-proposal')
		const c1 = await ocs(U1, 'POST', `${path(key(1))}/counter`, { hours: 36, end: '2026-11-15', note: 'more time' })
		check('pbuser1 counter-proposes', c1.status === 200 && c1.data?.data?.state === 'counter' && c1.data?.data?.counters?.[0]?.hours === 36, c1.text.slice(0, 300))
		check('pblead is notified', (await noteFor(L, key(1)))?.subject === 'Mia Muster sent a counter-proposal for the Job “Basics”')
		check('pbuser1\'s offer notification is gone', !(await noteFor(U1, key(1))))
		expect('accepting it now is not possible', await ocs(U1, 'POST', `${path(key(1))}/accept`), 409, 'conflict')
		expect('pbuser1 cannot answer their own', await ocs(U1, 'POST', `${path(key(1))}/accept-counter`), 403, 'forbidden')
		const tooMuch = await ocs(L, 'POST', `${path(key(1))}/accept-counter`)
		check('accepting 36 h exceeds AP1 → 422 volume', tooMuch.status === 422 && tooMuch.data?.rule === 'volume', tooMuch.text.slice(0, 200))
		expect('changing while it waits', await ocs(L, 'POST', `${path(key(1))}/change`, { hours: 20 }), 409, 'conflict')
		const rej = await ocs(L, 'POST', `${path(key(1))}/reject-counter`)
		check('pblead rejects it', rej.status === 200 && rej.data?.data?.state === 'rejected', rej.text.slice(0, 200))
		expect('pbuser1 no longer sees it', await get(U1, key(1)), 404, 'not_found')
		check('… the delta says gone', (await inDelta(U1, rev0, key(1)))?.deleted === true)
		check('pbuser1 is notified: rejected', (await noteFor(U1, key(1)))?.subject === 'Lea Planer rejected your counter-proposal for “Basics”')
		const re = await ocs(L, 'POST', `${path(key(1))}/change`, { recipients: ['pbuser1'], hours: 30 })
		check('offered again', re.status === 200 && re.data?.data?.state === 'offered' && re.data?.data?.counters?.length === 0, re.text.slice(0, 200))
		await ocs(U1, 'POST', `${path(key(1))}/counter`, { hours: 28, note: 'a bit less' })
		const ok = await ocs(L, 'POST', `${path(key(1))}/accept-counter`)
		check('the second counter-proposal is taken: In Progress with 28 h', ok.status === 200 && ok.data?.data?.state === 'in_progress'
			&& ok.data?.data?.assignee === 'pbuser1' && ok.data?.data?.hours === 28, ok.text.slice(0, 300))
		check('pbuser1 is notified: accepted', (await noteFor(U1, key(1)))?.subject === 'Lea Planer accepted your counter-proposal for “Basics”')

		head('Jobs: accept – the first wins')
		const market = await ocs(L, 'PUT', path(key(4)), offer({ work_package: 'AP2', code: 'PJ.2', hours: 10, start: '2026-12-01', end: '2026-12-20', recipients: ['pbuser1', 'pbuser2'] }))
		made.push(key(4))
		check('offered to two', market.status === 200 && market.data?.data?.recipients?.length === 2)
		const [x, y] = await Promise.all([ocs(U1, 'POST', `${path(key(4))}/accept`), ocs(U2, 'POST', `${path(key(4))}/accept`)])
		const won = [x, y].filter((r) => r.status === 200)
		const lost = [x, y].filter((r) => r.status !== 200)
		check('exactly one gets it, the other 409 conflict', won.length === 1 && lost.length === 1 && lost[0].data?.error === 'conflict', `${x.status} ${y.status} ${lost[0]?.text.slice(0, 200)}`)
		const winner = won[0]?.data?.data?.assignee
		const loser = winner === 'pbuser1' ? U2 : U1
		expect('the other no longer reads it', await get(loser, key(4)), 404, 'not_found')
		check('… and has it as gone in the delta', (await inDelta(loser, rev0, key(4)))?.deleted === true)
		const winnerWho = winner === 'pbuser1' ? U1 : U2
		const twice = await ocs(winnerWho, 'POST', `${path(key(4))}/accept`)
		check('accepting again changes nothing', twice.status === 200 && twice.data?.version === won[0]?.data?.version, twice.text.slice(0, 200))
		const ret = await ocs(winnerWho, 'POST', `${path(key(4))}/return`, { note: 'no time' })
		check('returned', ret.status === 200 && ret.data?.deleted === true, ret.text.slice(0, 200))
		const back = await get(L, key(4))
		check('pblead sees it returned', back.data?.data?.state === 'returned' && back.data?.data?.log?.at(-1)?.note === 'no time')
		check('pblead is notified: returned', /returned the Job “Talks”/.test((await noteFor(L, key(4)))?.subject || ''))

		head('Jobs: market – all decline')
		const revM = (await me(U1)).revision
		const mkt = (n, month, over = {}) => offer({ budget: null, work_package: null, code: 'PJ.2', hours: 8,
			start: `2027-${month}-01`, end: `2027-${month}-26`, recipients: ['pbuser1', 'pbuser2'], title: `Market ${n}`, ...over })
		expect('market M6 to pbuser1 and pbuser2', await ocs(L, 'PUT', path(key(6)), mkt(6, '02')), 200)
		made.push(key(6))
		const d6 = await ocs(U1, 'POST', `${path(key(6))}/decline`)
		check('pbuser1 declines: gone for them', d6.status === 200 && d6.data?.deleted === true, d6.text.slice(0, 200))
		const l6 = (await get(L, key(6))).data?.data
		check('… still offered, declined by pbuser1', l6?.state === 'offered' && JSON.stringify(l6?.declined_by) === '["pbuser1"]', JSON.stringify(l6).slice(0, 200))
		check('pbuser2 still has it', (await get(U2, key(6))).data?.data?.state === 'offered')
		check('pbuser1 has the tombstone in the delta', (await inDelta(U1, revM, key(6)))?.deleted === true)
		await ocs(U2, 'POST', `${path(key(6))}/decline`)
		const a6 = (await get(L, key(6))).data?.data
		check('pbuser2 declines too: declined by all', a6?.state === 'declined' && a6?.declined_by?.length === 2, JSON.stringify(a6).slice(0, 200))

		head('Jobs: market – a counter-proposal lapses')
		expect('market M7', await ocs(L, 'PUT', path(key(7)), mkt(7, '03')), 200)
		made.push(key(7))
		const c7 = await ocs(U1, 'POST', `${path(key(7))}/counter`, { hours: 10, note: 'two more' })
		check('pbuser1 counter-proposes: the market stays open', c7.status === 200 && c7.data?.data?.state === 'offered'
			&& c7.data?.data?.counters?.[0]?.by === 'pbuser1' && c7.data?.data?.counters?.[0]?.hours === 10, c7.text.slice(0, 300))
		check('pblead is notified: counter-proposal', /counter-proposal for the Job “Market 7”/.test((await noteFor(L, key(7)))?.subject || ''))
		check('pbuser2 still has it in the Inbox', (await get(U2, key(7))).data?.data?.state === 'offered')
		expect('pbuser1 cannot accept the offer now', await ocs(U1, 'POST', `${path(key(7))}/accept`), 409, 'conflict')
		expect('pblead cannot change it while it waits', await ocs(L, 'POST', `${path(key(7))}/change`, { hours: 9 }), 409, 'conflict')
		const t7 = await ocs(U2, 'POST', `${path(key(7))}/accept`)
		check('pbuser2 accepts first: theirs with 8 h', t7.status === 200 && t7.data?.data?.assignee === 'pbuser2' && t7.data?.data?.hours === 8, t7.text.slice(0, 200))
		const l7 = (await get(L, key(7))).data?.data
		check('… the counter-proposal lapsed', l7?.counters?.[0]?.answer === 'lapsed' && JSON.stringify(l7?.log?.at(-1)?.lapsed) === '["pbuser1"]', JSON.stringify(l7?.counters))
		check('pbuser1 is notified: lapsed', (await noteFor(U1, key(7)))?.subject === 'The Job “Market 7” has gone to someone else – your counter-proposal has lapsed', await noteFor(U1, key(7)))
		check('pblead has nothing left to answer', !(await noteFor(L, key(7))))
		expect('pbuser1 no longer sees it', await get(U1, key(7)), 404, 'not_found')
		check('… the delta says gone', (await inDelta(U1, revM, key(7)))?.deleted === true)
		const late = await ocs(L, 'POST', `${path(key(7))}/accept-counter`, { by: 'pbuser1' })
		check('accepting the lapsed counter-proposal → 409', late.status === 409 && /lapsed/.test(late.data?.message || ''), late.text.slice(0, 200))

		head('Jobs: market – the counter-proposal is taken')
		expect('market M8', await ocs(L, 'PUT', path(key(8)), mkt(8, '04')), 200)
		made.push(key(8))
		await ocs(U1, 'POST', `${path(key(8))}/counter`, { hours: 6, note: 'less' })
		const ok8 = await ocs(L, 'POST', `${path(key(8))}/accept-counter`)
		check('pblead takes it (the only one): pbuser1 has it with 6 h', ok8.status === 200 && ok8.data?.data?.state === 'in_progress'
			&& ok8.data?.data?.assignee === 'pbuser1' && ok8.data?.data?.hours === 6, ok8.text.slice(0, 300))
		expect('pbuser2 no longer sees it', await get(U2, key(8)), 404, 'not_found')
		check('… the delta says gone', (await inDelta(U2, revM, key(8)))?.deleted === true)
		const late8 = await ocs(U2, 'POST', `${path(key(8))}/accept`)
		check('pbuser2 accepts too late → 409 someone else', late8.status === 409 && /Someone else/.test(late8.data?.message || ''), late8.text.slice(0, 200))
		check('pbuser1 is notified: accepted', /accepted your counter-proposal/.test((await noteFor(U1, key(8)))?.subject || ''))
		check('pbuser2\'s offer notification is gone', !(await noteFor(U2, key(8))))

		head('Jobs: market – the sender and a recipient at the same time')
		expect('market M9', await ocs(L, 'PUT', path(key(9)), mkt(9, '05')), 200)
		made.push(key(9))
		await ocs(U1, 'POST', `${path(key(9))}/counter`, { hours: 7 })
		const [s9, u9] = await Promise.all([ocs(L, 'POST', `${path(key(9))}/accept-counter`, { by: 'pbuser1' }), ocs(U2, 'POST', `${path(key(9))}/accept`)])
		check('exactly one wins, the other 409', [s9, u9].filter((r) => r.status === 200).length === 1 && [s9, u9].filter((r) => r.status === 409).length === 1, `${s9.status} ${u9.status}`)
		const l9 = (await get(L, key(9))).data?.data
		check('… and the Job says who', s9.status === 200
			? l9?.assignee === 'pbuser1' && l9?.hours === 7 && l9?.counters?.[0]?.answer === 'accepted'
			: l9?.assignee === 'pbuser2' && l9?.hours === 8 && l9?.counters?.[0]?.answer === 'lapsed', JSON.stringify(l9).slice(0, 300))

		head('Jobs: market – several counter-proposals')
		expect('market M10', await ocs(L, 'PUT', path(key(10)), mkt(10, '06')), 200)
		made.push(key(10))
		await ocs(U1, 'POST', `${path(key(10))}/counter`, { hours: 5 })
		const b10 = await ocs(U2, 'POST', `${path(key(10))}/counter`, { end: '2027-06-30', note: 'later' })
		check('both counter-propose: it waits for the sender', b10.status === 200 && b10.data?.data?.state === 'counter' && b10.data?.data?.counters?.length === 2, b10.text.slice(0, 300))
		// 0.7.1: whoever does not manage the Job reads only their own counter-proposal.
		const own = (d, by) => (d?.counters || []).filter((c) => c.by === by)
		const g1 = (await get(U1, key(10))).data?.data
		check('pbuser1 reads their own counter-proposal and only that another waits',
			own(g1, 'pbuser1').length === 1 && own(g1, 'pbuser1')[0].hours === 5 && g1?.counters?.length === 2
			&& JSON.stringify(g1?.counters?.find((c) => !c.by)) === '{"other":true}', JSON.stringify(g1?.counters))
		check('… nothing of pbuser2\'s: no note, no end, not in the log', !/later|2027-06-30/.test(JSON.stringify(g1))
			&& !(g1?.log || []).some((e) => e.action === 'counter' && e.by === 'pbuser2'), JSON.stringify(g1?.log).slice(0, 300))
		const g2 = (await get(U2, key(10))).data?.data
		check('pbuser2 likewise: own with the note, pbuser1\'s as “other”', own(g2, 'pbuser2')[0]?.note === 'later'
			&& !own(g2, 'pbuser1').length && !JSON.stringify(g2?.log || []).includes('"hours":5'), JSON.stringify(g2?.counters))
		check('the answer to pbuser2\'s counter-proposal is trimmed as well', own(b10.data?.data, 'pbuser2').length === 1
			&& !own(b10.data?.data, 'pbuser1').length, JSON.stringify(b10.data?.data?.counters))
		const d1 = await inDelta(U1, 0, key(10))
		check('… and in the delta', d1 && !own(d1.data, 'pbuser2').length && !/later/.test(JSON.stringify(d1.data)), JSON.stringify(d1?.data?.counters))
		const gl = (await get(L, key(10))).data?.data
		check('the sender reads both, with the note', own(gl, 'pbuser1').length === 1 && own(gl, 'pbuser2')[0]?.note === 'later', JSON.stringify(gl?.counters))
		check('a Team Admin reads both', ((await get(A, key(10))).data?.data?.counters || []).filter((c) => c.by).length === 2)
		expect('the history only for those who manage it (pbuser1: 403)', await ocs(U1, 'GET', `/records/job/${encodeURIComponent(key(10))}/history`), 403, 'forbidden')
		expect('… the sender reads it', await ocs(L, 'GET', `/records/job/${encodeURIComponent(key(10))}/history`), 200)
		const which = await ocs(L, 'POST', `${path(key(10))}/accept-counter`)
		check('accepting without saying whose → 422', which.status === 422, which.text.slice(0, 200))
		const r10 = await ocs(L, 'POST', `${path(key(10))}/reject-counter`, { by: 'pbuser2' })
		check('pbuser2\'s is rejected; pbuser1\'s still waits', r10.status === 200 && r10.data?.data?.state === 'counter'
			&& r10.data?.data?.counters?.find((c) => c.by === 'pbuser2')?.answer === 'rejected', r10.text.slice(0, 300))
		expect('pbuser2 no longer sees it', await get(U2, key(10)), 404, 'not_found')
		check('pbuser2 is notified: rejected', /rejected your counter-proposal/.test((await noteFor(U2, key(10)))?.subject || ''))
		expect('rejecting pbuser2\'s again: as it is', await ocs(L, 'POST', `${path(key(10))}/reject-counter`, { by: 'pbuser2' }), 200)
		const a10 = await ocs(L, 'POST', `${path(key(10))}/accept-counter`, { by: 'pbuser1' })
		check('pbuser1\'s is accepted: 5 h', a10.status === 200 && a10.data?.data?.assignee === 'pbuser1' && a10.data?.data?.hours === 5, a10.text.slice(0, 200))
		expect('“by” not a string', await ocs(L, 'POST', `${path(key(10))}/accept-counter`, { by: 5 }), 422, 'invalid')

		head('Jobs: decline')
		const d = await ocs(L, 'PUT', path(key(5)), offer({ work_package: 'AP2', code: 'PJ.2', hours: 5, start: '2027-01-04', end: '2027-01-29', recipients: ['pbuser2'] }))
		made.push(key(5))
		check('offered to pbuser2', d.status === 200)
		const dec = await ocs(U2, 'POST', `${path(key(5))}/decline`)
		check('pbuser2 declines: gone for them', dec.status === 200 && dec.data?.deleted === true, dec.text.slice(0, 200))
		check('… declined for pblead', (await get(L, key(5))).data?.data?.state === 'declined' && (await get(L, key(5))).data?.data?.declined_by?.[0] === 'pbuser2')
		check('declining again: fine', (await ocs(U2, 'POST', `${path(key(5))}/decline`)).status === 200)

		head('Jobs: progress, Done, paid')
		const p1 = await ocs(U1, 'POST', '/jobs/progress', { jobs: { [key(1)]: { hours: 12.5, invoiced: false }, [key(5)]: { hours: 1 } } })
		check('pbuser1 reports 12.5 h; not their Job is skipped', p1.status === 200 && p1.data?.records?.length === 1
			&& p1.data.records[0].data.progress.hours === 12.5 && JSON.stringify(p1.data?.skipped) === JSON.stringify([key(5)]), p1.text.slice(0, 300))
		const p2 = await ocs(U1, 'POST', '/jobs/progress', { jobs: { [key(1)]: { hours: 12.5, invoiced: false } } })
		check('the same again writes nothing', p2.status === 200 && p2.data?.records?.length === 0 && p2.data?.revision >= p1.data?.revision, p2.text.slice(0, 200))
		check('pblead sees the progress', (await get(L, key(1))).data?.data?.progress?.hours === 12.5)
		expect('pblead cannot mark it paid yet (not Done, not Team Admin)', await ocs(L, 'POST', `${path(key(1))}/paid`), 403, 'forbidden')
		const full = await ocs(U1, 'POST', '/jobs/progress', { jobs: { [key(1)]: { hours: 31, invoiced: true } } })
		const fd = full.data?.records?.[0]?.data
		check('the volume reached: Done by itself, capped at 28 h', fd?.state === 'done' && fd?.progress?.hours === 28 && fd?.done?.reason === 'fulfilled' && fd?.progress?.invoiced === true, full.text.slice(0, 300))
		check('pbuser1 still sees it (Done)', (await get(U1, key(1))).status === 200)
		expect('changing a Job that is Done', await ocs(L, 'POST', `${path(key(1))}/change`, { hours: 40 }), 409, 'conflict')
		const paid = await ocs(A, 'POST', `${path(key(1))}/paid`, { paid: true })
		check('Team Admin marks it paid', paid.status === 200 && paid.data?.data?.paid?.by === 'pbadmin', paid.text.slice(0, 200))
		const dn = await ocs(U2, 'POST', `${path(key(2))}/accept`)
		check('pbuser2 accepts J2', dn.status === 200 && dn.data?.data?.state === 'in_progress')
		const early = await ocs(U2, 'POST', `${path(key(2))}/done`)
		check('… and declares it Done early', early.status === 200 && early.data?.data?.done?.reason === 'declared', early.text.slice(0, 200))
		expect('paid: not a boolean', await ocs(A, 'POST', `${path(key(2))}/paid`, { paid: 'yes' }), 422, 'invalid')

		head('Jobs: change and delete')
		const ch = await ocs(L, 'POST', `${path(key(3))}/change`, { hours: 6, description: 'more precise' })
		check('pblead changes J3; the change is noted', ch.status === 200 && ch.data?.data?.change?.fields?.hours?.from === 5 && ch.data?.data?.change?.fields?.hours?.to === 6, ch.text.slice(0, 300))
		check('pbuser1 is notified: changed', /changed the Job/.test((await noteFor(U1, key(3)))?.subject || ''))
		expect('a User changes', await ocs(U1, 'POST', `${path(key(3))}/change`, { hours: 60 }), 403, 'forbidden')
		expect('project or budget do not change', await ocs(L, 'POST', `${path(key(3))}/change`, { project: PX }), 422, 'invalid')
		expect('Team Admin (not the sender) deletes', await ocs(A, 'DELETE', path(key(3))), 403, 'forbidden')
		expect('pbuser1 deletes', await ocs(U1, 'DELETE', path(key(3))), 403, 'forbidden')
		const del = await ocs(L, 'DELETE', path(key(3)))
		check('the sender deletes', del.status === 200 && del.data?.deleted === true && del.data?.data === null, del.text.slice(0, 200))
		expect('gone for pbuser1', await get(U1, key(3)), 404, 'not_found')
		check('pbuser1 has the tombstone in the delta', (await inDelta(U1, rev0, key(3)))?.deleted === true)
		check('pbuser1 is notified: deleted', /deleted the Job/.test((await noteFor(U1, key(3)))?.subject || ''))
		const h = await ocs(A, 'GET', `/records/job/${key(3)}/history`)
		check('the history stays (Team Admin)', h.status === 200 && h.data?.length === 3 && h.data?.[0]?.deleted === true, h.text.slice(0, 200))
		expect('history for a User', await ocs(U1, 'GET', `/records/job/${key(3)}/history`), 403, 'forbidden')
		expect('deleting again', await ocs(L, 'DELETE', path(key(3))), 404, 'not_found')
		// The account language decides.
		const userPath = '/ocs/v2.php/cloud/users/pbuser1'
		const langBefore = (await ocs(ADMIN, 'GET', userPath)).data?.language || 'en'
		try {
			await ocs(ADMIN, 'PUT', userPath, { key: 'language', value: 'de' })
			const de = await ocs(U1, 'GET', '/ocs/v2.php/apps/notifications/api/v2/notifications', undefined, { lang: 'de' })
			const deNote = (de.data || []).find((n) => n.object_id === key(3))
			check('the notification in German keeps “Job”', deNote?.subject === 'Lea Planer hat den Job „PJ.1“ gelöscht'
				&& deNote?.message === 'Öffne TimeSister, um den Job zu sehen.', deNote)
			const wrong = await ocs(U1, 'POST', `${path(key(4))}/accept`, undefined, { lang: 'de' })
			check('an error in German keeps “Job”', wrong.status === 409 && wrong.data?.message === 'Dieser Job ist dir nicht mehr angeboten.', wrong.text.slice(0, 200))
		} finally {
			await ocs(ADMIN, 'PUT', userPath, { key: 'language', value: langBefore })
		}
	} finally {
		head('Jobs: cleaning up')
		for (const k of made) {
			const cur = await get(L, k)
			if (cur.status === 200) {
				await ocs(L, 'DELETE', path(k))
			}
		}
		for (const id of [PJ, PX]) {
			const p = await ocs(A, 'GET', `/records/project/${id}`)
			if (p.status === 200) {
				expect(`delete ${id}`, await ocs(A, 'DELETE', `/records/project/${id}?version=${p.data.version}`), 200)
			}
		}
		for (const u of [L, U1, U2]) {
			await clearNotes(u)
		}
	}
}
