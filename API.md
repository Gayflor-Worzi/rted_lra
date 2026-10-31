# RETD_LRA — REST API Reference

**Base URL:** `http://your-host:8000/api/v1`  
**Authentication:** Bearer token via `Authorization: Bearer {token}` header  
**Content-Type:** `application/json` for all requests

---

## Envelope

All responses are wrapped in:

```json
{
  "success": true,
  "data": { ... },
  "message": "optional human note"
}
```

Errors return `success: false` with a `message` field.

---

## 1. Authentication

### `POST /auth/login`

**Body:**
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| email | string | yes | User email |
| password | string | yes | Plain-text password |

**Response (200):**
```json
{
  "data": {
    "user": { "id": 1, "name": "Admin", "email": "admin@lra.gov.lr", "role": "Admin", "role_id": 1 },
    "token": "eyJ..."
  }
}
```

**Errors:** 422 (validation), 401 (wrong credentials), 403 (disabled account)

---

### `POST /auth/logout`

**Headers:** `Authorization: Bearer {token}`  
**Response:** `200 { "message": "Logged out" }`

---

### `GET /auth/me`

Returns the authenticated user profile.

**Response (200):**
```json
{
  "data": {
    "id": 1, "name": "Admin", "email": "admin@lra.gov.lr",
    "role": "Admin", "role_id": 1, "section": null,
    "is_active": true, "first_login": false
  }
}
```

---

## 2. Users

### `GET /users`

**Query:** `role_id`, `section`, `per_page` (default 50)  
**Response:** Paginated user list.

---

### `POST /users`

**RBAC:** Admin, Assistant Commissioner, Account Manager (restricted to Officer roles only)

**Body:**
| Field | Type | Required |
|-------|------|----------|
| name | string | yes |
| email | string | yes |
| password | string | yes (min 8) |
| role_id | integer | yes |
| section | string | no |

**Response:** Created user object (password excluded).

---

## 3. Roles

### `GET /roles`

**Response:** Array of `{ id, name, description }`.

---

## 4. Property Bills

### `GET /property-bills`

**Query:** `address` (search), `approval_status`, `case_status`, `payment_status`, `per_page`, `page`

**Response:** Paginated bills with `bill_number`, `property_address`, `case_status`, `approval_status`, `payment_status`, `delivery_stage`.

---

### `GET /property-bills/{id}`

**Response:** Full bill object including `current_owner`, `valuations` (if authorized), `penalty_amount`, `interest_amount`, `final_tax_due`, `outstanding_balance`.

**RBAC note:** `valuations` key is only included for Admin, AC, Valuation Manager/Officer roles.

---

### `PUT /property-bills/{id}`

**RBAC:** M&E Officer, Enforcement Manager, Admin

**Body:** `{ "case_status": "New" | "Valuation In Progress" | ... }`

---

### `POST /property-bills/{id}/amend`

**RBAC:** Account Manager (only on Statutorily Overdue bills)

**Body:**
| Field | Type | Required |
|-------|------|----------|
| amended_document_id | string | yes |
| penalty_amount | number | yes (≥0) |
| interest_amount | number | yes (≥0) |

**Response:** Updated bill + `{ "breakdown": { "final_tax_due": ... } }`

---

## 5. Valuations

### `GET /valuations`

**Query:** `status` (Submitted, Pending AC Approval, Approved, Rejected), `per_page`

---

### `GET /valuations/{id}`

**Response:** Valuation with `items` array (line items).

---

### `POST /valuations`

**RBAC:** Valuation Manager  
**Body:** `{ property_bill_id, total_value, declared_value, ... }`  
**Response:** Created valuation (status: Submitted).

---

### `POST /valuations/{id}/submit`

Transitions draft → Submitted.

---

### `POST /valuations/{id}/review`

**RBAC:** Valuation Manager (recommend), Assistant Commissioner (approve/reject)

**Body:**
| Field | Type | Required |
|-------|------|----------|
| action | string | "approve" or "reject" |
| decision_notes | string | required if rejecting |

---

## 6. Enforcement

### `GET /enforcement-assignments/my`

Returns the authenticated officer's current assignments with nested `property_bill`.

---

### `POST /enforcement-assignments/{id}/action`

**Body:**
| Field | Type | Required |
|-------|------|----------|
| action | string | "completed", "complete", or "escalate" |
| visit_date | string | YYYY-MM-DD |
| notes | string | no |

Bill Delivery tasks may be completed only when their latest recorded visit has a
`Delivered` delivery outcome. Recording the visit advances the task and bill;
clients should not submit a separate completion action after the visit.

---

### `POST /enforcement-visits`

**Body:**
| Field | Type | Required |
|-------|------|----------|
| assignment_id | integer | yes |
| property_bill_id | integer | yes |
| status | string | yes (see frontend) |
| bill_delivery_status | string | no; `Delivered`, `Out for Delivery`, `Returned`, `Undelivered`, or `Not Mailable` |
| gps_lat | number | no |
| gps_lng | number | no |
| proof_photo | string | no (file path) |
| notes | string | no |

