// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Integration check of the app against a running test Nextcloud with the
// accounts from seed.mjs. Checks every row of the permissions table from
// API.md and every endpoint, plus roles and departure (version 2), projects
// (catalog and lead), Jobs and their weekly numbers, billing marks and externals, invoices and their numbers, quotes, the invoice template, team boundary, conflicts, batch, delta, history,
// backups (with consent, visible copy and weekly job), heartbeat,
// concurrency, the time entries (named calendars, job, roles), invoicing
// on the server (issue, reverse, paid, ledger and chain) and the invoice
// documents (folders, preview, stored PDF, ledger hash, chain head) and the
// e-invoices and exports (tax case, ZUGFeRD, XRechnung, QRR, exports, webhooks),
// VAT per invoice line and the cost positions of a budget (0.21.0), receipts,
// expense reports and the document tree (0.22.0), approval by assignment and
// fixed expense reports with ledger (0.23.0), the invoicing mode and the
// hand-over to an accounting system that issues the invoice (0.24.0).
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
import { listen } from './teil-listen.mjs'
import { faktura } from './teil-faktura.mjs'
import { dokumente } from './teil-dokumente.mjs'
import { erechnung } from './teil-erechnung.mjs'
import { kosten } from './teil-kosten.mjs'
import { belege } from './teil-belege.mjs'
import { uebergabe } from './teil-uebergabe.mjs'
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
await listen()
await faktura()
await dokumente()
await erechnung()
await kosten()
await belege()
await uebergabe()
await rest()
await sicherungen()
process.exit(summary())
