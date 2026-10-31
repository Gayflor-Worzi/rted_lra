# RETD Production Readiness — Gate Status Register

Live, gate-by-gate status for the Phase-2 "Production Readiness Validation & UAT" run.
Both this file and `docs/PRODUCTION_READINESS_DEFECTS.md` are updated as each gate completes.

## Verdict table

| Gate | Title | Verdict | Evidence (one line) | Open items |
|------|-------|---------|---------------------|------------|
| Gate 0 | Environment alignment | **PASS** | 111 tests / 943 assertions, pint clean, Vite build OK, integrity-check 0 findings, dev DB reset | — |
| Gate 1 | Business process (end-to-end workflows) | **IN PROGRESS** | Function-by-function audit of every web + mobile screen/action; all findings fixed; suite **130/1150 green** | Live role walkthroughs + UAT sign-off (Gate 9) |
| Gate 2 | RBAC (positive + negative) | **IN PROGRESS** | Permission-catalog vs. UI vs. backend-guard audit; drift fixed; **132 tests / 1170 assertions green** | Live per-role walkthroughs + separation sign-off (Gate 9) |
| Gate 3 | Field-client sync reliability & data resilience | **IN PROGRESS** | Sync pipeline + server idempotency ledger audited; drift fixed; **135 tests / 1184 assertions green** | Device crash/offline drill on real hardware (Gate 9, DEFT-005) |
| Gate 3 | Data / constraints / concurrency | **PASS** | Hot-column indexes + unique constraints on bill log/fingerprint/ledger robust; `like_term` escapes LIKE wildcards; mass-assignment all-fillable; transition race + write atomicity closed with regression tests | `tests/Feature/AtomicWriteGuardsTest.php` (4/13) |
| Gate 4 | Mobile (real devices) | BLOCKED-EXTERNAL | hardware | Android devices |
| Gate 5 | Security (application + environment) | **IN PROGRESS** | App-level sweep clean after DEFT-027 (path traversal fixed, 3/7 regression); headers/CORS/throttling/upload/streaming audited; **141 tests / 1207 assertions green** | TLS/infra settings + real-device on-handset drill (Gate 7/9) |
| Gate 6 | Reporting (KPI reconciliation) | **IN PROGRESS** | All report + dashboard aggregates single-table authoritative; reconciliation regression **3/16**; integrity-check 0 findings | Visual KPI confirmation on live data during Gate 9 |
| Gate 7 | Infrastructure | BLOCKED-EXTERNAL | infra | prod env, PostgreSQL/MySQL cluster |
| Gate 8 | Recovery (backup + DR) | BLOCKED-EXTERNAL | infra | infra + rehearsed run |
| Gate 9 | UAT (role sign-off) | **IN PROGRESS** | `docs/UAT_WALKTHROUGH_SCRIPT.md` delivered (duty roster, gate-tagged G1/2/3/5/6 checks, seeded negative matrix, sign-off register) | Live walkthroughs + sign-offs |
| Gate 10 | Go-Live | Pending Gates 1–9 | — | — |

## Gate 0 — Environment alignment (2026-09-14) — **PASS**

Full-suite verification after the four Gate-0 fixes:
- `php artisan test` → **111 passed / 943 assertions** (added `tests/Unit/ProductionGuardTest.php`,
  and `test_enforcement_officer_lookup_exposes_no_personal_contact` +
  `test_internal_references_are_unique_and_id_based` in `tests/Feature/PreBetaAuditRegressionTest.php`).
- `vendor\bin\pint --test` → **passed** (pint applied; re-tested green).
- `& ".\node_modules\.bin\vite.cmd" build` → **built** (132 modules; only the pre-existing
  >500 kB chunk-size advisory).
- `php artisan lra:integrity-check` → **healthy, 0 findings**.
- `php artisan app:reset-dev --force` → dev DB truncated + demo baseline re-seeded.
- `php -l` on every touched PHP file → no syntax errors.

### G0.1 — Production config guard — DONE
`app/Support/ProductionGuard.php` — `assertEnvironmentSafe(?string $env, ?bool $debug)`
throws `RuntimeException` ("APP_ENV=production with APP_DEBUG=true …") when the effective
environment is `production` and debug is on. Parameterised for unit testing; normal boot
calls it from `AppServiceProvider::boot()`. Production config values to document in the
Configuration Guide (Gate 7): `APP_ENV=production`, `APP_DEBUG=false`, no `.env` change made here.