The legacy `Returned to Office` value is normalized to `Returned`.

### Enforcement organization management

- `GET /enforcement/organization` — returns tax regions, tax districts, teams, active memberships, officers, and recommendations. Results are scoped for Enforcement Managers and regional Enforcement Supervisors. The Assistant Commissioner and System Administrator can view the organization data; the Administrator does not receive approval authority.
- `POST /enforcement/organization/recommendations` — submits an organization change. Enforcement Managers can apply officer membership changes and regional Supervisor assignments directly. Structural changes and Manager-submitted team changes await Assistant Commissioner approval. Supervisor-submitted membership or team changes await the responsible Enforcement Manager.
- `POST /enforcement/organization/recommendations/{id}/approve` — the responsible Enforcement Manager reviews Supervisor membership/team recommendations for their teams; the Assistant Commissioner reviews structural changes and Manager-submitted team changes.
- `POST /enforcement/organization/recommendations/{id}/reject` — the corresponding reviewer rejects a recommendation. A reason is required.

Each recommendation includes a `can_review` boolean calculated for the authenticated
reviewer, so clients can show review controls only when the server recognizes the
reviewer and organization scope as authorized.

Supported `change_type` values:

| Change type | Key fields | Review and effect |
|---|---|---|
| `create_region` | `name`, `code`, `classification` (`Rural` or `Urban`), optional `description`, `reason` | Manager submits; Assistant Commissioner approves |
| `update_region` | `region_id`, optional `name`, `code`, `classification`, `description`, `is_active`, `reason` | Manager submits; Assistant Commissioner approves |
| `assign_region_supervisor` | `region_id`, `supervisor_id`, optional `reason` | Manager applies immediately; one active primary Supervisor is assigned to the region |
| `create_district` | `name`, optional `reason` | Manager submits; Assistant Commissioner approves |
| `update_district` | `district_id`, optional `name`, `is_active`, `reason` | Manager submits; Assistant Commissioner approves |
| `assign_districts_to_region` | `region_id`, `district_ids[]`, optional `reason` | Manager submits; Assistant Commissioner approves |
| `create_team` | `name`, `district_ids[]`, `members[]`, optional `reason` | Manager submits; Assistant Commissioner approves |
| `update_team` | `team_id`, optional `name`, `district_ids[]`, `is_active`, `reason` | Manager submits for Assistant Commissioner approval; regional Supervisor submits for responsible Manager approval |
| `assign_officer` | `team_id`, `officer_id`, `primary_district_id`, `is_team_lead` | Manager applies immediately; Supervisor submits for responsible Manager approval |
| `remove_officer` | `team_id`, `officer_id` | Manager applies immediately; Supervisor submits for responsible Manager approval |

Each `create_team` request must include at least one initial `members` entry and
exactly one Team Lead, for example:

```json
{
  "change_type": "create_team",
  "name": "Central Team",
  "district_ids": [12, 13],
  "members": [
    { "officer_id": 41, "primary_district_id": 12, "is_team_lead": true },
    { "officer_id": 42, "primary_district_id": 13, "is_team_lead": false }
  ]
}
```

All districts selected for a team must be active and assigned to the same
active Tax Region; the region's active Supervisor is inherited by the team.
Existing districts are not automatically classified or assigned to a region.
Administrators may manage geography but do not acquire Manager or Commissioner
decision authority. The mobile app provides a role-scoped, read-only view;
organization changes and approvals remain in the web Team Management workspace.

Enforcement Supervisors assign bills from the shared unassigned Bill Delivery
queue, and may assign only to officers with an active membership in one of
their own teams. They cannot reassign bills already assigned. Enforcement
Managers retain read-only access to the enforcement register and queue.
Assignments require an active team membership and primary district. A bill's
primary tax district is copied from the officer's active membership only on
explicit assignment. Existing bills are not backfilled and are not
automatically changed when team memberships change.

The Enforcement manager dashboard's delivery donut and visit delivery summary
use visit records only. Delivery outcome categories are `Delivered`, `Out for
Delivery`, `Returned`, `Undelivered`, and `Not Mailable`; the chart total is the
count of visits with one of those outcomes.

---

### `POST /enforcement/discover`

**Body:**
| Field | Type | Required |
|-------|------|----------|
| property_address | string | yes |
| gps_lat | number | no |
| gps_lng | number | no |
| property_classification | string | no |
| owner_name | string | no |
| owner_contact | string | no |
| documents | object | no (keys: property_photo, proof_of_ownership, nin_slip, cac_cert, utility_bill) |

**Response:** Created discovery (status: Submitted_ME).

---

### `GET /enforcement/discovered`

**Query:** `status` (Submitted_ME, Routed_to_Valuation, Routed_to_Accounts)

