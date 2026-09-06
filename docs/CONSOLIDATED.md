# RETD_LRA — Consolidated System Analysis & User Manual

**System:** Liberia Revenue Authority — Real Estate Tax Division (RETD) Property Tax Management System
**Backend:** Laravel 11/12 (PHP 8.3) · API `/api/v1` · Sanctum token auth · full reference in `API.md`
**Web frontend:** React 19 + Vite + Tailwind CSS v4 · `http://localhost:5173`
**Mobile frontend:** Expo SDK 54 / React Native 0.81 (Enforcement Officers, offline-capable)
**Tests:** 16 API suites / 308 tests green

**Contents**
1. System Analysis (architecture, modules, data model, security)
2. Workflows (end-to-end with state machines)
3. User Manual (web console + mobile, role-by-role, troubleshooting)
4. Bugs & Functionality Issues (prioritized with fixes)

---

# PART 1 — SYSTEM ANALYSIS

## 1.1 Overview

RETD_LRA is a three-tier property-tax administration platform:

1. **Laravel API backend** — single source of business logic, holding two "sources of truth":
   - **Master SSOT:** `property_bills` — canonical property/bill records synchronised with the legacy LITAS system.
   - **Sub-SSOT:** `discovered_properties` + `registration_queue` — field discoveries later promoted into the master record.
2. **React web app** — office console for all roles (dashboard, bills, valuations, assessments, payments, discovery routing, registration, appeals, enforcement, M&E, notifications, staff targets, reports).
3. **Expo mobile app** — field companion for Enforcement Officers (tasks, visits, discovery, receipt capture) with SQLite offline outbox.

**Stack details:** `bootstrap/app.php` Laravel 11/12, Sanctum bearer tokens, `{id}` route-model binding, auto-discovered policies, global `AuditableObserver` (SHA-256 chained audit log), `ApiResponseEnvelope` middleware (`{success, data, message, errors}`), `CorsMiddleware` + `SecurityHeaders`, login `ThrottleRequests`.

**Totals:** 26 models · 20 API controllers · 18 form requests · 3 middleware · 1 console command · ~25 migrations · 3 seeders.

## 1.2 Module Map (endpoint → purpose)

