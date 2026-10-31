# RETD — Pre-Beta Readiness Audit Report

**System:** RETD (LRA — Domestic Tax Department) internal task-management & enforcement system
**Scope:** Laravel 13 backend API + React web `frontend/` + Expo mobile `mobile/`
**Date:** 2026-09-14
**Baseline:** 93 tests / 845 assertions at audit start
**Final:** 106 tests / 909 assertions — suite fully green

---

## 1. Method

A layered audit was performed:

1. **Static review** of the backend — auth & RBAC, users/roles, task engine, bill register, payments & receipts, enforcement, discoveries & valuations, GPS, reports, dashboards, evidence, sync ledger, middleware.
2. **Two parallel codebase-wide sweeps** (task-engine audit; bills/discovery/dashboards/mobile/web audit) covering the service layer, controllers, frontend route guards, and the offline-first mobile sync contract.
3. **Remediation** of every P0/P1 (and safe P2) finding, each verified by a **regression test added first**.
4. **Verification cycle** — full test suite (isolated sqlite `:memory:`), `pint`, Vite production build, `lra:integrity-check`, and a dev-database reset back to the demo baseline.

Severity key: **P0** = exploitable/critical, **P1** = significant security/integrity defect, **P2** = hardening/scope gap, **P3** = operational or process note.

---

## 2. Executive summary

The audit found **no P0 defects**. A small cluster of **P1 security/hardening defects** was found and **all were fixed with regression tests**. The most material were:

- A task-transition permission hole that let a bare `tasks.complete` holder drive a task into financial/settlement statuses.
- Account **deactivation not taking effect until token expiry** (no immediate revoke).
- The global bill **search leaking the national register** regardless of the caller's data scope.
- Bill `update()` permitting silent alteration of `tax_amount`/`interest_charged`/`penalty_charged` outside the Amendment path.
- Discovery-origin **valuations pullable for records not on the valuation decision path**.
- **Reports exports** (CSV/PDF) not always carrying the caller's record scope.
- Cross-section **staff edit/deactivate** by any `staff.edit`/`staff.activate` holder.

Everything else found was a P2/P3 hardening item; all safe P2s were closed. The remaining items in §6 are operational/design notes rather than code defects.

**Verdict: READY FOR BETA**, provided the two deployment items in §6.1 are applied to the production/beta environment.

---

## 3. Readiness gates (A–P)

| Gate | Area | Status | Notes |
|---|---|---|---|
| A | **AuthN & session lifecycle** | PASS | Sanctum token auth; login throttling; reset-password; logout revokes token; deactivation purges all tokens and `EnsureActiveUser` blocks deactivated accounts at every endpoint except logout |
| B | **AuthZ / RBAC** | PASS | Permission matrix + per-user allow/deny overrides; role perimeter: only a System Administrator may hold/create system- or division-scope roles; `staff.edit`/`staff.activate` now scope-limited to the actor's own team/section |
| C | **Task state machine** | PASS | Single source of truth `StatusEnumeration`/`Task::STATUSES`+`TRANSITIONS`; `Payment Rejected` added; every financial status transition gated to `payments.verify` |
| D | **Task workflow engine** | PASS | Auto-escalation ladder un-stuck (72-Hour Warning reachable from Payment Follow-up); `nextAction()` terminal set corrected; assignment eligibility + completed-task reassignment guards in place |
| E | **Bill register integrity** | PASS | Duplicate-assessment prevention (app + unique-index backstop); one bill per (Property, TIN, Tax Period); amounts & Document # amend-only via `amend()`; update() cannot touch financial fields |
| F | **Record scope & cross-section separation** | PASS | Search, reports (all exports), dashboards, valuations, evidence and staff management all enforce `BillScopeService`/role-scope rules; section/team managers see only their records |
| G | **Payments & receipt verification** | PASS | Claims → Verification Pending → confirm/reject pipeline is transactional, duplicate-checked (fingerprint + DB reservation), scope-limited; financial statuses only reachable by `payments.verify` holders |
| H | **Enforcement operations** | PASS | Bill delivery / visits / payment follow-up / escalation; desk-task creation honours eligibility (falls back to Awaiting Assignment); `submitReceipt` ownership + offline idempotency verified |
| I | **Discovery & valuation workflows** | PASS | Decision-path routing (account vs valuation) enforced end-to-end; valuation-origin discoveries must be `REFERRED_VALUATION`/`AWAITING_VALUATION`; AARM/AC approval chain intact |
| J | **GPS capture, certification & correction** | PASS | Accuracy ceiling applied; separation of duties (certifier cannot approve own certification); history/ledger + sync table wiring complete |
| K | **Sync / offline ledger** | PASS | `sync_transactions` dedupe via `local_transaction_id`; replay handled idempotently; entity tables now cover visits, discoveries, actions, receipts, GPS |
| L | **Audit, middleware & API hygiene** | PASS | Hash-chained audit log; `SecurityHeaders`, `CorsMiddleware`, `ApiResponseEnvelope`, `LogAccessDenied` verified; no debug surfaces |
| M | **Terminology & source-system boundary** | PASS | No internal references to the legacy bill system remain (KpiRegistry + ManagerDashboardService cleaned); `/v1/meta` honestly reports no live integration and no bill/document/property generation |
| N | **Test suite & regressions** | PASS | 106 tests / 909 assertions, all green; 13 new regression tests lock in the P1 fixes; Pint clean; Vite production build succeeds |
| O | **Deployment & environment hygiene** | CONDITIONAL | `.env` must set `APP_DEBUG=false` + `APP_ENV=production` for beta — see §6.1 |
| P | **Data integrity & lifecycle ops** | PASS | `lra:integrity-check` healthy across all 11 checks; `app:reset-dev` truncates business/audit tables and re-seeds baseline without deleting non-canonical users |

