// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Integrationsprüfung der App gegen eine laufende Test-Nextcloud mit den
// Konten aus seed.mjs. Prüft jede Zeile der Rechte-Tabelle aus API.md und
// jeden Endpunkt, dazu Teamgrenze, Konflikte, Batch, Delta, Verlauf,
// Sicherungen, Lebenszeichen und Parallelität.
//
//   NC_URL=http://localhost:8081 node tests/integration/integration.mjs
//
// Wiederholbar: Jeder Lauf benutzt eigene Schlüssel (Suffix aus der Zeit).
import { datensaetze } from './teil-datensaetze.mjs'
import { rest } from './teil-rest.mjs'
import { teams } from './teil-teams.mjs'
import { summary } from './harness.mjs'

await teams()
await datensaetze()
await rest()
process.exit(summary())
