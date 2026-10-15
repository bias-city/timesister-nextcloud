// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Membership via the team group, Team Admin as group admin, roles, “allow
// overriding” and departure (PUT /team/members, admin side), calendar sharing. Lead
// follows from the projects (0.11.0): the test account kr<RUN> leads the project
// KRP-<RUN>; both are removed at the end.
import { ADMIN, as, ocs, sql } from './lib.mjs'
import { A, AA, ISO, L, RUN, U1, U2, V, check, expect, head } from './harness.mjs'

const users = (uid) => `/ocs/v2.php/cloud/users/${encodeURIComponent(uid)}`
const member = (uid) => `/team/members/${encodeURIComponent(uid)}`
const project = (key) => `/records/project/${encodeURIComponent(key)}`
/** A project led by `leads`, status as given. */
const led = (key, leads, status = 'active') => ({ schema: 1, id: key, name: `Konten ${key}`, codes: [key], subprojects: [], leads, status })

/** Create an account as the Nextcloud admin; false if it fails. */
async function makeUser(uid, groups) {
	const r = await ocs(ADMIN, 'POST', '/ocs/v2.php/cloud/users', { userid: uid, password: `Test-2026-${uid}!`, groups })
	check(`test account ${uid} created`, r.status === 200, r.text.slice(0, 200))
	return r.status === 200
}

