# Changes

## 0.26.1 – 2026-10-17

Additive, `api: 2` unchanged; capability `invoice_layout: 1`; migration
`Version1020Date20261017220000` (column `ts_inv_invoices.layout_json`).
Contract: `API.md` «Advance invoices, 0.26.0» (section on the layout). Not
published.

- **Invoice `layout`**: null or `{grouping: flat|level, appendix: [levels,
  tags, budget], levels: [{title, target, before, this}], budget: {target,
  before, this}}`; hours positions carry `path` (the levels above, at most
  10) and `tags`. Frozen on issue (`layout_json`, in the ledger payload
  only when set) and drawn: headings and subtotals by level, the tables
  after the sums. The team setting `tags` (vocabulary) passes unchecked.

## 0.26.0 – 2026-10-17

Additive, `api: 2` unchanged; capability `advances: 1`; no migration.
Contract: `API.md` «Advance invoices, 0.26.0». Not published.

- **Position `advance`**: a fee line ≥ 0 with the amount agreed in the
  budget's invoice plan. An invoice with such a line (not a reversal or
  credit note) is an **advance invoice**: the document says «Advance
  invoice» / «Akontorechnung», the e-invoice has type code 326 (partial
  invoice) instead of 380.
- **Position `advance_deduction`**: a fee line ≤ 0 in a later invoice of
  the budget, naming the advance invoice's key in `invoice`. Only this
  kind carries `invoice`. Neither kind is a cost line or pass-through.
