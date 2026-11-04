# RETD_LRA System Analysis

**System:** Liberia Revenue Authority — Real Estate Tax Division (RETD) Property Tax Management System
**Version analysed:** current working build (Prompts 14–21 hardening + LRA rebrand)
**Backend:** Laravel 11/12 (PHP 8.3), Sanctum token auth, SQLite/MySQL
**Web frontend:** React 19 + Vite + Tailwind CSS v4 (Port 5173)
**Mobile frontend:** Expo SDK 54 / React Native 0.81 (enforcement field officers, offline-capable)
**API:** `/api/v1` (JSON, enveloped responses), full reference in `API.md`

> **Status:** Discovery + Valuation rows reflect the rebuilt **spec-v4 controlled-routing**
> backend. Other modules' rows may still describe the prior build and are pending
> re-verification.

---

## 1. System Overview

RETD_LRA is a three-tier property-tax administration platform:

1. **Laravel API backend** — the single source of business logic. Holds two "sources of truth":
   - **Master SSOT:** `property_bills` — canonical property/bill records synchronised with the legacy source system.
   - **Sub-SSOT:** `discovered_properties` and `registration_queue` — field discoveries that are later promoted into the master record.
2. **React web app** — office-facing console for all roles (login, dashboards, bills, valuations, assessments, payments, discovery routing, registration, appeals, enforcement management, M&E, notifications, staff targets, reports).
3. **Expo mobile app** — field-only companion for Enforcement Officers (my tasks, visits, property discovery, receipt capture) with SQLite offline outbox.

**Tech stack:** Laravel 11 (`bootstrap/app.php`), Sanctum bearer tokens, route-model binding with `{id}` params, auto-discovered policies, global `AuditableObserver` writing a SHA-256 chained audit log, `ApiResponseEnvelope` middleware for uniform `{success, data, message, errors}` responses, `CorsMiddleware` + `SecurityHeaders` middleware, `ThrottleRequests` on login.

**Totals:** 26 models · 20 API controllers · 18 form requests · 3 middleware · 1 console command · ~25 migrations · 3 seeders · 16 API test suites (308 tests green).

---

## 2. Module Map (endpoint → purpose)

