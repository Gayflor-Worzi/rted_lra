# RETD_LRA User Manual

> **Status:** Sections 4–6 (Valuations, Discovery & Routing, Registration Queue) reflect the
> rebuilt **spec-v4 controlled-routing** backend. Other sections may still describe the prior
> build and are pending re-verification.

Two applications are in use:

1. **Web console** (office staff — all roles): `http://localhost:5173`
2. **Mobile app** (Enforcement Officers in the field): Expo Go, URL `exp://<LAN-IP>:8081` (e.g. `exp://10.0.27.12:8081`)

Common logins (demo data):

| Role | Email | Password |
|---|---|---|
| System Administrator | `wooze27@gmail.com` | (set via `SEED_ADMIN_PASSWORD` on `db:seed`; random if unset) |
| Account & Records Officer | `account.officer@test.lra` | `account123` |
| Account Manager | `account.manager@test.lra` | `account123` |
| Assistant Commissioner | `ac@test.lra` | `officer123` |
| Valuation Manager | `val.manager@test.lra` | `officer123` |
| Valuation Officer | `val.officer@test.lra` | `officer123` |
| Enforcement Officer | `enf.officer@test.lra` | `officer123` |

---

## Part A — Web Console

### 1. Signing in / out
- Open `http://localhost:5173` → left panel is the LRA brand screen, right panel the sign-in form.
- Enter email + password → **Sign in**. You land on the Dashboard.
- The sidebar shows the modules you are allowed to see (driven by your role). Top-right avatar → **Log out** to end the session.
- A red badge on the bell shows unread notifications (refreshed every 30 s automatically).

### 2. Dashboard
- Personal statistics: open tasks, overdue tasks, escalated cases, unread notifications.
- Role-dependent panels: awaiting-your-review items, appeals awaiting decision (AC), pending registration queue, personal performance.

### 3. Property Bills (Master Records — Admin / Account Manager / Enforcement Manager / M&E)
- **List:** the Bills screen shows all property bills with pagination; the search box filters by address.
- **Open a bill** → Bill Detail shows owner, balances, payment status, case status.
- **Update case status** (Admin / Account Manager): change the dropdown and save.
- **Advance delivery stage:** click through the delivery state machine — `Logged → Out for Delivery → Delivered → Signed Return / Delivered via Email (Overseas) → Filed`. Delivery begins at the moment the bill is logged by the Accounts / Records Account Manager. For the overseas email step you may supply a return copy proof.
- **Amend a bill:** only permitted when the bill is `Statutorily Overdue`; enter the amended document reference, penalty and interest amounts, and notes.
- **Search** filters by address.