### G0.2 — PII minimization — DONE
`app/Http/Controllers/Api/UsersController.php::enforcementOfficers()` returns only
`id, full_name, staff_id, role, section`; `phone` removed entirely; `email` only when the
caller holds `staff.view`. Frontend `BillCreate.jsx` degrades cleanly (`officer.phone || officer.email`
→ blank for non-`staff.view`) — the logging officer types the recipient contact at entry time.

### G0.3 — Timezone standardisation — DONE
`config/app.php` `timezone` → `env('APP_TIMEZONE', 'Africa/Monrovia')`. `BusinessClock`
was already pinned to `Africa/Monrovia` (all due-date/overdue/period calculations). Monrovia
is UTC+0 without DST, so stored `UTC` timestamps are value-identical; the change standardises
display + `date()`-driven YYYY that previously fell through the app default.
`OverdueConsistencyTest` still 15/15.

### G0.4 — Reference generation audit — DONE
Grep found 7 internal-reference generators, all previously pre-insert `MAX(id)+1` reads:
`TASK` (Task), `VIS` (EnforcementVisit), `VAL` (Valuation), `MEQ` (MeQuery), `APP` (Appeal),
`PHOTO` (EvidencePhoto), `ND` (PropertyDiscovery). All now share the new
`app/Models/Concerns/GeneratesInternalReference` trait: the canonical number is the row's
own auto-increment `id` (unique by construction), written post-insert; a collision-free
placeholder (`PREFIX-YYYY-XXXXXXX`) satisfies the NOT NULL unique columns at insert and is
immediately replaced. No pre-insert sequence race remains. Defect saw this fix:
`DEFT-001`.

## Gate 1 — Business-process / usability sweep (2026-09-21) — **IN PROGRESS**

Two read-only audits ran in parallel — **web** (every `frontend/src/pages` screen + `components`)
and **mobile** (every `mobile/src/screens` + `parts`) — tracing each end-to-end workflow
(property registration, enforcement pipeline, GPS certification, valuation path, payment
verification, M&E, admin/RBAC) against its backend controller and route. Every finding was
fixed in-session. Verdict per surface: **web — serviceable**, **mobile — serviceable** with
no P1/P0 findings outstanding; the remaining work is live role walkthroughs and role
sign-off, which belongs to Gate 9.

### Full-suite state at this gate
- `php artisan test` → **130 passed / 1150 assertions** (incl. 6 `HardeningRegressionTest`
  guards). `vendor\bin\pint --test` clean repo-wide. Vite build OK (132 modules; only the
  pre-existing >500 kB chunk advisory). `php artisan lra:integrity-check` → healthy, 0 findings.
- Dev DB reset twice (`app:reset-dev --force`), demo baseline re-seeded; **0 business rows**
  and **no orphaned evidence files** remain (`storage/app/private/evidence` purged, 254 test files removed).

### Defects closed at this gate (see `docs/PRODUCTION_READINESS_DEFECTS.md`)
- DEFT-008…DEFT-012 — hardening round: account-processing tax-amount contract, evidence
  replay idempotency (SyncService guard), mobile outbox stale `syncing` recovery + unknown-kind
  handling, `sendToRole` case/whitespace normalization, audit-chain integrity check.
- DEFT-014 — M&E resolve-flag 405 (`POST`→`PATCH`, route was PATCH).
- DEFT-015 — `BillDetail.jsx` removed `errMsg` from the API import while `EditForm` still used it — restored.
- DEFT-016 — garbled brand tagline (`verifiableDiscovery`) corrected.
- DEFT-017 — payments nav permission aligned to the route guard (`payments.view_queue/view_history`);
  `/gps?tab=reviews` deep-link now scrolls the certification queue instead of being inert.
- DEFT-018 — payment-claim "tax due" changed from a number input fed a date string to a real date input.
- DEFT-019 — receipt attachment (stored as a local-disk relative path) was never renderable on
  web or mobile; new scoped streaming endpoint
  `GET /api/v1/payments/verifications/{v}/receipt-file` + blob previews on both clients
  (regression test added).
- DEFT-020 — mobile valuation `construction_year` sent as number (422) — now the string the API expects.
- DEFT-021 — mobile GPS certify clamped accuracy to the API's `max:100` guard (indoor fixes 422).
- DEFT-022 — mobile valuation "Returned for Correction" chip now folds in `AC Returned`;
  valuation status badge tones added.
- DEFT-023 — mobile GPS quick-action now requires BOTH `gps.capture` and `gps.certify`
  (`hasAll`), matching the certify screen, instead of dead-ending.

