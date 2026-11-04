# RETD — Phase 2: Production Readiness Validation & UAT — MASTER PROMPT

> Copy the entire content of this file into a fresh coding/review agent session to
> execute Phase 2. It instructs the agent to run the production-readiness gates
> **sequentially, role-by-role, with evidence**, without repeating the already-
> completed prerelease audit.
>
> Companion documents you must read first:
> - `docs/PRERELEASE_AUDIT_REPORT.md` — completed Phase-1 audit (findings, fixes, gates A–P).
> - `AGENTS.md` — standing policies and verification commands.
> - `docs/04_BUGS_AND_ISSUES.md` — historical issues register.
> - `API.md` — API surface reference.

---

## 0. Session contract

You are a **production-readiness validation agent** working on the RETD system
(Laravel 13 backend + React web `frontend/` + Expo mobile `mobile/`) at
`C:\laragon\www\rted_lra` (Windows, PowerShell).

Your job is to answer this question, with evidence:

> **Can LRA operate RETD safely, reliably and continuously in a real production
> environment?**

Not: *"is the architecture substantially correct?"* (already answered in Phase 1).

### Standing rules (never violate)

1. **Do not repeat the Phase-1 audit.** Treat `docs/PRERELEASE_AUDIT_REPORT.md`
   as the baseline. Re-verify only what changed or what a gate explicitly requires.
2. **Regression tests before fixes.** Any defect you fix gets a failing regression
   test FIRST, then the fix, then proof the test passes and the suite stays green.
3. **Clean the dev database after every test-verified change:**
   `php artisan app:reset-dev --force` (preview with `--dry-run`). The automated
   suite is isolated (sqlite `:memory:`); manual/browser API testing writes to the
   MySQL dev DB and must be cleaned back to the demo baseline.
4. **Terminology:** the system is RETD. The external bill/registry source is
   "the source system". Do not write "LITAS" into code, UI, schema, or docs.
5. **Modify production data? Never.** Data changes go through the designed
   application workflows. Any migration/script that mutates data must be a
   reviewable migration or approved `app:*` console command.
6. **Record everything.** Maintain `docs/PRODUCTION_READINESS_STATUS.md`
   (gate-by-gate status) and `docs/PRODUCTION_READINESS_DEFECTS.md` (defect
   register). Both must be updated as you complete each gate.

### Verification commands (run and quote output as evidence)

- Full suite: `php artisan test`
- Code style: `vendor\bin\pint --test` (fix: `vendor\bin\pint`)
- Frontend build (from `frontend/`): `& ".\node_modules\.bin\vite.cmd" build`
- Lint a file: `php -l <file>`
- Data integrity: `php artisan lra:integrity-check`
- Dev DB reset: `php artisan app:reset-dev --force`
- Route list sanity: `php artisan route:list --columns=method,uri,action,middleware` (filter to `api`)

### Defect workflow (as you encounter defects)

Classify P0/P1/P2/P3 (pain levels below). For each defect:
1. Record it in `docs/PRODUCTION_READINESS_DEFECTS.md` (id, gate, severity, repro, evidence).
2. If severity is P0 or P1 and it is fixable in this session: write a failing regression test, fix, re-run suite, update status.
3. If it is a hardware/environment item (physical device, real network, PostgreSQL cluster): record as BLOCKED-EXTERNAL with exact hand-off notes.
4. P2/P3 that are cheap: fix with regression test. Others: record for the release backlog.

**Severity:** P0 = exploitable negligent (data loss/breach/unrecoverable); P1 = significant security/integrity defect; P2 = hardening gap; P3 = operational/process note.

### Final pass/fail at each gate

Each gate ends with a **PASS / FAIL / BLOCKED-EXTERNAL** verdict, one line of
evidence, and a list of open items. Only after Gates 1–10 are complete do you
produce a **Go-Live / No-Go** recommendation with reasons.

---

## Gate 0 — Environment alignment (do first; small, code-level)

These are the Phase-2 "order zero" fixes the Phase-1 report already scoped.

