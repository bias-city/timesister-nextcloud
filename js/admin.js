/* SPDX-License-Identifier: AGPL-3.0-or-later */
/*
 * TimeSister – Admin-Seite. Vanilla-JavaScript, ohne Build.
 * Teams kommen über die Admin-Endpunkte (OCS, requesttoken), der Zustand
 * als Initial-State. Kein innerHTML mit Daten: alles über textContent.
 */
(function () {
	'use strict'

	const ROLES = [
		['user', 'Mitarbeitende'],
		['lead', 'Projektleitung'],
		['subadmin', 'Verwaltung'],
		['admin', 'Admin'],
	]

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
			const msg = (data && data.message) || (json && json.ocs && json.ocs.meta && json.ocs.meta.message) || ('Fehler ' + res.status)
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
		return isNaN(d) ? iso : d.toLocaleString('de-CH', { dateStyle: 'medium', timeStyle: 'short' })
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

	function renderTeams() {
		const box = $('ts-teams')
		box.textContent = ''
		if (teams.length === 0) {
			box.append(h('p', { class: 'ts-muted', text: 'Noch kein Team. Legen Sie eines an und ordnen Sie ihm vier Gruppen zu.' }))
			return
		}
		const rows = teams.map((t) => {
			const st = stateOf(t.id)
			const broken = st && st.broken
			const roles = h('div', { class: 'ts-roles' }, ROLES.map(([role, label]) => [
				h('span', { text: label }),
				h('span', { class: (st && st.missing_roles && st.missing_roles.includes(role)) ? 'ts-bad' : '', text: t.groups[role] ? groupLabel(t.groups[role]) : '–' }),
				h('span', { class: 'ts-count', text: String(t.counts ? t.counts[role] : 0) }),
			]))
			return h('tr', { 'data-team': t.slug },
				h('td', {},
					h('div', { text: t.name }),
					h('div', { class: 'ts-slug', text: t.slug }),
					broken ? h('div', { class: 'ts-bad', text: 'Zuordnung gebrochen' }) : null,
				),
				h('td', {}, roles),
				h('td', { class: 'ts-right' },
					h('button', { type: 'button', class: 'button', onclick: () => openForm(t) }, 'Bearbeiten'),
					' ',
					h('button', {
						type: 'button',
						class: 'button ts-icon',
						title: 'Team löschen',
						'aria-label': 'Team ' + t.name + ' löschen',
						onclick: () => removeTeam(t),
					}, trashIcon()),
				),
			)
		})
		box.append(h('table', {},
			h('thead', {}, h('tr', {}, h('th', { text: 'Team' }), h('th', { text: 'Rollen, Gruppe und Mitglieder' }), h('th', {}))),
			h('tbody', {}, rows),
		))
	}

	function renderState() {
		const box = $('ts-state')
		box.textContent = ''
		box.append(h('p', {}, 'Schnittstelle: Fassung ', h('strong', { text: String(overview.api) }),
			' · App ', h('strong', { text: overview.version || '–' })))
		if (teams.length === 0) {
			return
		}
		const days = overview.silent_days || 14
		const rows = teams.map((t) => {
			const st = stateOf(t.id)
			if (!st) {
				return h('tr', {}, h('td', { text: t.name }), h('td', { class: 'ts-muted', colspan: 4, text: 'Neu – Zustand nach dem Neuladen.' }))
			}
			const silent = st.silent.length === 0
				? h('span', { class: 'ts-ok', text: 'alle gemeldet' })
				: h('ul', { class: 'ts-list' }, st.silent.map((p) => h('li', {},
					p.display_name + ' ', h('span', { class: 'ts-muted', text: p.seen_at ? '(zuletzt ' + formatTime(p.seen_at) + ')' : '(noch nie)' }))))
			return h('tr', {},
				h('td', {}, h('div', { text: t.name }), st.broken ? h('div', { class: 'ts-bad', text: 'Zuordnung gebrochen' }) : null),
				h('td', { class: 'ts-num', text: String(st.records) }),
				h('td', { text: formatTime(st.last_modified) }),
				h('td', { class: 'ts-num', text: String(st.revision) }),
				h('td', {}, silent),
			)
		})
		box.append(h('table', {},
			h('thead', {}, h('tr', {},
				h('th', { text: 'Team' }),
				h('th', { text: 'Datensätze' }),
				h('th', { text: 'Letzte Änderung' }),
				h('th', { text: 'Revision' }),
				h('th', { text: 'Ohne Lebenszeichen seit ' + days + ' Tagen' }),
			)),
			h('tbody', {}, rows),
		))
	}

	function fillSelects(current) {
		for (const [role] of ROLES) {
			const sel = $('ts-g-' + role)
			sel.textContent = ''
			sel.append(h('option', { value: '', text: '– Gruppe wählen –' }))
			for (const g of overview.groups) {
				const taken = g.team !== null && g.team !== undefined && g.team !== (editing || -1)
				sel.append(h('option', {
					value: g.id,
					text: (g.name && g.name !== g.id ? g.name + ' (' + g.id + ')' : g.id) + (taken ? ' – anderes Team' : ''),
					disabled: taken,
				}))
			}
			sel.value = (current && current.groups && current.groups[role]) || ''
		}
	}

	function openForm(team) {
		editing = team ? team.id : null
		$('ts-form-title').textContent = team ? 'Team bearbeiten' : 'Neues Team'
		$('ts-name').value = team ? team.name : ''
		$('ts-slug').value = team ? team.slug : ''
		$('ts-error').textContent = ''
		fillSelects(team)
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
			groups: Object.fromEntries(ROLES.map(([role]) => [role, $('ts-g-' + role).value])),
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
		if (!window.confirm('Team „' + t.name + '“ löschen? Das geht nur, solange es keine Datensätze und Sicherungen hat. Die Gruppen und Konten bleiben.')) {
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
			$('ts-teams').append(h('p', { class: 'ts-bad', text: 'Teams lassen sich nicht laden: ' + e.message }))
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