| Module | Controller | Key endpoints | Notes |
|---|---|---|---|
| Auth | `Auth\api` (class `api`) | `POST /auth/login`, `GET /auth/me`, `POST /auth/logout`, `POST /auth/reset-password` | Login throttled `30/min`; reset-password is the first-login forced-reset hook |
| Property Bills | `PropertyBillsController` | `GET/POST/PUT/DELETE /property-bills`, `POST /{id}/delivery-stage`, `POST /{id}/amend`, `POST /source-webhook-simulate` | **Master SSOT.** Delivery state machine; amendments only for Statutorily Overdue bills; source-system sync writes only `outstanding_balance`/`payment_status` |
| Valuations | `ValuationsController` | `GET/POST /valuations`, `PUT /{id}`, `POST /{id}/begin` · `submit` · `review` · `assign` · `decide` · `account-review` · `processing` · `complete` · `descriptions` | Controlled 13-state workflow (spec v4): Assigned → … → Registered → Completed; anchors a bill **or** discovery or stands alone; mirrors linked discovery statuses; never generates Document # / Property ID |
| Tax Assessments | `TaxAssessmentsController` | CRUD | Requires Approved valuation; one active assessment per property |
| Tax Bills | `TaxBillsController` | CRUD | Requires Active assessment; one bill per assessment |
| Payments | `PaymentsController` | CRUD | Ledger of truth; `lockForUpdate`, recalculates bill ledger, syncs property `case_status` (Billed → Tax Cleared) |
| Payment Verification | `PaymentVerificationsController` | `POST /enforcement/submit-receipt`, `GET /account/payment-verifications`, `POST /{id}/review` | Officer captures receipt → Account & Records verifies → mirrors truth to property bill |
| Property Discovery | `DiscoveryController` (office) + `EnforcementController::discover` (field intake) | `GET/POST /discoveries`, `GET /discoveries/stats`, `GET /property-profile`, `POST /discoveries/{id}/submit` · `resubmit` · `acct-review` · `val-review` · `account-processing` · `complete` · `reopen`, `POST /enforcement/discover` | **Controlled routing (spec v4).** Auto-classification on submit (`config/discovery.php`) → Account & Record Manager queue (path `account`) or Valuation Manager queue (path `valuation`); desk tasks, AC approvals, A&R officer processing + bill update; record never duplicated |
| Registration Queue | *(superseded — see Property Discovery)* | — | Former promotion step; approved discoveries now pass **directly** to Account & Record processing (source-system identifiers recorded there) — no longer a routing destination |
| Appeals | `AppealsController` | CRUD + `POST /{id}/review` | AC decision; adjustment cascades to assessment + unsettled bills |
| M&E Queries | `MeQueriesController` | CRUD + `POST /{id}/respond`, `POST /{id}/close` | Monitoring & Evaluation tasking; assign/reassign/answer notifications |
| Staff Targets | `StaffTargetsController` | CRUD + `POST /{id}/refresh` | Restricts `achieved_value` to custom metrics |
| Enforcement Assignments | `EnforcementAssignmentsController` | `GET /`, `GET /my`, `POST /assign`, `POST /{id}/escalate`, `DELETE /{id}` | Escalation ladder: none → 30-day notice → 72-hr warning → litigation → closure → settlement |
| Enforcement Visits | `EnforcementVisitsController` | CRUD | Snapshots live bill state; M&E copy on Delivery; sets `last_visit_date` |
| Reports | `ReportsController` | `GET /reports/enforcement-activity`, `/reports/compliance`, `/reports/me-summary`, `GET /reports/{report}/export` (CSV) | Allowlist of roles; CSV export ignores date filters (bug) |
| Global Search | `BillRegisterController::search` | `GET /search` | Document #, Property ID, TIN, taxpayer, address |
| Unified Task Engine | `TaskController` | `GET /tasks`, `GET /tasks/my`, `POST /tasks/{task}/transition` · `assign` · `advance`, `POST /tasks/{task}/engagements` | Desk queue for every workflow stage (Discovery Review, Valuation Manager Review, Valuation Review, AC Approval, Account & Record Manager Review, Account & Record Processing, Bill Delivery); completions/advancement move records between desks; `lookup/statuses` exposes the full enum catalogue from one source |
| Dashboard | `DashboardController` + `DashboardDataController` | `GET /dashboard/data`, `/dashboard/my`, `/dashboard/division`, `/dashboard/analytics`, `/dashboard/officer`, `/dashboard/drill`, `/dashboard/integrity` | Role-aware KPIs + discovery/valuation pipelines (`path_a_pipeline`, `path_v_pipeline`); action centre; recent activity feed |
| Notifications | `NotificationsController` | `GET /notifications`, `GET /notifications/unread-count`, `POST /notifications/{id}/read`, `POST /notifications/read-all`, `POST /notifications/broadcast` | Broadcast = System Admin only, `500` bug on all-users branch |
| Users | `UsersController` | `GET /users`, `POST /users` | Admin or Account Manager; new users get `must_reset_password=true` (deadlock bug) |
| Attachments | `AttachmentsController` | `GET?attachable_type&attachable_id`, `POST /attachments`, `DELETE /{id}` | Polymorphic; **no authorization on store/index** |
| Taxpayer Portfolio | `TaxpayerPortfolioController` | `GET /taxpayers/{tin}/portfolio` | **Zero authorization** — any authed user can read any TIN |
| Audit Logs | `AuditLogsController` | `GET /audit-logs`, `GET /audit-logs/{id}` | Admin/M&E list; self-actor view |
| Lookups | route closures | `GET /tax-rates` (active), `GET /roles` | Any authenticated user |

---

## 3. Data Model