> **DONE (2026-09-14).** Full suite 111 passed / 943 assertions, pint clean, Vite
> build passes, `lra:integrity-check` 0 findings, dev DB reset. Details per item:
> `docs/PRODUCTION_READINESS_STATUS.md` §Gate 0.

- [x] **G0.1 — Production config guard.** `APP_ENV=local` / `APP_DEBUG=true` must
      never silently reach production. Add a fail-fast guard so the application
      refuses to boot unauthenticated URLs (or loudly fails) when
      `APP_ENV=production && APP_DEBUG=true` (e.g. in `bootstrap/app.php`,
      a config validator, or a boot-time check). Do NOT change `.env` blindly —
      guard code first, then document the production values.
      **Done:** `App\Support\ProductionGuard::assertEnvironmentSafe()` invoked from
      `AppServiceProvider::boot()`; unit test `tests/Unit/ProductionGuardTest.php`
      (production+debug → RuntimeException; production+no-debug → OK; local+debug → OK).
- [x] **G0.2 — PII minimization on lookups.** `/lookup/enforcement-officers`
      must return only `id, full_name, staff_id, role, section` (drop `phone`,
      gate `email` behind `staff.view`). Regression test: officer (no `staff.view`)
      sees no email/phone; `staff.view` holder sees assignment-relevant fields only.
      **Done:** `UsersController::enforcementOfficers()` drops `phone`, returns
      `email` only to `staff.view` holders; representative regression test
      `test_enforcement_officer_lookup_exposes_no_personal_contact`.
- [x] **G0.3 — Timezone standardisation.** Business dates: set
      `app.timezone`/default timezone to **Africa/Monrovia** for business
      calculations and display; store UTC timestamps where appropriate. Verify
      `BusinessClock`/`OverdueConsistencyTest` stays green and that due-date,
      30-day, 72-hour and escalation calculations use Monrovia consistently.
      **Done:** `config/app.php` `timezone` → `Africa/Monrovia` (env-overridable via
      `APP_TIMEZONE`); `BusinessClock` already pinned to Monrovia; `OverdueConsistencyTest`
      15/15 green. Liberia is UTC+0 no-DST, so stored timestamps are unchanged.
- [x] **G0.4 — Reference generation audit.** Grep for `MAX(` , `max(`,
      `auto_increment`, `row id`, `orderByDesc('id')->first()` patterns in
      reference/identifier generators (`task_reference`, visit/valuation/amendment
      references, receipt references, audit references). Confirm each is atomic
      (DB sequence, UUID, transaction+lock, or post-insert id), not `MAX(id)+1`.
      Fix any that are not; record results in the status file.
      **Done:** all 7 internal-reference generators (`TASK/VIS/VAL/MEQ/APP/PHOTO/ND`)
      replaced by `GeneratesInternalReference` trait — canonical number is the row's
      own auto-increment id (unique by construction), written post-insert; no
      pre-insert `MAX(id)+1` race remains. Regression test
      `test_internal_references_are_unique_and_id_based`.

Evidence for Gate 0: full suite green, pint clean, the new regression tests, and a
one-line summary per item (G0.1–G0.4). Reset the dev DB after verification.

---

## Gate 1 — Business process (end-to-end workflows)

Execute each workflow **through the API as real role users**, not screen-by-screen.
For every step assert: creator, owner, viewers, editors, approvers, assigners,
blocked users, and the behaviour on rejection/return/missing info/payment.

### Workflow A — Self-declared property

`Discovery Officer → submit discovery → Account & Record Manager review →
Account & Record Officer → registration/update in source system → bill logged →
Enforcement assignment → delivery → payment follow-up → payment verification →
completion`

Assert specifically:
- [ ] A discovery for a self-declared (account-routed) classification never bypasses the A&R manager.
- [ ] After registration the Discovery Officer identity is still recorded.
- [ ] Task ownership/current-owner updates correctly at each hand-off.
- [ ] Notifications follow the task to the new owner.
- [ ] A bill logged from the source system carries the source identifiers and no RETD-generated ones.
- [ ] After payment verification completes the terminal status, the bill/task case closes consistently.

### Workflow B — Commercial/valuation property

`Discovery → Valuation Manager → Valuation Officer → Valuation Manager review →
AC approval → Account & Record Manager → Account & Record Officer →
source-system registration/update → bill → enforcement`

