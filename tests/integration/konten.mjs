// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The integration check's own accounts: the teams zb and za from lib.mjs,
// built like pb and at but disjoint from the test data set, so the check
// never writes into the data set's teams. harness.mjs keeps the pb/at
// accounts for the parts that still use them.
import { ACCOUNTS, TEAMS, as } from './lib.mjs'

export const A = as('zbadmin')
export const V = as('zbverw')
export const L = as('zblead')
export const U1 = as('zbuser1')
export const U2 = as('zbuser2')
export const AA = as('zaadmin')
export const AL = as('zalead')
export const AU = as('zauser1')

/** The two teams of the check: TEAM holds A … U2, TEAM2 holds AA … AU. */
export const TEAM = TEAMS.find((t) => t.slug === 'zb')
export const TEAM2 = TEAMS.find((t) => t.slug === 'za')

/** Display name of an account as seeded (lib.mjs ACCOUNTS). */
export const nameOf = (uid) => ACCOUNTS.find(([u]) => u === uid)?.[1] ?? uid