- **Cost position `forecast`**: null or at most 200 amounts ≥ 0, one per
  milestone of the budget (in the order of the work packages' hours). The
  client sets `cap` to their sum; a percentage position has none (it
  follows the fee per phase).
- **Work package `parent` and `tags`** replace `group` and `role` of
  0.25.1 (unpublished, no transition): `parent` null or the `no` of another
  work package of the same budget (the level: 31.2 below 31, 31 below 3);
  `tags` null or at most 20 trimmed texts of 1–60 characters, the team's
  own markers (e.g. «Site supervision», «external»). Also in quotes.

## 0.25.1 – 2026-10-17

Additive, `api: 2` unchanged; capability `templates: 2`; migration
`Version1019Date20261017180000` (column `ts_tenants.catalogs_json`).
Contract: `API.md` «Template owner, catalogs and the structured invoice
plan, 0.25.1». Not published.

- **Template owner `created_by`**: kept by the server in the data of every
  `template` write (new or on a tombstone: the writer; otherwise the stored
  `created_by`, for a 0.25.0 record its `modified_by`); a client's value is
  replaced. A Lead changes and removes the templates they created, also
  after a Team Admin changed them.
- **Team setting `catalogs`**: null (the client's default by country) or a
  list of at most 50 distinct `id@edition`; `[]` means none.
- **Budget `billing_plan`**: null or at most 200 `{date, share?, amount?,
  title?}` beside `invoice_dates` (unchanged).
- **`work_packages[].group`** (trimmed text 1–200, stored trimmed) and
  **`.role`** (`gesamtleitung`, `fachplanung`, `bauleitung`), also in quotes.

## 0.25.0 – 2026-10-17

Additive, `api: 2` unchanged; capability `templates: 1`; no migration.
Contract: `API.md` «Project templates, 0.25.0». Not published.

- **Record kind `template`**: a team's own project template (format
  `timesister-template` version 3, key `<id>@<edition>`, at most 256 KB, no
  person or customer fields anywhere). Leads and Team Admins read; Team
  Admins write, a Lead new ones and those they saved last. Team ZIP takes
  them along.
- **Project `budget_label`** (text ≤ 60 or null): what the project's
  budgets are called, from the template.

## 0.24.1 – 2026-10-17

Additive, `api: 2` unchanged; capability `invoice_handover: 2`; migration
`Version1018Date20261017150000` (`ts_inv_ledger.action` 16 → 32
characters). Contract: `API.md` «Budget progress, addenda and the accounting
system's PDF, 0.24.1». Not published.

- **Budget `progress`** (0–100, whole number, null = not set) and
  **`work_packages[].addendum`** (bool) checked in projects and quotes.
- **`invoicing_mode.archive_pdf`** (mode external, default true): store
  the accounting system's PDF of a handed-over invoice.
- **`PUT /invoices/{key}/external-document`** (Team Admins): the PDF (≤ 20
  MB) to `Buchhaltung/<YYYY>/Rechnungen/<date>_<system>-<number>_<customer>.pdf`,
  ledger `external_document` (sha256, path), record `external.document`;
  the same file again is no change, another one 409.
- **`POST /invoices/{key}/external-cancelled`**: the accounting system
  cancelled it – ledger `external_cancelled` once, `external.cancelled_at`,
  the PDF stays.
- Webhooks: `external_document` → `document`, `external_cancelled` →
  `reversed`.

## 0.24.0 – 2026-10-17

Additive, `api: 2` unchanged; capability `invoice_handover: 1`; migration
`Version1017Date20261017120000` (column `ts_tenants.invoicing_mode_json`).
Contract: `API.md` «Invoicing mode and hand-over, 0.24.0» with «As built».

- **Team setting `invoicing_mode`** (`PUT /team/settings`, `/admin/teams`):
  `null` (TimeSister issues, the default) or `{ mode: timesister|external,
  system, copy: off|auto|manual }` – TimeSister with a copy to an
  accounting system, or the hand-over. Checked per rule (422 with the
  field path), stored complete, shown in `/me`, `/team`, `/admin/teams` and
  the team ZIP.
- **`POST /invoices/{key}/handover`** (Team Admins, mode external): the
  accounting system issued the draft. One transaction: billing marks with
  the external number, the receipts billed, the record `status: external`
  with `external: { system, id, number, url, at, by }`, ledger
  `external_issued`; no number of the team's counter, no PDF.
- **`POST /invoices/{key}/handover/revoke`**: marks released, receipts
  approved again, the record a draft again, ledger `external_revoked`.
- Issuing in the mode external and reversing a handed-over invoice: 409.
  Clients may not set the status `external` (422) nor write or delete a
  handed-over invoice (409).
- `external` on the invoice record takes `number`; webhooks fire
  `exported` for both new ledger actions; bookings of handed-over invoices
  count per occurrence when issuing, handing over, reversing and revoking.
- A reversal or credit note no longer copies the original's `external`
  (the hand-over of the original is not the new document's).

## 0.23.1 – 2026-10-17

Additive, `api: 2` unchanged, no schema change. Contract: `API.md`
«External on the invoice record, 0.23.1» with «As built».

- **`external` on the invoice record** for the Mac app's plugins
  (`ts.invoices.setExternal`): `null` or `{ system, id, url, at, by }`,
  checked strictly (unknown keys 422) and stored complete (missing keys
  `null`, a number as id taken as text). Without invoicing on the server
  the Mac writes it into the record.
- **`POST /invoices/{key}/external`** takes any system name
  `^[a-z0-9][a-z0-9._-]{0,63}$` (`sevdesk`, `datev`, `ch.example.csv`;
  `bexio`, `lexware`, `other` stay) and writes `external` into the
  invoice record as well, in the same transaction as ledger `exported`
  and `fixed.external`; the answer carries the written `record` besides
  `fixed` and `ledger`.

## 0.23.0 – 2026-10-16 (beta)

API unchanged in form (`api: 2`); capability `expense_fixing: 1` replaces
`expense_reports`. Contract: `API.md` «Simpler receipt flow and fixed
expense reports, 0.23.0» with «As built». No schema change.

- **Assignment is approval:** a Team Admin's or the project Lead's write
  that completes a receipt (budget and cost position, file, amounts in
  the team's and the budget's currency, not marked «please check»)
  approves it in the same write – also the file upload; a general receipt
  the company paid becomes `internal` once complete, from anyone. What is
  missing comes back as `missing`. `approve` stays.
- **Amount and supplier first** (2026-10-17): no receipt is approved – by
  a write, the file upload or `approve` – without an amount above 0 (with
  `vat_lines` their sum) and a supplier; `missing` names `gross` and
  `supplier`, `approve` answers 422. Not for recurring receipts.
- **Expense reports fixed like invoices** (schema 2): `ready`, `fix`
  (number from `settings.expense_number`, default `SP-{year}-{no:4}`,
  frozen lines and sums, `report` on the receipts, ledger
  `expense_fixed`, one transaction), PDF with number, chain line and
  receipt copies in `Spesen/<person>/` (ledger `expense_document`, retry
  job on failure), `paid` (`expense_paid`), `reverse` (`expense_reversed`,
  receipts free again), `document`. Submitting and approving reports
  (0.22.0) are gone; old reports are ignored, not migrated.
- **Export of expenses** from the frozen lines of fixed and paid reports;
  `report` and DATEV Belegfeld 1 carry the number.
- The chain (verify, head file) covers the new actions; Leads no longer
  read expense reports.

## 0.22.0 – 2026-10-16 (beta)

API unchanged in form (`api: 2`); new capabilities `receipts: 1`,
`expense_reports: 1` and `document_tree: 1`. Plan: `PLAN-BELEGE.md` (B3,
B4, B4a, «Ablage in Nextcloud»), contract: `API.md` «Receipts, expense
reports and the document tree, 0.22.0» with «As built». No schema change.

- **Document tree:** the team setting `documents` is `{ root, chain_mail }`
  (default `TimeSister/<team>` in the backup owner's files); `invoices`,
  `quotes` and `per_year` are gone and ignored. One fixed scheme
  (`DocumentTree`): `Buchhaltung/<year>/{Offerten,Rechnungen,Belege,Spesen/<person>,Kette}`
  and `Projekte/<code> <name>`, names with date, number, counterpart,
  amount and project. Invoices, XRechnung files and the chain head go
  there; the admin page has one root field with a check.
- **Receipts** (record `receipt`): who paid, reimbursement and where it is
  booked with their defaults; the server owns status, file, invoice,
  report, approval; rights for members, Leads and Team Admins; the file
  (PDF, JPEG, PNG ≤ 20 MB) through `PUT/GET /receipts/{key}/file`;
  `approve` (the file is renamed once) and `reject`; trust receipts and
  recurring ones from an approved `expense_rule`, one per period (409
  `duplicate`).
- **Expense rules and reports:** `expense_rule` with approval
  (`/expense-rules/{key}/approve`); `expense_report` submitted by the
  person, sums per VAT rate, approved with a PDF in `Spesen/<person>/`,
  marked paid, `GET …/document`.
- **Invoicing from receipts:** `costs` lines with `receipts[]`, `markup`,
  and `expense_percent` now checked; receipts billed on issue and released
  on reversal in the same transaction; receipt copies (FPDI) after the
  hours appendix in the PDF, the ZUGFeRD PDF and the preview.
- **Quote documents** through `PUT/GET /quotes/{key}/document`, the
  **project dossier** `POST /projects/{key}/dossier` (ZIP with documents,
  receipts, hours and ledger lines), **export of expenses**
  `GET /exports/expenses` as CSV or DATEV (`datev.expense_account`, the
  person's `creditor_account`).
- The privacy notice names the module costs.
- `InvoicePage` now builds on FPDI (already a dependency since 0.20.0).
- **Member triage** (`API.md` «Member triage, 0.22.0»): new receipt field
  `cost_kind` (`expense`, `third_party`); a project receipt may lack
  budget and cost position while open; a member's write keeps the stored
  budget, cost position and invoice effect; Leads read and write the
  receipts of their projects as soon as the project is set; approval needs
  budget and cost position of the receipt's kind (422).
- **File names while open:** the receipt's file follows every change of
  its name until approval; no amount part while gross is 0.
- **Export of expenses:** the parameter is `kind=csv|datev` (was
  `format`, which OCS reserves).
- **Recognition fields** (`API.md` «Recognition fields, 0.22.0»): optional
  receipt fields `review` and `review_reasons` (the mark «please check»,
  cleared by approval or by Team Admins, the project's Leads and who
  recorded it), `recognized_by`, `qr_reference` and `vat_lines` for
  receipts with several VAT rates; such a receipt stands on one invoice
  line per rate, expense report sums count it per rate. The recognition
  runs in the Mac app, never on the server.
- **Another currency:** receipt field `booked: { gross, currency }`, the
  amount paid in the team's currency; required for approving a receipt in
  another currency (422); expense reports and the export of expenses count
  it.
- **Receipts against a budget in another currency** (`API.md` «Receipts
  against a budget in another currency, 0.22.0»): for the budget a receipt
  counts with its own amounts when its currency is the budget's, with
  `booked` when the budget is in the team's currency, else with the new
  field `billed: { gross, currency }` in the budget's currency – needed
  only then (else stored as null), required for approval (422 “Enter the
  amount in EUR for the budget.”) and correctable by Leads and Team Admins
  until billed. Expenses stay with `booked`. One place decides:
  `ReceiptBooking::forExpenses()` / `forBudget()`.
- **Team currency:** `settings.currency` is null or three capital letters
  (422); the team's currency no longer falls back to the issuer's first
  account, only to CHF.
- **Document language:** `settings.language` is null, `de` or `en` when
  written (422 otherwise); invoice, preview (watermark VORABZUG/DRAFT),
  expense report and e-invoice texts follow it, unset the server's
  default language. The Mac app sets it beside the team currency.

## 0.21.0 – 2026-10-16 (beta)

API unchanged in form (`api: 2`); new capabilities `vat_lines: 1` and
`costs: 1`. Plan: `PLAN-BELEGE.md` (B1, B2), contract: `API.md` «Cost
positions and VAT per line, 0.21.0».

- **VAT per invoice line:** positions take `vat` (a rate of their own),
  `vat_mode` (`taxable` or `pass_through` – a disbursement outside the
  taxable amount) and `cost` (the budget's cost position); new kinds
  `expense_percent`, `expense_flat`, `costs`. One class (`VatLines`)
  computes the sums for issuing, preview, reversal, documents and exports:
  net and VAT per rate, gross, the pass-through items and the total to
  pay. Frozen as `vat_lines`, `pass_through`, `total` (in `fixed`, the
  `issue` answer and the ledger payload); the record's `amount` is now
  the total; the frozen positions carry their rate and mode.
- **Documents:** the PDF shows VAT per rate, gross, a block of
  pass-through items and the total; the QR bill asks for the total.
  ZUGFeRD/XRechnung carry one tax group per rate and category and the
  pass-through items as category `E` with `VATEX-EU-79-C` (`O` is refused
  next to other categories, BR-O-11); checked with KoSIT.
- **Exports:** the CSV appends `vat_lines`, `vat_by_rate`,
  `pass_through`, `total`; DATEV books one row per rate and one for the
  pass-through items against the new optional account
  `datev.revenue_pass_through`.
- **Cost positions:** `budgets[].costs[]` of projects and quotes
  (`CostRules`): number, name, kind (expense, third-party), rule
  (percentage, flat, receipts), cap, mode (re-billed, pass-through),
  markup, own rate, description – checked and stored, 422 naming the
  field. The module switch `settings.modules.costs` (and the other
  switches) must be booleans.
- Webhook bodies carry `total`.
- Tables: `ts_inv_invoices` + `vat_lines_json`, `pass_through`, `total`
  (additive). Invoices issued before keep their single rate and read as
  one VAT line.

## 0.20.0 – 2026-10-15 (beta)

API unchanged in form (`api: 2`); new capabilities `e_invoice: 1` and
`invoice_export: 1`. Plan: `PLAN-FAKTURA.md` (F3, F4), contract: `API.md`
«E-invoices and exports, 0.20.0». New runtime library `horstoeko/zugferd`
(MIT) with `jms/serializer`, `setasign/fpdi`, `setasign/fpdf` (MIT).

- **Tax case:** `domestic`, `eu_reverse` or `export` from the countries of
  issuer and customer and the customer's VAT id (`TaxRules`); the draft
  may override it with `tax_case`. Frozen into `customer_json.tax_case`;
  abroad the invoice carries VAT 0 and the note (reverse charge, export)
  under the sums. `fixed.tax_case`.
- **ZUGFeRD:** the stored PDF is now PDF/A-3 with `factur-x.xml` (CII,
  profile EN 16931) inside; the chain line stays the PDF's subject and is
  a note in the XML. Ledger `document` entries carry `kind: "zugferd"`
  (before: `pdf`). The preview stays a plain PDF.
- **XRechnung:** customers with `e_invoice: true` or a `leitweg_id` get
  `<number>.xml` (profile XRechnung 3.0, Leitweg-ID as buyer reference)
  next to the PDF; `GET /invoices/{key}/document?kind=xml`; ledger
  `document` `kind: "xrechnung"`; `fixed.xml_path`, `xml_sha256`. The
  customer record takes `e_invoice`, `leitweg_id` and `datev_account`.
- **QR reference:** with a QR-IBAN the bill carries the 27-digit QRR
  (`issuer.besr_id` + counter + year + check digit); `fixed.qr_reference`
  and `counter_no`; the reference is in the issue payload. A QR-IBAN
  renders the payment part now (0.19.0 left it out).
- **Exports** (Team Admins): `GET /invoices/export?kind=csv|datev|ledger&from&to`
  – the invoices of a period as CSV (UTF-8 BOM, `;`), as DATEV
  Buchungsstapel (EXTF 700, Buchungsstapel 12) with the team setting
  `datev`, the ledger as CSV. `POST /invoices/{key}/external` records a
  hand-over to Bexio, Lexware or another system (ledger `exported`,
  `fixed.external`).
- **Webhooks:** team setting `webhooks` (≤ 10, https, events, secret);
  every ledger entry of a listed event queues a delivery
  (`ts_inv_webhook_queue`); the job `WebhookDelivery` (5 min) posts with
  `X-TimeSister-Signature`, up to ten tries; `GET /invoices/webhooks/status`.
- Tables: `ts_inv_invoices` + `counter_no`, `qr_reference`, `xml_path`,
  `xml_sha256`, `external_json`; `ts_tenants` + `datev_json`,
  `webhooks_json`; new `ts_inv_webhook_queue` (additive). Release archive
  about 3.9 MB.

## 0.19.0 – 2026-10-15 (beta)

API unchanged in form (`api: 2`); new capability `invoice_documents: 1`.
Plan: `PLAN-FAKTURA.md` (F2), contract: `API.md` «Invoice documents,
0.19.0». First version with runtime libraries: `tecnickcom/tcpdf`
(LGPL-3.0-or-later) and `sprain/swiss-qr-bill` (MIT) in `vendor/`.

- **The server renders the PDF of an issued invoice** (A4 portrait: issuer,
  customer, title, facts, greeting, positions, net/VAT/gross, body, the
  hours appendix without notes, the footer with the chain line on every
  page, the Swiss QR bill as the last page when the issuer has an IBAN in
  the invoice's currency) after `issue` and `reverse`, stores it as
  `<number>.pdf` in the team's invoices folder (`/<year>/` when
  `per_year`) in the backup owner's files, writes the ledger action
  `document` with path, size and SHA-256, and keeps `document_state`
  (`done`/`failed`, with `document_error`, `document_path`,
  `document_sha256` in `fixed`). The job `DocumentRetry` (15 min) renders
  `pending`/`failed` documents again, up to ten tries.
- **Preview:** `GET /invoices/{key}/preview` – the draft as it would be
  issued, as `application/pdf` (inline) with the watermark VORABZUG/DRAFT
  across every page; never stored, never in the ledger. `GET
  /invoices/{key}/document?kind=pdf` downloads the stored file, `POST
  /invoices/{key}/render` renders again, `GET /invoices/chain/head` gives
  the chain head.
- **Team setting `documents`** (`invoices`, `quotes`, `per_year`,
  `chain_mail`; `PUT /team/settings`, `/admin/teams`, shown in `/me` and
  `/team`): the folders are checked on writing (backup owner set, folder
  there or creatable, writable – `422 invalid` otherwise). The admin page
  shows them per team with a check button
  (`POST /admin/teams/{id}/documents/check`), the per-year switch, the
  chain mail address and the backup owner.
- **Chain head file:** the daily job `ChainHead` writes
  `chain-<YYYY-MM-DD>.txt` (team, date, entries, head seq, head hash) into
  the invoices folder and mails it to `documents.chain_mail` when set.
- Tables: `ts_inv_invoices` + `document_error`, `document_path`,
  `document_sha256`, `document_tries`; `ts_tenants` + `documents_json`
  (additive). The release workflow runs `composer install --no-dev`;
  `.nextcloudignore` keeps tests, docs, examples, unused fonts and unused
  Intl data of the libraries out of the archive (about 3.3 MB).

## 0.18.0 – 2026-10-15 (beta)

API unchanged in form (`api: 2`); new capability `invoicing: 1`. Plan:
`PLAN-FAKTURA.md`, contract: `API.md` «Invoicing, 0.18.0».

- **Invoices are issued on the server.** `POST /invoices/{key}/issue`
  takes the number from the team's counter and pattern, freezes issuer,
  customer, positions and texts, computes net, VAT and gross, writes the
  hours and the billing marks and appends the ledger entry – in one
  transaction. The record becomes `issued` with `number`, `due_on`,
  `ledger_seq` and `hash`; from then on every record write answers
  `409 issued`.
- **Reversal and credit note** (`POST /invoices/{key}/reverse`): a new
  invoice with negated positions and its own number; a reversal cancels
  the original and releases its hours. **Paid/unpaid**
  (`POST /invoices/{key}/paid`, `/unpaid`) with a ledger entry each.
- **Reading:** `GET /invoices/{key}/fixed` (Team Admins, the project's
  Leads), `GET /invoices/ledger`, `GET /invoices/chain/verify` recomputes
  the SHA-256 chain and names the first break.
- **Team setting `invoicing`** (default on; `PUT /team/settings`,
  `/admin/teams`): off, the endpoints answer 404 and clients issue as in
  0.16.0.
- Draft additions: `greeting`, `body`, `footer`, `positions`,
  `bookings[].hours/rate/code`; the record's `amount` may be negative.
- Tables `ts_inv_invoices`, `ts_inv_hours`, `ts_inv_ledger` (append-only
  mapper), `ts_tenants.invoicing`; the team ZIP carries `ledger.json` and
  `invoices.json` for reading.

## 0.17.0 – 2026-10-15 (beta)

API unchanged in form (`api: 2`); new capability `entries: 1`.

- **Naming by convention.** Members who have not named a time calendar get `zeit-<uid>` as soon as it exists (before each run of the reader job).
- **Immediate read.** `PUT /me/time-calendar` reads a newly named
  calendar at once when the CalDAV backend is there and it has at most
  3000 objects; the answer adds `read_now` and `count`. Larger ones wait
  for the job.
- **Members who left.** Their named calendar is neither read nor
  followed by the listener (`last_error` «member left», `left: true` in
  `GET /entries/status`); the entries stay in the book and are delivered
  as before. Back in the team, the next run reads it in full.
- **Team ZIP.** `team.json` carries `settings.entries_since` and the
  named calendars (`time_calendars`); the import applies the cutoff
  (`replace` always, `merge` only when none is stored) and names
  `calendar_uri` or `zeit-<uid>` of the mapped account, one row failing
  reported, not aborting. Preview and answer show both. The book itself
  is not in the ZIP; older ZIPs import as before.
- A feed that answers something other than an iCalendar file counts as
  failing (`last_error`), its entries stay; `GET /entries` with `since`
  beyond the current revision answers empty.
- Fixed: a moved cutoff now reaches objects whose etag did not change –
  the full read by etag skipped them, so entries before the new cutoff
  stayed in the book.

- **The app holds the team's time entries.** Every member names their
  time calendar once (`PUT`/`DELETE /me/time-calendar`); the server
  stores its calendar objects as received – one row per object, with the
  `#code`s it found for the role filter – and reads the `.ics` feeds of
  external persons itself. `GET /entries?since=&limit=` delivers them in
  revision order: Team Admins everything, Leads their own and the entries
  of their projects, Users their own. A vanished object becomes a
  tombstone, or stays as `kept` when a `billing` record exists.
- **Reading.** A listener on the calendar events stores the one object
  that was written; the job `EntryReader` (every 5 minutes) does the full
  reads – marked, never read or a day old – and the feeds (`If-None-Match`,
  http(s) only, 20 MB, 20 s). Errors stay on the source and show in
  `GET /status` (`time_calendar`, `entries_read_at`, `entries_error`) and
  `GET /entries/status`; `POST /entries/reread` marks every source.
- **Cutoff per team.** `entries_since` (`PUT /team/settings`, also
  `settings` of `/admin/teams`): entries ending before it are not read;
  default two years before the team was created. `GET /me` adds
  `time_calendar`, `entries_revision` and the effective `entries_since`.
- Tables `ts_time_calendars`, `ts_entries`; `ts_tenants` gets
  `entries_revision` and `entries_since`. Additive migration.
- **Shares matrix removed.** With the entries on the server nobody reads
  another person's calendar: `/team/access` (all four routes) and
  `/me/calendar-share` are gone (404); `/me` loses `share_targets`,
  `may_override`, `calendar_share`, `admins` and `leads`; `POST /status`
  ignores `calendar_shared` and `applied_shares`; `may_override` is no
  member field, `leads_see_calendars` no team setting (unknown key, 422);
  the notification `shares_changed` and the matrix in the privacy notice
  are gone; the team ZIP carries no `access`, `may_override` or
  `calendar_shared` (older ZIPs import, the parts are ignored). The admin
  overview counts `without_time_calendar` instead of `calendar_not_shared`.
  Table `ts_access` and the columns stay unused (no migration).

## 0.16.0 – 2026-10-12 (beta)

API unchanged in form (`api: 2`); new capabilities `quotes: 2`,
`cost_rates: 1`, `invoices: 1` and `invoice_template: 1`.

- **Quotes as projects.** The project record's `status` takes `draft`,
  `quoted` and `declined` before `active` (in that order; `active`,
  `running`, `paused`, `completed`, `archived` as before). A quote keeps
  its Leads – they plan with it; `declined` does not end the Lead role,
  only `completed` and `archived` do. Unknown status: `422`.
- **Team of a project, with roles.** Optional `team`: a list of
  `{ "user": uid, "role": role }` – the accounts assigned to the quote or
  project, at most 100, every one an account of the team (`422` naming
  the unknown ones; one who left the team stays allowed, an old record
  keeps them). Roles: `member` (the default for a missing or `null`
  role), `sparring`, `support`, `qa`; any other role: `422`. A plain
  string entry is read as `member` and stored as an object; other fields
  of an entry are dropped; a user twice: the first entry wins.
- **No code while a quote.** The rule of 0.12.0 (exactly one code per
  project) applies from `active` on – also to a record with no status.
  In `draft`, `quoted` and `declined` `codes` may be empty or missing
  (as may `subprojects`); more than one code is refused everywhere. A
  project set to `active` without a code: `422` “An active project
  needs a code.”
- **`from_quote`.** Optional boolean on the project record: the project
  began as a quote. It keeps it in every status, also `active`. Only the
  shape is checked (`true`, `false` or `null`; otherwise `422`); no rights
  hang on it.
- **Rates in the budgets.** Per budget optional `currency` (three
  letters, stored upper case), `cap`, the cost cap (number ≥ 0), and
  `invoice_dates`, the planned invoice dates (days, each once, ≤ 200); per
  work package optional `rate` (number ≥ 0). Both nullable; missing means nothing. Wrong shape: `422`. A
  `rates` object per budget (account → hourly rate) is **not** part of
  the record: with content it is refused (`422` “rates” is no longer
  part of a budget …); `null` or `{}` – a client's empty default –
  passes.
- **Members do not see rates.** Unchanged in mechanism: whoever neither
  manages the team nor leads the project reads only the booking catalog
  (no `budgets`, no `team`) – single record, list, delta and history
  alike. Team Admins and the project's Leads read everything and write
  as before.
- **Cost rates on the person.** Optional `cost_rates` on the person
  record: a list of `{ "from": "YYYY-MM-DD", "rate": number ≥ 0 }`,
  valid from the day on like `workload`, each day once; `null` or `[]`
  mean nothing. Wrong shape or a day twice: `422`. Personnel cost, so
  **Team Admins alone read it**: the person's own record, a Lead's name
  card, the list, the delta and the history come without it. Written by
  Team Admins only, as every person record. Capability `cost_rates: 1`.
- **Invoices.** New kind `invoice`, key chosen by the client (e.g.
  `inv-1a2b3c4d`): a draft of billable hours sent to accounting –
  `project` and `budget` (both required – every invoice belongs to a
  budget), `from` ≤ `to` (days), `status`
  (`draft`, `issued`, `paid`, `cancelled`), `number` (`null` or 1–64
  characters), `bookings` (at most 5000 `{ person, uid, start }`, `start`
  a day or a local time `YYYY-MM-DDTHH:MM:SS`, each triple once), `note`
  (≤ 2000), `created_by`, `created_on`, `issued_on`, `paid_on`, and as
  issued `hours`, `amount` (numbers ≥ 0) and `currency` (three letters),
  and `vat`, the VAT rate of the invoice in percent (0–100).
  Other fields pass, as for projects. At most 768 KB (`413`); wrong shape
  `422`.
- **Who reads and writes invoices.** Team Admins everything. A Lead reads
  the invoices of the active projects they lead (`/me.lead_of`) – list,
  delta, single record, history, tombstone – and creates, changes,
  deletes and restores only drafts without a number of these projects,
  before and after the write; issuing, paying, cancelling and the number
  stay with Team Admins (`403`). Members see none (`403`, not in the
  delta). Checked against the stored state inside the write's
  transaction; a Lead's batch may hold invoices. A deleted invoice keeps
  its project on the server, so the Leads get the tombstone. A project
  write that changes its leads delivers its invoices again.
- **Quotes as records.** New kind `quote` (capability `quotes: 2`; the
  project statuses `draft`, `quoted`, `declined`, `from_quote` and `team`
  of `quotes: 1` stay): a quote no longer creates a project, it holds a
  budget before acceptance and may be assigned to an existing project.
  Key `q-` and 8 lowercase hex digits (`422` otherwise). `name` (1–200)
  and `status` (`draft`, `quoted`, `accepted`, `declined`) required;
  `customer` and `project` `null` or a key – `project` required once
  `accepted`; `number` (1–64), the days `date`, `valid_until`,
  `accepted_on`, `created_on`, `leads` (at most 20 accounts or person
  keys), `contact` (≤ 200), `note` (≤ 2000), `pdf` (`null` or an
  `http(s)://` address ≤ 2000), `budget` (`null` or an object with a text
  `id`, checked as a project's budget: currency upper case, `cap` and
  work package `rate` ≥ 0, no `rates`), `budget_id`, and as accepted
  `hours`, `amount` (numbers ≥ 0) and `currency` (three letters).
  Other fields pass. At most 768 KB (`413`); wrong shape `422`.
  Accepting is two ordinary writes: the budget into the project, the
  quote `accepted`.
- **Who reads and writes quotes.** Team Admins everything. A Lead
  (`/me.lead_of` not empty) reads the quotes whose `leads` name them – the
  account or a person with it in `accounts`, as for a project's `leads` –
  or whose `project` they lead, as single record, list, delta, history
  and tombstone, and writes only these, before and after the write
  (`403` otherwise). Members see none, also when named. A Lead's batch may
  hold quotes. A deleted quote keeps its data on the server for the rule;
  a project write that changes its leads delivers again its quotes and
  those naming its old or new leads.
- **Invoice number pattern.** `setting/settings` takes `invoice_number:
  { pattern, per_year, start }`: `pattern` 1–64 characters with exactly
  one of `{no}`, `{no:N}`, `{nr}`, `{nr:N}` (N 1–8) besides `{year}`,
  `{jahr}`, `{yy}`, `{jj}`, `{project}`, `{projekt}`, `{customer}`,
  `{kunde}`; any other placeholder or a stray brace `422`. `per_year`
  boolean (default `true`), `start` integer ≥ 1 (default 1).
- **Invoice issuer.** `setting/settings` takes `issuer`: name, address,
  contact, `vat_id`, `foreign_note`, `qr` and `accounts` (one per currency,
  IBAN and BIC) – for the sender block, the note on invoices abroad and the
  payment part (Swiss QR for CHF, GiroCode for EUR, made by the client).
  Wrong shape `422`. A customer may carry `vat_id`.
- **`POST /invoices/next-number`** `{ "year": 2026 }` takes the next
  number of the team's counter – per year or one across years –
  atomically (two Macs never get the same), never below `start` (raised
  later: continues there): `{ "number": 12, "year": 2026 }`. `GET
  /invoices/next-number?year=2026` shows it without taking it. Team Admins
  only (`403`); a year outside 1900–9999 `422`; missing: this year (UTC).
- **Invoice template.** One Word file (`.docx`) per team, the source for
  every Mac of the team: `GET /templates/invoice/info` (`{ name, size,
  sha256, modified_at, modified_by }`), `GET /templates/invoice` (the same
  plus `base64`), `PUT /templates/invoice` `{ name, base64 }` (replaces
  it, answers the info) and `DELETE /templates/invoice` (`{ deleted }`).
  None stored: `404 not_found`. Team Admins only, reading too (`403`).
  Checked: `name` 1–200 characters ending in `.docx`, strict Base64, at
  most 10 MB (`413` “A template may be at most 10 MB.”, also for a body
  over 14 MB), a ZIP (`PK\x03\x04`) with `word/document.xml` (`422`).
  Stored in the app data (`templates/t<team>/invoice.docx` and
  `invoice.json`), no migration. The team ZIP carries it
  (`templates/invoice.docx`, `templates/invoice.json`; preview and import
  answer `template`; merge takes it when younger, replace when it
  differs, a ZIP without one leaves the team's). A template counts as a
  team's content (a ZIP is kept before importing or deleting); deleting
  the team removes it. Capability `invoice_template: 1`.
- **Migration 1011:** new table `ts_counters` (team, `invoice:2026` or
  `invoice`, last number). Additive only. Deleting a team removes its
  counters (`counts.counters`); the team ZIP does not carry them – after
  moving a team, raise `start`. Team ZIP account mapping also maps
  `created_by`.
- Translations: an orphaned Job message dropped from `de`/`de_DE`; the
  invoice, invoice template and quote messages added.

## 0.15.0 – 2026-10-11 (beta)

API unchanged in form (`api: 2`); the capability's `jobs` rises to 5.

- **Reopen a Job.** `POST /jobs/{key}/reopen` takes a Done Job back to In
  Progress – by the person who holds it or whoever manages it (Team Admin,
  Lead of the project, sender). Not once paid, not when the hours are
  booked in full (the next report would finish it again). The other side
  is told (`job_reopened`); overlap is checked as on accepting.
- **Members take Jobs themselves.** `PUT /jobs/{key}` with `recipients`
  exactly the caller is open to every member of the team, on any project;
  as before it is In Progress at once and nobody is notified. The sender
  may change such a Job as long as it stays theirs (`recipients` other than
  themselves: `403`) and delete it. Offering to others stays with Team
  Admins and the project's Leads.
- **Done by whoever manages.** `POST /jobs/{key}/done` is open to Team
  Admins, the project's Leads and the sender at any time – also on a Job a
  member took themselves; the assignee is told (`job_done`). Already Done:
  the unchanged record for everyone who may call it; not In Progress:
  `409`.

## 0.14.1 – 2026-10-08 (beta)

API unchanged (`api: 2`), narrower reads only.

- **Market offers for Members.** Whoever does not manage a Job (Team Admin, Lead of its project, sender) reads `recipients` and `declined_by` trimmed to themselves, `names` only for themselves and the sender, and the offer entry in the log only `to` themselves. A Member learns neither who else was offered the Job nor who declined; a Job offered to several carries `market: true` instead.

## 0.14.0 – 2026-10-07 (beta)

API unchanged in form (`api: 2`), only additions and narrower rights; the capability drops `lead_reach` and gains `lead_scope: 1`, `booking_reports: 1` and `job_fits: 1`.

- **Catalog flag `led`.** The booking catalog carries `led: true` when a project has a Lead, so every client knows which bookings to report.
- **Visibility per role.** Members see themselves, Leads themselves and the data of the projects they lead, Team Admins everything – enforced in what the server hands out. Replaces the Lead reach of 0.12.0.
- **Shares matrix: only Team Admins' rows.** A row whose viewer is a Lead or User is `none` whatever is stored; writing `view` or `edit` there is `422 invalid`. The owner's own entry may only lower a Team Admin's level (above it: `422`), so `overridden` is never `granted` any more. `GET /team/access` keeps `reason`, always `null`; `projects` is gone. External columns likewise: only Team Admins see a feed. `/me.share_targets` names Team Admins only; the owners' clients withdraw the other shares at their next sync.
- **Person records of others** reach Leads as a name card: `schema`, `login`, `first_name`, `last_name`, `initials`, `accounts` – in the delta and single reads. Users still read only their own person; the full record (with `feed`) only Team Admins and the person.
- **Customers for Leads:** only those named in `customer` of a project they lead – list, delta and single record (`403` otherwise).
- **Booking reports.** New kind `booking-report`, key `{uid}+{project}+{YYYY-MM}`: an account reports its bookings on a project for one month (date, time, minutes, code, title, notes, billable, billed). Only the account writes it; it, Team Admins and the current Leads of the active project read it. Only the documented fields; at most 2000 bookings and 768 KB.
- **Billable marks.** New kind `billable`, key `{uid}+{project}+{32 hex digits of the booking ID's SHA-256}`, data `{ person, project, uid, billable }`: written by Team Admins and the project's current Leads, read by them and the person, whose client applies it to its event.
- A batch of someone who is no Team Admin may hold billing marks, booking reports and billable marks only; each write is checked on its own.
- **Billing marks** (`billing`) are written by Team Admins only; Leads no longer read or write them (they see `billed` in the booking reports).
- **Delivered again:** a project write that changes `leads`, `customer` or the active state gives the customers it names and its booking reports and billable marks a new revision, so whoever may now read them finds them in the next delta.
- **`GET /jobs/weeks`:** Leads read only their own numbers. `GET /jobs/{key}/capacity` and the `422` with `rule: capacity` give Leads the weeks and days without hours.
- **`POST /jobs/fits`** (Team Admins and Leads): `{ hours, start, end, recipients, job? }` → `{ fits: { uid: true|false|null } }`, the capacity check of `accept-counter` per recipient; `null` without reported numbers; no numbers in the answer.
- **Privacy notice:** Leads see the bookings on their projects (with title and notes) and names and initials of the people – no time calendars, master data, balances or vacation of others; Users only their own data; new data category “Booking reports”.
- **Migration 1010:** every stored level in `ts_access` whose viewer is no Team Admin of the team becomes `none`, logged per team; nothing is deleted. A team whose Team Admins cannot be read is left as is (the server reads those rows as `none` anyway).
- `POST /status` still takes `projects`; no right follows from it any more.

## 0.13.0 – 2026-10-07 (beta)

API unchanged (`api: 2`), only additions.

- **Never billable.** A project record may carry `never_billable: true`; bookings on it never count as open for billing (vacation, absences, internal work). The field is part of the booking catalog, so users' clients see it too.

## 0.12.0 – 2026-10-06 (beta)

API unchanged (`api: 2`), only additions; the capability gains `lead_reach: 1`.

- **One project, one code.** A project record with more than one entry in `codes` is refused with `422 invalid`; parts belong in `subprojects` (`D200.1`, `D200.2`). Clients read only the code the parts belong to; bookings on former extra codes show up as suggestions.

- **Leads reach the people on their projects.** User's decision: Leads are like small Team Admins. In the shares matrix, the default of a Lead's row is `edit` for every active member who contributes to an active project the Lead leads; everything else stays as before (Team Admins `edit`, the rest `none`). A Team Admin's entry replaces the default, the owner's own entry wins while `may_override` is on. A person contributes when their client reports the project in `POST /status` (`projects`) or when they are a recipient (not declined) or the assignee of a Job in it. The reach is read once per request (`LeadReach`); `/me.share_targets`, `GET /team/access`, the withdrawal reminders, billing marks, weekly numbers and the privacy notice follow it.
- **`POST /status`** takes `projects`: the project keys the person booked on in the last 12 months (at most 500, each 1–64 bytes without control characters, otherwise `400 invalid`); duplicates removed, sorted, stored with the time. Missing keeps the last report, `null` clears it.
- **`GET /team/access`:** every member field carries `reason` – `contributes` when the Team Admin level is this default, then also `projects` (the shared project keys); otherwise `null`.
- **Privacy notice:** the Leads' line says they see and edit the time calendars of the people who book on their projects.
- **Migration 1009:** `ts_client_status` gains `projects` (JSON) and `projects_at`. Additive only.

## 0.11.0 – 2026-10-06 (beta)

API unchanged (`api: 2`), only additions; the capability gains `derived_lead: 1`.

- **The role Lead is derived.** Set roles are only User and Team Admin. Whoever is in `leads` of at least one active project (status not `completed`/`archived`, nor the old `abgeschlossen`/`archiviert`) is Lead; project ended or lead removed, the rights fall away. One derivation (`MembershipResolver::roleOf`, fed by the new `LeadResolver`) serves `/me`, `/team`, `/team/access`, `/status`, the privacy notice, Jobs, billing marks and the admin page; read once per request, no new table. A lead key names a person record (key or an account in `accounts`) or an account; a person without an account leads nothing.
- **`/me`** names the derived `role` and new `lead_of: [{ kind: "project", key, code, name }]`; **`/team`** and the admin page carry `lead_of` (count) and `lead_projects` (names) per member.
- **`PUT /team/members/{uid}`** and **`PUT /admin/teams/{id}/members/{uid}`**: `role: "lead"` is `422` with “Lead follows from the project leads and cannot be set.”
- **Leads read customers** – list, delta and single record, contact persons included; writing stays Team Admin.
- **Admin page:** the role choice offers only User and Team Admin; a derived Lead shows “Lead · 2 projects” with the project names as a tooltip, in the team list and under Members.
- **Migration 1008:** every stored Lead in `ts_members` becomes User (stored as empty) and is logged per team. On the test server 8081 before the migration: one stored Lead in each of the teams muster (bruno), pb (pblead), at (atlead), kleinbasel (kb-lhuber), ds (ds-jan), zzk (zzneu) – six in all; pblead keeps Lead through four active projects, the others only if they lead one.
- Team ZIPs keep `role` per member as information; an import no longer stores it.

## 0.10.2 – 2026-10-05 (beta)

API unchanged (`api: 2`), only additions; the capability gains `privacy_notice: 1`.

- **Privacy notice per team.** `GET /team/privacy?format=html|md` gives every member a privacy notice of their team as a download (`timesister-privacy-<slug>-<date>.html|md`), in the language of the account; the Nextcloud admin gets it for any team with `GET /admin/teams/{id}/privacy`. The content is read live from the team: members with role, Team Admins, Leads with their projects, the shares matrix (who sees or edits whose time calendar), backup owner, consents, modules switched on, absence categories, vacation calendar, externals (count only, never a feed address). Nine sections: controller and contact, purpose, data and categories, where the data is stored, who has access, backups, retention and deletion with the legal references as a template (ArG 46, ArGV 1 Art. 73, OR 958f, DSG 6/19/25/32, GDPR 5(1)(e)/6(1)(b,c)/13/15/17(3)(b)/30/32), rights of the persons, and the note that it is a template from the team's settings and no legal advice. Empty fields read “[to be completed]”. The HTML is printable and loads nothing from outside; everything foreign is escaped.
- **Record `setting/privacy`.** Team Admins keep the entries like `setting/settings`: `controller {name, address, contact}`, `privacy_contact`, `hosting {provider, location}`, `retention {time_years (5), billing_years (10), note}`, `law` (`ch`, `eu`, `both`; default `both`), `authority`. Texts at most 500 characters, years 0–30, otherwise `422`. The Nextcloud admin writes it from the admin page through `PUT /admin/teams/{id}/privacy` with `{ data }`.
- **Admin page:** “Edit team” gains the section “Privacy” with these fields and the buttons “Privacy notice (HTML)” and “(Markdown)”.
- **New team from a team ZIP.** “New team” takes a team ZIP as a second way: `POST /admin/teams/import/preview` (without a team) checks the file and names name, short name and the people of the backup; the admin chooses the team group, may change name and short name and maps the people to accounts here; `POST /admin/teams/from-zip` with `{ token, name, slug, group, mapping }` creates the team and imports into it (`merge`). A taken short name or group is `409` before anything is created; fails the import, the empty team is removed again and the message says so.

## 0.10.1 – 2026-10-05 (beta)

API unchanged (`api: 2`).

- **Delete a team with content.** `DELETE /admin/teams/{id}` now takes `{ "confirm": "<team name>" }` (typed name, trimmed, case does not matter; otherwise `422 confirm`) and deletes any team. A team with content is stored first as a team ZIP, exactly as before an import: protected under `exports/t<id>/` in the app data and visible with the backup owner under `TimeSister Backups/_team/`; if the protected copy cannot be written, `409 conflict` and nothing is deleted. Then records with history, status, absences, members, shares, consents, the group mapping and the server backups go in one transaction, and the protected calendar backups in the app data are removed. The Nextcloud group, the accounts, their time calendars and the visible `.ics` files with the backup owner stay. The answer names the ZIP paths and the counts per table.
- **Admin page:** the trash icon opens a small form instead of a browser dialog: type the team name, “Delete team” becomes active only when it matches; the result shows the ZIP path and the counts.

## 0.10.0 – 2026-10-05 (beta)

API unchanged (`api: 2`), only additions; the capability gains `team_backup: 1`.

- **Team backup as a ZIP.** On the admin page, every team has “Back up team (ZIP)” (`GET /admin/teams/{id}/export`): `manifest.json` with checksums, `team.json` (team, members, shares, consents), every record with its history under `records/<kind>/<key>.json`, `status.json`, `absences.json` and a fresh export of every active member's time calendar under `calendars/`. Person records carry their `feed`, so the ZIP is confidential (`contains_secrets: true`).
- **Restore and move.** “Restore from ZIP” uploads the ZIP for a preview (`POST /admin/teams/{id}/import/preview`): the people of the backup, whether their account exists here, and the accounts of this Nextcloud to map to. `POST /admin/teams/{id}/import` with `token`, `mapping` (old account → new account or `null` for “without account”) and `mode` `merge` or `replace` writes records with history, roles, shares, consents, absences and status in one transaction, puts mapped accounts into the team group and stores the calendars as backups per person (`source: "import"`), which people fetch in the Mac app under “Old events”. Before importing into a team with content, the server stores a backup of it (protected and with the backup owner under `TimeSister Backups/_team/`).
- Limits: ZIP 200 MB, entries 50 MB, 600 MB unpacked, no absolute paths or `..`; only Nextcloud admins, write routes with brute-force protection.

## 0.9.1 – 2026-10-05 (beta)

Three findings of the security review; API unchanged (`api: 2`).

- **The vacation calendar stays in the team.** `vacation_calendar` in the settings record is accepted only when `owner` is a Team Admin of the team and `url` is a calendar of that owner (`…/remote.php/dav/calendars/<owner>/<calendar>/`), otherwise `422 invalid` – on `PUT`, in a batch and on restore. The background job checks again (owner still a member, URL still theirs) and skips the team with a warning in the log; before, a Team Admin could point the mirror at any account's calendar.
- **`PUT /me/absences`:** control characters in `source_uid`, `kind`, `start` or `end` are refused with `400 invalid`; in the written events a CR is escaped like a line break and other control characters are dropped. Before, a CR in `source_uid` made the job rewrite the event on every run.
- **Brute-force protection:** every writing route (`PUT`, `POST`, `DELETE`) carries `#[BruteForceProtection(action: 'timesister')]`, and a refused write (`403`) counts for Nextcloud's throttling.

## 0.9.0 – 2026-10-04 (beta)

- API unchanged in form (`api: 2`), only additions; the capability gains `absences: 1`.
- **The shared vacation calendar.** A Team Admin creates the calendar «TimeSister – Vacation» in their own account from the Mac app and shares it: the team group read-only, every Team Admin with write access. The settings record (`setting/settings`) names it: `vacation_calendar: {url, owner}`; `absence_calendar_kinds` says which categories appear (`vacation`, `sickness`, `parental`, `civil_service`, `unpaid`; default `["vacation"]`).
- **`PUT /me/absences`:** every client reports the complete state of its own absences for this and next year – `items: [{source_uid, kind, start, end}]`, `end` exclusive, at most 400. The server replaces the person's rows (table `ts_absences`). A person with `vacation_calendar_optout: true` in their person record is stored nowhere, and what was there goes.
- **Written by the server:** a background job (every 15 minutes) puts the absences of every team with a calendar into the owner's calendar as all-day events «<Category> · Initials Name» with `X-TIMESISTER-PERSON`, `X-TIMESISTER-QUELLE` and `X-TIMESISTER-ART`, over the public calendar API (`ICreateFromString`, no Sabre). Absences that are gone are written again with `STATUS:CANCELLED` – the API cannot delete; a Team Admin's Mac tidies them up. Events without the marks are never touched.
- Migration: table `ts_absences`.

## 0.8.3 – 2026-10-02 (beta)

- Admin settings, new team: the short name is suggested from the name (lowercase, umlauts spelled out, hyphens), typing turns capitals and spaces into the allowed form, and a hint names the rule (lowercase letters, digits, hyphens, 2 to 32 characters). Before, the form only said “fill in this field”.

## 0.8.2 – 2026-10-02 (beta)

- App Store: the screenshots now come from bias.city/timesister. No code changes.

## 0.8.1 – 2026-10-02 (beta)

- App Store: current screenshots (teams in the admin settings, the Mac app's week and the team's capacity). No code changes.

## 0.8.0 – 2026-10-02 (beta)

- First release in the Nextcloud App Store, for the beta of the TimeSister Mac app 0.4.0.
- Contains everything listed below under 0.1.0 to 0.7.6; no further changes. API version 2.
- Modules (Jobs, Budgets, Clients) need no change here: the clients keep the switches in the settings record (`setting/settings`, field `modules`).

## 0.7.6 – unreleased

- API unchanged in form (`api: 2`), only additions; the capability gains `billing: 1`.
- **Billing marks as records:** kind `billing`, one per person and event UID (key: the person's key, `+`, 32 hex digits of the UID's SHA-256); `data`: `person`, `uid`, `billed_on`, optional `by`, `checksum`, and – carried through for a later hand-over to an accounting system – `source`, `external_id`, `document`. Written by Team Admins and by Leads who may at least view the person in the shares matrix (members and externals); read by the same and by the person themselves. Users never write them. `POST /records/batch` takes a Lead's batch when it holds billing marks only.
- **Externals in the shares matrix:** a person record with a feed and no account is a column after the team (`external: true`, `role: "external"`), levels `none` or `view` only (`edit` → `422`), set by Team Admins only, Team Admins see by default, nothing pending (there is no owner's client). `GET /team/access` lists them for Team Admins and, as the own row, for everyone.
- **Feed only for those who may:** `person.data.feed` goes to Team Admins, the person themselves and Leads with at least `view` on that person; everyone else gets the record without `feed` – in the delta, single reads and the history. Changing an external's column gives their person record a new revision (same version), so clients fetch it again.
- No migration.

## 0.7.5 – unreleased

- API unchanged in form (`api: 2`), only an addition; the capability stays `jobs: 4`.
- **Day weights for readers:** `GET /jobs/weeks` returns per week also `days`, the seven weights the client reported (a holiday by its factor, a vacation day 0). With them a reader splits a week that runs over a month's end – the clients' monthly view of the team's capacity. Same read rights as the rest of the week (the person, Team Admins, Leads who may at least view); a report stored without weights reads Monday–Friday 1. Clients tell it by the field being there.
- No migration: the numbers stay JSON in `ts_client_status.job_weeks`.
- **Above the work package allowed:** the volume rule no longer refuses (`422 rule: volume` is gone); the clients show the overrun.
- **A Job offered only to oneself** is accepted at once and notifies nobody.

## 0.7.4 – unreleased

- API unchanged in form (`api: 2`), only additions; the capability names `jobs: 4`.
- **Non-billable hours in the weekly numbers:** a client reports per Job and week also `booked_nb`, the non-billable part of `booked` (at most `booked`). `GET /jobs/weeks` returns it with the same read rights and merges it like `booked` into `other` for Jobs the reader does not see. Reports of older clients, and reports stored before, read as 0. The load and the capacity check stay booked plus planned.
- No migration: the numbers stay JSON in `ts_client_status.job_weeks`.

## 0.7.3 – unreleased

- **Nextcloud 35:** `max-version` 35; Psalm clean against 33, 34, 35 and master. `IL10N::t` gets only non-empty text; a plural text without `%n` (Nextcloud returns both forms joined by `|`) is resolved by the app.
- Early warning: installs the notifications app like the PHPUnit workflow, so the reminder tests run there too.
- Integration tests: no longer rely on local test data or on the account language picked at the first login.
- **Capacity check per day:** when a counter-proposal is accepted, no day may go above a full day's capacity either (the capacity line over the week's working days, by weight), besides the week. Hours that land on the one working day after a vacation, or on days without weight, no longer pass because the week as a whole still has room. An entry of such a day carries `day`, with that day's load and capacity. No migration, API unchanged in form.

## 0.7.2 – unreleased

- API unchanged in form (`api: 2`), only additions; the capability names `jobs: 3`.
- **Weekly numbers per person:** each client reports its own weeks (`POST /jobs/weeks`): the capacity line (target × pensum − holidays − vacation), per Job the booked and planned hours, and the weight of each day. Read (`GET /jobs/weeks`) by the person, Team Admins and Leads who may at least view the person's time calendar; Jobs the reader does not see are merged into `other`.
- **Capacity check when a counter-proposal is accepted:** the server spreads the counter-proposal over the person's working days and answers `422` with `rule: capacity` and the weeks above the capacity line; without reported numbers nothing is blocked. `GET /jobs/{key}/capacity?by=` shows the same before accepting.
- Migration: two columns in `ts_client_status` (`job_weeks`, `job_weeks_at`).

## 0.7.1 – unreleased

- **Privacy in the market:** whoever does not manage a Job (recipients, the assignee) reads only their own counter-proposal; the others' waiting ones as `{ other: true }`, answered ones not at all; the log without the others' counter-proposals and their answers. Everywhere a Job is read: delta, `GET /records/job/{key}`, the answers of `/jobs`, the `current` of a `409`.
- The history of a Job only for those who manage it (Team Admins, the project's Leads, the sender).
- No migration, API unchanged in form.

## 0.7.0 – unreleased

- API unchanged in form (`api: 2`), only additions; the capability names `jobs: 2`.
- **Market:** a Job offered to several stays open for the others while one of them counter-proposes; counter-proposals are kept per recipient (`counters`, a 0.6.0 `counter` reads as a list of one). Whoever is accepted first gets the Job – by accepting the offer or through their accepted counter-proposal; the counter-proposals still waiting lapse (`answer: lapsed`, notification `job_counter_lapsed`).
- `accept-counter` and `reject-counter` take `{ by }` to say whose counter-proposal while several wait.
- Rejecting a counter-proposal in a market puts only that person out; the Job is `rejected` once nobody is left.
- No migration.

## 0.6.0 – unreleased

- API unchanged in form (`api: 2`), only additions; the capability names `jobs: 1`.
- **Jobs:** records of kind `job`, read through `/records`, written through `/jobs`: offer (`PUT /jobs/{key}`), change, accept (atomic, the first wins), counter-propose and answer, decline, return, Done, paid (Team Admin), delete (whoever created it), and the booked hours from the person's client (`POST /jobs/progress`, Done by itself once the volume is reached).
- Rules on the server: the volume of a work package, no overlapping Jobs on the same work package or code for the same person, codes of the project.
- Who sees a Job: Team Admins, the project's Leads, the sender, and the person while it is theirs; whoever was involved before gets a tombstone in the delta.
- Notifications for new offers, counter-proposals and their answer, changes, returns and deletions.
- The words Job, Workload, Inbox, In Progress, Done and Outbox are the same in every language (`JobWord`, never through the l10n).
- No migration: Jobs live in `ts_records`.

## 0.5.0 – unreleased

- API unchanged in form (`api: 2`), only additions.
- **Three roles: Team Admin · Lead · User**, the same words in every language (`RoleName`, never through the l10n). The role Manager (`subadmin`) is gone: the migration turns stored entries into `lead`.
- What managers and admins could do is Team Admin only; Leads keep writing their own projects.
- `PUT /team/members/{uid}` sets `admin` (group admin of the team group via `ISubAdmin`), `lead`, `user` and `may_override`; the team always keeps at least one Team Admin.
- Shares matrix: `GET`/`PUT /team/access`, `PUT /team/access/{viewer}/{owner}`, `POST /team/access/remind`; `/me.share_targets` and `/me.may_override`; `applied_shares` in `POST /status`. Reminders as Nextcloud notifications, withdrawn once the owner's client has set everything.
- Admin page: roles Team Admin, Lead and User selectable per member; "Leads see all time calendars" removed (the matrix decides).

## 0.4.0 – unreleased

- API unchanged (`api: 2`).
- Roles are called User, Lead, Manager and Admin in every language (admin page, app description).
- Error messages have English source texts and are translated with Nextcloud's l10n (`de`, `de_DE`); `message` comes in the account's language (Nextcloud language setting, else `Accept-Language`, else English). Clients decide by `error`, never by wording.
- New visible backup copies go to the folder "TimeSister Backups" (was "TimeSister-Sicherungen"). Existing files stay where they are; their tracked paths remain valid and thinning removes them as before.
- Server backups carry `PRODID:-//B/IAS//TimeSister Server Backup//EN`.
- Log messages, code comments and tests are in English.

## 0.3.0 – unreleased

API version 2 (`api: 2`), no transition from version 1:

- **One** team group per team (`groups: { team }`). The team's Admin is
  whoever is that team group's group admin in Nextcloud (`ISubAdmin`); the
  `lead` and `subadmin` roles and leaving live in the app (table
  `ts_members`). Role, account and time groups are dropped; old rows in
  `ts_role_groups` stay and are ignored.
- `/me` with `admins`, `leads` and `calendar_share`; `/team` with
  `members: [{ uid, display_name, role, left_at }]`, including those who
  left.
- `PUT /team/members/{uid}` (Manager and Admin; only Admins grant
  `subadmin`), plus `PUT /admin/teams/{id}/members/{uid}` for the admin
  page.
- `GET`/`PUT /me/calendar-share` (default on), `calendar_shared` in
  `POST`/`GET /status`.
- Projects in full only for Manager, Admin and the project's Leads,
  otherwise the booking catalog; a Lead can fully edit their project but
  not create new ones. A project's history is also available to its Lead.
- Admin page: one group field, Admins as a list with a note, members with
  role and leaving (settable in "Edit team"), in the status the accounts
  without a shared time calendar.
- Migration `Version1004…`, additive only (`ts_members`,
  `ts_client_status.calendar_shared`).

## 0.2.2 – unreleased

- API version 1.2: team settings `settings` (`leads_see_calendars`, default
  on; `backup_required`, default off) in `/me`, `/team` and
  `/admin/teams`, settable via `POST`/`PUT /admin/teams`.
- Admin page: two switches in the "Edit team" form, status in the team
  list.
- Migration `Version1003…`, additive only.

## 0.2.1 – unreleased

API version 1.1, backups (0.2.0 was only a development snapshot):

- The server backs up on its own: checked hourly, at most once per ISO
  week per account, export with `ICalendarExport`, a new backup only on a
  changed checksum.
- Only with the person's consent (`GET`/`PUT /backups/consent`, with a
  version of the disclosure text); withdrawing deletes nothing.
- Two storage locations: protected in `IAppData` and visible with the
  team's backup owner (`backup_owner`, selectable on the admin page).
  Optionally an own copy in a person's own folder (`/backups/own-copy`).
- `POST /backups/now`, `source` and `file_path` in the backups,
  `backup_owner` in `/team`, consent in `/status`.
- Thinning instead of a fixed retention: all of the last 4 weeks, then
  monthly up to 12 months, then yearly up to 10 years. Only what the app
  created and remembered itself (`ts_backup_files`) gets deleted, via the
  trash.
- Admin page: backup owner per team, in the status consents, backups
  missing this week and the last server backup; wide tables scroll
  horizontally.
- Migrations `Version1001…` and `Version1002…`, additive only.

## 0.1.0 – unreleased

- First version: API version 1 (`docs/API.md`), teams with four role
  groups, records with version and revision, history, restore, batch,
  calendar backups, status reports, admin page, retention.
- Optional accounts group per team (`groups.accounts`): all accounts of the
  team, no role. As group admins of this group, team Admins can revoke any
  role (Nextcloud rule OCS 105). Stored as a row `role = 'accounts'` in
  `ts_role_groups`, without a migration.
- Nextcloud 33–34.
- Admin page translatable: English as the source, German with "du" (`de`)
  and with "Sie" (`de_DE`). API error messages remained German.
- App Store prepared: English and German description, image, workflow
  `appstore-build-publish.yml` (off as long as the secrets are missing).
</content>