| Model | Purpose | Key attributes |
|---|---|---|
| `User` | Staff accounts | `role_id`, `must_reset_password`, `is_active`, `section`; hashed password |
| `Role` | RBAC role names | `name`, `guard_name` |
| `PropertyBill` | **Master SSOT** record | `source_billing_number`, `source_tin`, `source_property_id`, `property_address`, `case_status`, `outstanding_balance`, `payment_status`, `delivery_stage`, `account_staff_id`, soft-deletes. `SYNC_MANAGED = [outstanding_balance, payment_status]` excluded from direct writes |
| `PropertyBillOwner` | Owners on a bill | `tin`, `is_current`, `is_in_trust`, county/district/town, role |
| `Valuation` | Property valuation dossier (spec v4) | `VAL-YYYY-#####`, 13-state controlled status, `valuation_officer_id`, `discovery_id`/`bill_id` anchors, `total_property_value`/`total_tax_payable` roll-ups, manager/ac/account review trail, `registration_status`; never generates Document # / Property ID |
| `ValuationPropertyDescription` | Valuation description lines | `seq`, description → value roll-up via `computeValue()` |
| `ValuationReview` | Valuation review trail | stage transitions, actor, decision, remarks |
| `TaxAssessment` | Assessed annual tax | `annual_tax`, links Approved valuation |
| `TaxBill` | Tax bill billable | `bill_number` `TB-YYYY-#####`, adjusts with `recalculateFromPayments()` |
| `Payment` | Payment ledger | `source_reference` unique; recalculates TaxBill balance |
| `PaymentVerification` | Receipt verification queue | Pending/Approved/Rejected |
| `PropertyDiscovery` | Field discovery (Sub-SSOT, spec v4) | `ND-YYYY-#####`, controlled status vocabulary (Draft → … → Completed), `decision_path` (`account`/`valuation`), `classification_source`, `current_owner_id` (desk), `aarm_*`/`ac_*` review fields, `valuation_id` mirror, `document_number`/`property_id` (source-system IDs, set only at account-processing) |
| `RegistrationQueue` | *(superseded)* | former promotion queue; no longer a routing destination |
| `Task` | Unified desk task | `task_type` (Discovery Review, Valuation Manager Review, Valuation Review, AC Approval, Account & Record Manager Review, Account & Record Processing, Bill Delivery …), `assigned_to`, status + completion set, `reference_type`/`reference_id`, engagements |
| `EvidencePhoto` | GPS/camera evidence | `PHOTO-YYYY-#####`, morphable to discovery/valuation, gps metadata, disposition |
| `EnforcementAssignment` | Delinquent case + officer | escalation stage ladder, statuses incl. `Pending Payment Verification` |
| `EnforcementVisit` | Field visit snapshot | snapshot fields (`outstanding_balance`, `payment_status`, `case_status`), `VIS-YYYY-#####` |
| `EnforcementEvidence` | **Empty shell** (unused) | id + timestamps only |
| `Appeal` | Tax appeals | Submitted/Under Review/Upheld/Adjusted/Dismissed/Withdrawn, `APP-YYYY-#####` |
| `MeQuery` | M&E tasking | Open/Answered/Closed, `MEQ-YYYY-#####` |
| `StaffTarget` | Performance targets | collections_amount, valuations_completed_count, visits_count, custom |
| `Notification` | In-app alerts | unread scoping |
| `AuditLog` | Immutable audit trail | SHA-256 chain: `previous_hash` + `hash` |
| `Attachment` | Polymorphic documents | morphable to Valuation/PropertyBill/Appeal |
| `BillAmendmentLog` | Amendment ledger | statutorily-overdue amendments |
| `BillDeliveryLog` | **Empty shell (unused)** | not wired to `advanceDelivery` |
| `BillFollowupTask` | **Stub with no table** | not usable |
| `TaxRate` | Rate engine config | code/land_type/category/`rate_pct`/active |

---

## 4. Business-Logic Highlights