### 4. Valuations (Valuation Officer / Valuation Manager / Assistant Commissioner / Account Manager / A&R Officer / Admin / M&E)
- List filtered by status: **Awaiting Review** (Submitted/Under Manager Review) · **Awaiting AC** · **Awaiting Account & Record** · plus the raw controlled statuses.
- **Valuation Officer:** create a dossier (standalone, or anchored to a bill/discovery), add **property descriptions**, capture evidence photos, then **Begin** and **Submit** (compliance with required data is enforced).
- **Valuation Manager:** **assign** submissions to officers; on submission **confirm → AC** or **return** for correction.
- **Assistant Commissioner:** **approve / return / reject** AC-stage valuations.
- **Account Manager:** final **account review** — approve (assign an A&R officer to process the registration) or refer back.
- **Account & Records Officer:** mark the valuation **Registered** (records Document # / Property ID from the source system, never generated here), then **Complete**.
- Each step mirrors the linked discovery's status and pushes a desk task to the next desk.

### 5. Discovery & Routing (controlled routing — Enforcement Officer intake; Account Manager / Valuation Manager desks)
- **Discovery screen** lists field discoveries; field officers capture them via the mobile app (`POST /enforcement/discover`) as `Draft`.
- **Submit** a discovery and the system **auto-classifies** it by `property_classification`:
  - Residential / Vacant Land / Agricultural / Government / Community → routed to the **Account & Record Manager** queue (registration path).
  - Commercial / Industrial / Mixed Use / Institutional → routed to the **Valuation Manager** queue (valuation path).
- **Account & Record Manager:** **approve** (→ an A&R officer records the source-system identifiers and completes) or **refer to Valuation**.
- **Valuation Manager:** assign the discovery → creates a **Valuation** dossier; the discovery then tracks the valuation.
- Document # / Property ID are source-system identifiers — RETD only records them at the Account & Record step.

### 6. Registration Queue (superseded)
- The former registration-queue promotion step is **no longer a routing destination**. Approved discoveries pass directly to **Account & Record processing** (source-system identifiers recorded there). See sections 4–5.

### 7. Tax Assessments & Bills (Account Manager / Admin)
- Assessments: create against an **Approved** valuation with a non-null `annual_tax`; one active assessment per property.
- Bills: create against an **Active** assessment; one bill per assessment; system numbers them `TB-YYYY-#####`.

### 8. Payments (Account Manager / Admin)
- Record payments against bills (reference, amount, period). Overpayment and duplicate settlement are rejected; the ledger and `case_status` update automatically (`Billed` → `Tax Cleared`).

### 9. Appeals (Appeals screen — Admin / Account Manager / Valuation Officer)
- Create, edit, and review appeals. The AC reviews: **Uphold / Adjust / Dismiss**. An **Adjust** propagates the new `annual_tax` down to the assessment and all unsettled bills.
- Numbers format `APP-YYYY-#####`.

### 10. M&E Queries (Monitoring & Evaluation Officer / Admin / Valuation Officer)
- Open/answer/close queries against properties. Assign or reassign with automatic notifications. Numbers format `MEQ-YYYY-#####`.

### 11. Staff Targets (managers)
- Set targets (collections amount, valuations completed count, visits count, or custom). **Refresh** recomputes achieved values from live data; `achieved_value` is only manually editable for custom metrics.

### 12. Enforcement Management (Admin / Enforcement Manager)
- **Assignments:** create assignments of delinquent bills to officers; view all or escalate.
- **Escalation ladder:** None → 30-Day Notice → 72-Hour Warning → Litigation → Closure → Settlement (forward-only).
- **Visits:** review field visits, GIS/snapshot data, delivery status, and the automatic M&E copy.

### 13. Notifications (all roles)
- Bell icon → unread list → open/read individually or **mark all read**.

### 14. Users (Admin / Account Manager)
- Manage staff accounts: name, email, password, role, section. Account Managers may only create Enforcement/Valuation Officer accounts.
- New users are flagged for a forced password reset on first login.

### 15. Reports (Admin / M&E / Enforcement Manager / Account Manager / Valuation Manager / AC)
- `enforcement-activity`, `compliance`, `me-summary`; CSV export per report.

### 16. Audit Logs (Admin / M&E)
- Read-only, chronological audit trail of actions on audited records (SHA-256 chained).

---

## Part B — Mobile App (Enforcement Officers)

### 1. Connecting
1. Ensure the phone and the PC are on the **same Wi-Fi network**.
2. Open **Expo Go**. From the running developer console on the PC, scan the QR code (or type `exp://<LAN-IP>:8081`).
3. Log in with your Enforcement Officer credentials.

> If the office Wi-Fi IP changes, the app must be re-pointed — see Troubleshooting.

### 2. Screens
- **Tasks** — your assigned delinquent cases, fetched from `/enforcement-assignments/my`. Tap a task to open it.
- **Discover** — register an unregistered property found in the field. Required documents: Property Photo, Proof of Ownership, NIN Slip (CAC Certificate and Utility Bill optional). Capture GPS + photos, then **Submit**.
- **Sync / Outbox** — shows locally queued records and a **Sync Now** button. Records sync automatically when connectivity returns (NetInfo listener).

### 3. Field Visit flow
1. Open a task → **Enforcement Visit** form.
2. Choose the visit status, capture **GPS**, take photo evidence, add remarks.
3. **Save Visit & Complete Task** — records the visit and (intended to) complete the task.
4. **Escalate to Manager** — flags the case for escalation.
5. **Submit Payment Receipt** — for cases where the taxpayer paid: amount, period, receipt number, receipt photo.

### 4. Offline use
- If you are offline (or the server is unreachable), submissions are **queued in the local SQLite outbox** and flushed automatically when connectivity returns.
- Queued items appear in the Sync tab as **Pending**.

---

## Part C — Troubleshooting & Operations

| Symptom | Cause / Fix |
|---|---|
| App won't load / "network error" on login | Phone not on same network; or the API IP in `mobile/src/config.js` is stale. Update `API_BASE` to the current PC LAN IP and reload the bundle. |
| Web console not loading | Vite dev server must be running on port 5173; open `http://localhost:5173`. |
| API not responding at `http://<IP>:8000` | Laravel server PID must be bound to `0.0.0.0:8000`; check Windows Firewall allows TCP 8000 (rule `RETD_LRA_API_8000`) and 8081 (`RETD_LRA_METRO_8081`). |
| Metro bundle stale after edits | Press `r` in the expo terminal to reload the bundle; `j` opens the debugger. |
| Login works but no data | Confirm the account has the correct role and the module is visible in that role's menu. |

### Known feature gaps (see `04_BUGS_AND_ISSUES.md` for the full list)
- First-login forced password reset exists in the backend but has **no screen** in either frontend.
- Mobile visit / discovery / receipt payloads currently mismatch the backend API and will be rejected (422) or 404 — see the bugs report for the exact fixes.
- Offline-queued items that fail validation are **deleted from the outbox** (data loss) until the sync bug is fixed.