Assert specifically:
- [ ] Commercial/Industrial/Mixed-Use/Institutional classifications **cannot** bypass valuation (route = `valuation`).
- [ ] A Valuation Officer cannot review/approve their own valuation (separation of duties).
- [ ] AC approval cannot be bypassed (no premature account registration of an unapproved valuation).
- [ ] A&R cannot register an unapproved valuation.
- [ ] Original Discovery Officer remains identifiable through the chain.
- [ ] Every transition is written to the audit chain.

### Workflow C — Existing-property reassessment

`Existing property → Valuation Officer → Valuation Manager → AC →
Account & Record Manager → Account & Record Officer → source-system update`

Assert specifically:
- [ ] Reassessment of an existing bill is distinct from new-property discovery (no discovery created, bill referenced directly).
- [ ] Reassessment financial result propagates only through defined statuses.
- [ ] No duplicate or orphaned records are produced.

**Evidence:** scripted API sequences (probe + assert), captured JSON, audit-log
rows for each transition, and `php artisan lra:integrity-check` clean afterwards.

---

## Gate 2 — RBAC (positive + negative, through the API)

For **every seeded role** (Assistant Commissioner, Account
Manager, Account Supervisor, Account & Records Officer, Enforcement Manager,
Enforcement Supervisor, Enforcement Officer, M&E Officer, Valuation Manager,
Valuation Supervisor, Valuation Officer) execute a positive AND negative matrix
through the endpoints, never relying on hidden UI buttons. Test the separately
provisioned System Administrator account in the same matrix after provisioning.

| User | Must be able to | Must NOT be able to |
| --- | --- | --- |
| Valuation Officer | valuation tasks, property valuation | bill delivery, payment follow-up, valuation approval/review of self |
| Account & Records Officer | account tasks, discovery record, payment verification | enforcement field tasks |
| Account & Record Manager | A&R workflow, records, amendments | enforcement field functions |
| Assistant Commissioner | approval queues, division dashboards | field enforcement |
| Enforcement Officer | assigned-bill delivery/visit/follow-up | valuation approval, bill amendment |
| M&E Officer | monitoring, reassignment within mandate | unauthorized property/bill modification |
| System Administrator | system administration | bypassing business audit controls (still audited) |

Assertions:
- [ ] Permission checks on routes (`permission:*` middleware) and in-controller checks both hold.
- [ ] Data-scope violators get 403 at the API (own/team/section/division/system).
- [ ] IDOR probes: changing a URL/ID of a record outside the caller's scope fails.
- [ ] Assignment eligibility: an ineligible assignee is rejected at every assignment surface.
- [ ] Every denial is audited (or at least logged by `LogAccessDenied`).

**Evidence:** a matrix table in the status file with one probe result per cell
(test name or captured 200/403 + assertion), plus IDs of the regression tests
added for any hole found.

---

## Gate 3 — Data (database, constraints, concurrency)

Use a **production-like PostgreSQL** database (see Gate 7 infra). Run the
automated suite against it (override `DB_CONNECTION`/`DB_*` in the test env),
and run the concurrency probes below with **two parallel request streams**.

Concurrency probes (expected outcomes in parentheses):
- [ ] Concurrent receipt submission x2 (one succeeds, other rejected; no double claim).
- [ ] Concurrent assignment of one task by two managers (one wins per business rule).
- [ ] Concurrent approval of one document (no duplicate approval/inconsistent state).
- [ ] Concurrent amendment of one bill (no lost update/corrupted financial values).
- [ ] Concurrent audit writes (chain integrity preserved; `audit_chain_locks` prevents fork).

Constraint hardening where safe (record + regression test each):
- [ ] **One approved receipt → one payment claim** enforced at DB level where
      feasible (partial unique index / unique constraint).
- [ ] **Open-task uniqueness** (one open task per reference-type+reference+task-type)
      reviewed for a durable DB-level solution (partial unique index) instead of
      application-only enforcement; if a partial index is added, keep the
      app-level dedupe and verify both agree.
- [ ] Verify FK integrity and that no orphaned references survive workflow failures.

