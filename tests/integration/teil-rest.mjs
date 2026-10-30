// SPDX-License-Identifier: AGPL-3.0-or-later
import { ADMIN, as, ocs, sql } from './lib.mjs'
import { C, NOAH, P, R, RUN, ISO, b64, check, expect, head, isObj, keysOf, me, sha256, ensure } from './harness.mjs'
import { A, AA, AL, AU, L, U1, U2, V, nameOf } from './konten.mjs'
import { restoreConsent } from './teil-sicherungen.mjs'

export async function rest() {
	await kalendersicherungen()
	await restOhneSicherungen()
}

/** POST/GET /backups (version 1), self-contained part: also runs alone. */
export async function kalendersicherungen() {
	let backupId
	head('Calendar backups')
	// Since version 1.1 only with consent; afterwards as before again.
	const prior = (await ocs(U1, 'GET', '/backups/consent')).data
	await ocs(U1, 'PUT', '/backups/consent', { consent: true, notice: '2026-09-26.2' })
	try {
		const ics1 = `BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//TimeSister//Test ${RUN}//DE\r\nEND:VCALENDAR\r\n`
		const ics2 = ics1.replace('Test', 'Zweite')
		const up = await ocs(U1, 'POST', '/backups', { taken_on: '2099-12-31', ics_base64: b64(ics1) })
		expect('upload backup', up, 200)
		backupId = up.data?.id
		check('response with id, uid, taken_on, size, sha256', Number.isInteger(backupId) && up.data?.uid === 'zbuser1' && up.data?.taken_on === '2099-12-31'
			&& up.data?.size === Buffer.byteLength(ics1) && up.data?.sha256 === sha256(ics1), up.text)
		check('uploaded: source client, visible copy at the backup account', up.data?.source === 'client'
			&& up.data?.file_path === `TimeSister Backups/${nameOf('zbuser1')} (zbuser1)/2099-12-31.ics`, up.text)
		const up2 = await ocs(U1, 'POST', '/backups', { taken_on: '2099-12-31', ics_base64: b64(ics2) })
		check('a second one on the same day replaces the first (same id, new hash)', up2.status === 200 && up2.data?.id === backupId && up2.data?.sha256 === sha256(ics2), up2.text)
		expect('older backup', await ocs(U1, 'POST', '/backups', { taken_on: '2099-12-30', ics_base64: b64(ics1) }), 200)
		const list = await ocs(U1, 'GET', '/backups')
		const days = (list.data || []).map((x) => x.taken_on)
		check('list newest first, one per day, without content', list.status === 200 && days.indexOf('2099-12-31') < days.indexOf('2099-12-30') && days.filter((d) => d === '2099-12-31').length === 1
			&& days.indexOf('2099-12-30') >= 0 && !('ics_base64' in (list.data?.[0] || {})), list.text)
		const one = await ocs(U1, 'GET', `/backups/${backupId}`)
		check('GET /backups/{id} with ics_base64', one.status === 200 && Buffer.from(one.data?.ics_base64 || '', 'base64').toString() === ics2, one.text.slice(0, 200))
		expect('wrong day', await ocs(U1, 'POST', '/backups', { taken_on: '2026-02-30', ics_base64: b64(ics1) }), 422, 'invalid')
		expect('not a calendar', await ocs(U1, 'POST', '/backups', { taken_on: '2026-09-21', ics_base64: b64('BEGIN:VCARD\r\nEND:VCARD\r\n') }), 422, 'invalid')
		expect('not base64', await ocs(U1, 'POST', '/backups', { taken_on: '2026-09-21', ics_base64: '%%%' }), 422, 'invalid')
		const headIcs = 'BEGIN:VCALENDAR\r\n'
		const exact = headIcs + 'x'.repeat(20 * 1024 * 1024 - headIcs.length)
		const ex = await ocs(U1, 'POST', '/backups', { taken_on: '2000-01-01', ics_base64: b64(exact) })
		check('exactly 20 MB is fine', ex.status === 200 && ex.data?.size === 20 * 1024 * 1024, ex.text.slice(0, 200))
		expect('20 MB + 1 byte', await ocs(U1, 'POST', '/backups', { taken_on: '2000-01-02', ics_base64: b64(exact + 'y') }), 413, 'too_large')

		expect('another backup as user', await ocs(U2, 'GET', `/backups/${backupId}`), 403, 'forbidden')
		expect('another list as user', await ocs(U2, 'GET', '/backups?uid=zbuser1'), 403, 'forbidden')
		expect('another backup as lead', await ocs(L, 'GET', `/backups/${backupId}`), 403, 'forbidden')
		const vl = await ocs(V, 'GET', '/backups?uid=zbuser1')
		check('second Team Admin sees another\'s list', vl.status === 200 && vl.data?.some?.((x) => x.id === backupId))
		expect('Team Admin gets another backup', await ocs(A, 'GET', `/backups/${backupId}`), 200)
		expect('za admin gets a zb backup', await ocs(AA, 'GET', `/backups/${backupId}`), 404, 'not_found')
		const al = await ocs(AA, 'GET', '/backups?uid=zbuser1')
		check('za admin: list for a zb account is empty', al.status === 200 && Array.isArray(al.data) && al.data.length === 0, al.text)
		expect('unknown backup', await ocs(A, 'GET', '/backups/999999999'), 404, 'not_found')
	} finally {
		await restoreConsent(U1, prior)
	}
}

