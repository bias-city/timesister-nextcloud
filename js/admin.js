/* SPDX-License-Identifier: AGPL-3.0-or-later */
/*
 * TimeSister – Admin-Seite. Vanilla-JavaScript, ohne Build.
 * Teams kommen über die Admin-Endpunkte (OCS, requesttoken), der Zustand
 * als Initial-State. Kein innerHTML mit Daten: alles über textContent.
 * Texte übersetzt der Server (Initial-State „l10n“), ohne OC.L10N.
 */
(function () {
	'use strict'

	const ROLES = ['user', 'lead', 'subadmin', 'admin']
	// Die optionale Konten-Gruppe: alle Konten des Teams, keine Rolle.
	const ACCOUNTS = 'accounts'

	/** Ohne OC-Globals: das Token steht im Kopf der Seite. */
	function requestToken() {
		return document.head.dataset.requesttoken || ''
	}

	/** Initial-State lesen (base64-JSON in einem versteckten Feld). */
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

	/** Übersetzter Text zum Schlüssel; {name} wird durch vars.name ersetzt. */
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

	/** Kleines DOM-Hilfsmittel: nur textContent, nie HTML. */
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

	/** Papierkorb als SVG in currentColor – keine Nextcloud-Symbolklassen. */
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
	let editing = null // Team-ID oder null für neu

	const $ = (id) => document.getElementById(id)

	function groupLabel(gid) {
		const g = overview.groups.find((x) => x.id === gid)
		return g && g.name && g.name !== gid ? g.name + ' (' + gid + ')' : gid
	}

	function stateOf(id) {
		return (overview.state || []).find((s) => s.id === id) || null
	}

	/** Anzeigename (Kennung) eines Admins aus dem Zustand, sonst die Kennung. */
	function adminLabel(st, uid) {
		const a = st && st.admins ? st.admins.find((x) => x.uid === uid) : null
		return a && a.display_name && a.display_name !== uid ? a.display_name + ' (' + uid + ')' : uid
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
			const roles = h('div', { class: 'ts-roles' }, ROLES.map((role) => [
				h('span', { text: tr('role_' + role) }),
				h('span', { class: (st && st.missing_roles && st.missing_roles.includes(role)) ? 'ts-bad' : '', text: t.groups[role] ? groupLabel(t.groups[role]) : '–' }),
				h('span', { class: 'ts-count', text: String(t.counts ? t.counts[role] : 0) }),
			]), t.groups[ACCOUNTS] ? [
				h('span', { class: 'ts-muted', text: tr('accounts_group') }),
				h('span', { text: groupLabel(t.groups[ACCOUNTS]) }),
				h('span', { class: 'ts-count', text: String(t.counts && t.counts[ACCOUNTS] !== undefined ? t.counts[ACCOUNTS] : 0) }),
			] : null)
			const owner = h('div', { class: 'ts-owner' },
				h('span', { class: 'ts-muted', text: tr('backups_stored_with') + ' ' }),
				t.backup_owner ? h('span', { text: adminLabel(st, t.backup_owner) }) : h('span', { class: 'ts-bad', text: tr('owner_none') }))
			const s = t.settings || {}
			const settings = [['leads_see', s.leads_see_calendars], ['backup_required', s.backup_required]].map(([key, on]) =>
				h('div', {}, h('span', { class: 'ts-muted', text: tr(key) + ': ' }), h('span', { text: on ? tr('yes') : tr('no') })))
			return h('tr', { 'data-team': t.slug },
				h('td', {},
					h('div', { text: t.name }),
					h('div', { class: 'ts-slug', text: t.slug }),
					broken ? h('div', { class: 'ts-bad', text: tr('broken') }) : null,
				),
				h('td', {}, roles, owner, settings),
				h('td', { class: 'ts-right' },
					h('button', { type: 'button', class: 'button', onclick: () => openForm(t) }, tr('edit')),
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
			h('thead', {}, h('tr', {}, h('th', { text: tr('team') }), h('th', { text: tr('roles_col') }), h('th', {}))),
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
				return h('tr', {}, h('td', { text: t.name }), h('td', { class: 'ts-muted', colspan: 5, text: tr('new_after_reload') }))
			}
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
				h('th', { text: tr('backups_col') }),
				h('th', { text: tr('silent') }),
			)),
			h('tbody', {}, rows),
		))
	}

	function fillSelects(current) {
		for (const role of [...ROLES, ACCOUNTS]) {
			const sel = $('ts-g-' + role)
			sel.textContent = ''
			sel.append(h('option', { value: '', text: role === ACCOUNTS ? tr('no_group') : tr('choose_group') }))
			for (const g of overview.groups) {
				const taken = g.team !== null && g.team !== undefined && g.team !== (editing || -1)
				sel.append(h('option', {
					value: g.id,
					text: (g.name && g.name !== g.id ? g.name + ' (' + g.id + ')' : g.id) + (taken ? ' ' + tr('other_team') : ''),
					disabled: taken,
				}))
			}
			sel.value = (current && current.groups && current.groups[role]) || ''
		}
	}

	/** Sicherungs-Konto: automatisch oder einer der Admins des Teams (nur beim Bearbeiten). */
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

	function openForm(team) {
		editing = team ? team.id : null
		$('ts-form-title').textContent = team ? tr('edit_team') : tr('new_team')
		$('ts-name').value = team ? team.name : ''
		$('ts-slug').value = team ? team.slug : ''
		$('ts-error').textContent = ''
		fillSelects(team)
		fillOwner(team)
		// Neues Team: die Standardwerte des Vertrags (Leitung sieht alles, keine Anordnung).
		const s = (team && team.settings) || {}
		$('ts-leads-see').checked = s.leads_see_calendars !== false
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
			// accounts leer: das Team hat keine Konten-Gruppe (beim Ändern: entfernen).
			groups: Object.fromEntries([...ROLES, ACCOUNTS].map((role) => [role, $('ts-g-' + role).value])),
		}
		body.settings = {
			leads_see_calendars: $('ts-leads-see').checked,
			backup_required: $('ts-backup-required').checked,
		}
		// Leer heisst automatisch; bei einem neuen Team gibt es noch keine Wahl.
		if (editing !== null) {
			body.backup_owner = $('ts-backup-owner').value || null
		}
		$('ts-save').disabled = true
		$('ts-error').textContent = ''
		try {
			if (editing === null) {
				await api('POST', '/admin/teams', body)
			} else {
				await api('PUT', '/admin/teams/' + editing, body)
			}
			// Zustand und Gruppenbelegung kommen frisch vom Server.
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
		load()
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init)
	} else {
		init()
	}
})()
