// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Integration check of the app against a running test Nextcloud with the
// accounts from seed.mjs. Checks every row of the permissions table from
// API.md and every endpoint, plus roles and departure (version 2), projects
// (catalog and lead), the shares matrix, team boundary, conflicts, batch, delta, history,
// backups (with consent, visible copy and weekly job), heartbeat
// and concurrency.
//
//   NC_URL=http://localhost:8081 node tests/integration/integration.mjs
//
// Repeatable: each run uses its own keys (a suffix from the time).
import { datensaetze } from './teil-datensaetze.mjs'
import { rest } from './teil-rest.mjs'
import { teams } from './teil-teams.mjs'
import { konten } from './teil-konten.mjs'
import { projekte } from './teil-projekte.mjs'
import { sicherungen } from './teil-sicherungen.mjs'
import { freigaben } from './teil-freigaben.mjs'
import { summary } from './harness.mjs'

await teams()
await konten()
await freigaben()
await datensaetze()
await projekte()
await rest()
await sicherungen()
process.exit(summary())