**Evidence:** suite runs against PostgreSQL, concurrency probe transcripts with
timing, constraint DDL, and a `lra:integrity-check` report.

---

## Gate 4 — Mobile (real Android devices, field conditions)

Requires physical devices — mark **BLOCKED-EXTERNAL** if no device is available,
but still test every testable piece in the Expo simulator and record gaps.

Connectivity:
- [ ] Strong, weak, none, intermittent.
- [ ] The officer NEVER sees "Saved successfully" when the record exists only
      locally and is not queued for sync.

GPS: disabled / enabled / poor accuracy / indoor-outdoor / capture / certification / official update.
Photos: capture, upload, offline, retry, duplicate upload, failed upload.
Receipt capture: offline + upload + duplicate + fingerprint.
Sync: `offline → save → reconnect → sync → server validation → confirmation`.
Conflict rules for: same record online+offline, duplicate submission, expired
auth, failed/partial sync, attachment failure, duplicate GPS event, duplicate
receipt. Every offline record must carry a sync state:
`PENDING → SYNCING → SYNCED → FAILED → RETRY_REQUIRED → CONFLICT`.

Audit the mobile code against these states; add a state machine document if missing.

**Evidence:** device test log (device, OS, network scenario, outcome), simulator
passes, and the sync-state inventory.

---

## Gate 5 — Security (application + environment)

Environment (verify + document):
- [ ] `APP_ENV=production`, `APP_DEBUG=false` in the production env (guard from G0.1 in place).
- [ ] Secrets not in VCS; `.env.example` documented; encryption/app keys rotated on deploy.
- [ ] HTTPS enforced; secure cookies; session security config; SameSite; CORS locked to real origins.
- [ ] Login throttling verified; password policy; reset flow; lock/deactivation immediate (EnsureActiveUser).
- [ ] File storage permissions; no raw filesystem paths or stack traces/SQL errors exposed to users.

Application security assessment — run a targeted pass (manual + scripted), highest
priority being authorization (RETD is a controlled government workflow):
- [ ] IDOR / broken access control across every record endpoint (evidence, receipts, GPS, valuations, bills, tasks, amendments).
- [ ] Privilege escalation probes at the API.
- [ ] SQL injection (parameterised queries confirmed), XSS, CSRF on the web UI.
- [ ] Auth bypass / session attacks.
- [ ] File upload attacks: extension/MIME/size/nul-byte/executables/corrupted images/direct URL access/unauthorized download/deletion-replacement controls.
- [ ] Rate limiting / enumeration protection on auth and lookups.

**Evidence:** probe results per item, IDs of added regression tests, and a
"no stack trace / no debug leak" capture from a forced-error request.

---

## Gate 6 — Reporting (KPI reconciliation)

Establish one formal data chain per KPI:

> Database → KPI Definition → Dashboard → Drilldown → Report → Export

For every KPI record in the status file: source table, source field, date field,
status filter, scope, calculation, exclusions, terminal states.

- [ ] Document in a new `docs/KPI_REGISTRY.md` (align to `app/Services/KpiRegistry.php`).
- [ ] Dashboard scope == drilldown scope == report scope == export scope (parameter tampering like `?staff_id=123` must not widen scope).
- [ ] Financial concepts stay distinct everywhere:
      **Tax Due ≠ Payment Submitted ≠ Payment Verified ≠ Outstanding Balance**.
- [ ] Outstanding Balance is never confused with Total Tax Due.
- [ ] The **source system remains authoritative** for actual tax/payment truth;
      RETD figures are workflow KPIs, and `/v1/meta` still says so.
- [ ] Cross-check every KpiRegistry entry against dashboard controllers and add
      reconciliation regression tests (dashboard vs. direct query).

**Evidence:** KPI registry doc, reconciliation tests, and export-scope probes.

---

## Gate 7 — Infrastructure

Produce/validate the deployment architecture:

```text
Internet / LRA Network
        │
        ▼
   Reverse Proxy (SSL)
        │
        ▼
   RETD Application (php-fpm/native server)
        │
        ├──────── PostgreSQL (production schema migrated)
        ├──────── File/Object Storage (evidence, receipts)
        ├──────── Queue/Worker (notifications, escalations, sync)
        ├──────── Notifications
        └──────── Logging/Monitoring
```

