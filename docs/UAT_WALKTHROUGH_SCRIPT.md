# RETD — UAT & Role Walkthrough Script (Gate 9)

Live sign-off companion for the readiness gates. Each role walks their own
workflow with the seeded demo accounts against a **fresh reset baseline**
(`php artisan app:reset-dev --force` before starting), then sign-offs are
recorded in the "Sign-off register" at the end.

Demo passwords are baseline-only; `wooze27@gmail.com` is seeded with a random
password unless `SEED_ADMIN_PASSWORD` is pinned in `.env`.

| Account type | Demo password |
|--------------|---------------|
| Account Manager / Account & Records Officer | `account123` |
| Assistant Commissioner, Enforcement Officer, Valuation Manager, Valuation Officer | `officer123` |

The roster, credentials and every expected (403) code below are pinned to the
seed baseline by `tests/Feature/UatBaselineCheckTest.php` — if any of them
drift, the suite fails before a walkthrough is attempted.

## 0. Refresh the baseline before walkthroughs

```bat
:: truncates business/audit tables, re-seeds users/roles/permissions/sections
php artisan app:reset-dev --force
php artisan lra:integrity-check   REM expect: healthy, 0 findings
```

## 1. Walkthrough duty roster

| Role | Login (email) | Walkthrough checklist |
|------|---------------|-----------------------|
| System Administrator | `wooze27@gmail.com` | G0/G2 admin, G5 security headers, audit chain |
| Assistant Commissioner | `ac@test.lra` | G6 analytics/KPI, reports, division drill-down |
| Account Manager | `account.manager@test.lra` | G1 account desk, G2 scope, confirm payments |
| Account & Records Officer | `account.officer@test.lra` | G1 log discovery→bill, receipt verification |
| Enforcement Officer | `enf.officer@test.lra` | G1 field visit/delivery, G3 evidence + GPS certify offline |
| Valuation Manager | `val.manager@test.lra` | G1 valuation review → submit to AC |
| Valuation Officer | `val.officer@test.lra` | G1 field valuation form, GPS, photos |

## 2. Gate-tag references

- **G1** = end-to-end workflow execution per role (web screens; mobile for field roles).
- **G2** = positive/negative RBAC: expected navigation is visible; **every
  disabled/403 expectation from `tests/Feature/RbacGate2SweepTest.php`** holds
  in the live session (see the negative table below).
- **G3** = offline field drill on a real Android device (ear-marked DEFT-005):
  1. Airplane mode on → capture evidence photo, GPS-certify a property, submit a
     payment receipt. Queue rows show **pending** and the `USD` amount labels.
  2. Reconnect → auto-flush. Verify the **Sync tab** shows synced states for
     `evidence`, `gps_certify`, `receipt_claim` rows.
  3. Kill the app while rows are still `pending`, reopen → stale `syncing` rows
     revive and flush. Submit the *same* photo twice → exactly **one** server row
     (no duplicate). Confirm the server ledger (`sync_transactions`) has one row
     per `local_transaction_id`.
- **G5** = security spot checks: all responses carry
  `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`,
  `Strict-Transport-Security`; 5 bad logins for one address in a minute →
  `429`; unknown/expired token → `401`; a deactivated account login → `403`;
  `receipt-file` and evidence `download` URLs for records outside the user's
  scope → `403`, and traversal-looking stored paths → `404`.
- **G6** = reconciliation: open each report (Bills, Collections, Enforcement,
  Valuations, Discoveries, Payment queue) and the dashboard analytics KPIs;
  every figure equals the authoritative count/sum (the
  `tests/Feature/ReportingReconciliationTest` asserts this automatically for a
  seeded subset; the walkthrough re-verifies it visually on live data).

## 3. Seeded negative (403) expectations for G2 walkthrough

| Actor attempting | Endpoint | Expected |
|------------------|----------|----------|
| Enforcement Officer | `/reports/bills` | 403 |
| Enforcement Officer | `/payments/queue` | 403 |
| Valuation Officer | `/dashboard/integrity` | 403 |
| Enforcement Officer | `/dashboard/integrity` | 403 |
| Account Officer | `/me/queries` | 403 |
| Enforcement Officer | `/me/queries` | 403 |
| Account Officer | GPS certify queue (`/gps/reviews`) | 403 |
| Valuation Officer | GPS certify queue (`/gps/reviews`) | 403 |

The authorization path is exercised with the **nearest staff role**, not a
random role, so every negative is a real separation-of-duties check.
The demo baseline ships exactly **7 accounts**; role behaviours of the removed
demo users (Enforcement Supervisor team scope, Valuation Supervisor first
review, M&E watchdog) remain covered by the automated suite in
`tests/Feature/Concerns/LocalDemoUsers.php`, which creates those role users
locally for the walkthrough-equivalent checks.

## 4. Sign-off register

| Gate | Role/lead | Date | Result (Pass / Fail) | Notes | Signed |
|------|-----------|------|----------------------|-------|--------|
| G1 business workflows | | | | | |
| G2 RBAC (positive + negative) | | | | | |
| G3 field sync drill (Android) | | | | | |
| G5 security spot checks | | | | | |
| G6 reporting reconciliation | | | | | |
| G9 UAT overall | | | | | |

---

## Closure conditions for Gate 10 (Go-Live)

- [ ] G4 mobile: real-device drill passed (DEFT-005 closed).
- [ ] G7 infrastructure: prod env built, `APP_ENV=production`, `APP_DEBUG=false`,
      TLS enabled, DB cluster reachable (DEFT-006/DEFT-007 closed).
- [ ] G8 recovery: restore rehearsal passed on prod infrastructure.
- [ ] G9: all role walkthroughs passed and signed above.
- [ ] Final `app:reset-dev --force` so the demo baseline is what ships to UAT/staging.