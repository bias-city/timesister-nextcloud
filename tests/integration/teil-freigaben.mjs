// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The shares matrix (0.5.0): GET/PUT /team/access, defaults, overriding,
// share_targets in /me, applied_shares in the status report, effective and
// pending, reminders as Nextcloud notifications. Leaves pb as it found it:
// every field back to default, overriding off.
import { ADMIN, as, ocs } from './lib.mjs'
import { A, AA, L, U1, U2, V, check, expect, head } from './harness.mjs'

const field = (d, viewer, owner) => (d?.fields || []).find((f) => f.viewer === viewer && f.owner === owner)
const put = (who, viewer, owner, level) => ocs(who, 'PUT', `/team/access/${viewer}/${owner}`, { level })
const notes = async (who, lang = 'en') => ((await ocs(who, 'GET', '/ocs/v2.php/apps/notifications/api/v2/notifications', undefined, { lang })).data || [])
	.filter((n) => n.app === 'timesister')
const targets = async (who) => JSON.stringify((await ocs(who, 'GET', '/me')).data?.share_targets)

export async function freigaben() {
	const pb = ['pbadmin', 'pbverw', 'pblead', 'pbuser1', 'pbuser2']
	try {
		head('GET /team/access: defaults')
		{
			const r = await ocs(A, 'GET', '/team/access')
			const d = r.data
			const uids = (d?.members || []).map((m) => m.uid)
			check('Team Admin: can_edit, all of pb, sorted by role', r.status === 200 && d.can_edit === true
				&& JSON.stringify(uids.filter((u) => pb.includes(u))) === '["pbverw","pbadmin","pblead","pbuser1","pbuser2"]'
				&& !uids.some((u) => u.startsWith('at')), uids)
			const n = uids.length
			check('every field except the diagonal', d?.fields?.length === n * (n - 1) && !d.fields.some((f) => f.viewer === f.owner), d?.fields?.length)
			check('rows of Team Admins: edit', field(d, 'pbadmin', 'pbuser1')?.level === 'edit' && field(d, 'pbverw', 'pblead')?.level === 'edit'
				&& field(d, 'pbadmin', 'pbverw')?.level === 'edit')
			check('everything else: none', field(d, 'pblead', 'pbuser1')?.level === 'none' && field(d, 'pbuser1', 'pbadmin')?.level === 'none')
			const u = (await ocs(U1, 'GET', '/team/access')).data
			check('User: only their column and row, can_edit off', u?.can_edit === false
				&& u.fields.every((f) => f.owner === 'pbuser1' || f.viewer === 'pbuser1') && u.fields.length === 2 * (n - 1), u?.fields?.length)
			check('… the team\'s names, without the others\' flags', (u?.members || []).some((m) => m.uid === 'pblead' && m.display_name === 'Lea Planer' && !('may_override' in m))
				&& (u?.members || []).find((m) => m.uid === 'pbuser1')?.may_override === false)
			const at = (await ocs(AA, 'GET', '/team/access')).data
			check('team boundary: at sees no pb account', !(at?.members || []).some((m) => pb.includes(m.uid)), at?.members)
			expect('Nextcloud admin without a team', await ocs(ADMIN, 'GET', '/team/access'), 403, 'no_team')
		}

		head('PUT /team/access: who may set what')
		{
			expect('Lead sets a field', await put(L, 'pblead', 'pbuser1', 'view'), 403, 'forbidden')
			expect('User, own column, overriding not allowed', await put(U1, 'pblead', 'pbuser1', 'view'), 403, 'forbidden')
			// The account language decides (Nextcloud keeps the first Accept-Language).
			const userPath = '/ocs/v2.php/cloud/users/pbuser1'
			const langBefore = (await ocs(ADMIN, 'GET', userPath)).data?.language || 'en'
			try {
				await ocs(ADMIN, 'PUT', userPath, { key: 'language', value: 'de' })
				const de = await put(U1, 'pblead', 'pbuser1', 'view')
				check('… the message in German with the role words unchanged', de.data?.message === 'Dein Team Admin hat das Übersteuern nicht erlaubt.', de.data)
			} finally {
				await ocs(ADMIN, 'PUT', userPath, { key: 'language', value: langBefore })
			}
			expect('User, another column', await put(U1, 'pblead', 'pbuser2', 'view'), 403, 'forbidden')
			expect('invalid level', await put(A, 'pblead', 'pbuser1', 'read'), 422, 'invalid')
			expect('diagonal', await put(A, 'pblead', 'pblead', 'view'), 422, 'invalid')
			expect('account of another team', await put(A, 'atuser1', 'pbuser1', 'view'), 404, 'not_found')
			expect('batch without changes', await ocs(A, 'PUT', '/team/access', { changes: [] }), 422, 'invalid')
			const r = await put(A, 'pblead', 'pbuser1', 'view')
			const f = field(r.data, 'pblead', 'pbuser1')
			check('Team Admin: Lead sees pbuser1', r.status === 200 && f?.level === 'view' && f?.admin_level === 'view' && f?.changed_by === 'pbadmin' && f?.pending === true, f)
			check('/me.share_targets of pbuser1', await targets(U1) === '[{"uid":"pbadmin","access":"write"},{"uid":"pblead","access":"read"},{"uid":"pbverw","access":"write"}]', await targets(U1))
			const b = await ocs(V, 'PUT', '/team/access', { changes: [
				{ viewer: 'pblead', owner: 'pbuser2', level: 'edit' },
				{ viewer: 'pbuser1', owner: 'pbuser2', level: 'view' },
			] })
			check('batch by the second Team Admin', b.status === 200 && field(b.data, 'pblead', 'pbuser2')?.level === 'edit' && field(b.data, 'pbuser1', 'pbuser2')?.level === 'view', b.text.slice(0, 300))
			const bad = await ocs(V, 'PUT', '/team/access', { changes: [
				{ viewer: 'pblead', owner: 'pbuser2', level: 'none' },
				{ viewer: 'pblead', owner: 'pblead', level: 'none' },
			] })
			check('batch with one bad change: nothing written', bad.status === 422 && field((await ocs(A, 'GET', '/team/access')).data, 'pblead', 'pbuser2')?.level === 'edit', bad.text)
			const dflt = await put(A, 'pbuser1', 'pbuser2', 'default')
			check('default: back to none', field(dflt.data, 'pbuser1', 'pbuser2')?.level === 'none' && field(dflt.data, 'pbuser1', 'pbuser2')?.changed_at === null)
		}

		head('Status report: applied_shares, effective and pending')
		{
			await ocs(U1, 'POST', '/status', { applied_shares: [{ uid: 'pbadmin', access: 'write' }, { uid: 'pbverw', access: 'write' }] })
			let d = (await ocs(A, 'GET', '/team/access')).data
			check('reported without the Lead: pbadmin effective, pblead pending', field(d, 'pbadmin', 'pbuser1')?.effective === true
				&& field(d, 'pblead', 'pbuser1')?.effective === false && field(d, 'pblead', 'pbuser1')?.applied === 'none'
				&& (d.members || []).find((m) => m.uid === 'pbuser1')?.reported_at, [field(d, 'pblead', 'pbuser1'), d.members?.[3]])
			await ocs(U1, 'POST', '/status', { applied_shares: [{ uid: 'pbadmin', access: 'write' }, { uid: 'pblead', access: 'read' }, { uid: 'pbverw', access: 'write' }] })
			d = (await ocs(A, 'GET', '/team/access')).data
			check('reported with the Lead: effective', field(d, 'pblead', 'pbuser1')?.effective === true && field(d, 'pblead', 'pbuser1')?.pending === false)
			expect('applied_shares with a bad entry', await ocs(U1, 'POST', '/status', { applied_shares: [{ uid: 'pblead', access: 'owner' }] }), 422, 'invalid')
		}

		head('Reminders: Nextcloud notification')
		{
			// pbuser2 has never reported: its fields are pending.
			const r = await ocs(A, 'POST', '/team/access/remind')
			check('Team Admin reminds: pbuser2 among them, pbuser1 (all set) not', r.status === 200 && r.data?.notified?.includes('pbuser2') && !r.data?.notified?.includes('pbuser1'), r.text)
			expect('User reminds', await ocs(U1, 'POST', '/team/access/remind'), 403, 'forbidden')
			const en = await notes(U2)
			check('pbuser2 has the notification', en.length === 1 && en[0].subject === 'Please open TimeSister – your calendar shares have changed.', en)
			const u2Path = '/ocs/v2.php/cloud/users/pbuser2'
			const langBefore = (await ocs(ADMIN, 'GET', u2Path)).data?.language || 'en'
			try {
				await ocs(ADMIN, 'PUT', u2Path, { key: 'language', value: 'de' })
				const de = await notes(U2, 'de')
				check('… in German for an account in German', de[0]?.subject === 'Bitte öffne TimeSister – deine Kalenderfreigaben haben sich geändert.', de[0]?.subject)
			} finally {
				await ocs(ADMIN, 'PUT', u2Path, { key: 'language', value: langBefore })
			}
			await ocs(U2, 'POST', '/status', { applied_shares: [{ uid: 'pbadmin', access: 'write' }, { uid: 'pblead', access: 'write' }, { uid: 'pbverw', access: 'write' }] })
			check('all set: the notification goes away', (await notes(U2)).length === 0)
			// Withdrawing: automatic reminder.
			await put(A, 'pblead', 'pbuser2', 'none')
			check('withdrawing reminds automatically', (await notes(U2)).length === 1)
			const d = (await ocs(A, 'GET', '/team/access')).data
			check('… pending until pbuser2 syncs', field(d, 'pblead', 'pbuser2')?.pending === true && field(d, 'pblead', 'pbuser2')?.applied === 'edit')
			await ocs(U2, 'POST', '/status', { applied_shares: [{ uid: 'pbadmin', access: 'write' }, { uid: 'pbverw', access: 'write' }] })
			check('… withdrawn: effective, notification gone', field((await ocs(A, 'GET', '/team/access')).data, 'pblead', 'pbuser2')?.effective === true && (await notes(U2)).length === 0)
		}

		head('Overriding: only when allowed')
		{
			await ocs(A, 'PUT', '/team/members/pbuser1', { may_override: true })
			check('/me.may_override on', (await ocs(U1, 'GET', '/me')).data?.may_override === true)
			const own = await put(U1, 'pbadmin', 'pbuser1', 'none')
			let f = field(own.data, 'pbadmin', 'pbuser1')
			check('the person shuts out a Team Admin', own.status === 200 && f?.level === 'none' && f?.self_level === 'none' && f?.overridden === 'restricted', f)
			const more = await put(U1, 'pbuser2', 'pbuser1', 'view')
			check('… and grants someone else', field(more.data, 'pbuser2', 'pbuser1')?.overridden === 'granted')
			check('share_targets: the own choice wins', await targets(U1) === '[{"uid":"pblead","access":"read"},{"uid":"pbuser2","access":"read"},{"uid":"pbverw","access":"write"}]', await targets(U1))
			const adm = await put(A, 'pbadmin', 'pbuser1', 'edit')
			f = field(adm.data, 'pbadmin', 'pbuser1')
			check('the Team Admin cannot undo it', f?.level === 'none' && f?.admin_level === 'edit' && f?.overridden === 'restricted', f)
			await ocs(A, 'PUT', '/team/members/pbuser1', { may_override: false })
			f = field((await ocs(A, 'GET', '/team/access')).data, 'pbadmin', 'pbuser1')
			check('overriding off: the choice rests, the Team Admin\'s applies', f?.level === 'edit' && f?.resting === true && f?.self_level === 'none', f)
			expect('… and the person can no longer change it', await put(U1, 'pbadmin', 'pbuser1', 'default'), 403, 'forbidden')
			await ocs(A, 'PUT', '/team/members/pbuser1', { may_override: true })
			check('on again: the rested choice applies again', field((await ocs(A, 'GET', '/team/access')).data, 'pbadmin', 'pbuser1')?.level === 'none')
			const back = await put(U1, 'pbadmin', 'pbuser1', 'default')
			check('the person returns to the Team Admin\'s choice', field(back.data, 'pbadmin', 'pbuser1')?.level === 'edit' && field(back.data, 'pbadmin', 'pbuser1')?.self_level === null)
		}
	} finally {
		head('Shares: cleaning up')
		const users = ['pbadmin', 'pbverw', 'pblead', 'pbuser1', 'pbuser2']
		const changes = []
		for (const viewer of users) {
			for (const owner of users) {
				if (viewer !== owner) {
					changes.push({ viewer, owner, level: 'default' })
				}
			}
		}
		// The own choices of pbuser1 (only they can remove them).
		await ocs(A, 'PUT', '/team/members/pbuser1', { may_override: true })
		await ocs(U1, 'PUT', '/team/access', { changes: users.filter((u) => u !== 'pbuser1').map((viewer) => ({ viewer, owner: 'pbuser1', level: 'default' })) })
		await ocs(A, 'PUT', '/team/members/pbuser1', { may_override: false })
		const r = await ocs(A, 'PUT', '/team/access', { changes })
		check('every pb field back to default', r.status === 200 && r.data.fields.every((f) => f.changed_at === null && f.self_level === null), r.text.slice(0, 200))
		for (const who of [U1, U2]) {
			await ocs(who, 'POST', '/status', { applied_shares: null })
		}
		for (const who of [as('pbuser1'), U2]) {
			for (const n of await notes(who)) {
				await ocs(who, 'DELETE', `/ocs/v2.php/apps/notifications/api/v2/notifications/${n.notification_id}`)
			}
		}
	}
}