- [ ] Production server + reverse proxy + SSL configured (documented runbook).
- [ ] PostgreSQL production database initialized with migrations + seed baseline.
- [ ] File storage layout defined; storage permissions locked down.
- [ ] Queue workers configured for `queue:listen`/`queue:work`; scheduled jobs
      listed (`schedule:list`) and wired (`cron`/task scheduler).
- [ ] Scheduled/background reliability: retry + failure logging + monitoring;
      a failed job must never silently disappear.
- [ ] Logging + monitoring prove "Is RETD working right now?" without waiting for users.

**Evidence:** architecture diagram (text), runbook checklist, `schedule:list`
output, queue config, log/monitor config.

---

## Gate 8 — Recovery (backup + disaster recovery)

- [ ] Automated DB backups: frequency, retention, encryption, storage location.
- [ ] **Restoration test performed:** production-like backup → clean database →
      restore → application works. This is mandatory, not optional.
- [ ] File storage backup strategy (evidence/receipts).
- [ ] Written DR procedure; RPO and RTO defined and recorded.

**Evidence:** restore runbook + actual restore transcript + RPO/RTO statement.

---

## Gate 9 — UAT (role sign-off)

Prepare a UAT pack per role (test script + expected results), driven by real or
proxy SME representatives. At minimum per seeded role:

- **Account & Records Officer:** my tasks, discovery, assignment, processing,
  registration/update entry, payment verification, amendment, data quality.
- **Account & Records Manager:** discovery review, assignment, records, bills,
  payment verification, amendments, workload.
- **Valuation Officer:** valuation, GPS, evidence, submission, correction.
- **Valuation Manager:** discovery queue, assignment, review, reassessment, AC submission.
- **Assistant Commissioner:** valuation/reassessment approval, rejection, return
  for correction, final decisions.
- **Enforcement Officer:** bill delivery, visits, GPS, payment follow-up,
  reminders, demands, proof, completion.
- **System Administrator:** staff, roles, permissions, activation/deactivation,
  configuration, audit, monitoring.

Each role walks the applicable workflows end-to-end in a browser/device against
the dev/pre-production dataset. Capture pass/fail + observed defects.

**Evidence:** sign-off sheet per role (who tested, when, env, result, defect ids).

---

## Gate 10 — Go-Live

- [ ] Production database initialized and verified (migrations + baseline + integrity-check).
- [ ] Initial master data loaded (sections, roles, permissions, users) through proper channels.
- [ ] Permissions verified per role against the RBAC matrix (Gate 2).
- [ ] Production smoke test passed (login → dashboard → one task list → one workflow → audit row).
- [ ] Rollback plan ready and rehearsed.
- [ ] Support/incident-response team briefed.
- [ ] Change-control in place: Dev → Test → UAT → Approval → Prod, versioned
      releases, migration + rollback scripts, deployment records, audit trail.
- [ ] Formal production documentation package delivered (see below).

### Documentation package to deliver with Gate 10

Technical: System Architecture, Database/Data Dictionary, API doc, RBAC
Authorization Matrix, Integration Specification, Deployment Guide, Configuration Guide.
Business: RETD SOP, Workflow Manual, Role Responsibilities, Assignment Rules,
Approval Matrix, Escalation Rules, Payment Verification Procedure, GPS
Certification Procedure, Data Correction/Amendment Procedure.
Operations: Administrator Manual, User Manual, Mobile User Guide, Incident
Response Procedure, Backup & Recovery Procedure, Business Continuity Plan,
Change Management Procedure.

---

## Final decision

After Gates 0–10, produce a single verdict:

**GO-LIVE** — gates PASS, every P0/P1 closed with regression tests, UAT signed off.
**CONDITIONAL GO-LIVE** — specific conditions list (each traced to a gate).
**NO-GO** — blocking items list with evidence and recommended next steps.

Update `docs/PRODUCTION_READINESS_STATUS.md` with the per-gate verdict table and
final recommendation, and deliver a one-page executive summary.