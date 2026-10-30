// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The team's invoice template (0.16.0): /templates/invoice for Team Admins
// only – info, download, upload and delete –, the Word check, the 10 MB
// limit and the template in the team ZIP. Leaves zb as it found it: a
// template that was there is put back (with a new modified_at).
// The team's own project templates (0.25.0): the record kind `template`
// (format, key, size, no person fields; Leads and Team Admins read, a Lead
// writes their own), the project's `budget_label` and the team ZIP.
// 0.25.1: the owner `created_by` kept by the server (a Lead keeps their own
// after a Team Admin's change) and the team setting `catalogs` (put back).
import { ADMIN, API, NC, ocs, sql } from './lib.mjs'
import { RUN, check, expect, head, isObj, ISO, keysOf, sha256 } from './harness.mjs'
import { A, AA, L, U1, V } from './konten.mjs'

const BASE = '/templates/invoice'
const MB10 = 10 * 1024 * 1024
const TOO_LARGE = 'A template may be at most 10 MB.'
const NOT_WORD = 'The template must be a Word document (.docx).'
const NOT_ADMIN = 'Only Team Admins manage the invoice template.'

const CRC = Array.from({ length: 256 }, (_, n) => {
	let c = n
	for (let k = 0; k < 8; k++) {
		c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1
	}
	return c >>> 0
})
function crc32(buf) {
	let c = 0xffffffff
	for (const b of buf) {
		c = CRC[(c ^ b) & 0xff] ^ (c >>> 8)
	}
	return (c ^ 0xffffffff) >>> 0
}

/** A ZIP with stored (uncompressed) entries – enough for a Word document; node has no ZIP writer. */
export function storedZip(entries) {
	const parts = []
	const central = []
	let offset = 0
	for (const [name, content] of Object.entries(entries)) {
		const n = Buffer.from(name)
		const data = Buffer.isBuffer(content) ? content : Buffer.from(content)
		const crc = crc32(data)
		const local = Buffer.alloc(30)
		local.writeUInt32LE(0x04034b50, 0)
		local.writeUInt16LE(20, 4)
		local.writeUInt32LE(crc, 14)
		local.writeUInt32LE(data.length, 18)
		local.writeUInt32LE(data.length, 22)
		local.writeUInt16LE(n.length, 26)
		const dir = Buffer.alloc(46)
		dir.writeUInt32LE(0x02014b50, 0)
		dir.writeUInt16LE(20, 4)
		dir.writeUInt16LE(20, 6)
		dir.writeUInt32LE(crc, 16)
		dir.writeUInt32LE(data.length, 20)
		dir.writeUInt32LE(data.length, 24)
		dir.writeUInt16LE(n.length, 28)
		dir.writeUInt32LE(offset, 42)
		parts.push(local, n, data)
		central.push(dir, n)
		offset += 30 + n.length + data.length
	}
	const cd = Buffer.concat(central)
	const end = Buffer.alloc(22)
	end.writeUInt32LE(0x06054b50, 0)
	end.writeUInt16LE(Object.keys(entries).length, 8)
	end.writeUInt16LE(Object.keys(entries).length, 10)
	end.writeUInt32LE(cd.length, 12)
	end.writeUInt32LE(offset, 16)
	return Buffer.concat([...parts, cd, end])
}

/** A minimal Word document; `pad` bytes more make it large. */
export function docx(text = 'Invoice {number}', pad = 0) {
	const entries = {
		'[Content_Types].xml': '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
		'word/document.xml': `<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>${text}</w:t></w:r></w:p></w:body></w:document>`,
	}
	if (pad > 0) {
		entries['word/media/pad.bin'] = Buffer.alloc(pad, 7)
	}
	return storedZip(entries)
}

const put = (who, name, bytes) => ocs(who, 'PUT', BASE, { name, base64: Buffer.from(bytes).toString('base64') })
const auth = (who) => ({ 'OCS-APIRequest': 'true', Accept: 'application/json', Authorization: 'Basic ' + Buffer.from(`${who.user}:${who.pass}`).toString('base64') })

/** The team ZIP as bytes (ocs() reads text). */
async function exportZip(id) {
	const res = await fetch(`${NC}${API}/admin/teams/${id}/export`, { headers: auth(ADMIN) })
	return { status: res.status, zip: Buffer.from(await res.arrayBuffer()) }
}