export async function konten() {
	const made = []
	const kr = `kr${RUN}`
	const ga = `kr${RUN}ga`
	const KRP = `KRP-${RUN}`
	const pbId = (await ocs(ADMIN, 'GET', '/admin/teams')).data?.find?.((t) => t.slug === 'pb')?.id
	try {
		head('GET /team: members with role')
		{
			const t = await ocs(A, 'GET', '/team')
			const list = t.data?.members || []
			const by = Object.fromEntries(list.map((x) => [x.uid, x]))
			check('pb: roles from group admin and project leads', t.status === 200 && by.pbadmin?.role === 'admin' && by.pbverw?.role === 'admin'
				&& by.pblead?.role === 'lead' && by.pbuser1?.role === 'user' && by.pbuser2?.role === 'user', list)
			check('entries with uid, display_name, role, left_at, lead_of, lead_projects; no at accounts', list.every((x) => JSON.stringify(Object.keys(x)) === '["uid","display_name","role","left_at","lead_of","lead_projects"]')
				&& by.pblead?.display_name === 'Lea Planer' && by.pblead?.left_at === null && !list.some((x) => x.uid.startsWith('at')), list[0])
			check('pblead: lead_of ≥ 1 with the project names; users 0', by.pblead?.lead_of >= 1 && by.pblead?.lead_projects?.length === by.pblead?.lead_of
				&& by.pbuser1?.lead_of === 0 && JSON.stringify(by.pbuser1?.lead_projects) === '[]', by.pblead)
		}

		if (await makeUser(kr, ['pb-team'])) {
			made.push(kr)
			head('Lead follows from the projects (0.11.0)')
			check('new account: user, lead_of empty', (await ocs(as(kr), 'GET', '/me')).data?.role === 'user' && JSON.stringify((await ocs(as(kr), 'GET', '/me')).data?.lead_of) === '[]')
			expect('Team Admin creates a project led by the account', await ocs(A, 'PUT', project(KRP), { version: 0, data: led(KRP, [kr]) }), 200)
			const derived = (await ocs(as(kr), 'GET', '/me')).data
			check('… /me: role lead, lead_of names the project', derived?.role === 'lead' && derived?.lead_of?.length === 1 && derived?.lead_of?.[0]?.key === KRP
				&& derived?.lead_of?.[0]?.kind === 'project' && derived?.lead_of?.[0]?.code === KRP && derived?.lead_of?.[0]?.name === `Konten ${KRP}`, derived)
			expect('project ended (completed)', await ocs(A, 'PUT', project(KRP), { version: 1, data: led(KRP, [kr], 'completed') }), 200)
			check('… /me: user again, lead_of empty', (await ocs(as(kr), 'GET', '/me')).data?.role === 'user' && JSON.stringify((await ocs(as(kr), 'GET', '/me')).data?.lead_of) === '[]')
			expect('project active again', await ocs(A, 'PUT', project(KRP), { version: 2, data: led(KRP, [kr]) }), 200)
			check('… /me: lead again', (await ocs(as(kr), 'GET', '/me')).data?.role === 'lead')
			check('/team: the account with lead_of 1 and the project name', ((await ocs(A, 'GET', '/team')).data?.members || []).some((x) => x.uid === kr && x.role === 'lead' && x.lead_of === 1 && x.lead_projects?.[0] === `Konten ${KRP}`))

			head('PUT /team/members: only Team Admins, only admin and user')
			expect('user sets admin', await ocs(U1, 'PUT', member(kr), { role: 'admin' }), 403, 'forbidden')
			expect('lead sets admin', await ocs(L, 'PUT', member(kr), { role: 'admin' }), 403, 'forbidden')
			expect('lead records a leaving', await ocs(L, 'PUT', member(kr), { left: false }), 403, 'forbidden')
			const noLead = await ocs(V, 'PUT', member(kr), { role: 'lead' })
			check('role lead cannot be set → 422 with the reason', noLead.status === 422 && noLead.data?.error === 'invalid' && /project leads/.test(noLead.data?.message || ''), noLead.text)
			const r = await ocs(V, 'PUT', member(kr), { role: 'user' })
			check('second Team Admin sets user → entry as in /team, role still lead (derived)', r.status === 200 && r.data?.uid === kr && r.data?.role === 'lead' && r.data?.lead_of === 1 && r.data?.left_at === null && !('may_override' in r.data), r.text)
			expect('the old role subadmin', await ocs(A, 'PUT', member(kr), { role: 'subadmin' }), 422, 'invalid')
			expect('empty body', await ocs(A, 'PUT', member(kr), {}), 400, 'invalid')
			expect('left is not a boolean', await ocs(A, 'PUT', member(kr), { left: 'ja' }), 422, 'invalid')
			expect('may_override is gone (0.17.0): nothing to set', await ocs(A, 'PUT', member(kr), { may_override: true }), 400, 'invalid')
			expect('account of another team', await ocs(A, 'PUT', member('atuser1'), { role: 'user' }), 404, 'not_found')
			expect('account that does not exist', await ocs(A, 'PUT', member(`nie${RUN}`), { role: 'user' }), 404, 'not_found')
			expect('at Team Admin for a pb account', await ocs(AA, 'PUT', member(kr), { role: 'user' }), 404, 'not_found')

			head('Role Team Admin = group admin of the team group (ISubAdmin)')
			const s = await ocs(A, 'PUT', member(kr), { role: 'admin' })
			check('Team Admin appoints a Team Admin', s.status === 200 && s.data?.role === 'admin', s.text)
			check('… the account\'s /me: admin', (await ocs(as(kr), 'GET', '/me')).data?.role === 'admin')
			const sa = await ocs(ADMIN, 'GET', `${users(kr)}/subadmins`)
			check('… group admin of pb-team in Nextcloud', sa.status === 200 && (sa.data || []).includes('pb-team'), sa.text.slice(0, 200))
			const down = await ocs(as(kr), 'PUT', member(kr), { role: 'user' })
			check('a Team Admin of several takes the role from themselves → lead (derived)', down.status === 200 && down.data?.role === 'lead', down.text)
			const sb = await ocs(ADMIN, 'GET', `${users(kr)}/subadmins`)
			const gs = await ocs(ADMIN, 'GET', `${users(kr)}/groups`)
			check('… no longer group admin, still in the team group', !(sb.data || []).includes('pb-team') && (gs.data?.groups || []).includes('pb-team'), [sb.data, gs.data])
			check('… /me: lead, still in the team', (await ocs(as(kr), 'GET', '/me')).data?.role === 'lead')
			expect('the last Team Admin of at takes the role from themselves', await ocs(AA, 'PUT', member('atadmin'), { role: 'user' }), 409, 'conflict')
			expect('own departure', await ocs(A, 'PUT', member('pbadmin'), { left: true }), 403, 'forbidden')

			head('Departure and readmission')
			const before = (await ocs(as(kr), 'GET', '/me')).data
			check('before: lead', before?.role === 'lead', before)
			const weg = await ocs(V, 'PUT', member(kr), { left: true })
			check('left: true → left_at set, role stays (still in leads of the project)', weg.status === 200 && ISO.test(weg.data?.left_at || '') && weg.data?.role === 'lead', weg.text)
			expect('/me afterwards', await ocs(as(kr), 'GET', '/me'), 403, 'no_team')
			expect('… and /records', await ocs(as(kr), 'GET', '/records'), 403, 'no_team')
			const e = ((await ocs(A, 'GET', '/team')).data?.members || []).find((x) => x.uid === kr)
			check('/team names the departed member with a date', e?.left_at === weg.data?.left_at && e?.role === 'lead', e)
			check('/status does not name them', !((await ocs(A, 'GET', '/status')).data || []).some((x) => x.uid === kr))
			const counts = (await ocs(ADMIN, 'GET', '/admin/teams')).data?.find?.((t) => t.id === pbId)?.counts
			check('/admin/teams counts them under left', counts?.left === 1 && counts?.lead === 1, counts)
			const again = await ocs(V, 'PUT', member(kr), { left: true })
			check('left: true twice keeps the first date', again.data?.left_at === weg.data?.left_at, again.text)
			const back = await ocs(V, 'PUT', member(kr), { left: false })
			check('left: false readmits', back.status === 200 && back.data?.left_at === null, back.text)
			const after = (await ocs(as(kr), 'GET', '/me')).data
			check('readmission returns the role', after?.role === 'lead' && after?.team?.slug === 'pb', after)

			// Departed from pb and in at-team: belongs to at, not to two teams.
			await ocs(V, 'PUT', member(kr), { left: true })
			await ocs(ADMIN, 'POST', `${users(kr)}/groups`, { groupid: 'at-team' })
			try {
				const at = await ocs(as(kr), 'GET', '/me')
				check('departed from pb, in at-team: team at, role user', at.status === 200 && at.data?.team?.slug === 'at' && at.data?.role === 'user', at.text.slice(0, 300))
				await ocs(V, 'PUT', member(kr), { left: false })
				expect('readmitted to pb, while also in at-team', await ocs(as(kr), 'GET', '/me'), 409, 'ambiguous_team')
			} finally {
				await ocs(ADMIN, 'DELETE', `${users(kr)}/groups`, { groupid: 'at-team' }, { form: true })
			}

			head('Calendar sharing is gone (0.17.0)')
			const K = as(kr)
			expect('GET /me/calendar-share', await ocs(K, 'GET', '/me/calendar-share'), 404)
			expect('PUT /me/calendar-share', await ocs(K, 'PUT', '/me/calendar-share', { enabled: false }), 404)
			const sh = await ocs(K, 'POST', '/status', { calendar_shared: false, applied_shares: [{ uid: 'pbadmin', access: 'write' }], app_version: '0.3.0' })
			const st1 = ((await ocs(V, 'GET', '/status')).data || []).find((x) => x.uid === kr)
			check('POST /status ignores calendar_shared and applied_shares; GET /status has no calendar_shared', sh.status === 200 && st1?.app_version === '0.3.0' && !('calendar_shared' in (st1 || {})), st1)
		}

		head('Admin = group admin of the team group')
		if (await makeUser(ga, [])) {
			made.push(ga)
			expect('without a group: /me', await ocs(as(ga), 'GET', '/me'), 403, 'no_team')
			const sub = await ocs(ADMIN, 'POST', `${users(ga)}/subadmins`, { groupid: 'pb-team' })
			check('Nextcloud admin makes the account group admin of pb-team', sub.status === 200, sub.text.slice(0, 200))
			const m = await ocs(as(ga), 'GET', '/me')
			check('group admin, not in the group: team pb, role admin', m.status === 200 && m.data?.team?.slug === 'pb' && m.data?.role === 'admin', m.text.slice(0, 300))
			check('… and in /team as admin', ((await ocs(A, 'GET', '/team')).data?.members || []).some((x) => x.uid === ga && x.role === 'admin'))
			const st = ((await ocs(V, 'GET', '/status')).data || []).find((x) => x.uid === ga)
			check('… and in /status, without calendar_shared (0.17.0)', !!st && !('calendar_shared' in st), st)
		}

		head('Roles from the admin side (PUT /admin/teams/{id}/members/{uid})')
		{
			expect('as pbadmin (not a Nextcloud admin)', await ocs(A, 'PUT', `/admin/teams/${pbId}/members/pbuser2`, { role: 'admin' }), 403)
			expect('role lead from the admin side', await ocs(ADMIN, 'PUT', `/admin/teams/${pbId}/members/pbuser2`, { role: 'lead' }), 422, 'invalid')
			const r = await ocs(ADMIN, 'PUT', `/admin/teams/${pbId}/members/pbuser2`, { role: 'admin' })
			check('Nextcloud admin appoints a Team Admin', r.status === 200 && r.data?.role === 'admin', r.text)
			const back = await ocs(ADMIN, 'PUT', `/admin/teams/${pbId}/members/pbuser2`, { role: 'user' })
			check('… and withdraws it', back.status === 200 && back.data?.role === 'user', back.text)
			check('… pbuser2 stays in the team', (await ocs(U2, 'GET', '/me')).data?.role === 'user')
			expect('old role subadmin', await ocs(ADMIN, 'PUT', `/admin/teams/${pbId}/members/pbuser2`, { role: 'subadmin' }), 422, 'invalid')
			const atId = (await ocs(ADMIN, 'GET', '/admin/teams')).data?.find?.((t) => t.slug === 'at')?.id
			expect('the last Team Admin of at, from the admin side', await ocs(ADMIN, 'PUT', `/admin/teams/${atId}/members/atadmin`, { role: 'user' }), 409, 'conflict')
			expect('unknown team', await ocs(ADMIN, 'PUT', '/admin/teams/999999/members/pbuser2', { role: 'user' }), 404, 'not_found')
			expect('account not in the team group', await ocs(ADMIN, 'PUT', `/admin/teams/${pbId}/members/atuser1`, { role: 'user' }), 404, 'not_found')
		}
	} finally {
		head('Roles: cleaning up')
		const cur = await ocs(A, 'GET', project(KRP))
		if (cur.status === 200) {
			expect(`delete ${KRP}`, await ocs(A, 'DELETE', `${project(KRP)}?version=${cur.data.version}`), 200)
		}
		for (const uid of made) {
			const r = await ocs(ADMIN, 'DELETE', users(uid))
			check(`test account ${uid} deleted`, r.status === 200, r.text.slice(0, 200))
		}
		const left = sql(`SELECT COUNT(*) FROM oc_ts_members WHERE uid LIKE 'kr${RUN}%';`)
		if (left === null) {
			console.log('    --  app roles of deleted accounts: not checked without TS_SQL')
		} else {
			check('UserDeletedEvent: app role and departure removed', left === '0', left)
		}
	}
}
