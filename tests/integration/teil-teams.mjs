// SPDX-License-Identifier: AGPL-3.0-or-later
import { ADMIN, as, groupsOf, ocs, sql } from './lib.mjs'
import { A, AA, AL, AU, C, L, NOAH, P, R, RUN, U1, U2, V, ISO, b64, check, expect, head, isObj, keysOf, me, sha256, ensure } from './harness.mjs'

export async function teams() {
	head('Capability')
	{
		const r = await ocs(U1, 'GET', '/ocs/v1.php/cloud/capabilities')
		const cap = r.data?.capabilities?.timesister
		check('capabilities contain timesister {api: 2, version}', cap?.api === 2 && typeof cap?.version === 'string', cap)
	}

	// ---------------------------------------------------------------------------
	head('GET /me – team from the team group, role from group admin and app role')
	{
		const expected = [
			['pbadmin', 'pb', 'admin'], ['pbverw', 'pb', 'subadmin'], ['pblead', 'pb', 'lead'],
			['pbuser1', 'pb', 'user'], ['pbuser2', 'pb', 'user'],
			['atadmin', 'at', 'admin'], ['atlead', 'at', 'lead'], ['atuser1', 'at', 'user'],
		]
		for (const [uid, slug, role] of expected) {
			const r = await ocs(as(uid), 'GET', '/me')
			const d = r.data || {}
			check(`${uid}: team ${slug}, role ${role}`,
				r.status === 200 && d.uid === uid && d.team?.slug === slug && d.role === role && d.api === 2
				&& JSON.stringify(d.groups) === JSON.stringify(groupsOf(slug)) && ISO.test(d.server_time)
				&& Number.isInteger(d.revision) && typeof d.display_name === 'string', r.text)
		}
		const lead = (await ocs(L, 'GET', '/me')).data
		check('pblead: admins, leads and calendar_share (default on)', JSON.stringify(lead?.admins) === '["pbadmin"]'
			&& JSON.stringify(lead?.leads) === '["pblead"]' && lead?.calendar_share === true, lead)
		const at = (await ocs(AU, 'GET', '/me')).data
		check('atuser1: admins and leads only from at', JSON.stringify(at?.admins) === '["atadmin"]' && JSON.stringify(at?.leads) === '["atlead"]', at)
		expect('Nextcloud admin without a team group', await ocs(ADMIN, 'GET', '/me'), 403, 'no_team')
		expect('Nextcloud admin without a team group reads no records', await ocs(ADMIN, 'GET', '/records'), 403, 'no_team')
	}

	// ---------------------------------------------------------------------------
	head('Team administration (Nextcloud admins only)')
	{
		const r = await ocs(ADMIN, 'GET', '/admin/teams')
		const pb = r.data?.find?.((t) => t.slug === 'pb')
		const at = r.data?.find?.((t) => t.slug === 'at')
		check('GET /admin/teams: two teams', r.status === 200 && pb && at, r.text)
		check('count per role, plus departed',
			JSON.stringify(pb?.counts) === JSON.stringify({ user: 2, lead: 1, subadmin: 1, admin: 1, left: 0 })
			&& JSON.stringify(at?.counts) === JSON.stringify({ user: 1, lead: 1, subadmin: 0, admin: 1, left: 0 }), { pb: pb?.counts, at: at?.counts })
		check('groups only with team', JSON.stringify(pb?.groups) === '{"team":"pb-team"}' && JSON.stringify(at?.groups) === '{"team":"at-team"}', { pb: pb?.groups, at: at?.groups })
		check('list without members', pb && !('members' in pb))
		expect('GET /admin/teams as pbadmin', await ocs(A, 'GET', '/admin/teams'), 403)
		expect('POST /admin/teams as pbadmin', await ocs(A, 'POST', '/admin/teams', { name: 'X', slug: 'xx', groups: groupsOf('pb') }), 403)
		expect('GET /admin/teams as pbuser1', await ocs(U1, 'GET', '/admin/teams'), 403)
		expect('DELETE /admin/teams as atadmin', await ocs(AA, 'DELETE', `/admin/teams/${pb?.id}`), 403)

		const tmp = { team: `tmp-${RUN}-t` }
		for (const gid of Object.values(tmp)) {
			await ocs(ADMIN, 'POST', '/ocs/v2.php/cloud/groups', { groupid: gid })
		}
		expect('invalid short name', await ocs(ADMIN, 'POST', '/admin/teams', { name: 'T', slug: 'X Y', groups: tmp }), 422, 'invalid')
		expect('unknown group', await ocs(ADMIN, 'POST', '/admin/teams', { name: 'T', slug: `t-${RUN}`, groups: { team: `missing-${RUN}` } }), 422, 'invalid')
		expect('without a team group', await ocs(ADMIN, 'POST', '/admin/teams', { name: 'T', slug: `t-${RUN}`, groups: {} }), 422, 'invalid')
		for (const alt of ['user', 'accounts', 'zeit']) {
			expect(`old key groups.${alt}`, await ocs(ADMIN, 'POST', '/admin/teams', { name: 'T', slug: `t-${RUN}`, groups: { ...tmp, [alt]: 'pb-team' } }), 422, 'invalid')
		}
		expect('team group of another team', await ocs(ADMIN, 'POST', '/admin/teams', { name: 'T', slug: `t-${RUN}`, groups: { team: 'pb-team' } }), 409, 'conflict')
		expect('short name already taken', await ocs(ADMIN, 'POST', '/admin/teams', { name: 'T', slug: 'pb', groups: tmp }), 409, 'conflict')
		const made = await ocs(ADMIN, 'POST', '/admin/teams', { name: 'Temporary', slug: `t-${RUN}`, groups: tmp })
		expect('create team', made, 200)
		check('created team as in GET /team, with counts', made.data?.slug === `t-${RUN}` && JSON.stringify(made.data?.groups) === JSON.stringify(tmp) && made.data?.counts?.user === 0, made.text)
		const upd = await ocs(ADMIN, 'PUT', `/admin/teams/${made.data?.id}`, { name: 'Temporary 2', slug: `t-${RUN}`, groups: tmp })
		check('rename team', upd.status === 200 && upd.data?.name === 'Temporary 2', upd.text)
		expect('delete team without records', await ocs(ADMIN, 'DELETE', `/admin/teams/${made.data?.id}`), 200)
		const after = await ocs(ADMIN, 'GET', '/admin/teams')
		check('deleted team is missing from the list', !after.data?.some?.((t) => t.slug === `t-${RUN}`))
		expect('change an unknown team', await ocs(ADMIN, 'PUT', '/admin/teams/999999', { name: 'T', slug: `t-${RUN}`, groups: tmp }), 404, 'not_found')

		// Group deleted → mapping broken
		const again = await ocs(ADMIN, 'POST', '/admin/teams', { name: 'Broken', slug: `g-${RUN}`, groups: tmp })
		await ocs(ADMIN, 'DELETE', `/ocs/v2.php/cloud/groups/${tmp.team}`)
		const broken = sql(`SELECT broken_at IS NOT NULL FROM oc_ts_tenants WHERE slug = 'g-${RUN}';`)
		if (broken === null) {
			console.log('    --  broken mapping: not checked without TS_SQL')
		} else {
			check('GroupDeletedEvent: team marked as “mapping broken”', broken === '1', broken)
		}
		expect('delete team with a deleted group', await ocs(ADMIN, 'DELETE', `/admin/teams/${again.data?.id}`), 200)
		for (const gid of Object.values(tmp)) {
			await ocs(ADMIN, 'DELETE', `/ocs/v2.php/cloud/groups/${gid}`)
		}
	}

}