| Module | Controller | Key endpoints | Notes |
|---|---|---|---|
| Auth | `Auth\api` | `POST /auth/login`, `GET /auth/me`, `POST /auth/logout`, `POST /auth/reset-password` | Login throttled 30/min; reset-password = first-login forced reset hook |
| Property Bills | `PropertyBillsController` | CRUD `/property-bills`, `POST /{id}/delivery-stage`, `POST /{id}/amend`, `POST /litas-webhook-simulate` | **Master SSOT.** Delivery state machine; amendments only for Statutorily Overdue; LITAS sync writes only `outstanding_balance`/`payment_status` |
| Valuations | `ValuationsController` | CRUD + `POST /{id}/submit`, `POST /{id}/review` | Two-stage approval (Manager → AC); anchors to bill **or** discovery (dual SSOT) |
| Tax Assessments | `TaxAssessmentsController` | CRUD | Requires Approved valuation; one active per property |
| Tax Bills | `TaxBillsController` | CRUD | Requires Active assessment; one per assessment; `TB-YYYY-#####` |
| Payments | `PaymentsController` | CRUD | Ledger of truth; `lockForUpdate`; recalculates bill, syncs `case_status` (Billed → Tax Cleared) |
| Payment Verification | `PaymentVerificationsController` | `POST /enforcement/submit-receipt`, `GET /account/payment-verifications`, `POST /{id}/review` | Officer captures receipt → Account & Records verifies → mirrors truth to property bill |
| Discovered Properties | `DiscoveredPropertiesController` | `GET/POST /enforcement/discovered`, `POST /{id}/route` | Sub-SSOT intake; routing to Accounts (RegistrationQueue) or Valuations |
| Registration Queue | `RegistrationQueueController` | CRUD + `POST /{id}/claim`, `POST /{id}/complete`, `POST /{id}/reject` | Promotion step into Master SSOT |
| Appeals | `AppealsController` | CRUD + `POST /{id}/review` | AC decision; adjustment cascades to assessment + unsettled bills |
| M&E Queries | `MeQueriesController` | CRUD + `POST /{id}/respond`, `POST /{id}/close` | M&E tasking with assign/reassign notifications |
| Staff Targets | `StaffTargetsController` | CRUD + `POST /{id}/refresh` | `achieved_value` restricted to custom metrics |
| Enforcement Assignments | `EnforcementAssignmentsController` | `GET /`, `GET /my`, `POST /assign`, `POST /{id}/escalate`, `DELETE /{id}` | Escalation ladder: none → 30-day → 72-hr warning → litigation → closure → settlement |
| Enforcement Visits | `EnforcementVisitsController` | CRUD | Snapshots live bill state; M&E copy on Delivery; sets `last_visit_date` |
| Reports | `ReportsController` | `GET /reports/enforcement-activity`, `/reports/compliance`, `/reports/me-summary`, `GET /reports/{report}/export` | Role allowlist; CSV export ignores date filters (bug) |
| Dashboard | `DashboardController` | `GET /dashboard/my` | Role-aware personal stats |
| Notifications | `NotificationsController` | list, `unread-count`, `{id}/read`, `read-all`, `broadcast` | Broadcast = System Admin only |
| Users | `UsersController` | `GET /users`, `POST /users` | Admin or Account Manager; new users flagged forced reset (deadlock bug) |
| Attachments | `AttachmentsController` | `GET/POST/DELETE /attachments` | Polymorphic; **no authorization on store/index** |
| Taxpayer Portfolio | `TaxpayerPortfolioController` | `GET /taxpayers/{tin}/portfolio` | **Zero authorization** |
| Audit Logs | `AuditLogsController` | `GET /audit-logs` | Admin/M&E list; self-actor view |
| Lookups | route closures | `GET /tax-rates`, `GET /roles` | Any authenticated user |

## 1.3 Data Model

| Model | Purpose | Key attributes |
|---|---|---|
| User | Staff accounts | `role_id`, `must_reset_password`, `is_active`, `section` |
| Role | RBAC roles | `name`, `guard_name` |
| PropertyBill | **Master SSOT** | `litas_billing_number/tin/property_id`, `property_address`, `case_status`, `outstanding_balance`, `payment_status`, `delivery_stage`, `account_staff_id`; `SYNC_MANAGED` fields excluded from direct writes |
| PropertyBillOwner | Owners | `tin`, `is_current`, `is_in_trust`, county/district/town, role |
| Valuation | Dossier | Form1/Form2, `valuation_number` (year-scoped), land type, rate engine, `is_reassessment` |
| ValuationItem / ValuationApproval | Lines / approval trail | stage, actor, notes |
| TaxAssessment / TaxBill | Assessed annual tax / billable | `annual_tax`; `TB-YYYY-#####`; ledger recalc |
| Payment | Payment ledger | `litas_reference` unique |
| PaymentVerification | Receipt queue | Pending/Approved/Rejected |
| DiscoveredProperty | Field discovery (Sub-SSOT) | Submitted_ME, Routed_Account/Valuation, Registered, Duplicate, Invalid, On_Hold, Contact_Made, Partly_Registered, Rejected |
| RegistrationQueue | Promotion queue | `RQ-YYYY-#####`, claim/complete/reject |
| EnforcementAssignment | Delinquent case + officer | escalation stages; incl. `Pending Payment Verification` |
| EnforcementVisit | Field visit snapshot | `VIS-YYYY-#####`; snapshot fields |
| EnforcementEvidence | **Empty shell** | id + timestamps only |
| Appeal | Tax appeals | Submitted/Under Review/Uphold/Adjust/Dismiss/Withdrawn; `APP-YYYY-#####` |
| MeQuery | M&E tasking | Open/Answered/Closed; `MEQ-YYYY-#####` |
| StaffTarget | Targets | collections, valuations count, visits count, custom |
| Notification | In-app alerts | unread scoping |
| AuditLog | Immutable audit trail | SHA-256 chain (`previous_hash` + `hash`) |
| Attachment | Polymorphic docs | morphable to Valuation/PropertyBill/Appeal |
| BillAmendmentLog | Amendment ledger | statutorily-overdue amendments |
| BillDeliveryLog / BillFollowupTask | **Unused stubs** | not wired; latter has no table |
| TaxRate | Rate engine config | code/land_type/category/`rate_pct`/active |

