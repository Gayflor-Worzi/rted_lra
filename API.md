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
| action | string | "in_progress", "completed", or "escalate" |
| visit_date | string | YYYY-MM-DD |
| notes | string | no |

---

### `POST /enforcement-visits`

**Body:**
| Field | Type | Required |
|-------|------|----------|
| assignment_id | integer | yes |
| property_bill_id | integer | yes |
| status | string | yes (see frontend) |
| bill_delivery_status | string | yes |
| gps_lat | number | no |
| gps_lng | number | no |
| proof_photo | string | no (file path) |
| notes | string | no |

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
| litas_tin | string | yes |
| litas_property_id | string | yes |
| litas_billing_number | string | yes |
| notes | string | no |

---

### `POST /registration-queue/{id}/reject`

**Body:** `{ "rejection_reason": "..." }`

---

## 8. Payments (LITAS)

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
| 2 | Account Manager | Case status control, bill amendments, user creation (Officer only), LITAS registration |
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
