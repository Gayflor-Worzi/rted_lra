# RETD_LRA System Analysis

**System:** Liberia Revenue Authority — Real Estate Tax Division (RETD) Property Tax Management System
**Version analysed:** current working build (Prompts 14–21 hardening + LRA rebrand)
**Backend:** Laravel 11/12 (PHP 8.3), Sanctum token auth, SQLite/MySQL
**Web frontend:** React 19 + Vite + Tailwind CSS v4 (Port 5173)
**Mobile frontend:** Expo SDK 54 / React Native 0.81 (enforcement field officers, offline-capable)
**API:** `/api/v1` (JSON, enveloped responses), full reference in `API.md`

---

## 1. System Overview

RETD_LRA is a three-tier property-tax administration platform:

1. **Laravel API backend** — the single source of business logic. Holds two "sources of truth":
   - **Master SSOT:** `property_bills` — canonical property/bill records synchronised with the legacy LITAS system.
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
| Property Bills | `PropertyBillsController` | `GET/POST/PUT/DELETE /property-bills`, `POST /{id}/delivery-stage`, `POST /{id}/amend`, `POST /litas-webhook-simulate` | **Master SSOT.** Delivery state machine; amendments only for Statutorily Overdue bills; LITAS sync writes only `outstanding_balance`/`payment_status` |
| Valuations | `ValuationsController` | CRUD + `POST /{id}/submit`, `POST /{id}/review` | Two-stage approval (Manager → Assistant Commissioner); anchors to a bill **or** a discovery (dual SSOT) |
| Tax Assessments | `TaxAssessmentsController` | CRUD | Requires Approved valuation; one active assessment per property |
| Tax Bills | `TaxBillsController` | CRUD | Requires Active assessment; one bill per assessment |
| Payments | `PaymentsController` | CRUD | Ledger of truth; `lockForUpdate`, recalculates bill ledger, syncs property `case_status` (Billed → Tax Cleared) |
| Payment Verification | `PaymentVerificationsController` | `POST /enforcement/submit-receipt`, `GET /account/payment-verifications`, `POST /{id}/review` | Officer captures receipt → Account & Records verifies → mirrors truth to property bill |
| Discovered Properties | `DiscoveredPropertiesController` | `GET /enforcement/discovered`, `POST /enforcement/discovered`, `POST /{id}/route` | Sub-SSOT intake; routing to Accounts (RegistrationQueue) or Valuations |
| Registration Queue | `RegistrationQueueController` | CRUD + `POST /{id}/claim`, `POST /{id}/complete`, `POST /{id}/reject` | Promotion step into the master SSOT |
| Appeals | `AppealsController` | CRUD + `POST /{id}/review` | AC decision; adjustment cascades to assessment + unsettled bills |
| M&E Queries | `MeQueriesController` | CRUD + `POST /{id}/respond`, `POST /{id}/close` | Monitoring & Evaluation tasking; assign/reassign/answer notifications |
| Staff Targets | `StaffTargetsController` | CRUD + `POST /{id}/refresh` | Restricts `achieved_value` to custom metrics |
| Enforcement Assignments | `EnforcementAssignmentsController` | `GET /`, `GET /my`, `POST /assign`, `POST /{id}/escalate`, `DELETE /{id}` | Escalation ladder: none → 30-day notice → 72-hr warning → litigation → closure → settlement |
| Enforcement Visits | `EnforcementVisitsController` | CRUD | Snapshots live bill state; M&E copy on Delivery; sets `last_visit_date` |
| Reports | `ReportsController` | `GET /reports/enforcement-activity`, `/reports/compliance`, `/reports/me-summary`, `GET /reports/{report}/export` (CSV) | Allowlist of roles; CSV export ignores date filters (bug) |
| Dashboard | `DashboardController` | `GET /dashboard/my` | Role-aware personal stats |
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
| `PropertyBill` | **Master SSOT** record | `litas_billing_number`, `litas_tin`, `litas_property_id`, `property_address`, `case_status`, `outstanding_balance`, `payment_status`, `delivery_stage`, `account_staff_id`, soft-deletes. `SYNC_MANAGED = [outstanding_balance, payment_status]` excluded from direct writes |
| `PropertyBillOwner` | Owners on a bill | `tin`, `is_current`, `is_in_trust`, county/district/town, role |
| `Valuation` | Property valuation dossier | Form1/Form2 frames, `valuation_number` (year-scoped), `land_type`, area, rate engine `applyLraRate()`/`recalculateTotals()`, `is_reassessment` |
| `ValuationItem` | Valuation line items | land/improvements splits |
| `ValuationApproval` | Approval trail | stage transitions, actor, notes |
| `TaxAssessment` | Assessed annual tax | `annual_tax`, links Approved valuation |
| `TaxBill` | Tax bill billable | `bill_number` `TB-YYYY-#####`, adjusts with `recalculateFromPayments()` |
| `Payment` | Payment ledger | `litas_reference` unique; recalculates TaxBill balance |
| `PaymentVerification` | Receipt verification queue | Pending/Approved/Rejected |
| `DiscoveredProperty` | Field discovery (Sub-SSOT) | statuses: Submitted_ME, Routed_Account, Routed_Valuation, Registered, Duplicate, Invalid, On_Hold, Contact_Made, Partly_Registered, Rejected |
| `RegistrationQueue` | Promotion queue | `RQ-YYYY-#####`, claim/complete/reject |
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