## 1.4 Business-Logic Highlights

- **Dual SSOT:** LITAS bills in `property_bills`; field discoveries in `discovered_properties` → route → `registration_queue` → complete (validates unique `litas_billing_number` + trust docs) → canonical master record created.
- **Two-stage valuation:** Draft → Submitted (submit enforces doc compliance) → Pending AC Approval (Manager forwards) → Approved/Rejected (AC decides; approval syncs case to `Valuation Approved` or promotes the discovery).
- **Assessment→Bill→Payment:** Approved valuation → assessment → tax bill → payments recalc ledger → `case_status` sync (`Billed` on first payment — see bugs — then `Tax Cleared`).
- **Receipt pipeline:** officer submits → `PaymentVerification(Pending)` (assignment paused) → Account & Records approve (mirror to master + audit) or reject (resume enforcement).
- **Enforcement:** assign delinquent → visits (snapshot) → escalate ladder → `lra:statutory-overdue` sweep flips to `Statutorily Overdue` + notifies managers.
- **Amendments:** statutorily-overdue only; immutable `BillAmendmentLog`; penalty/interest.
- **Audit trail:** `AuditableObserver` on 9 models, global SHA-256 previous-hash chain.

## 1.5 Security (RBAC matrix)

Roles: **System Administrator, Valuation Officer, Valuation Manager, Assistant Commissioner, Account Manager, Enforcement Officer, Enforcement Manager, M&E Officer.**

| Resource | viewAny | create | update | delete / special |
|---|---|---|---|---|
| PropertyBill | Admin, AcctMgr, EnfMgr, M&E | Admin, AcctMgr | Admin (+owner — dead branch) | Admin; delivery/amend role-checked |
| Valuation | Admin, ValMgr, AC, M&E | Admin, ValOfficer | Admin, ValMgr, owner | submit=owner; review=Manager/AC |
| Assessment | Admin, AcctMgr, ValMgr, M&E | Admin, AcctMgr (+conditions) | — | Admin |
| TaxBill | Admin, AcctMgr, ValMgr, M&E | Admin, AcctMgr | — | Admin |
| Payment | Admin, AcctMgr, M&E, EnfMgr | Admin, AcctMgr | — | Admin (void) |
| Visit | Admin, EnfMgr, M&E | Admin, EnfMgr, EnfOfficer (assigned) | Admin, EnfMgr, owner | Admin |
| Assignment | Admin, EnfMgr | Admin, EnfMgr | Admin, EnfMgr | escalate ladder |
| RegistrationQueue | Admin, AcctMgr, ValMgr, M&E | Admin, AcctMgr, ValMgr | claim/complete/reject | Admin |
| Appeal | Admin, AcctMgr, ValOfficer | Admin, AcctMgr, ValOfficer | review = Admin/AC | Admin |
| MeQuery | Admin, M&E, ValOfficer | Admin, M&E, ValOfficer | managers/owners; respond; close | Admin |
| StaffTarget | managers | Admin, EnfMgr, AcctMgr, ValMgr | metric-scoped | Admin |
| Notification | own only | broadcast = Admin | own | — |
| AuditLog | Admin, M&E | — | — | self-actor view |
| Discovery | routers only | enforcement staff | route = routers | — |
| **Attachment** | **any authed** | **any authed** | — | uploader/admin |
| **Taxpayer Portfolio** | **no check** | — | — | — |

---

# PART 2 — WORKFLOWS

## 2.1 Discovery to Master SSOT (registration)