/** Preview of a team ZIP for team `id`: multipart field `file`. */
async function preview(id, zip) {
	const form = new FormData()
	form.append('file', new Blob([zip], { type: 'application/zip' }), 'team.zip')
	const res = await fetch(`${NC}${API}/admin/teams/${id}/import/preview`, { method: 'POST', headers: auth(ADMIN), body: form })
	const json = await res.json().catch(() => null)
	return { status: res.status, data: json?.ocs?.data ?? null }
}

/**
 * A team of its own: export with the template, import with merge (the
 * younger one here stays) and replace (the backup's wins), delete (the
 * template goes, a ZIP is kept first).
 */
async function teamZip() {
	head('Invoice template: team ZIP and deleting the team (own test team)')
	const group = `tv-${RUN}-t`
	const uid = `tv${RUN}`
	const TA = { user: uid, pass: `Test-2026-${uid}!` }
	const older = docx('From the backup')
	const younger = docx('Changed since')
	await ocs(ADMIN, 'POST', '/ocs/v2.php/cloud/groups', { groupid: group })
	await ocs(ADMIN, 'POST', '/ocs/v2.php/cloud/users', { userid: uid, password: TA.pass, groups: [group] })
	await ocs(ADMIN, 'POST', `/ocs/v2.php/cloud/users/${uid}/subadmins`, { groupid: group })
	const name = `Template Test ${RUN}`
	let id
	try {
		const made = await ocs(ADMIN, 'POST', '/admin/teams', { name, slug: `tv-${RUN}`, groups: { team: group } })
		id = made.data?.id
		expect('team created', made, 200)
		expect('its Team Admin uploads a template', await put(TA, 'Backup.docx', older), 200)
		const exp = await exportZip(id)
		check('export → 200', exp.status === 200, exp.zip.toString('utf8', 0, 200))
		check('… holds templates/invoice.docx and templates/invoice.json',
			exp.zip.includes('templates/invoice.docx') && exp.zip.includes('templates/invoice.json'))
		await new Promise((r) => setTimeout(r, 1100))
		expect('… then a younger one', await put(TA, 'Younger.docx', younger), 200)

		const p1 = await preview(id, exp.zip)
		check('preview names the template', p1.status === 200 && p1.data?.template?.name === 'Backup.docx' && p1.data?.template?.sha256 === sha256(older), p1.data?.template ?? p1.data)
		const merge = await ocs(ADMIN, 'POST', `/admin/teams/${id}/import`, { token: p1.data?.token, mode: 'merge', mapping: {} })
		expect('import with merge', merge, 200)
		check('… keeps the younger one here', JSON.stringify(merge.data?.template) === JSON.stringify({ name: 'Backup.docx', stored: false, reason: 'kept_existing' }), merge.data?.template)
		check('… info unchanged', (await ocs(TA, 'GET', `${BASE}/info`)).data?.sha256 === sha256(younger))

		const p2 = await preview(id, exp.zip)
		const replace = await ocs(ADMIN, 'POST', `/admin/teams/${id}/import`, { token: p2.data?.token, mode: 'replace', mapping: {} })
		expect('import with replace', replace, 200)
		check('… stores the backup’s', JSON.stringify(replace.data?.template) === JSON.stringify({ name: 'Backup.docx', stored: true }), replace.data?.template)
		check('… a ZIP of the team kept first (the template counts as content)', typeof replace.data?.pre_backup?.protected === 'string', replace.data?.pre_backup)
		const info = await ocs(TA, 'GET', `${BASE}/info`)
		check('… info: the backup’s file, name and author', info.data?.sha256 === sha256(older) && info.data?.name === 'Backup.docx' && info.data?.modified_by === uid, info.data)

		const del = await ocs(ADMIN, 'DELETE', `/admin/teams/${id}`, { confirm: name })
		expect('deleting the team', del, 200)
		check('… keeps a ZIP first (only a template is content too)', typeof del.data?.backup?.protected === 'string', del.data)
		if (del.status === 200) {
			const files = sql(`SELECT COUNT(*) FROM oc_filecache WHERE path LIKE 'appdata\_%/timesister/templates/t${id}/%'`)
			if (files === null) {
				console.log('    (without TS_SQL the template folder of the deleted team is not looked at)')
			} else {
				check('… and its template folder is gone (TS_SQL)', files === '0', files)
			}
			id = undefined
		}
	} finally {
		if (id) {
			await ocs(ADMIN, 'DELETE', `/admin/teams/${id}`, { confirm: name })
		}
		await ocs(ADMIN, 'DELETE', `/ocs/v2.php/cloud/users/${uid}`)
		await ocs(ADMIN, 'DELETE', `/ocs/v2.php/cloud/groups/${group}`)
	}
}