- **Dual SSOT flow:** LITAS bills live in `property_bills`; field discoveries live in `discovered_properties` → `route` pushes to `registration_queue` (accounts) or creates a draft `valuation` → `complete` validates unique `litas_billing_number` + trust documents and promotes a canonical master record.
- **Two-stage valuation approval:** Draft → Submitted (V.Officer submits, doc-compliance enforced) → Pending AC Approval (Manager forwards) → Approved/Rejected (AC decides). Stage-2 approval syncs parent case to `Valuation Approved` or promotes the associated discovery.
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
| Valuation | Admin, ValMgr, AC, M&E | Admin, ValOfficer | Admin, ValMgr, owning officer | Admin; submit=owner, review=Manager/AC |
| Assessment | Admin, AcctMgr, ValMgr, M&E | Admin, AcctMgr (+conditions) | — | Admin |
| TaxBill | Admin, AcctMgr, ValMgr, M&E | Admin, AcctMgr | — | Admin |
| Payment | Admin, AcctMgr, M&E, EnfMgr | Admin, AcctMgr | — | Admin (void) |
| Visit | Admin, EnfMgr, M&E | Admin, EnfMgr, EnfOfficer (if assigned) | Admin, EnfMgr, owner | Admin |
| Assignment | Admin, EnfMgr | Admin, EnfMgr | Admin, EnfMgr | escalate ladder |
| RegistrationQueue | Admin, AcctMgr, ValMgr, M&E | Admin, AcctMgr, ValMgr | claim/complete/reject | Admin |
| Appeal | Admin, AcctMgr, ValOfficer | Admin, AcctMgr, ValOfficer | review = Admin/AC | Admin |
| MeQuery | Admin, M&E, ValOfficer | Admin, M&E, ValOfficer | managers/owners; respond; close | Admin |
| StaffTarget | managers | Admin, EnfMgr, AcctMgr, ValMgr | metric-scoped | Admin |
| Notification | own only | broadcast = Admin | own | — |
| AuditLog | Admin, M&E | — | — | self-actor view |
| Discovery | routers only (index) | enforcement staff | route = routers | — |
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
- Seeders: roles, `tax_rates`, demo users (`wooze27@gmail.com` / `admin123` admin; `enf.officer@test.lra` / `officer123` enforcement), LRA demo dataset.
- Deliberately missing: `already_visited_reset_page` column referenced by login logic (bug 1).