```
Enforcement Officer (field) — POST /enforcement/discovered
  captures address, GPS, classification + 5 docs
    (property_photo, ownership_legal_document, owner_passport_photo,
     owner_official_id, property_schedule_form)
        ▼
DiscoveredProperty [Submitted_ME]
  routed by Admin/M&E/Enforcement Manager — POST /discovered/{id}/route
    ├─ "accounts"  → RegistrationQueue [Pending]
    └─ "valuation" → Draft Valuation  (→ 2-stage approval → may auto-promote)
        ▼
RegistrationQueue [Pending]  ← claim (POST /{id}/claim)
        ▼
Account Manager completes — POST /{id}/complete
  { litas_tin, litas_property_id, litas_billing_number, notes }
  validates unique billing number + trust docs
        ▼
PropertyBill [Master SSOT]  (promoteToMasterSSOT → Registered)
```

Guards: open duplicate entry rejected; unique `litas_billing_number` on completion.

## 2.2 Valuation Approval (two-stage)

```
V.Officer create (anchor bill OR Routed_Valuation) → submit → [Submitted]
V.Manager review {action:"forward"} ──────────────► [Pending AC Approval]
AC review {action:"approve"|"reject", decision_notes}
        ▼
 Approved → parent case_status="Valuation Approved"
            OR discovery promoted to RegistrationQueue
```

Rate engine: `applyLraRate()` → `recalculateTotals()`; numbers year-scoped.

## 2.3 Assessment → Bill → Payment

```
Approved valuation → TaxAssessment (annual_tax≠null, one/property)
→ TaxBill (TB-YYYY-#####, one/assessment)
→ Payment recorded (lockForUpdate, overpay/duplicate rejected) → recalc
→ PropertyBill.case_status: "Billed" (first payment) / "Tax Cleared" (settled)
→ notify officers on the case; compliance report reflects reduction
```

## 2.4 Enforcement — Assignment, Visit, Escalation

```
Admin/EnfMgr assign (POST /enforcement-assignments/assign) → [Assigned]
  officer views via GET /enforcement-assignments/my
Field officer POST /enforcement-visits (snapshot; M&E copy if delivered; last_visit_date)
  ├─ escalate ladder (forward-only, POST /{id}/escalate):
  │    None → 30-Day Notice → 72-Hr Warning → Litigation → Closure → Settlement
  └─ payment in field: POST /enforcement/submit-receipt → PV[Pending]
       (assignment paused) → Account&Records review:
         approve = mirror amount to property bill + audit "payment_verified"
         reject  = resume enforcement
artisan lra:statutory-overdue
   → eligible bills [Statutorily Overdue] + notify EnfMgr & M&E
```

## 2.5 Bill Delivery

```
Logged → Out for Delivery → Delivered
   ├─ Signed Return Received → Filed
   └─ Delivered via Email (Overseas) → Filed
        (email + CBL wire instructions; audit "overseas_email_dispatched")
```

Transitions via `POST /property-bills/{id}/delivery-stage`, role-checked.

## 2.6 Amendments (statutorily-overdue bills only)

```
POST /property-bills/{id}/amend {amended_document_id, penalty_amount, interest_amount, notes}
→ BillAmendmentLog (immutable) + audit "bill_amended"
 ```

## 2.7 Appeals

```
Create (APP-YYYY-#####) → [Submitted] → [Under Review]
AC review: Uphold / Adjust / Dismiss (Withdrawn possible)
Adjust → annual_tax propagates to assessment + unsettled TaxBills
```

## 2.8 M&E Queries

```
Raise (MEQ-YYYY-#####) → assign/reassign (notification) → [Open]
Officer respond → [Answered] → close (raiser/M&E/Admin) → [Closed]
```

## 2.9 Support processes

- **Users:** Admin/Account Manager create → `must_reset_password=true`. Intended: first login forces a reset via `POST /auth/reset-password`. **Frontends currently have no reset screen — blocked feature (bug B1).**
- **Staff targets:** manager creates; `POST /staff-targets/{id}/refresh` recomputes achieved from live data; `achieved_value` manual only for custom metrics.
- **Reporting:** `/reports/enforcement-activity|compliance|me-summary` JSON; CSV via `/reports/{report}/export` (note: CSV ignores date filters — bug E6).
- **Audit & notifications:** actions on the 9 audited models write SHA-256-chained `AuditLog`; notifications fire on reassignment, appeal review, verification outcome, escalation, statutory-overdue sweep, broadcast (System Admin).