---

### `POST /enforcement/discovered/{id}/route`

**Body:** `{ "route_to": "valuation" | "accounts" }`

---

### `POST /enforcement/submit-receipt`

**Body:**
| Field | Type | Required |
|-------|------|----------|
| billing_number | string | yes |
| amount | number | yes (>0) |
| period | string | no |
| receipt_number | string | yes |
| receipt_photo | string | no (file path) |

---

## 7. Registration Queue

### `GET /registration-queue`

**Query:** `status` (Pending, Registered, Rejected)

---

### `POST /registration-queue/{id}/complete`

**Body:**
| Field | Type | Required |
|-------|------|----------|
| source_tin | string | yes |
| source_property_id | string | yes |
| source_billing_number | string | yes |
| notes | string | no |

---

### `POST /registration-queue/{id}/reject`

**Body:** `{ "rejection_reason": "..." }`

---

## 8. Payments (source)

### `POST /payments/track`

**Body:** `{ "billing_number": "...", "amount": 15000, "period": "2024 Q1", "receipt_number": "REC-001" }`

**Response:** `{ "status": "Accepted" | "Already Recorded", "updated_bill": { ... } }`

---

### `GET /payments/history/{billing_number}`

Returns payment history for a bill.

---

## 9. Dashboard

### `GET /dashboard/my`

**Response:**
```json
{
  "data": {
    "stats": { "open": 12, "overdue": 3, "escalated": 1, "completed_this_month": 8, "unread_alerts": 5 },
    "tasks": { "open": [...], "overdue": [...], "escalated": [...] },
    "valuations": { "awaiting_review": 4 },
    "queue_depth": 7
  }
}
```

---

### `GET /dashboard/performance/management`

Returns aggregated operational performance for Account, Valuation, and Enforcement Managers; their corresponding Supervisors; and the Assistant Commissioner. Individual performance remains available to operational staff, including Enforcement Team Leads.

Approved targets are grouped by metric, measurement unit, and exact target window. For staff assignment changes within a target window, the target denominator is allocated by inclusive calendar days and eligible achievements are attributed by the event date. Metrics without a live target calculation are reported as unsupported rather than as zero. Historical periods before recorded organizational history are marked limited.

The response contains `scope`, `scope_label`, `period_basis`, `periods` (eligible target, achievement, completion rate, staff/target counts, and attribution state), `unsupported_metrics`, and overall attribution status. The endpoint is restricted to mapped management roles.

### `GET /reports/management/performance`

Returns the same management-performance calculation used by the dashboard.

### `GET /reports/management/performance/export?format=csv|pdf`

Exports the same management performance groups as CSV or PDF. The personal `/reports/my/performance` endpoints are not available to management roles.

---

## 10. Notifications

### `GET /notifications`

**Query:** `per_page` (default 20)

**Response:** Paginated notifications with `title`, `message`, `read_at`, `created_at`.

---

### `POST /notifications/{id}/read`

Marks a single notification as read.

---

### `POST /notifications/read-all`

Marks all notifications as read.

---

## 11. Health Check

### `GET /health`

**Response:** `{ "status": "ok", "version": "1.0" }`

---

## Roles Reference

| ID | Role | Key Permissions |
|----|------|----------------|
| 1 | Admin | Full system access |
| 2 | Account Manager | Case status control, bill amendments, user creation (Officer only), property registration |
| 3 | Assistant Commissioner | Valuation final approval/rejection |
| 4 | Valuation Manager | Valuation creation, first-stage review, routing |
| 5 | Valuation Officer | Valuation line-item editing |
| 6 | M&E Officer | Case status updates, visit logging, bill delivery tracking |
| 7 | Enforcement Manager | Case status, escalation, discovery routing |
| 8 | Enforcement Officer | Field visits, discovery registration, receipt submission |

---

## RBAC Enforcement

- **Case status** editable only by: M&E Officer, Enforcement Manager, Admin
- **Valuation details** visible only to: Admin, AC, Valuation Manager/Officer
- **Bill amendments** only by: Account Manager, on Statutorily Overdue bills
- **Valuation two-stage review**: Valuation Manager recommends → AC approves/rejects
- **User creation by Account Manager**: restricted to Officer roles only
- **Discovery routing**: M&E Officer / Enforcement Manager only

---

## Offline Mobile Sync (Expo App)

The mobile app uses a local SQLite outbox. When offline, mutations are queued:

| Queue Kind | Endpoint | Payload |
|------------|----------|---------|
| `visit` | `POST /enforcement-visits` | Visit form data |
| `discovery` | `POST /enforcement/discover` | Discovery + documents |
| `receipt` | `POST /enforcement/submit-receipt` | Payment receipt |
| `action` | `POST /enforcement-assignments/{id}/action` | Assignment status change |

The outbox auto-flushes when network connectivity is restored (via NetInfo listener).