/** A project template (format 3) under `id@edition`. */
function projektvorlage(id, edition, over = {}) {
	return { format: 'timesister-template', format_version: 3, id, edition, status: 'custom', title: `Housing ${RUN}`,
		budget_label: 'Phasen', currency: 'CHF',
		items: [{ nr: '1', title: 'Preliminary', kind: 'budget', children: [
			{ nr: '1.1', title: 'Survey', kind: 'arbeitspaket', rate: 160, hours: 20 },
			{ nr: 'M1', title: 'Hand-in', kind: 'meilenstein', offset_days: 30 }] }],
		billing_plan: [{ offset_days: 60, budget: '1' }], ...over }
}

/** 0.25.1: the team setting `catalogs` – set, read, refused; left as found. */
async function kataloge() {
	const before = (await ocs(A, 'GET', '/team')).data?.settings
	const put = (who, catalogs) => ocs(who, 'PUT', '/team/settings', { catalogs })
	try {
		head('Template catalogs: the team setting catalogs (0.25.1)')
		check('/team.settings.catalogs is there (null or a list)', before && 'catalogs' in before && (before.catalogs === null || Array.isArray(before.catalogs)), before)
		const list = ['sia-102@2020', 'hoai.lp@2021']
		const r = await put(A, list)
		expect('Team Admin sets two catalogs', r, 200)
		check('… answered in the settings', JSON.stringify(r.data?.catalogs) === JSON.stringify(list), r.data)
		check('… in GET /team', JSON.stringify((await ocs(A, 'GET', '/team')).data?.settings?.catalogs) === JSON.stringify(list))
		check('… in the Lead’s /me', JSON.stringify((await ocs(L, 'GET', '/me')).data?.settings?.catalogs) === JSON.stringify(list))
		const none = await put(A, [])
		check('an empty list: none active', none.status === 200 && JSON.stringify(none.data?.catalogs) === '[]', none.data)
		const dflt = await put(A, null)
		check('null: the client’s default', dflt.status === 200 && dflt.data?.catalogs === null, dflt.data)
		const bad = async (name, value, message) => {
			const b = await put(A, value)
			expect(name, b, 422, 'invalid')
			check(`… “${message}”`, b.data?.message === message, b.data?.message)
		}
		await bad('not a list', 'sia-102@2020', '“catalogs” must be null or a list of at most 50 catalogs.')
		await bad('51 catalogs', Array.from({ length: 51 }, (_, i) => `c${i}@1`), '“catalogs” must be null or a list of at most 50 catalogs.')
		const item = 'Each catalog in “catalogs” is “id@edition”: the id in lowercase letters, digits, dots, dashes and underscores, the edition in letters, digits, dots, dashes and underscores, each at most 100.'
		await bad('without edition', ['sia-102'], item)
		await bad('capital id', ['SIA@2020'], item)
		await bad('twice', ['sia@2020', 'sia@2020'], '“catalogs” names “sia@2020” twice.')
		check('a refused value changes nothing', (await ocs(A, 'GET', '/team')).data?.settings?.catalogs === null)
		expect('a Lead sets them', await put(L, list), 403, 'forbidden')
	} finally {
		await put(A, before?.catalogs ?? null)
		const after = (await ocs(A, 'GET', '/team')).data?.settings
		check('zb left as found (catalogs)', JSON.stringify(after?.catalogs ?? null) === JSON.stringify(before?.catalogs ?? null), after?.catalogs)
	}
}