---

# PART 3 — USER MANUAL

## 3.1 Common logins (demo data)

| Role | Email | Password |
|---|---|---|
| System Administrator | `wooze27@gmail.com` | (set via `SEED_ADMIN_PASSWORD` on `db:seed`; random if unset) |
| Enforcement Officer | `enf.officer@test.lra` | `officer123` |

**Access:** Web console `http://localhost:5173` · Mobile: Expo Go, `exp://<LAN-IP>:8081` (same Wi-Fi as the PC).

## 3.2 Web console — all roles

**Sign in/out:** open `http://localhost:5173` (LRA brand screen + sign-in) → Dashboard. Sidebar shows only your allowed modules; red badge on the bell = unread notifications (auto-refresh 30 s). Top-right avatar → Log out.

**Dashboard:** open tasks, overdue, escalated, unread notifications, plus role panels (awaiting review, appeals for decision, pending registration, performance).

**Property Bills (Admin / Account Mgr / Enf Mgr / M&E):** Bills screen = list + address search + pagination. Bill Detail shows owner/balances/status. Update case status (Admin/AcctMgr). **Advance delivery** through the state machine (above; overseas step optionally attaches return-copy proof). **Amend** only when `Statutorily Overdue` (amended doc ref + penalty/interest + notes).

**Valuations (V.Officer / V.Manager / AC / Admin / M&E):** tabbed list by status. V.Officer = create (anchor bill or Routed_Valuation discovery), line items, submit (document compliance enforced). V.Manager = review & forward to AC. AC = approve/reject (syncs case or promotes discovery).

**Discovery & Routing (Admin / M&E / EnfMgr route):** Discovery screen lists `Submitted_ME`; route each to **Valuation** (draft) or **Accounts** (registration queue).

**Registration Queue (Admin / AcctMgr / ValMgr / M&E):** claim → complete (LITAS identifiers, promotes to Master SSOT) → or reject with reason.

**Assessments & Bills (AcctMgr / Admin):** assessment against an **Approved** valuation (`annual_tax` ≠ null; one per property); bill against an **Active** assessment (`TB-YYYY-#####`; one per assessment).

**Payments (AcctMgr / Admin):** record payments (reference/amount/period); overpayment & duplicates rejected; ledger + `case_status` update automatically.

**Appeals (Admin / AcctMgr / V.Officer):** create/edit/review; AC = Uphold/Adjust/Dismiss. Adjust cascades annual tax down. `APP-YYYY-#####`.

**M&E Queries (M&E / Admin / V.Officer):** open/answer/close; assign with notifications. `MEQ-YYYY-#####`.

**Staff Targets (managers):** set targets; **Refresh** recomputes achieved from live data; manual `achieved_value` only for custom metrics.

**Enforcement (Admin / EnfMgr):** create assignments; escalate (ladder, forward-only); review visits (snapshot/GIS/delivery + automatic M&E copy).

**Notifications:** bell → unread → open/read or mark-all-read.

**Users (Admin / AcctMgr):** create staff (name/email/password/role/section); AcctMgr limited to Enforcement/Valuation Officer roles. New users flagged for forced reset on first login.

**Reports:** enforcement-activity, compliance, me-summary (+CSV export). **Audit Logs:** read-only chronological SHA-256-chained trail (Admin/M&E).

## 3.3 Mobile app — Enforcement Officers

**Connect:** same Wi-Fi → Expo Go → scan QR (or `exp://<LAN-IP>:8081`) → log in.

**Screens:** **Tasks** = your cases (`/enforcement-assignments/my`); **Discover** = register unregistered property (address, classification, GPS + 5 documents, submit); **Sync/Outbox** = queued records + Sync Now (auto-flush on reconnect).

**Field visit:** open task → visit form → status, GPS, photo evidence, remarks → **Save Visit & Complete Task** or **Escalate to Manager**; **Submit Payment Receipt** when the taxpayer paid (amount/period/receipt number/photo).

