// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Weekly numbers of the Jobs (0.7.2, per day 0.7.3, non-billable 0.7.4, day weights to readers 0.7.5): each client reports its own weeks,
// who reads them (the person and Team Admins; since 0.14.0 Leads only their
// own), the capacity check when a Lead accepts a counter-proposal – with the
// person's reported weeks, a running Job the report does not know yet, and
// without numbers (not blocked); Leads get the weeks without hours – and
// POST /jobs/fits (0.14.0). Team “za”, whose User has no client running;
// everything reset at the end.
import { ADMIN, ocs, sql } from './lib.mjs'
import { RUN, check, expect, head } from './harness.mjs'
import { A, AA, AL, AU, nameOf } from './konten.mjs'

const PJ = `PA-${RUN}`
const key = (n) => `w${n}-${RUN}`
const path = (k) => `/jobs/${encodeURIComponent(k)}`
const day = (d) => d.toISOString().slice(0, 10)
const addDays = (s, n) => { const d = new Date(s + 'T00:00:00Z'); d.setUTCDate(d.getUTCDate() + n); return day(d) }
const now = new Date()
const W0 = addDays(day(now), -((now.getUTCDay() + 6) % 7))
const W = (i) => addDays(W0, 7 * i)
const isoWeek = (s) => {
	const d = new Date(s + 'T00:00:00Z')
	d.setUTCDate(d.getUTCDate() + 3 - ((d.getUTCDay() + 6) % 7))
	const y = new Date(Date.UTC(d.getUTCFullYear(), 0, 4))
	return 1 + Math.round(((d - y) / 86400000 - 3 + ((y.getUTCDay() + 6) % 7)) / 7)
}
const offer = (over = {}) => ({ project: PJ, code: 'PA.1', hours: 10, start: W(0), end: addDays(W(1), 4), recipients: ['zauser1'], ...over })
const week = (i, jobs = {}) => ({ start: W(i), full: 40, available: 20, holidays: 0, vacation: 0, days: [1, 1, 1, 1, 1, 0, 0], jobs })
const notes = async (who) => ((await ocs(who, 'GET', '/ocs/v2.php/apps/notifications/api/v2/notifications')).data || [])
	.filter((n) => n.app === 'timesister' && n.object_type === 'job' && n.object_id.endsWith(RUN))