/** 0.25.0: the record kind `template` and the project's `budget_label`. */
export async function projektvorlagen() {
	const id = `buero.t${RUN}`.toLowerCase()
	const K1 = `${id}@2026-10`
	const K2 = `${id}-lead@2026-10`
	const PJ = `tv${RUN}`.toLowerCase()
	const path = (k) => `/records/template/${k}`
	const version = async (k) => (await ocs(A, 'GET', path(k))).data?.version
	try {
		head('Project templates: capability, write, read')
		{
			const cap = await ocs(A, 'GET', '/ocs/v2.php/cloud/capabilities')
			check('capability templates: 2', cap.data?.capabilities?.timesister?.templates === 2, cap.data?.capabilities?.timesister)
			const r = await ocs(A, 'PUT', path(K1), { version: 0, data: projektvorlage(id, '2026-10', { created_by: 'zblead' }) })
			expect('Team Admin writes a template', r, 200)
			check('… created_by set by the server, the client’s value replaced (0.25.1)', r.data?.data?.created_by === 'zbadmin', r.data?.data?.created_by)
			check('… stored as sent', r.data?.data?.budget_label === 'Phasen' && r.data?.data?.items?.[0]?.children?.[1]?.kind === 'meilenstein', r.data)
			expect('Lead reads it', await ocs(L, 'GET', path(K1)), 200)
			expect('Member reads it', await ocs(U1, 'GET', path(K1)), 403, 'forbidden')
			check('in the Lead’s delta', keysOf(await ocs(L, 'GET', '/records')).has(`template/${K1}`))
			check('not in the Member’s', !keysOf(await ocs(U1, 'GET', '/records')).has(`template/${K1}`))
			expect('another team does not see it', await ocs(AA, 'GET', path(K1)), 404, 'not_found')
		}

		head('Project templates: refused')
		{
			const bad = async (name, r, status, error, message) => {
				expect(name, r, status, error)
				check(`… “${message}”`, r.data?.message === message, r.data?.message)
			}
			await bad('a customer field', await ocs(A, 'PUT', path(`${id}-x@1`), { version: 0, data: projektvorlage(`${id}-x`, '1', { customer: 'kanton' }) }), 422, 'invalid',
				'A template carries no person or customer data: “customer”.')
			const items = projektvorlage(id, '1').items
			items[0].children[0].lead = 'zblead'
			await bad('a lead deep in the tree', await ocs(A, 'PUT', path(`${id}-x@1`), { version: 0, data: projektvorlage(`${id}-x`, '1', { items }) }), 422, 'invalid',
				'A template carries no person or customer data: “items[0].children[0].lead”.')
			await bad('key and id@edition differ', await ocs(A, 'PUT', path(`${id}-x@2`), { version: 0, data: projektvorlage(`${id}-x`, '1') }), 422, 'invalid',
				'The key of a template is its “id”, “@” and its “edition”.')
			await bad('format_version 2', await ocs(A, 'PUT', path(`${id}-x@1`), { version: 0, data: projektvorlage(`${id}-x`, '1', { format_version: 2 }) }), 422, 'invalid',
				'“format_version” of a template must be 3.')
			const many = Array.from({ length: 1800 }, (_, i) => ({ nr: String(i), title: 't'.repeat(150), kind: 'arbeitspaket' }))
			await bad('more than 256 KB', await ocs(A, 'PUT', path(`${id}-x@1`), { version: 0, data: projektvorlage(`${id}-x`, '1', { items: many }) }), 413, 'too_large',
				'A template may be at most 256 KB.')
			await bad('a Member writes one', await ocs(U1, 'PUT', path(`${id}-u@1`), { version: 0, data: projektvorlage(`${id}-u`, '1') }), 403, 'forbidden',
				'Only Team Admins and Leads write templates.')
		}

		head('Project templates: a Lead writes their own')
		{
			const neu = await ocs(L, 'PUT', path(K2), { version: 0, data: projektvorlage(`${id}-lead`, '2026-10', { created_by: 'zbadmin' }) })
			expect('Lead writes a new one', neu, 200)
			check('… created_by is the Lead', neu.data?.data?.created_by === 'zblead', neu.data?.data?.created_by)
			expect('… and changes it', await ocs(L, 'PUT', path(K2), { version: 1, data: projektvorlage(`${id}-lead`, '2026-10', { title: 'Mine' }) }), 200)
			const fremd = await ocs(L, 'PUT', path(K1), { version: await version(K1), data: projektvorlage(id, '2026-10', { title: 'Taken' }) })
			expect('Lead changes the Team Admin’s', fremd, 403, 'forbidden')
			check('… “A Lead changes and removes only their own templates.”', fremd.data?.message === 'A Lead changes and removes only their own templates.', fremd.data)
			expect('Lead removes the Team Admin’s', await ocs(L, 'DELETE', `${path(K1)}?version=${await version(K1)}`), 403, 'forbidden')
			const admin = await ocs(A, 'PUT', path(K2), { version: 2, data: projektvorlage(`${id}-lead`, '2026-10', { title: 'Checked', created_by: 'zbadmin' }) })
			expect('Team Admin changes the Lead’s', admin, 200)
			check('… created_by stays the Lead (0.25.1)', admin.data?.data?.created_by === 'zblead' && admin.data?.modified_by === 'zbadmin', [admin.data?.data?.created_by, admin.data?.modified_by])
			const again = await ocs(L, 'PUT', path(K2), { version: 3, data: projektvorlage(`${id}-lead`, '2026-10') })
			expect('… the Lead still changes their own (0.25.1)', again, 200)
			check('… created_by still the Lead', again.data?.data?.created_by === 'zblead', again.data?.data?.created_by)
			expect('Team Admin changes it once more', await ocs(A, 'PUT', path(K2), { version: 4, data: projektvorlage(`${id}-lead`, '2026-10', { title: 'Checked again' }) }), 200)
			expect('… the Lead removes their own after it', await ocs(L, 'DELETE', `${path(K2)}?version=5`), 200)
			const fremd2 = await ocs(L, 'PUT', path(K1), { version: await version(K1), data: projektvorlage(id, '2026-10', { title: 'Taken', created_by: 'zblead' }) })
			expect('a Lead claiming created_by of another’s', fremd2, 403, 'forbidden')
			const batch = await ocs(L, 'POST', '/records/batch', { writes: [{ kind: 'template', key: `${id}-b@1`, version: 0, data: projektvorlage(`${id}-b`, '1', { created_by: 'zbadmin' }) }] })
			expect('a Lead’s batch with a template', batch, 200)
			check('… created_by from the server in a batch', batch.data?.records?.[0]?.data?.created_by === 'zblead', batch.data?.records?.[0]?.data)
			const fb = await ocs(L, 'POST', '/records/batch', { writes: [{ kind: 'template', key: K1, version: await version(K1), data: projektvorlage(id, '2026-10') }] })
			expect('a Lead’s batch changing another’s', fb, 403, 'forbidden')
			check('… “Write 1: A Lead changes and removes only their own templates.”', fb.data?.message === 'Write 1: A Lead changes and removes only their own templates.', fb.data?.message)
			expect('… and removes it', await ocs(L, 'DELETE', `${path(`${id}-b@1`)}?version=1`), 200)
		}

		head('Project templates: budget_label on the project, team ZIP')
		{
			const project = (label) => ({ id: PJ, name: `Templates ${RUN}`, codes: [PJ.toUpperCase()], status: 'active', budget_label: label })
			const p = await ocs(A, 'PUT', `/records/project/${PJ}`, { version: 0, data: project('Phasen') })
			expect('a project with budget_label', p, 200)
			check('… kept', p.data?.data?.budget_label === 'Phasen', p.data)
			const lang = await ocs(A, 'PUT', `/records/project/${PJ}`, { version: 1, data: project('x'.repeat(61)) })
			expect('61 characters', lang, 422, 'invalid')
			check('… “budget_label” must be text of 1 to 60 characters.', lang.data?.message === '“budget_label” must be text of 1 to 60 characters.', lang.data)
			const teams = await ocs(ADMIN, 'GET', '/admin/teams')
			const zb = (teams.data?.teams || teams.data || []).find?.((x) => x.slug === 'zb' || x.groups?.team === 'zb-team')
			if (!zb) {
				console.log('    (team zb not found in /admin/teams – the ZIP is not looked at)')
			} else {
				const res = await fetch(`${NC}${API}/admin/teams/${zb.id}/export`, { headers: { 'OCS-APIRequest': 'true', Authorization: 'Basic ' + Buffer.from(`${ADMIN.user}:${ADMIN.pass}`).toString('base64') } })
				const zip = Buffer.from(await res.arrayBuffer())
				check('the team ZIP holds the template', res.status === 200 && zip.includes(`records/template/${K1}.json`), res.status)
			}
		}
	} finally {
		head('Project templates: cleaning up')
		for (const k of [K1, K2]) {
			const v = await version(k)
			if (v) expect(`${k} removed`, await ocs(A, 'DELETE', `${path(k)}?version=${v}`), 200)
		}
		const pv = (await ocs(A, 'GET', `/records/project/${PJ}`)).data?.version
		if (pv) expect('the project removed', await ocs(A, 'DELETE', `/records/project/${PJ}?version=${pv}`), 200)
	}
}