**Offline:** offline submissions queue in local SQLite; auto-flush when reconnected; queued items show **Pending**.

## 3.4 Troubleshooting & operations

| Symptom | Cause / Fix |
|---|---|
| App won't load / network error on login | Not on same network, or `mobile/src/config.js` API_BASE IP stale → update to current PC LAN IP + reload bundle |
| Web console not loading | Vite on 5173 must be running; open `http://localhost:5173` |
| API unreachable on `http://<IP>:8000` | Laravel bound `0.0.0.0:8000`; firewall rules `RETD_LRA_API_8000` / `RETD_LRA_METRO_8081` (TCP, any profile) |
| Stale Metro bundle | Press `r` in the expo terminal to reload; `j` opens debugger |
| Login OK but no data | Confirm role grants the module |

**Current known gaps:** forced-reset screen missing (B1); mobile visit/discovery/receipt payloads reject (C1–C4); offline-queued failures deleted from outbox (C5).

---

# PART 4 — BUGS & FUNCTIONALITY ISSUES

Severity: **[CRIT]** blocks a feature/security · **[HIGH]** breaks an important flow · **[MED]** inconsistency · **[LOW]** cosmetic.

## A. Security & access control
- **A1 [CRIT]** Taxpayer portfolio has **zero authorization** — `TaxpayerPortfolioController.php:20` exposes any TIN's bills/payments/outstanding/verifications to any authed user. *Fix: policy gate.*
- **A2 [CRIT]** Attachments — `AttachmentsController.php:23,53` store/index have no policy; any role uploads/lists docs & sees missing-compliance for any record. *Fix: AttachmentPolicy.*
- **A3 [HIGH]** Inactive users can still log in — `Auth/api.php:23-29` never checks `is_active`.
- **A4 [LOW]** Login throttle comment says 5/min, code is 30/min — `routes/api.php:20-23`.

## B. First-login / password-reset deadlock
- **B1 [CRIT]** New users can never access the system — `UsersController.php:78,96` sets `must_reset_password=true`; `Auth/api.php:31-41` withholds the token whenever `must_reset_password && !already_visited_reset_page`, but the **`already_visited_reset_page` column does not exist** → no token forever; `POST /auth/reset-password` needs a token (cyclic), and neither frontend has a reset screen. *Fix: add the column, issue token on reset-pending login, build reset UI.*

## C. Mobile app ↔ backend contract mismatches
- **C1 [CRIT]** Visits always fail validation — `VisitFormScreen.jsx:49-58` sends `assignment_id` (expects `enforcement_assignment_id`), status values outside allowed set, `bill_delivery_status:'delivered'` (expects `Delivered`), `notes` (expects `remarks`), `gps_lat/lng` (expects `gps_coordinates` string). → **422 every time**.
- **C2 [CRIT]** Assignment "complete"/"escalate" hits non-existent `POST /enforcement-assignments/{id}/action` — `VisitFormScreen.jsx:61,75` → **404**. Route doesn't exist.
- **C3 [CRIT]** Discovery wrong route + fields — `DiscoverScreen.jsx:65` posts `/enforcement/discover` (backend `/enforcement/discovered`), `property_classification` (expects `preliminary_classification`), and doc keys `proof_of_ownership/nin_slip/cac_cert/utility_bill` vs backend's `ownership_legal_document/owner_passport_photo/owner_official_id/property_schedule_form` → **404 + 422**.
- **C4 [CRIT]** Receipt fails validation — `ReceiptScreen.jsx:28-34` omits required `property_bill_id`; sends `amount/period/receipt_photo` (expects `amount_paid/payment_period/receipt_photo_path`) → **422**.
- **C5 [CRIT]** Outbox **destroys data on any non-network error** — `sync.jsx:52-55` deletes queued rows on any 4xx/5xx (with C1–C4, every queued visit/discovery/receipt gets deleted). No failed-queue UI/retry.
- **C6 [HIGH]** must-reset login stores `"undefined"` token — `mobile/src/auth.jsx`.
- **C7 [LOW]** `config.js:3` hardcodes LAN IP `http://10.0.27.12:8000`.
- **C8 [LOW]** Dead/unused imports (Alert, duplicate ActivityIndicator, mid-file `import { ScrollView }` at `VisitFormScreen.jsx:147`).