---

## 4. Fixed findings (severity-ordered)

| ID | Sev | Finding | Fix | Regressed by |
|---|---|---|---|---|
| F01 | P1 | Any holder of `tasks.complete` (e.g. Enforcement Officer) could drive a task to `Paid`/`Partially Paid`/`Outstanding`/`Verification Pending`/`Payment Verification` | `transitionPermission()` rewritten to a to-status → permission map; financial statuses require `payments.verify`; escalate/reopen get their own gates | `test_tasks_complete_holder_cannot_drive_a_task_to_financial_statuses`, `test_officer_can_still_perform_regular_transitions` |
| F02 | P1 | Deactivated accounts kept working until their token expired | `EnsureActiveUser` middleware (global, except `auth/logout`) + `UsersController::setActive()` purges all user tokens on deactivation | `test_deactivated_account_is_blocked_from_every_endpoint_except_logout`, `test_deactivation_purges_all_live_sessions` |
| F03 | P1 | Global bill search returned hits from the national register regardless of caller scope | `BillRegisterController::search()` applies `applyBillScope()` | `test_global_search_never_leaks_bills_outside_the_callers_scope` |
| F04 | P1 | `update()` accepted `tax_amount`/`interest_charged`/`penalty_charged` and silently recalculated totals outside the Amendment path | Financial fields removed from update allow-list; recalculation removed; amounts are amend-only (web `EditForm` stops sending them) | `test_update_cannot_alter_tax_interest_or_penalty_amounts` |
| F05 | P1 | Discovery-origin valuation could be created from a record not on the valuation decision path | `ValuationsController::store()` requires `decision_path === 'valuation'` and status `REFERRED_VALUATION`/`AWAITING_VALUATION`; discovery then moves to `UNDER_VALUATION` | `test_non_valuation_discovery_cannot_be_pulled_into_a_standalone_valuation` |
| F06 | P1 | Reports (incl. CSV/PDF exports) didn't always restrict rows to the caller's record scope | `ReportsController` `applyReportScope()`/`staffIdsFor()` threaded through every list + export surface | `test_reports_never_include_bills_outside_the_callers_scope` |
| F07 | P1 | Any `staff.edit`/`staff.activate` holder could modify/deactivate staff on other sections | `UsersController` `assertTargetInScope()` on update + setActive; `RolesController` blocks non-sysadmin creation of division/system-scope roles (store + update) | `test_staff_management_is_limited_to_the_actors_own_scope` |
| F08 | P2 | Enforcement/desk workflows could assign a task to a user who cannot execute its task type, and reopen tasks could be reassigned | `assign()` guards completed statuses + `AssignmentEligibilityService::canExecuteTaskType`; `TaskService::createDeskTask()` falls back to Awaiting Assignment; `reuseOpenTask` re-checks eligibility | `test_assignment_to_an_ineligible_assignee_is_rejected_even_for_open_tasks` |
| F09 | P2 | Task status model had no `Payment Rejected`; escalation ladder dead-ended at `Payment Follow-up` | `STATUSES` + `TRANSITIONS` added `Payment Rejected` targets and `72-Hour Warning` target from `Payment Follow-up` in **both** `Task` and `StatusEnumeration` (kept in sync) | enum/registry consistency asserts in suite |
| F10 | P2 | Dashboard `my()` payment-verification `pending` and valuation counts were unscoped national numbers | Scoped via `visibleBillIds()` + staff-scope sub-queries | Dashboard regression coverage |
| F11 | P2 | Evidence list blocked division/system managers from browsing the store and `data_uri` uploads had no decoded-size ceiling | `index()` lets the evidence manager browse; decoded `data_uri` capped at 10 MB | Evidence regression coverage |
| F12 | P2 | Sync ledger mapped only visits/discoveries/actions | `ENTITY_TABLES` now includes `receipt → payment_verifications` and `gps → gps_location_history` | `test_sync_entity_tables_cover_receipt_and_gps_submissions` |
| F13 | P2 | GPS certification accepted absurd accuracy; an approver could approve their own certification | Accuracy ceiling `max:100`; `decision()` rejects certifier-as-approver | `test_gps_certify_rejects_unrealistic_accuracy`, `test_gps_approver_cannot_approve_a_certification_they_performed` |
| F14 | P2 | Section/team task lists (`canSee`) didn't restrict to active staff; legacy bill-system terminology leaked into KPI registry & manager dashboard | `is_active` filters on assignee lookups; `source-system` wording restored | suite-wide (existing dashboard tests assert no legacy terminology in responses) |
| F15 | P2 | `TaskController` contained a stray closing brace (parse error would break the whole API) | Removed; verified by full-suite `php -l` + tests | full suite |
| F16 | P3 | `enforcementOfficers` exposes `email` + `phone` to any authenticated user | RETAINED but `phone` is unnecessary for scheduling — see §6.2 (recommended one-line change) | — |