export async function vorlagen() {
	const before = await ocs(A, 'GET', BASE)
	const first = docx('Rechnung {number}')
	const second = docx('Invoice {nr} v2')
	try {
		head('Invoice template: capability and rights')
		{
			const cap = await ocs(A, 'GET', '/ocs/v2.php/cloud/capabilities')
			check('capability invoice_template: 1', cap.data?.capabilities?.timesister?.invoice_template === 1, cap.data?.capabilities?.timesister)
			check('the team’s template read before (200 or 404)', [200, 404].includes(before.status), before.text.slice(0, 200))
			for (const [who, role] of [[U1, 'Member'], [L, 'Lead']]) {
				const info = await ocs(who, 'GET', `${BASE}/info`)
				expect(`${role} reads the info`, info, 403, 'forbidden')
				check('… with the message', info.data?.message === NOT_ADMIN, info.data)
				expect(`${role} downloads it`, await ocs(who, 'GET', BASE), 403, 'forbidden')
				expect(`${role} uploads one`, await put(who, 'Rechnung.docx', first), 403, 'forbidden')
				expect(`${role} deletes it`, await ocs(who, 'DELETE', BASE), 403, 'forbidden')
			}
			// The account language decides.
			const userPath = '/ocs/v2.php/cloud/users/zbuser1'
			const langBefore = (await ocs(ADMIN, 'GET', userPath)).data?.language || 'en'
			try {
				await ocs(ADMIN, 'PUT', userPath, { key: 'language', value: 'de' })
				const de = await ocs(U1, 'GET', `${BASE}/info`, undefined, { lang: 'de' })
				check('… in German', de.data?.message === 'Die Rechnungsvorlage verwalten nur Team Admins.', de.data)
			} finally {
				await ocs(ADMIN, 'PUT', userPath, { key: 'language', value: langBefore })
			}
		}

		head('Invoice template: upload, info, download, replace')
		{
			expect('Team Admin starts without one', await ocs(A, 'DELETE', BASE), 200)
			const none = await ocs(A, 'GET', `${BASE}/info`)
			expect('info without a template', none, 404, 'not_found')
			check('… with the message', none.data?.message === 'The team has no invoice template.', none.data)
			expect('download without a template', await ocs(A, 'GET', BASE), 404, 'not_found')

			const r = await put(A, 'Rechnung.docx', first)
			expect('Team Admin uploads Rechnung.docx', r, 200)
			check('… answers name, size, sha256, modified_at, modified_by',
				r.data?.name === 'Rechnung.docx' && r.data?.size === first.length && r.data?.sha256 === sha256(first)
				&& ISO.test(r.data?.modified_at || '') && r.data?.modified_by === 'zbadmin' && Object.keys(r.data).length === 5, r.data)
			const info = await ocs(A, 'GET', `${BASE}/info`)
			expect('info', info, 200)
			check('… the same as the upload answered', JSON.stringify(info.data) === JSON.stringify(r.data), [info.data, r.data])
			const got = await ocs(V, 'GET', BASE)
			expect('the second Team Admin downloads it', got, 200)
			const bytes = Buffer.from(got.data?.base64 || '', 'base64')
			check('… the same bytes', bytes.equals(first) && got.data?.sha256 === sha256(bytes), got.data?.sha256)
			check('… with the info fields', got.data?.name === 'Rechnung.docx' && got.data?.modified_by === 'zbadmin', isObj(got.data) ? Object.keys(got.data) : got.data)
			expect('another team has none', await ocs(AA, 'GET', `${BASE}/info`), 404, 'not_found')

			const r2 = await put(V, 'Rechnung V2.DOCX', second)
			expect('the second Team Admin replaces it (.DOCX)', r2, 200)
			const info2 = await ocs(A, 'GET', `${BASE}/info`)
			check('info shows the new one', info2.data?.name === 'Rechnung V2.DOCX' && info2.data?.sha256 === sha256(second) && info2.data?.modified_by === 'zbverw', info2.data)
		}

		head('Invoice template: refused')
		{
			const bad = async (name, r, status, error, message) => {
				expect(name, r, status, error)
				check(`… “${message}”`, r.data?.message === message, r.data?.message)
			}
			await bad('not ending in .docx', await put(A, 'Rechnung.pdf', first), 422, 'invalid', '“name” must be a file name of 1 to 200 characters ending in .docx.')
			await bad('a name of 201 characters', await put(A, `${'a'.repeat(196)}.docx`, first), 422, 'invalid', '“name” must be a file name of 1 to 200 characters ending in .docx.')
			await bad('a text file named .docx', await put(A, 'Rechnung.docx', Buffer.from('Dear customer, …')), 422, 'invalid', NOT_WORD)
			await bad('a PDF named .docx', await put(A, 'Rechnung.docx', Buffer.from('%PDF-1.7\n%âãÏÓ\n')), 422, 'invalid', NOT_WORD)
			await bad('a ZIP without word/document.xml', await put(A, 'Rechnung.docx', storedZip({ 'xl/workbook.xml': '<workbook/>' })), 422, 'invalid',
				'The template is not a Word document: “word/document.xml” is missing.')
			await bad('base64 not Base64', await ocs(A, 'PUT', BASE, { name: 'Rechnung.docx', base64: 'not base64!' }), 422, 'invalid', '“base64” is not valid Base64.')
			await bad('base64 missing', await ocs(A, 'PUT', BASE, { name: 'Rechnung.docx' }), 422, 'invalid', '“base64” is missing.')
			await bad('name missing', await ocs(A, 'PUT', BASE, { base64: first.toString('base64') }), 422, 'invalid', '“name” is missing.')
			await bad('10 MB and one byte', await put(A, 'Big.docx', Buffer.concat([first, Buffer.alloc(MB10 + 1 - first.length)])), 413, 'too_large', TOO_LARGE)
			await bad('11 MB (beyond the body limit)', await put(A, 'Big.docx', Buffer.concat([first, Buffer.alloc(11 * 1024 * 1024 - first.length)])), 413, 'too_large', TOO_LARGE)
			const still = await ocs(A, 'GET', `${BASE}/info`)
			check('the stored one unchanged', still.data?.sha256 === sha256(second), still.data)
		}

		head('Invoice template: nearly 10 MB')
		{
			const big = docx('Big', MB10 - 1200)
			check('the test file is just under 10 MB', big.length <= MB10 && big.length > MB10 - 2000, big.length)
			const r = await put(A, 'Gross.docx', big)
			expect('Team Admin uploads it (≈ 13.4 MB of JSON)', r, 200)
			check('… size and checksum', r.data?.size === big.length && r.data?.sha256 === sha256(big), r.data)
			const got = await ocs(A, 'GET', BASE)
			expect('download', got, 200)
			check('… the same bytes', Buffer.from(got.data?.base64 || '', 'base64').equals(big))
			expect('back to the small one', await put(A, 'Rechnung V2.DOCX', second), 200)
		}

		await teamZip()

		head('Invoice template: delete')
		{
			const d = await ocs(A, 'DELETE', BASE)
			expect('Team Admin deletes it', d, 200)
			check('… { deleted: true }', d.data?.deleted === true, d.data)
			expect('info afterwards', await ocs(A, 'GET', `${BASE}/info`), 404, 'not_found')
			expect('download afterwards', await ocs(A, 'GET', BASE), 404, 'not_found')
			const again = await ocs(A, 'DELETE', BASE)
			check('deleting again → 200 { deleted: false }', again.status === 200 && again.data?.deleted === false, again.data)
		}
	} finally {
		head('Invoice template: cleaning up')
		if (before.status === 200) {
			const back = await ocs(A, 'PUT', BASE, { name: before.data.name, base64: before.data.base64 })
			expect('the team’s template put back', back, 200)
			check('… the same bytes', back.data?.sha256 === before.data.sha256, back.data)
		} else {
			expect('no template left', await ocs(A, 'DELETE', BASE), 200)
		}
	}
	await projektvorlagen()
	await kataloge()
}
