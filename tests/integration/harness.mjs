// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Test harness and shared values for the integration check.
import { createHash } from 'node:crypto'
import { as, ocs } from './lib.mjs'

export const RUN = Date.now().toString(36)
export const stats = { passed: 0, failed: [], section: '' }

export function head(title) {
	stats.section = title
	console.log(`\n\x1b[1;34m==>\x1b[0m ${title}`)
}
export function check(name, ok, detail) {
	if (ok) {
		stats.passed++
		console.log(`    \x1b[32mok\x1b[0m  ${name}`)
	} else {
		stats.failed.push(`${stats.section}: ${name}`)
		console.log(`    \x1b[31mFAIL\x1b[0m  ${name}${detail === undefined ? '' : ' – ' + (typeof detail === 'string' ? detail : JSON.stringify(detail)).slice(0, 400)}`)
	}
}
/** Check a response's status and error code. */
export function expect(name, r, status, error) {
	const ok = r.status === status && (error === undefined || r.data?.error === error)
	check(`${name} → ${status}${error ? ' ' + error : ''}`, ok, `HTTP ${r.status} ${r.text.slice(0, 300)}`)
	return ok
}
export const isObj = (v) => v !== null && typeof v === 'object' && !Array.isArray(v)
export const ISO = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/
export const sha256 = (s) => createHash('sha256').update(s).digest('hex')
export const b64 = (s) => Buffer.from(s).toString('base64')
export const keysOf = (r) => new Set((r.data?.records || []).map((x) => `${x.kind}/${x.key}`))

export const A = as('pbadmin')
export const V = as('pbverw')
export const L = as('pblead')
export const U1 = as('pbuser1')
export const U2 = as('pbuser2')
export const AA = as('atadmin')
export const AL = as('atlead')
export const AU = as('atuser1')

export const P = `P-${RUN}`
export const C = `K-${RUN}`
export const R = `CH-${RUN}`
export const NOAH = `noah.${RUN}@example.test`

/**
 * Makes sure the record exists – but never overwrites what is already
 * there (several checks share the test environment).
 */
export async function ensure(who, kind, key, data) {
	const cur = await ocs(who, 'GET', `/records/${kind}/${encodeURIComponent(key)}`)
	if (cur.status === 200) {
		return cur
	}
	return ocs(who, 'PUT', `/records/${kind}/${encodeURIComponent(key)}`, { version: 0, data })
}
export const me = async (who) => (await ocs(who, 'GET', '/me')).data


/** Print the result; returns the exit code. */
export function summary() {
	console.log(`\n${stats.passed} passed, ${stats.failed.length} failed`)
	for (const f of stats.failed) {
		console.log(`  - ${f}`)
	}
	return stats.failed.length ? 1 : 0
}