## D. Web app issues
- **D1 [HIGH]** BillDetail locks the page on any mutation error — `BillDetail.jsx:57,70` error replaces the whole view; no retry/dismiss.
- **D2 [HIGH]** No reset-password screen (web) — same root as B1.
- **D3 [MED]** Demo credentials hardcoded — `Login.jsx:8-9`.
- **D4 [MED]** Notifications endless spinner on request failure — `.catch(() => {})` keeps `rows === null`.
- **D5 [MED]** `dangerouslySetInnerHTML` on pagination labels — `Bills.jsx` (safe today, avoid raw HTML).
- **D6 [LOW]** Unused `role` destructure — `BillDetail.jsx:20`; dead imports across pages.
- **D7 [LOW]** Dashboard omits M&E fields (`open_me_queries`, `delivered_bills_copies`).

## E. Backend logic / data integrity
- **E1 [HIGH]** Valuation-number collision — `ValuationsController.php:444-456` (year max+1) vs `DiscoveredPropertiesController.php:199-200` (`count()+1` all years) → duplicate-key 500.
- **E2 [HIGH]** Routed valuation drafts stranded — `route()` sets only `prepared_by_id`; officer can't edit/submit (ownership policy) → only Manager/Admin can unblock.
- **E3 [HIGH]** Dead `account_staff_id` owner logic — `PropertyBillPolicy.php:24,41`, `TaxBillPolicy.php:24,41`; nothing sets it, so owner branches never fire.
- **E4 [MED]** `case_status='Billed'` set on payment, not bill issue — `PaymentsController.php:131-132`.
- **E5 [MED]** Audit-chain caveats — `AuditableObserver.php:57-65`: hash omits `old_values`/`ip_address`; backfill hashes JSON-string vs array at runtime (verification mismatch); no locking (fork risk).
- **E6 [MED]** CSV exports ignore date filters — `ReportsController.php:274-303`.
- **E7 [MED]** Envelope double-wraps native 4xx — `ApiResponseEnvelope.php:30-31` (error body inside `data`, `errors=null`).
- **E8 [MED]** Visit update can falsify snapshots (`outstanding_balance`, `payment_status`, `case_status`) — no transition guard.
- **E9 [MED]** Assignment store validates neither officer role nor duplicate/parallel active assignments.
- **E10 [MED]** Duplicated doc-type lists (attachments controller vs discovery store) enable drift (mobile keys are a third, incompatible list).
- **E11 [LOW]** `sendToRole` exact name matching — a typo silently no-ops.
- **E12 [LOW]** Dead scaffolds — `BillDeliveryLog`, `EnforcementEvidence`, `BillFollowupTask` (no table); policies reference non-existent columns.
- **E13 [LOW]** Stage-1 valuation review can only forward — managers can't reject at stage 1 (AC-only).

## F. Consolidated fix priority

| # | Fix | Severity |
|---|---|---|
| 1 | C5 — never delete outbox rows on 4xx (data loss) | CRIT |
| 2 | C1–C4 — align mobile payloads to backend contracts | CRIT |
| 3 | A1–A2 — authorize portfolio + attachments | CRIT |
| 4 | B1/C6 — unblock first-login reset (DB column + UI) | CRIT |
| 5 | C2 — real assignment action/completion endpoint | CRIT |
| 6 | A3 — block inactive logins | HIGH |
| 7 | E1–E3 — valuation numbering, stranded drafts, account_staff_id | HIGH |
| 8 | D1 — BillDetail error recovery | HIGH |
| 9 | D2/D3 — reset-password screen; remove demo creds | HIGH/MED |
| 10 | E4–E13 — remaining backend/UX items | MED/LOW |

*Re-run the 16 PHP test suites (308 tests) after any backend change. Tests currently do not exercise the mobile contract — add contract tests.*