async function restOhneSicherungen() {

	// ---------------------------------------------------------------------------
	head('Heartbeat')
	{
		const s = await ocs(U1, 'POST', '/status', {
			app_version: '0.2.0', last_sync: '2026-09-26T08:15:00Z', last_backup: '2026-09-22',
			calendar_url: 'http://localhost:8081/remote.php/dav/calendars/zbuser1/zeit-zbuser1/',
		})
		check('POST /status → seen_at', s.status === 200 && ISO.test(s.data?.seen_at), s.text)
		expect('calendar_url javascript:', await ocs(U1, 'POST', '/status', { calendar_url: 'javascript:alert(1)' }), 422, 'invalid')
		expect('last_sync not ISO', await ocs(U1, 'POST', '/status', { last_sync: 'gestern' }), 422, 'invalid')
		// A fresh account that certainly never reported yet (several checks share the environment).
		const still = `still${RUN}`
		await ocs(ADMIN, 'POST', '/ocs/v2.php/cloud/users', { userid: still, password: `Test-2026-${still}!`, groups: ['zb-team'] })
		const list = await ocs(V, 'GET', '/status')
		const by = Object.fromEntries((list.data || []).map((x) => [x.uid, x]))
		check('all zb members, even without a heartbeat', ['zbadmin', 'zblead', 'zbuser1', 'zbuser2', 'zbverw', still].every((u) => u in by) && !Object.keys(by).some((u) => u.startsWith('za')), Object.keys(by))
		check('zbuser1 with their data', by.zbuser1?.app_version === '0.2.0' && by.zbuser1?.last_sync === '2026-09-26T08:15:00Z'
			&& by.zbuser1?.last_backup === '2026-09-22' && by.zbuser1?.role === 'user' && by.zbuser1?.display_name === nameOf('zbuser1'), by.zbuser1)
		check('new account without a heartbeat: everything null', by[still] && by[still].seen_at === null && by[still].app_version === null && by[still].calendar_url === null && by[still].role === 'user', by[still])
		check('roles from group admin and project leads', by.zbadmin?.role === 'admin' && by.zbverw?.role === 'admin' && by.zblead?.role === 'lead')
		const part = await ocs(U1, 'POST', '/status', { app_version: '0.2.1' })
		const after = Object.fromEntries(((await ocs(V, 'GET', '/status')).data || []).map((x) => [x.uid, x]))
		check('missing fields stay as they were', part.status === 200 && after.zbuser1?.app_version === '0.2.1' && after.zbuser1?.last_backup === '2026-09-22', after.zbuser1)
		await ocs(ADMIN, 'DELETE', `/ocs/v2.php/cloud/users/${still}`)
	}

	// ---------------------------------------------------------------------------
	head('Account in two teams, account deleted')
	{
		await ocs(ADMIN, 'POST', '/ocs/v2.php/cloud/users/zbuser1/groups', { groupid: 'za-team' })
		try {
			expect('zbuser1 additionally in za: /me', await ocs(U1, 'GET', '/me'), 409, 'ambiguous_team')
			expect('… and /records', await ocs(U1, 'GET', '/records'), 409, 'ambiguous_team')
			expect('… and /backups', await ocs(U1, 'GET', '/backups'), 409, 'ambiguous_team')
		} finally {
			await ocs(ADMIN, 'DELETE', '/ocs/v2.php/cloud/users/zbuser1/groups', { groupid: 'za-team' }, { form: true })
		}
		const back = await ocs(U1, 'GET', '/me')
		check('cleaned up: zbuser1 back in only zb', back.status === 200 && back.data?.team?.slug === 'zb', back.text)

		const tmp = `weg${RUN}`
		await ocs(ADMIN, 'POST', '/ocs/v2.php/cloud/users', { userid: tmp, password: `Test-2026-${tmp}!`, groups: ['zb-team'] })
		const pr = await ocs(A, 'PUT', `/records/person/${tmp}`, { version: 0, data: { login: tmp, first_name: 'Weg' } })
		const del = await ocs(ADMIN, 'DELETE', `/ocs/v2.php/cloud/users/${tmp}`)
		check('account deleted', pr.status === 200 && del.status === 200, `${pr.status} ${del.status}`)
		const still = await ocs(A, 'GET', `/records/person/${tmp}`)
		check('the person record stays, data and version unchanged', still.status === 200 && still.data?.version === 1 && still.data?.data?.first_name === 'Weg'
			&& still.data?.revision === pr.data?.revision, still.text)
		const mark = sql(`SELECT account_deleted_at IS NOT NULL FROM oc_ts_records WHERE kind = 'person' AND rkey = '${tmp}';`)
		if (mark === null) {
			console.log('    --  account_deleted_at mark: not checked without TS_SQL')
		} else {
			check('UserDeletedEvent: account_deleted_at mark set', mark === '1', mark)
		}
	}

	// ---------------------------------------------------------------------------
	head('Deleting a team needs the typed name (0.10.1); zb is never deleted here')
	{
		const zb = (await ocs(ADMIN, 'GET', '/admin/teams')).data?.find?.((t) => t.slug === 'zb')
		expect('DELETE /admin/teams/{zb} without confirm', await ocs(ADMIN, 'DELETE', `/admin/teams/${zb?.id}`), 422, 'confirm')
		expect('DELETE /admin/teams/{zb} wrong name', await ocs(ADMIN, 'DELETE', `/admin/teams/${zb?.id}`, { confirm: 'zb' }), 422, 'confirm')
	}

	// ---------------------------------------------------------------------------
	head('Cleaning up')
	await aufraeumen(RUN)

}

