// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Integration check of the app against a running test Nextcloud with the
// accounts from seed.mjs. Checks every row of the permissions table from
// API.md and every endpoint, plus roles and departure (version 2), projects
// (catalog and lead), Jobs and their weekly numbers, billing marks and externals, invoices and their numbers, quotes, the invoice template, team boundary, conflicts, batch, delta, history,
// backups (with consent, visible copy and weekly job), heartbeat,
// concurrency and the time entries (named calendars, job, roles).
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
import { abrechnung } from './teil-abrechnung.mjs'
import { rechnungen } from './teil-rechnungen.mjs'
import { offerten } from './teil-offerten.mjs'
import { vorlagen } from './teil-vorlagen.mjs'
import { jobs } from './teil-jobs.mjs'
import { wochen } from './teil-wochen.mjs'
import { eintraege } from './teil-eintraege.mjs'
import { summary } from './harness.mjs'

await teams()
await konten()
await abrechnung()
await datensaetze()
await projekte()
await rechnungen()
await offerten()
await vorlagen()
await jobs()
await wochen()
await eintraege()
await rest()
await sicherungen()
process.exit(summary())
