// SPDX-License-Identifier: AGPL-3.0-or-later
import { ADMIN, as, groupsOf, ocs, sql } from './lib.mjs'
import { A, AA, AL, AU, C, L, NOAH, P, R, RUN, U1, U2, V, ISO, b64, check, expect, head, isObj, keysOf, me, sha256, ensure } from './harness.mjs'

export async function teams() {
	head('Fähigkeit')
	{
		const r = await ocs(U1, 'GET', '/ocs/v1.php/cloud/capabilities')
		const cap = r.data?.capabilities?.timesister
		check('Capabilities enthalten timesister {api: 2, version}', cap?.api === 2 && typeof cap?.version === 'string', cap)
	}

	// ---------------------------------------------------------------------------
	head('GET /me – Team aus der Teamgruppe, Rolle aus Gruppenadmin und App-Rolle')
	{
		const expected = [
			['pbadmin', 'pb', 'admin'], ['pbverw', 'pb', 'subadmin'], ['pblead', 'pb', 'lead'],
			['pbuser1', 'pb', 'user'], ['pbuser2', 'pb', 'user'],
			['atadmin', 'at', 'admin'], ['atlead', 'at', 'lead'], ['atuser1', 'at', 'user'],
		]
		for (const [uid, slug, role] of expected) {
			const r = await ocs(as(uid), 'GET', '/me')
			const d = r.data || {}
			check(`${uid}: Team ${slug}, Rolle ${role}`,
				r.status === 200 && d.uid === uid && d.team?.slug === slug && d.role === role && d.api === 2
				&& JSON.stringify(d.groups) === JSON.stringify(groupsOf(slug)) && ISO.test(d.server_time)
				&& Number.isInteger(d.revision) && typeof d.display_name === 'string', r.text)
		}
		const lead = (await ocs(L, 'GET', '/me')).data
		check('pblead: admins, leads und calendar_share (Standard an)', JSON.stringify(lead?.admins) === '["pbadmin"]'
			&& JSON.stringify(lead?.leads) === '["pblead"]' && lead?.calendar_share === true, lead)
		const at = (await ocs(AU, 'GET', '/me')).data
		check('atuser1: admins und leads nur aus at', JSON.stringify(at?.admins) === '["atadmin"]' && JSON.stringify(at?.leads) === '["atlead"]', at)
		expect('Nextcloud-Admin ohne Teamgruppe', await ocs(ADMIN, 'GET', '/me'), 403, 'no_team')
		expect('Nextcloud-Admin ohne Teamgruppe liest keine Datensätze', await ocs(ADMIN, 'GET', '/records'), 403, 'no_team')
	}

	// ---------------------------------------------------------------------------
	head('Verwaltung der Teams (nur Nextcloud-Admins)')
	{
		const r = await ocs(ADMIN, 'GET', '/admin/teams')
		const pb = r.data?.find?.((t) => t.slug === 'pb')
		const at = r.data?.find?.((t) => t.slug === 'at')
		check('GET /admin/teams: zwei Teams', r.status === 200 && pb && at, r.text)
		check('Zählung je Rolle, dazu Ausgetretene',
			JSON.stringify(pb?.counts) === JSON.stringify({ user: 2, lead: 1, subadmin: 1, admin: 1, left: 0 })
			&& JSON.stringify(at?.counts) === JSON.stringify({ user: 1, lead: 1, subadmin: 0, admin: 1, left: 0 }), { pb: pb?.counts, at: at?.counts })
		check('groups nur mit team', JSON.stringify(pb?.groups) === '{"team":"pb-team"}' && JSON.stringify(at?.groups) === '{"team":"at-team"}', { pb: pb?.groups, at: at?.groups })
		check('Liste ohne members', pb && !('members' in pb))
		expect('GET /admin/teams als pbadmin', await ocs(A, 'GET', '/admin/teams'), 403)
		expect('POST /admin/teams als pbadmin', await ocs(A, 'POST', '/admin/teams', { name: 'X', slug: 'xx', groups: groupsOf('pb') }), 403)
		expect('GET /admin/teams als pbuser1', await ocs(U1, 'GET', '/admin/teams'), 403)
		expect('DELETE /admin/teams als atadmin', await ocs(AA, 'DELETE', `/admin/teams/${pb?.id}`), 403)

		const tmp = { team: `tmp-${RUN}-t` }
		for (const gid of Object.values(tmp)) {
			await ocs(ADMIN, 'POST', '/ocs/v2.php/cloud/groups', { groupid: gid })
		}
		expect('ungültiger Kurzname', await ocs(ADMIN, 'POST', '/admin/teams', { name: 'T', slug: 'X Y', groups: tmp }), 422, 'invalid')
		expect('unbekannte Gruppe', await ocs(ADMIN, 'POST', '/admin/teams', { name: 'T', slug: `t-${RUN}`, groups: { team: `fehlt-${RUN}` } }), 422, 'invalid')
		expect('ohne Teamgruppe', await ocs(ADMIN, 'POST', '/admin/teams', { name: 'T', slug: `t-${RUN}`, groups: {} }), 422, 'invalid')
		for (const alt of ['user', 'accounts', 'zeit']) {
			expect(`alter Schlüssel groups.${alt}`, await ocs(ADMIN, 'POST', '/admin/teams', { name: 'T', slug: `t-${RUN}`, groups: { ...tmp, [alt]: 'pb-team' } }), 422, 'invalid')
		}
		expect('Teamgruppe eines anderen Teams', await ocs(ADMIN, 'POST', '/admin/teams', { name: 'T', slug: `t-${RUN}`, groups: { team: 'pb-team' } }), 409, 'conflict')
		expect('Kurzname schon vergeben', await ocs(ADMIN, 'POST', '/admin/teams', { name: 'T', slug: 'pb', groups: tmp }), 409, 'conflict')
		const made = await ocs(ADMIN, 'POST', '/admin/teams', { name: 'Temporär', slug: `t-${RUN}`, groups: tmp })
		expect('Team anlegen', made, 200)
		check('angelegtes Team wie GET /team, mit counts', made.data?.slug === `t-${RUN}` && JSON.stringify(made.data?.groups) === JSON.stringify(tmp) && made.data?.counts?.user === 0, made.text)
		const upd = await ocs(ADMIN, 'PUT', `/admin/teams/${made.data?.id}`, { name: 'Temporär 2', slug: `t-${RUN}`, groups: tmp })
		check('Team umbenennen', upd.status === 200 && upd.data?.name === 'Temporär 2', upd.text)
		expect('Team ohne Datensätze löschen', await ocs(ADMIN, 'DELETE', `/admin/teams/${made.data?.id}`), 200)
		const after = await ocs(ADMIN, 'GET', '/admin/teams')
		check('gelöschtes Team fehlt in der Liste', !after.data?.some?.((t) => t.slug === `t-${RUN}`))
		expect('unbekanntes Team ändern', await ocs(ADMIN, 'PUT', '/admin/teams/999999', { name: 'T', slug: `t-${RUN}`, groups: tmp }), 404, 'not_found')

		// Gruppe gelöscht → Zuordnung gebrochen
		const again = await ocs(ADMIN, 'POST', '/admin/teams', { name: 'Gebrochen', slug: `g-${RUN}`, groups: tmp })
		await ocs(ADMIN, 'DELETE', `/ocs/v2.php/cloud/groups/${tmp.team}`)
		const broken = sql(`SELECT broken_at IS NOT NULL FROM oc_ts_tenants WHERE slug = 'g-${RUN}';`)
		if (broken === null) {
			console.log('    --  Zuordnung gebrochen: ohne TS_SQL nicht geprüft')
		} else {
			check('GroupDeletedEvent: Team als „Zuordnung gebrochen“ markiert', broken === '1', broken)
		}
		expect('Team mit gelöschter Gruppe löschen', await ocs(ADMIN, 'DELETE', `/admin/teams/${again.data?.id}`), 200)
		for (const gid of Object.values(tmp)) {
			await ocs(ADMIN, 'DELETE', `/ocs/v2.php/cloud/groups/${gid}`)
		}
	}

}