- **Controlled discovery routing (spec v4):** field capture → `Draft`; **auto-classification** on submit by `property_classification` (`config/discovery.php`) chooses `decision_path` (`account` → Account & Record Manager queue | `valuation` → Valuation Manager queue); every stage is a **desk task**; Document # / Property ID are recorded only from the source system at `account-processing`; the record is never duplicated and every transition is audited.
- **Valuation approval chain (spec v4):** Assigned → Assessment in Progress → Submitted to Manager → **Valuation Manager** confirms or returns → Submitted to AC → **AC** approves/returns/rejects → **Account Manager** final review (+ A&R officer) → **A&R officer** records source-system identifiers → Registered → Completed. Linked discoveries mirror the valuation status at every step.
- **Assessment→Bill→Payment:** Approved valuation → `annual_tax` assessment → tax bill → payments recalc the ledger → property `case_status` syncs (`Billed` when a payment lands — see bug list — then `Tax Cleared` at full settlement).
- **Receipt pipeline:** officer submits receipt → `PaymentVerification(Pending)` → Account & Records approves (mirrors amount onto the property bill + audit entry) or rejects (resumes enforcement).
- **Enforcement:** assign delinquent case → field visit (snapshot) → escalate through the ladder → `lra:statutory-overdue` sweep flips cases to `Statutorily Overdue` and notifies managers.
- **Amendments:** only on Statutorily Overdue bills; records base vs amended document, penalty/interest, immutable `BillAmendmentLog`.
- **Audit trail:** `AuditableObserver` registers on 9 models, writes a global SHA-256 previous-hash chain (see caveats in bug list).

---

## 5. Security Model (RBAC matrix)

Roles: **System Administrator**, **Valuation Officer**, **Valuation Manager**, **Assistant Commissioner**, **Account Manager**, **Enforcement Officer**, **Enforcement Manager**, **M&E Officer**.

| Resource | viewAny | create | update | delete / special |
|---|---|---|---|---|
| PropertyBill | Admin, AcctMgr, EnfMgr, M&E | Admin, AcctMgr | Admin (+AcctMgr if owner — dead branch) | Admin; delivery/amend = role checks |
| Valuation | AC, ValMgr, M&E, Admin | ValOfficer, Admin | ValMgr (assign/review), AC (decide), A&R Manager (account-review) | submit=valuation officer; begin/submit/descriptions=valuation officer; complete=A&R officer; processing=A&R officer |
| Assessment | Admin, AcctMgr, ValMgr, M&E | Admin, AcctMgr (+conditions) | — | Admin |
| TaxBill | Admin, AcctMgr, ValMgr, M&E | Admin, AcctMgr | — | Admin |
| Payment | Admin, AcctMgr, M&E, EnfMgr | Admin, AcctMgr | — | Admin (void) |
| Visit | Admin, EnfMgr, M&E | Admin, EnfMgr, EnfOfficer (if assigned) | Admin, EnfMgr, owner | Admin |
| Assignment | Admin, EnfMgr | Admin, EnfMgr | Admin, EnfMgr | escalate ladder |
| RegistrationQueue | *(superseded)* | — | — | — |
| Appeal | Admin, AcctMgr, ValOfficer | Admin, AcctMgr, ValOfficer | review = Admin/AC | Admin |
| MeQuery | Admin, M&E, ValOfficer | Admin, M&E, ValOfficer | managers/owners; respond; close | Admin |
| StaffTarget | managers | Admin, EnfMgr, AcctMgr, ValMgr | metric-scoped | Admin |
| Notification | own only | broadcast = Admin | own | — |
| AuditLog | Admin, M&E | — | — | self-actor view |
| Discovery | enforcement (create); desk owners (acct-review / val-review) | enforcement officer | A&R Manager (approve/refer), Valuation Manager (assign) | reopen=completed; account-processing=A&R officer; complete=A&R officer |
| **Attachment** | **any authed** | **any authed** | — | uploader/admin |
| **Taxpayer Portfolio** | **no check** | — | — | — |

Consistently implemented through `AuthServiceProvider` policy auto-discovery + explicit `Gate::authorize`/`can()` calls; discovery, verification, attachments and portfolio rely on inline/manual checks (some missing — see bugs 3–4).

---

## 6. Notifications & Automation

- Auth events create notifications on assignment reassignment, appeal review, verification outcomes, escalation, statutory-overdue sweep.
- `NotificationService::sendToRole` matches role **name strings exactly** — a miss silently no-ops.
- Web app polls `unread-count` every 30 s; sidebar badge updates.

---

## 7. Database / Migrations & Seeding

- ~25 migrations cover all models above plus `add_hash_to_audit_logs` (chain backfill, batch 13).
- `ConfigurationSeeder` initializes sections, roles, permissions and role grants without users or operational data. `DemoUserSeeder` creates only six development/test accounts; the System Administrator is provisioned separately with the authorized interactive command.
- Deliberately missing: `already_visited_reset_page` column referenced by login logic (bug 1).