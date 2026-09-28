// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Weekly numbers of the Jobs (0.7.2): each client reports its own weeks,
// who reads them (the person, Team Admins, Leads with at least “view” in
// the shares matrix), and the capacity check when a Lead accepts a
// counter-proposal – with the person's reported weeks, a running Job the
// report does not know yet, and without numbers (not blocked). Team “at”,
// whose User has no client running; everything reset at the end.
import { ocs, sql } from './lib.mjs'
import { A, AA, AL, AU, RUN, check, expect, head } from './harness.mjs'

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
const offer = (over = {}) => ({ project: PJ, code: 'PA.1', hours: 10, start: W(0), end: addDays(W(1), 4), recipients: ['atuser1'], ...over })
const week = (i, jobs = {}) => ({ start: W(i), full: 40, available: 20, holidays: 0, vacation: 0, days: [1, 1, 1, 1, 1, 0, 0], jobs })
const notes = async (who) => ((await ocs(who, 'GET', '/ocs/v2.php/apps/notifications/api/v2/notifications')).data || [])
	.filter((n) => n.app === 'timesister' && n.object_type === 'job' && n.object_id.endsWith(RUN))

export async function wochen() {
	const made = []
	const foreign = `j-foreign-${RUN}`
	try {
		head('Job weeks: reporting and reading')
		sql(`UPDATE oc_ts_client_status SET job_weeks = NULL, job_weeks_at = NULL WHERE uid = 'atuser1';`)
		expect('project with lead atlead', await ocs(AA, 'PUT', `/records/project/${PJ}`, { version: 0, data: {
			schema: 1, id: PJ, name: `Weeks ${RUN}`, codes: ['PA'], subprojects: [{ code: 'PA.1', name: 'One' }, { code: 'PA.2', name: 'Two' }], leads: ['atlead'],
		} }), 200)
		expect('JA1 offered to atuser1', await ocs(AL, 'PUT', path(key(1)), offer()), 200)
		made.push(key(1))
		expect('… accepted', await ocs(AU, 'POST', `${path(key(1))}/accept`), 200)
		expect('JA2 offered (week +1)', await ocs(AL, 'PUT', path(key(2)), offer({ code: 'PA.2', start: W(1), end: addDays(W(1), 4) })), 200)
		made.push(key(2))
		expect('… atuser1 counter-proposes 16 h', await ocs(AU, 'POST', `${path(key(2))}/counter`, { hours: 16 }), 200)
		if (process.env.TS_SQL) {
			const none = await ocs(AL, 'GET', `${path(key(2))}/capacity`)
			check('without numbers: not blocked, “reported: false”', none.status === 200 && none.data?.reported === false && none.data?.fits === true
				&& none.data?.user === 'atuser1' && none.data?.display_name === 'Tim Test', none.text.slice(0, 300))
		}
		expect('not a Monday', await ocs(AU, 'POST', '/jobs/weeks', { weeks: [{ ...week(0), start: addDays(W0, 1) }] }), 422, 'invalid')
		expect('weeks not a list', await ocs(AU, 'POST', '/jobs/weeks', { weeks: 'x' }), 422, 'invalid')
		expect('a bad Job key', await ocs(AU, 'POST', '/jobs/weeks', { weeks: [week(0, { 'a b': { booked: 1, planned: 0 } })] }), 400, 'invalid')
		const rep = await ocs(AU, 'POST', '/jobs/weeks', { pensum: 50, jobs: [key(1), foreign], weeks: [
			week(0, { [key(1)]: { booked: 0, planned: 5 }, [foreign]: { booked: 2, planned: 3 } }),
			week(1, { [key(1)]: { booked: 0, planned: 5 } }),
			week(2, { [foreign]: { booked: 0, planned: 12 } }), week(3), week(4), week(5),
		] })
		check('atuser1 reports six weeks', rep.status === 200 && /^\d{4}-\d{2}-\d{2}T/.test(rep.data?.reported_at || ''), rep.text.slice(0, 200))
		const own = await ocs(AU, 'GET', '/jobs/weeks')
		const me = own.data?.people?.[0]
		check('atuser1 reads only their own', own.status === 200 && own.data?.people?.length === 1 && me?.uid === 'atuser1' && me?.pensum === 50, own.text.slice(0, 300))
		check('… week 0: load 10, the Job by key, the unknown one as “other”', me?.weeks?.length === 6 && me.weeks[0].start === W0
			&& me.weeks[0].week === isoWeek(W0) && me.weeks[0].load === 10 && me.weeks[0].jobs?.[key(1)]?.planned === 5
			&& me.weeks[0].other?.booked === 2 && me.weeks[0].other?.planned === 3 && me.weeks[0].days === undefined, JSON.stringify(me?.weeks?.[0]))
		const lead = await ocs(AL, 'GET', '/jobs/weeks')
		check('a Lead without “view” reads only their own', lead.status === 200 && lead.data?.people?.length === 1 && lead.data.people[0].uid === 'atlead'
			&& lead.data.people[0].weeks === null, lead.text.slice(0, 300))
		expect('… nor with ?uid=atuser1', await ocs(AL, 'GET', '/jobs/weeks?uid=atuser1'), 403, 'forbidden')
		expect('the matrix: atlead views atuser1', await ocs(AA, 'PUT', '/team/access/atlead/atuser1', { level: 'view' }), 200)
		const lead2 = await ocs(AL, 'GET', '/jobs/weeks?uid=atuser1')
		const tim = lead2.data?.people?.[0]
		check('then the Lead reads atuser1', lead2.status === 200 && tim?.uid === 'atuser1' && tim?.weeks?.[0]?.jobs?.[key(1)]?.planned === 5
			&& tim?.weeks?.[0]?.other?.booked === 2 && typeof tim?.reported_at === 'string', lead2.text.slice(0, 300))
		const all = await ocs(AA, 'GET', '/jobs/weeks')
		const uids = (all.data?.people || []).map((p) => p.uid)
		check('a Team Admin reads everyone of the team', all.status === 200 && ['atadmin', 'atlead', 'atuser1'].every((u) => uids.includes(u))
			&& !uids.includes('pbuser1'), JSON.stringify(uids))
		expect('another team does not', await ocs(A, 'GET', '/jobs/weeks?uid=atuser1'), 403, 'forbidden')

		head('Job weeks: the capacity check of a counter-proposal')
		const pre = await ocs(AL, 'GET', `${path(key(2))}/capacity`)
		check('16 h in week +1 on top of 5 h: 1 h above the line', pre.status === 200 && pre.data?.reported === true && pre.data?.fits === false
			&& pre.data?.weeks?.length === 1 && pre.data.weeks[0].start === W(1) && pre.data.weeks[0].over === 1 && pre.data.weeks[0].available === 20, pre.text.slice(0, 300))
		expect('the User may not ask', await ocs(AU, 'GET', `${path(key(2))}/capacity`), 403, 'forbidden')
		expect('by someone without a counter-proposal', await ocs(AL, 'GET', `${path(key(2))}/capacity?by=atadmin`), 409, 'conflict')
		const no = await ocs(AL, 'POST', `${path(key(2))}/accept-counter`)
		check('accepting it → 422 rule capacity with the week', no.status === 422 && no.data?.rule === 'capacity' && no.data?.user === 'atuser1'
			&& no.data?.weeks?.[0]?.week === isoWeek(W(1)) && no.data?.weeks?.[0]?.over === 1
			&& no.data?.message === `This counter-proposal does not fit the capacity of Tim Test: week ${isoWeek(W(1))} (+1 h) above the capacity line.`, no.text.slice(0, 300))
		check('… the Job still waits', (await ocs(AL, 'GET', `/records/job/${key(2)}`)).data?.data?.counters?.[0]?.answer === undefined)
		expect('atuser1 counter-proposes 15 h', await ocs(AU, 'POST', `${path(key(2))}/counter`, { hours: 15 }), 200)
		const fits = await ocs(AL, 'GET', `${path(key(2))}/capacity?by=atuser1`)
		check('15 h fit', fits.status === 200 && fits.data?.fits === true && fits.data?.weeks?.length === 0, fits.text.slice(0, 200))
		const ok = await ocs(AL, 'POST', `${path(key(2))}/accept-counter`)
		check('accepted: In Progress with 15 h', ok.status === 200 && ok.data?.data?.state === 'in_progress' && ok.data?.data?.hours === 15, ok.text.slice(0, 200))
		expect('JA3 on the project code, week +1', await ocs(AL, 'PUT', path(key(3)), offer({ code: 'PA', hours: 1, start: W(1), end: addDays(W(1), 4) })), 200)
		made.push(key(3))
		expect('… atuser1 counter-proposes 1.5 h', await ocs(AU, 'POST', `${path(key(3))}/counter`, { hours: 1.5 }), 200)
		const extra = await ocs(AL, 'GET', `${path(key(3))}/capacity`)
		check('JA2 counts although the report does not know it yet', extra.status === 200 && extra.data?.fits === false
			&& extra.data?.weeks?.[0]?.load === 21.5, extra.text.slice(0, 300))
		const de = await ocs(AL, 'POST', `${path(key(3))}/accept-counter`, undefined, { lang: 'de' })
		check('… accepting it answers in German', de.status === 422 && /Kapazität von Tim Test: KW \d+ \(\+1\.5 h\)/.test(de.data?.message || ''), de.data?.message)
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
		await ocs(AA, 'PUT', '/team/access/atlead/atuser1', { level: 'default' })
		if (sql(`UPDATE oc_ts_client_status SET job_weeks = NULL, job_weeks_at = NULL WHERE uid = 'atuser1';`) === null) {
			await ocs(AU, 'POST', '/jobs/weeks', { weeks: [] })
		}
		for (const who of [AL, AU]) {
			for (const n of await notes(who)) {
				await ocs(who, 'DELETE', `/ocs/v2.php/apps/notifications/api/v2/notifications/${n.notification_id}`)
			}
		}
	}
}