## Gate 2 — RBAC positive/negative sweep (2026-09-22) — **IN PROGRESS**
Cross-checked all 110 catalogue permissions (`config/permissions.php`) against every backend
controller guard and every `can()`/`hasAny()`/`hasAll()`/route-`Guard` call site in the web
and mobile clients. Verdict: **permission vocabularies are consistent** after the fixes.
- **Mobile `DiscoverScreen.jsx` gated on `discovery.view_history` — not in the catalogue**
  (permanently fail-closed for every role). Corrected to `discovery.view` (all field roles)
  with `discovery.review` preserved for reviewers.
- **Dead phantom permission names in backend guards** (never granted to any seeded role),
  each replaced with the real permission the feature intends:
  `view_all_dashboard` → `dashboard.view_division` (drill scope + property profile);
  `manage:analytics`/`view_all_dashboard`/`manage:data_quality` → `audit.view`
  (data-integrity endpoint — previously **only** the System Administrator wildcard passed;
  an Assistant Commissioner can now run it); `query.manage` → `dashboard.view_division`;
  `bills.process` — removed (covered by section gate + existing perms).
- **New `tests/Feature/RbacGate2SweepTest.php`** — positive/negative matrix: 7 authorized-role
  GETs return 200, 13 nearest-staff-role GETs return 403 across the payments / reports /
  audit / me / gps / integrity / account-desk / valuation-desk clusters.
- Full suite now **132 tests / 1170 assertions**; Pint clean; `integrity-check` healthy.
- Re-grep of both clients after fixes: **zero** permission strings outside the catalogue.

## Gate 3 — Field-client sync reliability & data resilience (2026-09-22) — **IN PROGRESS**

Audited the offline-first pipeline end to end: mobile outbox (`db.js` serialized SQLite
mutex + WAL, single-flight flush lock, pending→syncing→synced/failed state machine, 5-minute
stale-`syncing` revive, per-row `attempts`/`last_error`, deferred parent-child rows via
`svc:` server-id map, 7-day prune of synced rows) against the backend idempotency ledger
(`SyncService` + `sync_transactions`, unique on `(user_id, local_transaction_id)`).
**Verdict: every mobile-facing create endpoint replays-guards AND commits the ledger inside
`if ($syncId)`.** No duplicate-creation path found for redispatched offline writes.
- **`OutboxScreen.jsx` detail row labelled the amount in `UGX` (Ugandan shilling).** The system
  currency is USD (`frontend/src/lib/constants.js`) — a wrong-currency display on every offline
  payment-detail row. Corrected to `USD`.
- **Outbox row metadata lacked `evidence` and `gps_certify` kinds** — offline evidence photos and
  GPS certifications surfaced with a generic 🗂/raw-kind label in the Sync tab. Added icons +
  labels + tones.
- **New `tests/Feature/SyncIdempotencySweepTest.php`** (3 methods / 14 assertions): a mobile
  discovery replay returns `replayed: true` with the same id and creates no duplicate; distinct
  device ids create distinct rows + distinct ledger rows; web-style creates without a device id
  are neither deduped nor written to the ledger keyed on an empty id.
- **Full suite now 135 tests / 1184 assertions**; Pint clean; `integrity-check` healthy; dev DB
  reset (0 business/audit rows) after the cycle.
- Known residual (backlog, not blocking): entity insertion and the ledger commit are two
  transactions, so a process crash in the tiny window between them could lose idempotency for
  one write — folding both into one transaction is queued as a hardening item.

## Gate 3 (data scope) — Data / constraints / concurrency (2026-09-22) — **PASS**

- Migration audit: every hot filter/order column is indexed (bills
  `document_number`/`property_id`/`tin`/`account_staff_id`/`assigned_enforcement_officer_id`,
  tasks `assigned_to`/`reference_type+reference_id`/`assigned_to+status`, visits, valuations,
  discoveries, GPS `(property_id, verification_status)`, receipts `(receipt_number,
  verification_status)`, audit morphtoable, `sync_transactions (user_id,
  local_transaction_id)` unique). Unique backstops present where duplicates matter
  (bill-logging, receipt fingerprint, ledger, references).
