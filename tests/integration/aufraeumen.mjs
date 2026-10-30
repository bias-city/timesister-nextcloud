// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Cleans up what one integration run left behind – only the records whose
// key contains its run id (the suffix of P-, K-, …) in the check's teams zb
// and za (konten.mjs), as the harness does at its end. Issued invoices
// stay. Only against localhost (lib.mjs).
//
//   NC_URL=http://localhost:8081 node tests/integration/aufraeumen.mjs <run>
import { head, summary } from './harness.mjs'
import { aufraeumen } from './teil-rest.mjs'

const run = process.argv[2] || ''
if (!/^[a-z0-9]{6,}$/.test(run)) {
	console.error('Usage: node tests/integration/aufraeumen.mjs <run> – the run id, e.g. mvtvbphi')
	process.exit(2)
}
head(`Cleaning up run ${run}`)
await aufraeumen(run)
process.exit(summary())