export async function wochen() {
	const made = []
	const foreign = `j-foreign-${RUN}`
	try {
		head('Job weeks: reporting and reading')
		sql(`UPDATE oc_ts_client_status SET job_weeks = NULL, job_weeks_at = NULL WHERE uid = 'zauser1';`)
		expect('project with lead zalead', await ocs(AA, 'PUT', `/records/project/${PJ}`, { version: 0, data: {
			schema: 1, id: PJ, name: `Weeks ${RUN}`, codes: ['PA'], subprojects: [{ code: 'PA.1', name: 'One' }, { code: 'PA.2', name: 'Two' }], leads: ['zalead'],
		} }), 200)
		expect('JA1 offered to zauser1', await ocs(AL, 'PUT', path(key(1)), offer()), 200)
		made.push(key(1))
		expect('… accepted', await ocs(AU, 'POST', `${path(key(1))}/accept`), 200)
		expect('JA2 offered (week +1)', await ocs(AL, 'PUT', path(key(2)), offer({ code: 'PA.2', start: W(1), end: addDays(W(1), 4) })), 200)
		made.push(key(2))
		expect('… zauser1 counter-proposes 16 h', await ocs(AU, 'POST', `${path(key(2))}/counter`, { hours: 16 }), 200)
		if (process.env.TS_SQL) {
			const none = await ocs(AL, 'GET', `${path(key(2))}/capacity`)
			check('without numbers: not blocked, “reported: false”', none.status === 200 && none.data?.reported === false && none.data?.fits === true
				&& none.data?.user === 'zauser1' && none.data?.display_name === nameOf('zauser1'), none.text.slice(0, 300))
		}
		expect('not a Monday', await ocs(AU, 'POST', '/jobs/weeks', { weeks: [{ ...week(0), start: addDays(W0, 1) }] }), 422, 'invalid')
		expect('weeks not a list', await ocs(AU, 'POST', '/jobs/weeks', { weeks: 'x' }), 422, 'invalid')
		expect('a bad Job key', await ocs(AU, 'POST', '/jobs/weeks', { weeks: [week(0, { 'a b': { booked: 1, planned: 0 } })] }), 400, 'invalid')
		expect('more non-billable than booked', await ocs(AU, 'POST', '/jobs/weeks', { weeks: [week(0, { [key(1)]: { booked: 1, booked_nb: 2, planned: 0 } })] }), 422, 'invalid')
		const rep = await ocs(AU, 'POST', '/jobs/weeks', { pensum: 50, jobs: [key(1), foreign], weeks: [
			week(0, { [key(1)]: { booked: 0, planned: 5 }, [foreign]: { booked: 2, booked_nb: 0.5, planned: 3 } }),
			week(1, { [key(1)]: { booked: 0, planned: 5 } }),
			week(2, { [foreign]: { booked: 0, planned: 12 } }), week(3), week(4), week(5),
		] })
		check('zauser1 reports six weeks', rep.status === 200 && /^\d{4}-\d{2}-\d{2}T/.test(rep.data?.reported_at || ''), rep.text.slice(0, 200))
		const own = await ocs(AU, 'GET', '/jobs/weeks')
		const me = own.data?.people?.[0]
		check('zauser1 reads only their own', own.status === 200 && own.data?.people?.length === 1 && me?.uid === 'zauser1' && me?.pensum === 50, own.text.slice(0, 300))
		check('… week 0: load 10, the Job by key, the unknown one as “other”, the day weights (0.7.5)', me?.weeks?.length === 6 && me.weeks[0].start === W0
			&& me.weeks[0].week === isoWeek(W0) && me.weeks[0].load === 10 && me.weeks[0].jobs?.[key(1)]?.planned === 5
			&& me.weeks[0].other?.booked === 2 && me.weeks[0].other?.planned === 3
			&& JSON.stringify(me.weeks[0].days) === '[1,1,1,1,1,0,0]', JSON.stringify(me?.weeks?.[0]))
		check('… non-billable (0.7.4): 0 where none was sent, merged into “other”', me?.weeks?.[0]?.jobs?.[key(1)]?.booked_nb === 0
			&& me?.weeks?.[0]?.other?.booked_nb === 0.5, JSON.stringify(me?.weeks?.[0]))
		// 0.14.0: Leads read only their own numbers.
		const lead = await ocs(AL, 'GET', '/jobs/weeks')
		check('a Lead reads only their own', lead.status === 200 && lead.data?.people?.length === 1 && lead.data.people[0].uid === 'zalead'
			&& lead.data.people[0].weeks === null, lead.text.slice(0, 300))
		const other = await ocs(AL, 'GET', '/jobs/weeks?uid=zauser1')
		check('… not with ?uid=zauser1 → 403 forbidden', other.status === 403 && other.data?.error === 'forbidden'
			&& other.data?.message === 'Only your own numbers; Team Admins read everyone’s.', other.text.slice(0, 300))
		const adm = await ocs(AA, 'GET', '/jobs/weeks?uid=zauser1')
		const tim = adm.data?.people?.[0]
		check('a Team Admin reads zauser1', adm.status === 200 && adm.data?.people?.length === 1 && tim?.uid === 'zauser1' && tim?.weeks?.[0]?.jobs?.[key(1)]?.planned === 5
			&& tim?.weeks?.[0]?.other?.booked === 2 && tim?.weeks?.[0]?.other?.booked_nb === 0.5 && typeof tim?.reported_at === 'string', adm.text.slice(0, 300))
		const all = await ocs(AA, 'GET', '/jobs/weeks')
		const uids = (all.data?.people || []).map((p) => p.uid)
		check('a Team Admin reads everyone of the team', all.status === 200 && ['zaadmin', 'zalead', 'zauser1'].every((u) => uids.includes(u))
			&& !uids.includes('zbuser1'), JSON.stringify(uids))
		expect('another team does not', await ocs(A, 'GET', '/jobs/weeks?uid=zauser1'), 403, 'forbidden')

		head('Job weeks: the capacity check of a counter-proposal')
		const pre = await ocs(AA, 'GET', `${path(key(2))}/capacity`)
		check('Team Admin: 16 h in week +1 on top of 5 h: 1 h above the line', pre.status === 200 && pre.data?.reported === true && pre.data?.fits === false
			&& pre.data?.weeks?.length === 1 && pre.data.weeks[0].start === W(1) && pre.data.weeks[0].over === 1 && pre.data.weeks[0].available === 20, pre.text.slice(0, 300))
		const preL = await ocs(AL, 'GET', `${path(key(2))}/capacity`)
		const wL = preL.data?.weeks?.[0] || {}
		check('Lead: the same week, without hours (0.14.0)', preL.status === 200 && preL.data?.fits === false && preL.data?.weeks?.length === 1
			&& wL.start === W(1) && wL.week === isoWeek(W(1)) && !('load' in wL) && !('available' in wL) && !('over' in wL), preL.text.slice(0, 300))
		expect('the User may not ask', await ocs(AU, 'GET', `${path(key(2))}/capacity`), 403, 'forbidden')
		expect('by someone without a counter-proposal', await ocs(AL, 'GET', `${path(key(2))}/capacity?by=zaadmin`), 409, 'conflict')
		const no = await ocs(AL, 'POST', `${path(key(2))}/accept-counter`)
		check('accepting it → 422 rule capacity with the week, no hours for the Lead', no.status === 422 && no.data?.rule === 'capacity' && no.data?.user === 'zauser1'
			&& no.data?.weeks?.[0]?.week === isoWeek(W(1)) && !('over' in (no.data?.weeks?.[0] || {}))
			&& no.data?.message === `This counter-proposal does not fit the capacity of ${nameOf('zauser1')}: week ${isoWeek(W(1))} above the capacity line.`, no.text.slice(0, 300))
		check('… the Job still waits', (await ocs(AL, 'GET', `/records/job/${key(2)}`)).data?.data?.counters?.[0]?.answer === undefined)
		expect('zauser1 counter-proposes 15 h', await ocs(AU, 'POST', `${path(key(2))}/counter`, { hours: 15 }), 200)
		const fits = await ocs(AL, 'GET', `${path(key(2))}/capacity?by=zauser1`)
		check('15 h fit', fits.status === 200 && fits.data?.fits === true && fits.data?.weeks?.length === 0, fits.text.slice(0, 200))
		const ok = await ocs(AL, 'POST', `${path(key(2))}/accept-counter`)
		check('accepted: In Progress with 15 h', ok.status === 200 && ok.data?.data?.state === 'in_progress' && ok.data?.data?.hours === 15, ok.text.slice(0, 200))
		expect('JA3 on the project code, week +1', await ocs(AL, 'PUT', path(key(3)), offer({ code: 'PA', hours: 1, start: W(1), end: addDays(W(1), 4) })), 200)
		made.push(key(3))
		expect('… zauser1 counter-proposes 1.5 h', await ocs(AU, 'POST', `${path(key(3))}/counter`, { hours: 1.5 }), 200)
		const extra = await ocs(AA, 'GET', `${path(key(3))}/capacity`)
		check('JA2 counts although the report does not know it yet', extra.status === 200 && extra.data?.fits === false
			&& extra.data?.weeks?.[0]?.load === 21.5, extra.text.slice(0, 300))
		// The account language decides (Nextcloud keeps the first Accept-Language).
		const userPath = '/ocs/v2.php/cloud/users/zalead'
		const langBefore = (await ocs(ADMIN, 'GET', userPath)).data?.language || 'en'
		try {
			await ocs(ADMIN, 'PUT', userPath, { key: 'language', value: 'de' })
			const de = await ocs(AL, 'POST', `${path(key(3))}/accept-counter`, undefined, { lang: 'de' })
			check('… accepting it answers in German', de.status === 422
				&& de.data?.message === `Dieser Gegenvorschlag passt nicht in die Kapazität von ${nameOf('zauser1')}: KW ${isoWeek(W(1))} über der Pensum-Linie.`, de.data?.message)
		} finally {
			await ocs(ADMIN, 'PUT', userPath, { key: 'language', value: langBefore })
		}

		head('Job weeks: the capacity per day (0.7.3)')
		expect('JA4 offered for week +2, 10 h', await ocs(AL, 'PUT', path(key(4)), offer({ start: W(2), end: addDays(W(2), 4) })), 200)
		made.push(key(4))
		expect('… zauser1 counter-proposes it all on the Monday', await ocs(AU, 'POST', `${path(key(4))}/counter`, { end: W(2) }), 200)
		const oneDay = await ocs(AA, 'GET', `${path(key(4))}/capacity`)
		check('the week holds, the day not: 10 h against 20 h / 5 days', oneDay.status === 200 && oneDay.data?.fits === false
			&& oneDay.data?.weeks?.length === 1 && oneDay.data.weeks[0].day === W(2) && oneDay.data.weeks[0].over === 6
			&& oneDay.data.weeks[0].available === 4, oneDay.text.slice(0, 300))
		const noDay = await ocs(AL, 'POST', `${path(key(4))}/accept-counter`)
		check('accepting it → 422 with the day, no hours for the Lead', noDay.status === 422 && noDay.data?.rule === 'capacity' && noDay.data?.weeks?.[0]?.day === W(2)
			&& !('over' in (noDay.data?.weeks?.[0] || {}))
			&& noDay.data?.message === `This counter-proposal does not fit the capacity of ${nameOf('zauser1')}: week ${isoWeek(W(2))} (${W(2)}) above the capacity line.`, noDay.text.slice(0, 300))
		expect('zauser1 counter-proposes Monday to Wednesday', await ocs(AU, 'POST', `${path(key(4))}/counter`, { end: addDays(W(2), 2) }), 200)
		const threeDays = await ocs(AL, 'GET', `${path(key(4))}/capacity?by=zauser1`)
		check('3.3 h a day fit', threeDays.status === 200 && threeDays.data?.fits === true, threeDays.text.slice(0, 300))

		head('Job weeks: POST /jobs/fits (0.14.0)')
		{
			const ask = (who, body) => ocs(who, 'POST', '/jobs/fits', { start: W(3), end: addDays(W(3), 4), recipients: ['zauser1', 'zaadmin'], ...body })
			const small = await ask(AL, { hours: 5 })
			// zaadmin's numbers depend on other checks (null when never reported).
			check('Lead: 5 h in week +3 fit zauser1, keys in the order of recipients', small.status === 200 && small.data?.fits?.zauser1 === true
				&& JSON.stringify(Object.keys(small.data?.fits || {})) === '["zauser1","zaadmin"]' && [true, false, null].includes(small.data?.fits?.zaadmin), small.text.slice(0, 300))
			const big = await ask(AL, { hours: 30 })
			check('… 30 h do not fit – only true/false/null, no numbers', big.status === 200 && big.data?.fits?.zauser1 === false
				&& Object.values(big.data?.fits || {}).every((v) => v === true || v === false || v === null) && JSON.stringify(Object.keys(big.data || {})) === '["fits"]', big.text.slice(0, 300))
			const adm = await ask(AA, { hours: 30 })
			check('Team Admin asks too', adm.status === 200 && adm.data?.fits?.zauser1 === false, adm.text.slice(0, 300))
			const withJob = await ask(AL, { hours: 5, start: W(2), end: addDays(W(2), 2), recipients: ['zauser1'], job: key(4) })
			check('with job: its own hours do not count twice', withJob.status === 200 && typeof withJob.data?.fits?.zauser1 === 'boolean', withJob.text.slice(0, 300))
			expect('a User asks', await ask(AU, { hours: 5 }), 403, 'forbidden')
			expect('a recipient outside the team', await ask(AL, { hours: 5, recipients: ['zbuser1'] }), 422, 'invalid')
			expect('hours 0', await ask(AL, { hours: 0 }), 422, 'invalid')
			expect('end before start', await ask(AL, { hours: 5, end: addDays(W(3), -1) }), 422, 'invalid')
			expect('an unknown job', await ask(AL, { hours: 5, job: `nie-${RUN}` }), 404, 'not_found')
		}
	} finally {
		head('Job weeks: cleaning up')
		for (const k of made) {
			if ((await ocs(AL, 'GET', `/records/job/${encodeURIComponent(k)}`)).status === 200) {
				await ocs(AL, 'DELETE', path(k))
			}
		}
		const p = await ocs(AA, 'GET', `/records/project/${PJ}`)
		if (p.status === 200) {
			expect(`delete ${PJ}`, await ocs(AA, 'DELETE', `/records/project/${PJ}?version=${p.data.version}`), 200)
		}
		if (sql(`UPDATE oc_ts_client_status SET job_weeks = NULL, job_weeks_at = NULL WHERE uid = 'zauser1';`) === null) {
			await ocs(AU, 'POST', '/jobs/weeks', { weeks: [] })
		}
		for (const who of [AL, AU]) {
			for (const n of await notes(who)) {
				await ocs(who, 'DELETE', `/ocs/v2.php/apps/notifications/api/v2/notifications/${n.notification_id}`)
			}
		}
	}
}
