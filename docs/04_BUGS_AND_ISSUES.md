# RETD_LRA — Bugs & Functionality Issues Report

Severity legend: **[CRIT]** blocks a feature or is a security hole · **[HIGH]** breaks an important flow · **[MED]** inconsistency/UX debt · **[LOW]** cosmetic.

Every reference is `file:line`. Test suites: 16 suites / 308 tests green — the bugs below are **not** covered by the current automated tests.

---

## A. Security & access control

### A1 [CRIT] Taxpayer portfolio has zero authorization — `app/Http/Controllers/Api/TaxpayerPortfolioController.php:20`
`GET /taxpayers/{tin}/portfolio` aggregates bills, valuations, payments, outstanding balances, discoveries and pending verifications **for any TIN** with only an authenticated session. Any officer can enumerate any taxpayer's finances.
**Fix:** poly gate (Admin, Account Manager, Enforcement Manager, M&E) or own-officer attribution; add a policy `viewAny`.

### A2 [CRIT] Attachments are unauthenticated by role — `app/Http/Controllers/Api/AttachmentsController.php:23,53`
`GET /attachments?...` and `POST /attachments` have **no policy** — any authenticated user can upload arbitrary documents or list another record's files and see which compliance documents are missing.
**Fix:** add `AttachmentPolicy` (uploader-or-admin delete; view tied to the parent record's owner policy) and `Gate::authorize` in store/index.

### A3 [HIGH] Inactive users can still log in — `app/Http/Controllers/Auth/api.php:23-29`
Login never checks `is_active = true`. Deactivated staff continue to obtain tokens.
**Fix:** add `where('is_active', true)` (or explicit 403) to the login credential lookup.

### A4 [LOW] Login throttle comment mismatch — `routes/api.php:20-23`
Code says `throttle:30,1` (30/min) but the comment says 5/min. Align documentation (behavior is intentional).

---

## B. First-login / password-reset deadlock

### B1 [CRIT] New users can never access the system — `UsersController.php:78,96` + `Auth/api.php:31-41`
`store()` sets `must_reset_password=true`, and `login()` **withholds the token** when `must_reset_password && !already_visited_reset_page`. But:
- the `already_visited_reset_page` column **does not exist** (0 hits in migrations), so the condition is always true → **no token, forever**;
- `POST /auth/reset-password` requires a token to call (cyclic dependency — no token, can't reset);
- neither frontend has any reset-password screen.
The DatabaseSeeder even carries a comment about this (:41-42). Effect: **any account created via `/users` is bricked** until manually patched in the DB.
**Fix (backend):** add `already_visited_reset_page` boolean column, allow login to issue a token when reset is pending, and have `me()`/login signal `must_reset=True` so the client shows the reset form using that token. **Fix (frontends):** build the forced-reset UI (web + mobile) against `POST /auth/reset-password`.

---

## C. Mobile app ↔ backend contract mismatches (functionality broken)

The mobile app is currently **non-functional for its primary field operations** against this backend. Verified payload-vs-`Rules` comparisons:

### C1 [CRIT] Visits always fail validation — `mobile/src/screens/VisitFormScreen.jsx:49-58` vs `app/Http/Requests/VisitStoreRequest.php`
| Mobile sends | Backend expects | Result |
|---|---|---|
| `assignment_id` | `enforcement_assignment_id` | ignored → null |
| `status` ∈ {`Not Home` …} | `in: Planned, Completed, No Answer, Property Locked, Rescheduled, Cancelled` | **422 every time** |
| `bill_delivery_status: 'delivered'` (lowercase) | `in: Delivered, Not Delivered, Refused, Pending` | **422** |
| `notes` | `remarks` | silently dropped |
| `gps_lat` / `gps_lng` | `gps_coordinates` (one string) | ignored |

**Fix:** map fields in `VisitFormScreen` (or add them to the request): `enforcement_assignment_id`, a valid `status` value, `bill_delivery_status: 'Delivered'`, `remarks`, `gps_coordinates: "lat,lng"`.

### C2 [CRIT] Assignment "complete"/"escalate" hits a non-existent endpoint — `VisitFormScreen.jsx:61,75`
Mobile posts `POST /enforcement-assignments/{id}/action`. That route does **not exist** (`routes/api.php` only defines `/`, `/my`, `/{id}`, `/assign`, `/{id}/escalate`, `/{id}/destroy`). Both submit and escalate → 404.
**Fix:** add a `POST /{id}/action` route+controller (or reuse `/{id}/escalate` with `stage`, and a new complete endpoint).

### C3 [CRIT] Discovery uses the wrong route + wrong fields — `mobile/src/screens/DiscoverScreen.jsx:65` & `:56-62` vs `routes/api.php` (`enforcement/discovered`, plural) and `DiscoveredPropertiesController.php:66-75`
- POST to `/enforcement/discover` (singular) → **404**; backend is `/enforcement/discovered`.
- `property_classification` → backend `preliminary_classification`.
- Documents: mobile requires `property_photo, proof_of_ownership, nin_slip, cac_cert, utility_bill`; backend requires `property_photo, ownership_legal_document, owner_passport_photo, owner_official_id, property_schedule_form` → **the 4 mandated keys are missing, so even the fixed URL 422s**.
**Fix:** use `/enforcement/discovered`, rename the field, and align `DOC_KEYS` with the backend's five doc types.

### C4 [CRIT] Receipt submission fails validation — `mobile/src/screens/ReceiptScreen.jsx:28-34` vs `PaymentVerificationsController.php:32-37`
| Mobile sends | Backend expects | Result |
|---|---|---|
| _(nothing)_ | `property_bill_id` (required) | **422** |
| `amount` | `amount_paid` | ignored |
| `period` | `payment_period` | ignored |
| `receipt_photo` | `receipt_photo_path` | required||error |
**Fix:** include `property_bill_id` (the `bill.id` is already available via route params), and rename `amount → amount_paid`, `period → payment_period`, `receipt_photo → receipt_photo_path`.

### C5 [CRIT] Outbox destroys data on any non-network error — `mobile/src/sync.jsx:52-55`
On a sync attempt, only `ERR_NETWORK` breaks; **any other error (422/404/500) permanently deletes the queued row** and continues. Combined with C1–C4, every offline visit/discovery/receipt that syncs will be deleted. There is also no failed-queue UI/retry.
**Fix:** never delete on 4xx; mark as `failed` with the server's error message, keep for manual retry; only delete on confirmed success (or explicit user action).

### C6 [HIGH] must-reset login explodes silently — `mobile/src/auth.jsx` login destructure
Backend's reset-required response carries no `token`; the code stores `"undefined"` in kv and sets an `undefined` token. No reset screen exists. (Same root cause as B1.)

### C7 [LOW] `config.js` hardcodes the LAN IP — `mobile/src/config.js:3`
`http://10.0.27.12:8000` breaks on any network change. Move to `app.json`/`expo-constants`/env; document per-deployment.
### C8 [LOW] Dead/unused imports in mobile screens
`Alert`, duplicate `ActivityIndicator` (task/outbox screens), mid-file `import { ScrollView }` at `VisitFormScreen.jsx:147`, unused `statusBadge` in `theme.js`.

---

## D. Web app issues

### D1 [HIGH] BillDetail locks the whole page on any mutation error — `frontend/src/pages/BillDetail.jsx:57,70`
After any failed save, `err` is set and the early `if (err) return <ErrorBox/>` permanently replaces the entire bill view — no dismiss/retry path.
**Fix:** render `<ErrorBox/>` inline (not as a page replacement) and always show the content with a retry.

### D2 [HIGH] Missing password-reset screen (web) — `login` path
`auth.jsx` supports `mustReset`, but `Login.jsx` never routes to a reset form → same deadlock as B1.
### D3 [RESOLVED] Demo credentials hardcoded — `frontend/src/pages/Login.jsx:8-9`
`admin123` was removed from the codebase (Seeder now uses `SEED_ADMIN_PASSWORD`, random if unset) and the live admin password was rotated; no prefilled credentials remain.
### D4 [MED] Notifications endless spinner on request failure — `frontend/src/pages/Notifications.jsx`
`.catch(() => {})` leaves `rows === null` → spinner forever, no error or retry.
### D5 [MED] `dangerouslySetInnerHTML` on pagination labels — `frontend/src/pages/Bills.jsx`
Labels are server-rendered (safe today), but raw HTML injection should be replaced with plain text rendering.
### D6 [LOW] Unused `role` destructure — `frontend/src/pages/BillDetail.jsx:20`; dead imports (`Alert`, `ScrollView`, etc.) across pages.
### D7 [LOW] Dashboard omits M&E fields — backend returns `open_me_queries` and `delivered_bills_copies` but the dashboard never renders them.

---

## E. Backend logic bugs / data integrity

### E1 [HIGH] Debounced valuation-number collision — `ValuationsController.php:444-456` vs `DiscoveredPropertiesController.php:199-200`
`nextValuationNumber()` uses max+1 (year-scoped); discovery `route()` uses `withTrashed()->count()+1` across **all** years. Two systems can emit the same `valuation_number` → duplicate-key 500.
**Fix:** route both through one `nextValuationNumber()` helper.

### E2 [HIGH] Routed valuation drafts are stranded — `DiscoveredPropertiesController.php` (route)
`route()` creates the Draft valuation with only `prepared_by_id`; the officer can't edit/submit a draft they don't own (ValuationPolicy ownership), so only a Manager/Admin can unblock it. No guard or relation is set to a Valuation Officer.
**Fix:** set `valuation_officer_id` (e.g. to the router or an assigned officer) and/or allow the owning officer to claim.

### E3 [HIGH] Dead `account_staff_id` ownership logic — `PropertyBillPolicy.php:24,41`; `TaxBillPolicy.php:24,41`
The "owner" branches are unreachable because nothing ever sets `account_staff_id`. Consequences: Account Managers can't update bills, and assigned Enforcement/Valuation Officers can't view bills through the gates.
**Fix:** assign `account_staff_id` at bill creation (`PropertyBillsController::store`) and audit it.

### E4 [MED] "Billed" is set when money arrives, not when a bill is issued — `PaymentsController.php:131-132`
`case_status` flips to `Billed` only inside the payment handler; a freshly issued bill with zero payments never shows `Billed`.
**Fix:** set `case_status='Billed'` in the bill-issue path; keep `Tax Cleared` on settlement.

### E5 [MED] Audit-chain caveats — `AuditableObserver.php:57-65` + backfill migration
- `hash` omits `old_values` and `ip_address` (tamper scope incomplete).
- Backfill hashed JSON-string `new_values`; runtime hashes the array → future chain verification fails on pre-migration rows.
- Global chained hash without locking can fork under concurrent writes.
**Fix:** hash a canonical serialization (e.g. `json_encode` both), include `old_values`/`ip_address`, and verify + re-serialize the backfill.

### E6 [MED] CSV exports ignore date filters — `ReportsController.php:274-303`
JSON views honor `start_date`/`end_date`; CSV export does not. Inconsistent reporting. **Fix:** apply the same scopes to the export.

### E7 [MED] Envelope double-wraps native errors — `ApiResponseEnvelope.php:30-31`
Native Laravel 401/403/422 payloads (no `success` key) get wrapped with the error body inside `data` and `errors=null`, inconsistent with hand-rolled 403 shapes. **Fix:** detect native error status codes (4xx/5xx) and pass through.

### E8 [MED] Visit update can falsify snapshots — `EnforcementVisitsController::update` + `VisitUpdateRequest`
`outstanding_balance`, `payment_status`, `case_status`, and delivery fields are directly writable with no transition guard — a visit record can be edited to contradict the live bill. **Fix:** only auto-populate snapshots; disallow manual edits to them post-create.

### E9 [MED] Assignment store validates neither role nor duplicates — `AssignmentStoreRequest.php` + `EnforcementAssignmentsController::assign`
No check that the assignee is an Enforcement Officer; no guard against a parallel active assignment on the same bill. **Fix:** validate role and (un)assign existing active assignment first.

### E10 [MED] Discovery/verification rely on duplicated inline rules — `AttachmentsController::DOC_TYPES`
The doc-type lists live in two places (attachment controller vs discovery store) — drift risk (real today: the mobile `DOC_KEYS` are a third, incompatible list).

### E11 [LOW] `sendToRole` exact-name matching — `NotificationService`
Role names must match the DB string verbatim or notifications silently no-op.

### E12 [LOW] Dead scaffolds — `BillDeliveryLog`, `EnforcementEvidence`, `BillFollowupTask`
Tables/classes exist with no routes, no policy backing (e.g. `BillDeliveryLogPolicy` references non-existent `performed_by_id`; `AttachmentPolicy` references non-existent `label`). `advanceDelivery` writes nothing to `bill_delivery_log`.

### E13 [LOW] Stage-1 valuation review can only forward — `ValuationsController.php:325-332`
Managers can't reject at stage 1; rejection is AC-only (documented behavior, but likely needs a manager reject).

---

## F. Consolidated fix priority (recommended order)

| # | Fix | Severity |
|---|---|---|
| 1 | C5 — never delete outbox rows on 4xx (data loss) | CRIT |
| 2 | C1–C4 — align mobile payloads to backend contracts | CRIT |
| 3 | A1–A2 — authorize portfolio + attachments | CRIT |
| 4 | B1/C6 — unblock first-login reset (DB column + UI) | CRIT |
| 5 | C2 — add real assignment action/completion endpoint | CRIT |
| 6 | A3 — block inactive logins | HIGH |
| 7 | E1–E3 — valuation numbering, stranded drafts, account_staff_id | HIGH |
| 8 | D1 — BillDetail error recovery | HIGH |
| 9 | D2/D3 — reset-password screen, remove demo creds | HIGH/MED |
| 10 | E4–E13 — remaining backend/UX items | MED/LOW |

*Re-run the 16 PHP test suites (308 tests) after any backend change; validate mobile against the live API (see `04` note: tests do not currently cover the mobile contract).*