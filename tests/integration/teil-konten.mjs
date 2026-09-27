// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Version 2: membership via the team group, admin as group admin, app roles
// and departure (PUT /team/members, admin side), calendar sharing. This
// run's test accounts are named kr<RUN>… and are deleted at the end.
import { ADMIN, as, ocs, sql } from './lib.mjs'
import { A, AA, ISO, L, RUN, U1, V, check, expect, head } from './harness.mjs'

const users = (uid) => `/ocs/v2.php/cloud/users/${encodeURIComponent(uid)}`
const member = (uid) => `/team/members/${encodeURIComponent(uid)}`

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
	const pbId = (await ocs(ADMIN, 'GET', '/admin/teams')).data?.find?.((t) => t.slug === 'pb')?.id
	try {
		head('GET /team: members with role')
		{
			const t = await ocs(A, 'GET', '/team')
			const list = t.data?.members || []
			const by = Object.fromEntries(list.map((x) => [x.uid, x]))
			check('pb: roles from group admin and app role', t.status === 200 && by.pbadmin?.role === 'admin' && by.pbverw?.role === 'subadmin'
				&& by.pblead?.role === 'lead' && by.pbuser1?.role === 'user' && by.pbuser2?.role === 'user', list)
			check('entries with uid, display_name, role, left_at; no at accounts', list.every((x) => JSON.stringify(Object.keys(x)) === '["uid","display_name","role","left_at"]')
				&& by.pblead?.display_name === 'Lea Planer' && by.pblead?.left_at === null && !list.some((x) => x.uid.startsWith('at')), list[0])
		}

		if (await makeUser(kr, ['pb-team'])) {
			made.push(kr)
			head('PUT /team/members: permissions')
			expect('user sets lead', await ocs(U1, 'PUT', member(kr), { role: 'lead' }), 403, 'forbidden')
			expect('lead sets lead', await ocs(L, 'PUT', member(kr), { role: 'lead' }), 403, 'forbidden')
			const r = await ocs(V, 'PUT', member(kr), { role: 'lead' })
			check('manager sets lead → entry as in /team', r.status === 200 && r.data?.uid === kr && r.data?.role === 'lead' && r.data?.left_at === null, r.text)
			expect('manager grants subadmin', await ocs(V, 'PUT', member(kr), { role: 'subadmin' }), 403, 'forbidden')
			expect('admin is not an app role', await ocs(A, 'PUT', member(kr), { role: 'admin' }), 422, 'invalid')
			expect('empty body', await ocs(A, 'PUT', member(kr), {}), 400, 'invalid')
			expect('left is not a boolean', await ocs(A, 'PUT', member(kr), { left: 'ja' }), 422, 'invalid')
			expect('account of another team', await ocs(A, 'PUT', member('atuser1'), { role: 'lead' }), 404, 'not_found')
			expect('account that does not exist', await ocs(A, 'PUT', member(`nie${RUN}`), { role: 'lead' }), 404, 'not_found')
			expect('at admin for a pb account', await ocs(AA, 'PUT', member(kr), { role: 'user' }), 404, 'not_found')
			const s = await ocs(A, 'PUT', member(kr), { role: 'subadmin' })
			check('admin grants subadmin', s.status === 200 && s.data?.role === 'subadmin', s.text)
			check('… the account\'s /me: subadmin', (await ocs(as(kr), 'GET', '/me')).data?.role === 'subadmin')
			expect('manager changes another manager', await ocs(V, 'PUT', member(kr), { role: 'user' }), 403, 'forbidden')
			expect('manager records the admin\'s departure', await ocs(V, 'PUT', member('pbadmin'), { left: true }), 403, 'forbidden')
			expect('own departure', await ocs(A, 'PUT', member('pbadmin'), { left: true }), 403, 'forbidden')
			await ocs(A, 'PUT', member(kr), { role: 'lead' })

			head('Departure and readmission')
			const before = (await ocs(as(kr), 'GET', '/me')).data
			check('before: lead, is in leads', before?.role === 'lead' && before?.leads?.includes(kr), before)
			const weg = await ocs(V, 'PUT', member(kr), { left: true })
			check('left: true → left_at set, role stays', weg.status === 200 && ISO.test(weg.data?.left_at || '') && weg.data?.role === 'lead', weg.text)
			expect('/me afterwards', await ocs(as(kr), 'GET', '/me'), 403, 'no_team')
			expect('… and /records', await ocs(as(kr), 'GET', '/records'), 403, 'no_team')
			check('… no longer in the leads of others', !(await ocs(U1, 'GET', '/me')).data?.leads?.includes(kr))
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

			head('Calendar sharing: /me/calendar-share and calendar_shared')
			const K = as(kr)
			const d = await ocs(K, 'GET', '/me/calendar-share')
			check('default: on', d.status === 200 && JSON.stringify(d.data) === '{"enabled":true}', d.text)
			const off = await ocs(K, 'PUT', '/me/calendar-share', { enabled: false })
			check('PUT false', off.status === 200 && off.data?.enabled === false, off.text)
			check('GET and /me read false', (await ocs(K, 'GET', '/me/calendar-share')).data?.enabled === false
				&& (await ocs(K, 'GET', '/me')).data?.calendar_share === false)
			check('other accounts unaffected', (await ocs(U1, 'GET', '/me')).data?.calendar_share !== undefined)
			expect('enabled is not a boolean', await ocs(K, 'PUT', '/me/calendar-share', { enabled: 'nein' }), 400, 'invalid')
			expect('uid in the body', await ocs(K, 'PUT', '/me/calendar-share', { enabled: true, uid: 'pbuser1' }), 400, 'invalid')
			check('PUT true', (await ocs(K, 'PUT', '/me/calendar-share', { enabled: true })).data?.enabled === true)
			expect('Nextcloud admin without a team', await ocs(ADMIN, 'GET', '/me/calendar-share'), 403, 'no_team')

			const sh = await ocs(K, 'POST', '/status', { calendar_shared: false })
			const st1 = ((await ocs(V, 'GET', '/status')).data || []).find((x) => x.uid === kr)
			check('POST /status calendar_shared false → GET /status', sh.status === 200 && st1?.calendar_shared === false, st1)
			await ocs(K, 'POST', '/status', { app_version: '0.3.0' })
			const st2 = ((await ocs(V, 'GET', '/status')).data || []).find((x) => x.uid === kr)
			check('missing stays as is', st2?.calendar_shared === false && st2?.app_version === '0.3.0', st2)
			await ocs(K, 'POST', '/status', { calendar_shared: true })
			check('true', ((await ocs(V, 'GET', '/status')).data || []).find((x) => x.uid === kr)?.calendar_shared === true)
			expect('calendar_shared is not a boolean', await ocs(K, 'POST', '/status', { calendar_shared: 1 }), 422, 'invalid')
		}

		head('Admin = group admin of the team group')
		if (await makeUser(ga, [])) {
			made.push(ga)
			expect('without a group: /me', await ocs(as(ga), 'GET', '/me'), 403, 'no_team')
			const sub = await ocs(ADMIN, 'POST', `${users(ga)}/subadmins`, { groupid: 'pb-team' })
			check('Nextcloud admin makes the account group admin of pb-team', sub.status === 200, sub.text.slice(0, 200))
			const m = await ocs(as(ga), 'GET', '/me')
			check('group admin, not in the group: team pb, role admin', m.status === 200 && m.data?.team?.slug === 'pb' && m.data?.role === 'admin', m.text.slice(0, 300))
			check('… in admins alongside pbadmin', m.data?.admins?.includes(ga) && m.data?.admins?.includes('pbadmin'), m.data?.admins)
			check('… and in /team as admin', ((await ocs(A, 'GET', '/team')).data?.members || []).some((x) => x.uid === ga && x.role === 'admin'))
			const st = ((await ocs(V, 'GET', '/status')).data || []).find((x) => x.uid === ga)
			check('… and in /status, calendar_shared never reported: null', st && st.calendar_shared === null, st)
		}

		head('Roles from the admin side (PUT /admin/teams/{id}/members/{uid})')
		{
			expect('as pbadmin (not a Nextcloud admin)', await ocs(A, 'PUT', `/admin/teams/${pbId}/members/pbuser2`, { role: 'lead' }), 403)
			const r = await ocs(ADMIN, 'PUT', `/admin/teams/${pbId}/members/pbuser2`, { role: 'subadmin' })
			check('Nextcloud admin grants subadmin', r.status === 200 && r.data?.role === 'subadmin', r.text)
			const back = await ocs(ADMIN, 'PUT', `/admin/teams/${pbId}/members/pbuser2`, { role: 'user' })
			check('… and withdraws it', back.status === 200 && back.data?.role === 'user', back.text)
			expect('app role admin', await ocs(ADMIN, 'PUT', `/admin/teams/${pbId}/members/pbuser2`, { role: 'admin' }), 422, 'invalid')
			expect('unknown team', await ocs(ADMIN, 'PUT', '/admin/teams/999999/members/pbuser2', { role: 'lead' }), 404, 'not_found')
			expect('account not in the team group', await ocs(ADMIN, 'PUT', `/admin/teams/${pbId}/members/atuser1`, { role: 'lead' }), 404, 'not_found')
		}
	} finally {
		head('Roles: cleaning up')
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