- `like_term()`/`like_escape()` escape `\`, `%`, `_` — user search terms match literally (no
  wildcard driving an unindexed full scan or results-poisoning).
- Mass-assignment hygiene: every model now declares `$fillable`; `SyncTransaction` closed its
  `$guarded = []` gap to the explicit field list (written only by `SyncService`).
- Concurrency: `Task::transitionTo` commits with an optimistic status-guarded update
  (`where('status', $from)->update(...)`); a stale concurrent transition throws rather than
  writing a duplicate `task_histories` row — same-state requests remain idempotent no-ops.
- **Write-atomicity hardening (closed):** entity create + audit + `SyncService::record` are now
  a single `DB::transaction` across `DiscoveryController::store`, `EvidenceController::store`,
  `GpsController::certify` and `EnforcementController` (`assignmentAction`, `recordVisit`,
  field-discovery capture); a ledger failure rolls the entity back with it. Regression:
  `tests/Feature/AtomicWriteGuardsTest.php` (4 methods / 13 assertions).

## Gate 5 — Security (application + environment) (2026-09-22) — **IN PROGRESS**

- Finding **DEFT-027 (P1)**: `EvidenceController::download` streamed any `Storage::exists()`
  path, and the photo API persists a raw device `path` verbatim — a crafted `../../…`
  reference (e.g. `../../.env`) could make the server serve private-disk files to an
  authenticated officer. Fixed by requiring a server-owned `evidence/` prefix and no `..`
  segment on the evidence **and** the receipt-file streamers; `SyncTransaction` also
  tightened to `$fillable`.
- Regression: **`tests/Feature/FileTraversalGuardTest.php`** (3 methods / 7 assertions) —
  traversal reference → 404, legit server-owned upload streams the exact bytes, traversal
  receipt path → 404.
- Verified posture otherwise intent: all image storage uses server-randomized names + 10 MB
  cap; `data:`/`http` prefixes and legacy device paths cannot be served; auth is uniform
  ("Invalid credentials.", no user enumeration), inactive-account 403, password reset
  requires current password or `must_reset_password`, kills other sessions, min 8 chars;
  login is throttled `30,1`; `CORS` is an allow-list; `SecurityHeaders` applies nosniff /
  frame-options / HSTS / Permissions-Policy and a CSP for HTML responses; `EnsureActiveUser`
  + `EnsurePasswordReset` run on every API route; no `dangerouslySetInnerHTML`/`v-html` in
  either client (D5 pagination note confirmed already fixed).

## Gate 6 — Reporting / KPI reconciliation (2026-09-22) — **IN PROGRESS**

- Audit: every `ReportsController` list totals from the authoritative single table (no join
  over-count); every `AnalyticsController` KPI is a `clone` of one base query with
  `count()`/`sum()` — no drifted denominators, no double-counted rows.
- Regression: **`tests/Feature/ReportingReconciliationTest.php`** (3 methods / 16 assertions)
  — bills register equals `PropertyBill::count()`, collections count/sum equal
  `Payment::count()`/`sum('amount')`, payment-queue equals claim count, analytics
  `metric=bills|collections` KPIs equal the same authoritative aggregates, and the
  enforcement summary counts each task exactly once across statuses (2/1/1/0).
- `php artisan lra:integrity-check` — healthy, 0 findings, "every dashboard figure matches
  its source records."

## Gate 9 — UAT / role sign-off prep (2026-09-22) — **IN PROGRESS**

- Delivered **`docs/UAT_WALKTHROUGH_SCRIPT.md`**: refresh-baseline step, per-role duty
  roster with seeded credentials, gate-tagged G1/G2/G3/G5/G6 walkthrough instructions
  (incl. the real-device offline drill with replay expectations and the expected-403
  negative matrix), a sign-off register, and Gate-10 closure conditions.
- **Baseline pinning:** `tests/Feature/UatBaselineCheckTest.php` (4 methods / 28 assertions)
  locks the roster — exactly **7 demo accounts** (System Administrator, Account Manager,
  Account & Records Officer, Assistant Commissioner, Enforcement Officer, Valuation Manager,
  Valuation Officer) — the demo passwords (`account123` / `officer123`), the retained team
  wiring, and every expected-403 matrix string to the seed baseline. `DatabaseSeeder` no
  longer ships the Enforcement Supervisor / Valuation Supervisor / M&E demo accounts; those
  role behaviours keep full automated coverage via local fixtures in
  `tests/Feature/Concerns/LocalDemoUsers.php`.

## Next
Gate 1/2/3/5/6 remain open pending live walkthroughs + field drills under Gate 9
(`docs/UAT_WALKTHROUGH_SCRIPT.md` holds the duty roster, expected 403 matrix and the
sign-off register). Gate 4/7/8 wait on production infrastructure (DEFT-005/006/007).