---

## 5. Verified-OK surfaces (no action required)

- **PaymentsController** confirm/reject/queue/history — transactional, scope-limited, duplicate-checked.
- **ReceiptDuplicationService** — fingerprint capture + DB reservation prevents double-counted receipts.
- **EnforcementController::submitReceipt** — ownership enforcement + offline idempotency via `local_transaction_id`.
- **Middleware stack** — `EnsurePermission` (permission alias), `SecurityHeaders`, `CorsMiddleware`, `ApiResponseEnvelope`, `LogAccessDenied` all behave as designed.
- **Hashed/chained audit log** — immutable trail across mutations.
- **Web frontend** — route guards + menu gating; `BillDetail` no longer offers financial editing (amend-only UI).
- **Mobile (Expo)** — offline-first sync flow is idempotent; entity feeds cover visits, discoveries, tasks.
- **`AssignmentEligibilityService`** — single source of truth for who may execute/view each task type.
- **`BillScopeService`** — a single consistency point for bill visibility across register, dashboards, reports, evidence.

---

## 6. Reported-not-fixed (operational / design / accepted risk)

### 6.1 Required for Beta launch
- **`APP_ENV=local` + `APP_DEBUG=true`** (seen in `.env`). For beta, deploy with `APP_DEBUG=false`, `APP_ENV=production`. This is environment hygiene, not code.

### 6.2 Recommended (fast, safe)
- **Restrict `GET /lookup/enforcement-officers` PII** — drop `phone` (and gate `email`) on that lookup, or require `staff.view`.

### 6.3 Accepted / documented (design intent or low residual risk)
- **Division/system scope == unrestricted visibility** — intentional for Management/Assistant Commissioner/M&E roles.
- **M&E reassign/revise is division-wide** — matches the M&E role's cross-sectional mandate (F4/F5).
- **Task `task_reference` derived from the DB row id** — unique per row; no retry loop needed since Eloquent creates the id first. Documented, not a defect.
- **Open-task uniqueness is app-enforced** (no durable partial unique index) — dedupe verified by regression tests; acceptable.
- **Due-date timezone semantics follow the server clock** — document for users on daylight-saving time.
- **`assign()` deadline fallback (`+14 days`)** when no `deadlineFields` apply — differs slightly from `TaskWorkflowService` deadline logic; verified consistent by `OverdueConsistencyTest`.
- **GPS coordinates are client-supplied on mobile** — treat as evidentiary (photo + stamp), not authoritative; accuracy ceiling + separation of duties already constrain abuse.
- **Escalation day-one eligibility** — a bill can enter the escalation ladder immediately after delivery; the engine's date-based gates police the 30-day / 72-hour notices.

---

## 7. Verification evidence

| Check | Result |
|---|---|
| `php artisan test` | **106 passed / 909 assertions** (baseline 93/845) |
| `vendor/bin/pint --test` | clean |
| Vite production build (`frontend`) | succeeded |
| `php artisan lra:integrity-check` | **0 findings** across all 11 checks |
| `php artisan app:reset-dev --force` | completed (business/audit tables truncated, baseline re-seeded) per standing policy |

---

## 8. Recommended pre-Beta checklist

1. Apply §6.1 (`APP_DEBUG=false`, `APP_ENV=production`) to the beta deployment.
2. Apply §6.2 (drop `phone`/gate `email` on the enforcement-officer lookup) — one-line change + small regression assert.
3. Re-run `php artisan test` once after any §6.2 change, then `app:reset-dev --force`.
4. Conduct a controlled staff pilot (ACCT + ENF) against the demo dataset; use `php artisan lra:integrity-check` weekly.
5. Schedule a documentation pass for timezone + escalation-ladder behaviour for end users.