/** An issued (or reversed, paid) invoice stays on the server – deleting it is `409 issued`. */
const bleibt = (x) => x.kind === 'invoice' && !!x.data && typeof x.data.status === 'string' && x.data.status !== 'draft'
/** What the server keeps by its rules (409 with these codes): left out, not a failure. */
const BLEIBT = ['invoice_issued', 'issue_pending', 'receipt_approved_not_deleted', 'receipt_billed', 'expense_settled', 'rule_has_receipts']

/**
 * Deletes everything a run created (key contains `run`), as a tombstone: a
 * fresh client (since=0) then sees only the fixed test data. Issued
 * invoices stay (the server keeps them); when a batch is refused anyway,
 * one by one, and what the server keeps by its rules (issued, approved,
 * settled, `BLEIBT`) is left out, not a failure.
 * Also alone: `node tests/integration/aufraeumen.mjs <run>`.
 */
export async function aufraeumen(run) {
	for (const who of [A, AA]) {
		const alle = ((await ocs(who, 'GET', '/records?since=0')).data?.records || []).filter((x) => !x.deleted && x.key.includes(run))
		const live = alle.filter((x) => !bleibt(x))
		if (alle.length > live.length) {
			console.log(`    --  ${who.user}: ${alle.length - live.length} issued invoices stay`)
		}
		for (let i = 0; i < live.length; i += 500) {
			const writes = live.slice(i, i + 500).map((x) => ({ kind: x.kind, key: x.key, version: x.version, data: null }))
			const r = await ocs(who, 'POST', '/records/batch', { writes })
			if (r.status === 200) {
				check(`${who.user}: ${writes.length} test records deleted`, true)
				continue
			}
			let weg = 0
			const kept = [], failed = []
			for (const w of writes) {
				const d = await ocs(who, 'DELETE', `/records/${encodeURIComponent(w.kind)}/${encodeURIComponent(w.key)}?version=${w.version}`)
				if (d.status === 200) {
					weg++
				} else if (d.status === 409 && BLEIBT.includes(d.data?.code ?? (d.data?.error === 'issued' ? 'invoice_issued' : ''))) {
					kept.push(`${w.kind}/${w.key}`)
				} else {
					failed.push(`${w.kind}/${w.key} ${d.status} ${d.text.slice(0, 120)}`)
				}
			}
			if (kept.length) {
				console.log(`    --  ${who.user}: ${kept.length} records stay by the server's rules: ${kept.join(', ').slice(0, 300)}`)
			}
			check(`${who.user}: ${weg} test records deleted one by one`, failed.length === 0, failed.join(' | ').slice(0, 400))
		}
	}
}
