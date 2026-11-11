/* SPDX-License-Identifier: AGPL-3.0-or-later */
/*
 * TimeSister – personal page (0.49.0). Vanilla JavaScript, no build step.
 * Names or un-names the own time calendar through PUT/DELETE
 * /me/time-calendar (OCS, requesttoken), like the Mac app does.
 * No innerHTML with data: everything via textContent.
 */
(function () {
	'use strict'

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
	const state = loadState('personal')

	function tr(key, vars) {
		const text = typeof TEXTS[key] === 'string' ? TEXTS[key] : key
		return text.replace(/\{(\w+)\}/g, (m, k) => (vars && k in vars ? String(vars[k]) : m))
	}

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
			if (c !== null && c !== undefined && c !== false) {
				node.append(c instanceof Node ? c : document.createTextNode(String(c)))
			}
		}
		return node
	}

	async function api(method, path, body) {
		const res = await fetch(state.api_base + path, {
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
			throw new Error((data && data.message) || tr('error_status', { status: res.status }))
		}
		return data
	}

	function formatTime(iso) {
		const d = new Date(iso)
		if (isNaN(d)) {
			return iso
		}
		try {
			return d.toLocaleString(state.locale || undefined, { dateStyle: 'medium', timeStyle: 'short' })
		} catch (e) {
			return d.toLocaleString()
		}
	}

	const el = (id) => document.getElementById(id)
	let busy = false

	function say(message, error) {
		el('ts-p-message').textContent = error ? '' : message
		el('ts-p-error').textContent = error ? message : ''
	}

	function renderCurrent() {
		const box = el('ts-p-current')
		const cur = state.current
		box.replaceChildren()
		el('ts-p-stop').hidden = !cur
		if (!cur) {
			box.append(h('p', { text: tr('none_yet') }))
			return
		}
		box.append(h('p', {},
			h('strong', { text: cur.found ? cur.name : tr('not_found', { name: cur.name }) }),
			' ',
			h('span', { class: 'ts-p-uri', text: cur.uri })))
		if (cur.last_error) {
			box.append(h('p', { class: 'ts-error', text: tr('read_failed', { message: cur.last_error }) }))
		} else {
			box.append(h('p', { class: 'ts-muted', text: cur.last_read_at ? tr('last_read', { date: formatTime(cur.last_read_at) }) : tr('not_read') }))
		}
	}

	function renderList() {
		const box = el('ts-p-calendars')
		box.replaceChildren()
		if (!state.calendars.length) {
			box.append(h('p', { class: 'ts-muted', text: tr('no_calendars') }))
			return
		}
		const rows = state.calendars.map((c) => {
			let action
			if (c.current) {
				action = h('button', { type: 'button', class: 'button', disabled: true, text: tr('in_use') })
			} else if (c.vacation) {
				action = h('button', { type: 'button', class: 'button', disabled: true, title: tr('vacation'), text: tr('vacation') })
			} else {
				action = h('button', { type: 'button', class: 'button', disabled: busy, onclick: () => use(c), text: tr('use') })
			}
			return h('tr', { class: c.current ? 'ts-p-current' : null },
				h('td', {}, h('span', { text: c.name }), h('br'), h('span', { class: 'ts-p-uri', text: c.uri })),
				h('td', { class: 'ts-right' }, action))
		})
		box.append(h('table', {}, h('tbody', {}, rows)))
	}

	function render() {
		renderCurrent()
		renderList()
	}

	async function use(c) {
		const old = state.current
		if (old && !window.confirm(tr('confirm_switch', { name: c.name, old: old.name }))) {
			return
		}
		busy = true
		render()
		try {
			const r = await api('PUT', '/me/time-calendar', { calendar_url: c.uri })
			state.calendars.forEach((x) => { x.current = x.uri === r.calendar_uri })
			state.current = { uri: r.calendar_uri, name: c.name, found: true, last_read_at: r.read_now ? new Date().toISOString() : null, last_error: null }
			say(tr('named', { name: c.name }), false)
		} catch (e) {
			say(tr('failed', { message: e.message }), true)
		}
		busy = false
		render()
	}

	async function stop() {
		const cur = state.current
		if (!cur || !window.confirm(tr('confirm_stop', { name: cur.name }))) {
			return
		}
		el('ts-p-stop').disabled = true
		try {
			await api('DELETE', '/me/time-calendar')
			state.calendars.forEach((x) => { x.current = false })
			state.current = null
			say(tr('stopped'), false)
		} catch (e) {
			say(tr('failed', { message: e.message }), true)
		}
		el('ts-p-stop').disabled = false
		render()
	}

	function start() {
		if (!state || !el('ts-p-current')) {
			return
		}
		el('ts-p-team').textContent = tr('team', { name: state.team })
		el('ts-p-stop').addEventListener('click', stop)
		render()
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start)
	} else {
		start()
	}
})()
