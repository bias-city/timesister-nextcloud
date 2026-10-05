/* SPDX-License-Identifier: AGPL-3.0-or-later */
/*
 * TimeSister – admin page. Vanilla JavaScript, no build step.
 * Teams come via the admin endpoints (OCS, requesttoken), the status as an
 * initial state. No innerHTML with data: everything via textContent.
 * The server translates texts (initial state "l10n"), without OC.L10N.
 */
(function () {
	'use strict'

	// One team group; Team Admin is whoever manages it in Nextcloud. Strongest first.
	const ROLES = ['admin', 'lead', 'user']

	/** Without OC globals: the token is in the page's head. */
	function requestToken() {
		return document.head.dataset.requesttoken || ''
	}

	/** Read the initial state (base64 JSON in a hidden field). */
	function loadState(key) {
		const input = document.getElementById('initial-state-timesister-' + key)
		if (!input) {
			return null
		}
		try {
			const bin = atob(input.value)
			const bytes = Uint8Array.from(bin, (c) => c.charCodeAt(0))
			return JSON.parse(new TextDecoder().decode(bytes))
		} catch (e) {
			return null
		}
	}

	const TEXTS = loadState('l10n') || {}
	// Role words: the same in every language, never translated.
	const ROLE_NAMES = loadState('roles') || {}
	const roleName = (r) => ROLE_NAMES[r] || r

	/** Translated text for a key; {name} is replaced with vars.name. */
	function tr(key, vars) {
		const text = typeof TEXTS[key] === 'string' ? TEXTS[key] : key
		return text.replace(/\{(\w+)\}/g, (m, k) => (vars && k in vars ? String(vars[k]) : m))
	}

	async function api(method, path, body) {
		const res = await fetch(overview.api_base + path, {
			method,
			credentials: 'same-origin',
			headers: {
				'OCS-APIRequest': 'true',
				Accept: 'application/json',
				'Content-Type': 'application/json',
				requesttoken: requestToken(),
			},
			body: body === undefined ? undefined : JSON.stringify(body),
		})
		let json = null
		try {
			json = await res.json()
		} catch (e) {
			json = null
		}
		const data = json && json.ocs ? json.ocs.data : null
		if (!res.ok) {
			const msg = (data && data.message) || (json && json.ocs && json.ocs.meta && json.ocs.meta.message) || tr('error_status', { status: res.status })
			throw new Error(msg)
		}
		return data
	}

	/** Small DOM helper: textContent only, never HTML. */
	function h(tag, props, ...children) {
		const node = document.createElement(tag)
		for (const [k, v] of Object.entries(props || {})) {
			if (v === undefined || v === null || v === false) {
				continue
			}
			if (k === 'class') {
				node.className = v
			} else if (k === 'text') {
				node.textContent = v
			} else if (k.startsWith('on')) {
				node.addEventListener(k.slice(2), v)
			} else {
				node.setAttribute(k, v === true ? '' : String(v))
			}
		}
		for (const c of children.flat(Infinity)) {
			if (c === null || c === undefined || c === false) {
				continue
			}
			node.append(c instanceof Node ? c : document.createTextNode(String(c)))
		}
		return node
	}

	/** Trash icon as SVG in currentColor – no Nextcloud icon classes. */
	function trashIcon() {
		const ns = 'http://www.w3.org/2000/svg'
		const svg = document.createElementNS(ns, 'svg')
		svg.setAttribute('viewBox', '0 0 24 24')
		svg.setAttribute('width', '18')
		svg.setAttribute('height', '18')
		svg.setAttribute('aria-hidden', 'true')
		const path = document.createElementNS(ns, 'path')
		path.setAttribute('fill', 'currentColor')
		path.setAttribute('d', 'M9 3h6l1 2h4v2H4V5h4l1-2zm-3 6h12l-1 12H7L6 9zm4 2v8h1.5v-8H10zm3.5 0v8H15v-8h-1.5z')
		svg.append(path)
		return svg
	}

	function formatTime(iso) {
		if (!iso) {
			return '–'
		}
		const d = new Date(iso)
		if (isNaN(d)) {
			return iso
		}
		const opts = { dateStyle: 'medium', timeStyle: 'short' }
		try {
			return d.toLocaleString(overview.locale || undefined, opts)
		} catch (e) {
			return d.toLocaleString(undefined, opts)
		}
	}

	const overview = loadState('overview') || { state: [], groups: [], silent_days: 14, api: 1, version: '', api_base: '/ocs/v2.php/apps/timesister/api/v1' }
	let teams = []
	let editing = null // team ID, or null for a new one

	const $ = (id) => document.getElementById(id)

	function groupLabel(gid) {
		const g = overview.groups.find((x) => x.id === gid)
		return g && g.name && g.name !== gid ? g.name + ' (' + gid + ')' : gid
	}

	function stateOf(id) {
		return (overview.state || []).find((s) => s.id === id) || null
	}

	/** Display name (uid) of an admin from the status, otherwise the uid. */
	function adminLabel(st, uid) {
		const a = st && st.admins ? st.admins.find((x) => x.uid === uid) : null
		return a && a.display_name && a.display_name !== uid ? a.display_name + ' (' + uid + ')' : uid
	}

	/** Display name (uid) of a member. */
	function memberLabel(m) {
		return m.display_name && m.display_name !== m.uid ? m.display_name + ' (' + m.uid + ')' : m.uid
	}

	function formatDay(day) {
		const d = new Date(day + 'T12:00:00Z')
		if (!day || isNaN(d)) {
			return day || '–'
		}
		try {
			return d.toLocaleDateString(overview.locale || undefined, { dateStyle: 'medium' })
		} catch (e) {
			return d.toLocaleDateString(undefined, { dateStyle: 'medium' })
		}
	}

	function renderTeams() {
		const box = $('ts-teams')
		box.textContent = ''
		if (teams.length === 0) {
			box.append(h('p', { class: 'ts-muted', text: tr('no_team') }))
			return
		}
		const rows = teams.map((t) => {
			const st = stateOf(t.id)
			const broken = st && st.broken
			const c = t.counts || {}
			const group = h('div', { class: 'ts-roles' },
				h('span', { text: tr('team_group') }),
				h('span', { class: (st && st.missing_groups && st.missing_groups.includes('team')) ? 'ts-bad' : '', text: t.groups.team ? groupLabel(t.groups.team) : '–' }),
				h('span', { class: 'ts-count', text: String(ROLES.reduce((n, r) => n + (c[r] || 0), 0)) }))
			const adminList = (st && st.admins) || []
			const admins = h('div', { class: 'ts-owner' },
				h('span', { class: 'ts-muted', text: tr('admins') + ': ' }),
				adminList.length ? h('span', { text: adminList.map((a) => adminLabel(st, a.uid)).join(', ') }) : h('span', { class: 'ts-bad', text: tr('no_admin') }))
			// All members with role; those who left stay visible, marked.
			const members = h('ul', { class: 'ts-list ts-members' }, ((st && st.members) || []).map((m) => h('li', { class: m.left_at ? 'ts-left' : '' },
				h('span', { text: memberLabel(m) + ' ' }),
				h('span', { class: 'ts-muted', text: roleName(m.role) + (m.left_at ? ' · ' + tr('left_on', { date: formatDay(m.left_at.slice(0, 10)) }) : '') }))))
			const owner = h('div', { class: 'ts-owner' },
				h('span', { class: 'ts-muted', text: tr('backups_stored_with') + ' ' }),
				t.backup_owner ? h('span', { text: adminLabel(st, t.backup_owner) }) : h('span', { class: 'ts-bad', text: tr('owner_none') }))
			const s = t.settings || {}
			const settings = [['backup_required', s.backup_required]].map(([key, on]) =>
				h('div', {}, h('span', { class: 'ts-muted', text: tr(key) + ': ' }), h('span', { text: on ? tr('yes') : tr('no') })))
			return h('tr', { 'data-team': t.slug },
				h('td', {},
					h('div', { text: t.name }),
					h('div', { class: 'ts-slug', text: t.slug }),
					broken ? h('div', { class: 'ts-bad', text: tr('broken') }) : null,
				),
				h('td', {}, group, admins, members, owner, settings),
				h('td', { class: 'ts-right' },
					h('button', { type: 'button', class: 'button', onclick: () => openForm(t) }, tr('edit')),
					' ',
					h('button', { type: 'button', class: 'button', title: tr('export_zip_title'), onclick: () => exportTeam(t) }, tr('export_zip')),
					' ',
					h('button', { type: 'button', class: 'button', onclick: () => openImport(t) }, tr('import_zip')),
					' ',
					h('button', {
						type: 'button',
						class: 'button ts-icon',
						title: tr('delete_team'),
						'aria-label': tr('delete_team_named', { name: t.name }),
						onclick: () => removeTeam(t),
					}, trashIcon()),
				),
			)
		})
		box.append(h('table', {},
			h('thead', {}, h('tr', {}, h('th', { text: tr('team') }), h('th', { text: tr('group_col') }), h('th', {}))),
			h('tbody', {}, rows),
		))
	}

	function renderState() {
		const box = $('ts-state')
		box.textContent = ''
		box.append(h('p', {}, tr('api_version') + ' ', h('strong', { text: String(overview.api) }),
			' · ' + tr('app') + ' ', h('strong', { text: overview.version || '–' })))
		if (teams.length === 0) {
			return
		}
		const rows = teams.map((t) => {
			const st = stateOf(t.id)
			if (!st) {
				return h('tr', {}, h('td', { text: t.name }), h('td', { class: 'ts-muted', colspan: 6, text: tr('new_after_reload') }))
			}
			const calendars = h('span', { class: st.calendar_not_shared > 0 ? 'ts-bad' : '', text: tr('not_shared', { n: st.calendar_not_shared || 0 }) })
			const backups = h('ul', { class: 'ts-list' },
				h('li', { text: tr('consent_count', { n: st.backup_consent, total: st.member_count }) }),
				h('li', { class: st.without_backup_week > 0 ? 'ts-bad' : '', text: tr('without_week', { n: st.without_backup_week }) }),
				h('li', { class: 'ts-muted', text: st.last_server_backup ? tr('last_server', { date: formatDay(st.last_server_backup) }) : tr('no_server_backup') }))
			const silent = st.silent.length === 0
				? h('span', { class: 'ts-ok', text: tr('all_reported') })
				: h('ul', { class: 'ts-list' }, st.silent.map((p) => h('li', {},
					p.display_name + ' ', h('span', { class: 'ts-muted', text: p.seen_at ? tr('last_seen', { time: formatTime(p.seen_at) }) : tr('never') }))))
			return h('tr', {},
				h('td', {}, h('div', { text: t.name }), st.broken ? h('div', { class: 'ts-bad', text: tr('broken') }) : null),
				h('td', { class: 'ts-num', text: String(st.records) }),
				h('td', { text: formatTime(st.last_modified) }),
				h('td', { class: 'ts-num', text: String(st.revision) }),
				h('td', {}, calendars),
				h('td', {}, backups),
				h('td', {}, silent),
			)
		})
		box.append(h('table', {},
			h('thead', {}, h('tr', {},
				h('th', { text: tr('team') }),
				h('th', { text: tr('records') }),
				h('th', { text: tr('last_change') }),
				h('th', { text: tr('revision') }),
				h('th', { text: tr('calendars_col') }),
				h('th', { text: tr('backups_col') }),
				h('th', { text: tr('silent') }),
			)),
			h('tbody', {}, rows),
		))
	}

	function fillSelects(current) {
		for (const key of ['team']) {
			const sel = $('ts-g-' + key)
			sel.textContent = ''
			sel.append(h('option', { value: '', text: tr('choose_group') }))
			for (const g of overview.groups) {
				const taken = g.team !== null && g.team !== undefined && g.team !== (editing || -1)
				sel.append(h('option', {
					value: g.id,
					text: (g.name && g.name !== g.id ? g.name + ' (' + g.id + ')' : g.id) + (taken ? ' ' + tr('other_team') : ''),
					disabled: taken,
				}))
			}
			sel.value = (current && current.groups && current.groups[key]) || ''
		}
	}

	/** Backup owner: automatic, or one of the team's admins (only when editing). */
	function fillOwner(team) {
		const sel = $('ts-backup-owner')
		const st = team ? stateOf(team.id) : null
		sel.textContent = ''
		sel.append(h('option', { value: '', text: tr('owner_auto') }))
		for (const a of (st && st.admins) || []) {
			sel.append(h('option', { value: a.uid, text: adminLabel(st, a.uid) }))
		}
		const choice = st ? st.backup_owner_choice : null
		sel.value = choice && [...sel.options].some((o) => o.value === choice) ? choice : ''
		sel.disabled = !st
	}

	/** Admins (read-only: they come from Nextcloud's user management). */
	function fillAdmins(team) {
		const st = team ? stateOf(team.id) : null
		const list = (st && st.admins) || []
		const box = $('ts-admins')
		box.textContent = ''
		// New team: the page only knows the group admins after saving.
		if (!st) {
			box.append(h('span', { class: 'ts-muted', text: tr('admins_after_save') }))
			return
		}
		box.append(list.length
			? h('ul', { class: 'ts-list' }, list.map((a) => h('li', { text: adminLabel(st, a.uid) })))
			: h('span', { class: 'ts-muted', text: tr('no_admin') }))
	}

	/** Members with app role and leaving; changes apply only on save. */
	function fillMembers(team) {
		const box = $('ts-members')
		box.textContent = ''
		const st = team ? stateOf(team.id) : null
		if (!st) {
			box.append(h('p', { class: 'ts-muted', text: tr('members_after_save') }))
			return
		}
		if (!st.members || st.members.length === 0) {
			box.append(h('p', { class: 'ts-muted', text: tr('no_members') }))
			return
		}
		const rows = st.members.map((m) => {
			const name = memberLabel(m)
			const role = h('select', { 'data-uid': m.uid, 'data-was': m.role, 'aria-label': tr('role_of', { name }) },
				ROLES.map((r) => h('option', { value: r, text: roleName(r), selected: r === m.role })))
			const left = h('input', { type: 'checkbox', 'data-uid': m.uid, 'data-was': m.left_at ? '1' : '0', checked: Boolean(m.left_at), 'aria-label': tr('left_of', { name }) })
			return h('tr', { class: m.left_at ? 'ts-left' : '' }, h('td', { text: name }), h('td', {}, role), h('td', {}, left))
		})
		box.append(h('table', {},
			h('thead', {}, h('tr', {}, h('th', { text: tr('member') }), h('th', { text: tr('role') }), h('th', { text: tr('left') }))),
			h('tbody', {}, rows)))
	}

	/** Changed members: uid → { role?, left? }; new Team Admins first, so the team never loses its last one. */
	function memberChanges() {
		const out = new Map()
		const put = (uid, key, value) => out.set(uid, Object.assign(out.get(uid) || {}, { [key]: value }))
		for (const sel of $('ts-members').querySelectorAll('select[data-uid]')) {
			if (sel.value !== sel.dataset.was) {
				put(sel.dataset.uid, 'role', sel.value)
			}
		}
		for (const box of $('ts-members').querySelectorAll('input[type=checkbox][data-uid]')) {
			if (box.checked !== (box.dataset.was === '1')) {
				put(box.dataset.uid, 'left', box.checked)
			}
		}
		return new Map([...out].sort(([, a], [, b]) => (b.role === 'admin') - (a.role === 'admin')))
	}

	// Short name: lowercase letters, digits, hyphens (TeamRules::SLUG_PATTERN).
	function slugFrom(text) {
		return text.toLowerCase()
			.replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
			.normalize('NFD').replace(/[\u0300-\u036f]/g, '')
			.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 32).replace(/-+$/, '')
	}
	// The suggestion follows the name until someone types a short name of their own.
	let slugSuggested = ''
	function suggestSlug() {
		const slug = $('ts-slug')
		if (editing !== null || (slug.value && slug.value !== slugSuggested)) return
		slugSuggested = slugFrom($('ts-name').value)
		slug.value = slugSuggested
	}
	function tidySlug() {
		const slug = $('ts-slug'), v = slug.value.toLowerCase().replace(/\s+/g, '-')
		if (v !== slug.value) slug.value = v
	}

	function openForm(team) {
		editing = team ? team.id : null
		$('ts-form-title').textContent = team ? tr('edit_team') : tr('new_team')
		$('ts-name').value = team ? team.name : ''
		$('ts-slug').value = team ? team.slug : ''
		slugSuggested = ''
		$('ts-error').textContent = ''
		fillSelects(team)
		fillAdmins(team)
		fillMembers(team)
		fillOwner(team)
		// New team: no backup requirement. Who sees which calendar is set in the Mac app.
		const s = (team && team.settings) || {}
		$('ts-backup-required').checked = s.backup_required === true
		$('ts-form').hidden = false
		$('ts-name').focus()
	}

	function closeForm() {
		$('ts-form').hidden = true
		editing = null
	}

	async function save(ev) {
		ev.preventDefault()
		const body = {
			name: $('ts-name').value.trim(),
			slug: $('ts-slug').value.trim(),
			groups: { team: $('ts-g-team').value },
		}
		body.settings = {
			backup_required: $('ts-backup-required').checked,
		}
		// Empty means automatic; a new team has no choice yet.
		if (editing !== null) {
			body.backup_owner = $('ts-backup-owner').value || null
		}
		$('ts-save').disabled = true
		$('ts-error').textContent = ''
		try {
			if (editing === null) {
				await api('POST', '/admin/teams', body)
			} else {
				const changes = memberChanges()
				await api('PUT', '/admin/teams/' + editing, body)
				// Roles and leaving via the same service as PUT /team/members.
				for (const [uid, change] of changes) {
					try {
						await api('PUT', '/admin/teams/' + editing + '/members/' + encodeURIComponent(uid), change)
					} catch (e) {
						throw new Error(tr('member_failed', { name: uid, message: e.message }))
					}
				}
			}
			// Status and group assignment come fresh from the server.
			window.location.reload()
		} catch (e) {
			$('ts-error').textContent = e.message
		} finally {
			$('ts-save').disabled = false
		}
	}

	async function removeTeam(t) {
		if (!window.confirm(tr('confirm_delete', { name: t.name }))) {
			return
		}
		try {
			await api('DELETE', '/admin/teams/' + t.id)
			window.location.reload()
		} catch (e) {
			window.alert(e.message)
		}
	}

	// --- Team backup as ZIP (0.10.0) ---

	let importing = null // the team a ZIP goes into
	let preview = null // the server's preview of the uploaded ZIP

	/** Download through fetch, so the OCS headers and the request token travel along. */
	async function exportTeam(t) {
		try {
			const res = await fetch(overview.api_base + '/admin/teams/' + t.id + '/export', {
				credentials: 'same-origin',
				headers: { 'OCS-APIRequest': 'true', requesttoken: requestToken() },
			})
			if (!res.ok) {
				let msg = tr('error_status', { status: res.status })
				try {
					const json = await res.json()
					msg = (json.ocs && json.ocs.data && json.ocs.data.message) || msg
				} catch (e) { /* no JSON body */ }
				throw new Error(msg)
			}
			const m = /filename="?([^";]+)"?/.exec(res.headers.get('Content-Disposition') || '')
			const name = m ? m[1] : 'timesister-' + t.slug + '.zip'
			const url = URL.createObjectURL(await res.blob())
			const a = h('a', { href: url, download: name })
			document.body.append(a)
			a.click()
			a.remove()
			setTimeout(() => URL.revokeObjectURL(url), 10000)
		} catch (e) {
			window.alert(tr('export_failed', { message: e.message }))
		}
	}

	function openImport(t) {
		closeForm()
		importing = t
		preview = null
		$('ts-import-title').textContent = tr('import_title', { name: t.name })
		$('ts-import-file').value = ''
		$('ts-import-preview').textContent = ''
		$('ts-import-error').textContent = ''
		$('ts-import-go').disabled = true
		$('ts-import').hidden = false
		$('ts-import-file').focus()
	}

	function closeImport() {
		$('ts-import').hidden = true
		importing = null
		preview = null
	}

	async function previewUpload() {
		const file = $('ts-import-file').files[0]
		$('ts-import-preview').textContent = ''
		$('ts-import-error').textContent = ''
		$('ts-import-go').disabled = true
		preview = null
		if (!file || !importing) {
			return
		}
		$('ts-import-preview').append(h('p', { class: 'ts-muted', text: tr('previewing') }))
		const form = new FormData()
		form.append('file', file, file.name)
		try {
			const res = await fetch(overview.api_base + '/admin/teams/' + importing.id + '/import/preview', {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'OCS-APIRequest': 'true', Accept: 'application/json', requesttoken: requestToken() },
				body: form,
			})
			let json = null
			try {
				json = await res.json()
			} catch (e) {
				json = null
			}
			const data = json && json.ocs ? json.ocs.data : null
			if (!res.ok) {
				throw new Error((data && data.message) || tr('error_status', { status: res.status }))
			}
			preview = data
			renderPreview()
		} catch (e) {
			$('ts-import-preview').textContent = ''
			$('ts-import-error').textContent = e.message
		}
	}

	function renderPreview() {
		const box = $('ts-import-preview')
		box.textContent = ''
		const p = preview
		const records = Object.values(p.records || {}).reduce((n, c) => n + c, 0)
		box.append(h('p', {},
			h('strong', { text: tr('preview_source', { name: p.team.name, date: formatTime(p.taken_at) }) }),
			' · ',
			h('span', { class: 'ts-muted', text: tr('preview_counts', { n: records, c: (p.calendars || []).filter((c) => !c.missing).length, a: p.absences || 0 }) })))
		if (p.target && !p.target.empty) {
			box.append(h('p', { class: 'ts-bad', text: tr('preview_not_empty') }))
		}
		// Person of the backup → account here: same identifier when it exists, otherwise without account.
		const accounts = p.accounts || []
		const rows = (p.persons || []).map((m) => {
			const sel = h('select', { 'data-uid': m.uid, 'aria-label': tr('account_for', { name: m.uid }) },
				h('option', { value: '', text: tr('no_account') }),
				accounts.map((a) => h('option', { value: a.uid, selected: a.uid === m.uid && m.exists ? true : undefined, text: a.display_name && a.display_name !== a.uid ? a.display_name + ' (' + a.uid + ')' : a.uid })))
			if (m.exists && !accounts.some((a) => a.uid === m.uid)) {
				sel.append(h('option', { value: m.uid, selected: true, text: m.uid }))
			}
			return h('tr', { class: m.left_at ? 'ts-left' : '' },
				h('td', {}, h('span', { text: memberLabel(m) }), ' ', h('span', { class: 'ts-muted', text: roleName(m.role) + (m.left_at ? ' · ' + tr('left') : '') })),
				h('td', {}, sel, m.exists ? null : h('div', { class: 'ts-muted', text: tr('not_here') }), m.member ? h('div', { class: 'ts-muted', text: tr('already_member') }) : null))
		})
		box.append(h('table', {},
			h('thead', {}, h('tr', {}, h('th', { text: tr('person_col') }), h('th', { text: tr('account_col') }))),
			h('tbody', {}, rows.length ? rows : h('tr', {}, h('td', { class: 'ts-muted', colspan: 2, text: tr('no_members') })))))
		box.append(h('div', { class: 'ts-mode' },
			h('label', {}, h('input', { type: 'radio', name: 'ts-mode', value: 'merge', checked: true }), tr('mode_merge')),
			h('label', {}, h('input', { type: 'radio', name: 'ts-mode', value: 'replace' }), tr('mode_replace'))))
		$('ts-import-go').disabled = false
	}

	async function runImport(ev) {
		ev.preventDefault()
		if (!preview || !importing) {
			return
		}
		const mapping = {}
		for (const sel of $('ts-import-preview').querySelectorAll('select[data-uid]')) {
			mapping[sel.dataset.uid] = sel.value === '' ? null : sel.value
		}
		const mode = ($('ts-import-preview').querySelector('input[name=ts-mode]:checked') || {}).value || 'merge'
		$('ts-import-go').disabled = true
		$('ts-import-error').textContent = ''
		const busy = h('p', { class: 'ts-muted', text: tr('importing') })
		$('ts-import-preview').append(busy)
		try {
			const r = await api('POST', '/admin/teams/' + importing.id + '/import', { token: preview.token, mapping, mode })
			renderResult(r)
			preview = null
			await load()
		} catch (e) {
			busy.remove()
			$('ts-import-error').textContent = e.message
			$('ts-import-go').disabled = false
		}
	}

	function renderResult(r) {
		const box = $('ts-import-preview')
		box.textContent = ''
		const rec = r.records || {}
		const stored = (r.calendars || []).filter((c) => c.stored).map((c) => c.uid)
		const kept = (r.calendars || []).filter((c) => c.kept_existing).map((c) => c.uid)
		const list = h('ul', { class: 'ts-list ts-result' },
			h('li', { text: tr('import_done', { inserted: rec.inserted || 0, updated: rec.updated || 0, tombstoned: rec.tombstoned || 0, members: (r.members && r.members.rows) || 0, calendars: stored.length }) }),
			r.pre_backup ? h('li', { text: tr('pre_backup', { path: r.pre_backup.visible || r.pre_backup.protected }) }) : null,
			(r.without_account || []).length ? h('li', { text: tr('without_account', { list: r.without_account.join(', ') }) }) : null,
			kept.length ? h('li', { text: tr('calendars_kept', { list: kept.join(', ') }) }) : null,
			h('li', { class: 'ts-muted', text: tr('new_after_reload') }))
		box.append(h('p', { class: 'ts-ok', text: tr('import_ok', { name: r.team ? r.team.name : '' }) }), list)
	}

	async function load() {
		try {
			teams = await api('GET', '/admin/teams')
		} catch (e) {
			$('ts-teams').textContent = ''
			$('ts-teams').append(h('p', { class: 'ts-bad', text: tr('load_failed', { message: e.message }) }))
			return
		}
		renderTeams()
		renderState()
	}

	function init() {
		if (!$('timesister-admin')) {
			return
		}
		$('ts-new').addEventListener('click', () => openForm(null))
		$('ts-cancel').addEventListener('click', closeForm)
		$('ts-form').addEventListener('submit', save)
		$('ts-name').addEventListener('input', suggestSlug)
		$('ts-slug').addEventListener('input', tidySlug)
		$('ts-import-cancel').addEventListener('click', closeImport)
		$('ts-import-file').addEventListener('change', previewUpload)
		$('ts-import').addEventListener('submit', runImport)
		load()
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init)
	} else {
		init()
	}
})()
