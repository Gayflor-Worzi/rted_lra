# RETD_LRA Workflows

End-to-end business processes with actors, state machines and API calls.

---

## 1. Property Registration — Discovery to Master SSOT (dual source of truth)

```
Enforcement Officer (mobile/field)
   │  captures address, GPS, classification, 5 documents (photo, ownership
   │  legal doc, owner passport, owner official ID, property schedule form)
   ▼
DiscoveredProperty [Submitted_ME]              POST /enforcement/discovered
   │
   ▼  Routed by Admin / M&E / Enforcement Manager
Route:  ┌─ "accounts"  → RegistrationQueue [Pending]           POST /discovered/{id}/route
         └─ "valuation" → Draft Valuation (numbered, assigned)  (route_to=valuation)
                              │
                              ▼
                         Valuation 2-stage approval (workflow 2)
                              │  (Stage-2 AC approval may auto-promote)
                              ▼
RegistrationQueue [Pending] ◄──────────────────────────────────────┘
   │  Account Manager claims  ── POST /registration-queue/{id}/claim
   │  Account Manager completes ── POST /registration-queue/{id}/complete
   │     fields: litas_tin, litas_property_id, litas_billing_number, notes
   │
   ▼  validates unique billing number + trust docs
PropertyBill [Master SSOT]  (promoteToMasterSSOT: canonical bill, owner,
   │                         anchors valuation, resolves discovery → Registered)
   ▼
case_status becomes "Registered" (then valuations/assessments continue)
```

**State machine:** Submitted_ME → Routed_Account/Routed_Valuation → Registered | Duplicate | Invalid | On_Hold | Contact_Made | Partly_Registered | Rejected

Guards: open duplicate entry rejected; `litas_billing_number` must be unique on completion.

---

## 2. Valuation Approval (two-stage)

```
Valuation Officer          Valuation Manager        Assistant Commissioner
  create dossier ──► store POST /valuations
  (anchor: bill OR Routed_Valuation discovery; lines; rate engine)
  submit ─────────► POST /valuations/{id}/submit
                    [draft → Submitted]  (doc compliance enforced)
                              │ forward POST /valuations/{id}/review
                              │ {action:"forward"}
                              ▼
                       [Pending AC Approval]
                                        │ review POST /valuations/{id}/review
                                        │ {action:"approve"|"reject", decision_notes}
                                        ▼
                        Approved / Rejected
   Approved ──► parent case_status = "Valuation Approved"
                OR discovery promoted to RegistrationQueue
```

Rate engine: `applyLraRate()` by land type/category → `recalculateTotals()`. Numbers year-scoped `nextValuationNumber()`.

---

## 3. Assessment → Tax Bill → Payment

```
Valuation [Approved]
   │ TaxAssessment created (annual_tax != null, one per property)
   ▼
TaxAssessment [Active]                       POST /tax-assessments
   │ TaxBill created (one per assessment)    POST /tax-bills   (TB-YYYY-#####)
   ▼
TaxBill
   │ Payment recorded (ledger, lockForUpdate,
   │   over-pay/duplicate rejected)           POST /payments
   ▼
   recalculateFromPayments()
   │   balance → 0
   ▼
PropertyBill.case_status:  "Billed" (first payment lands — see bug #6)
                           "Tax Cleared" (fully settled)
   ├─► notification to officers assigned to the case
   └─► compliance report reflects reduction
```

---

## 4. Enforcement — Assignment, Visit, Escalation, Statutory Overdue

```
Admin / Enforcement Manager
   │ POST /enforcement-assignments/assign (delinquent property_bill → officer)
   ▼
EnforcementAssignment [Assigned]
   │ shared with officer via GET /enforcement-assignments/my
   ▼
Field Officer
   │ POST /enforcement-visits           (snapshot: outstanding, payment &
   │    status from live bill; M&E copy if delivered; last_visit_date set)
   ▼
   ┌─ escalate ladder (POST /{id}/escalate, forward-only):
   │     None → 30-Day Notice → 72-Hour Warning → Litigation
   │              → Closure → Settlement
   │
   └─ payment received in field:
         officer submits receipt  POST /enforcement/submit-receipt
         ──► PaymentVerification [Pending] (assignment paused)
              Account & Records review POST /account/payment-verifications/{id}/review
                 approve → mirror amount to property bill + audit entry
                           ("payment_verified") + compliance task completed
                 reject  → resume enforcement assignment
   ▼
Nightly/scheduled: artisan lra:statutory-overdue
   └─ flips eligible bills to [Statutorily Overdue]
      + notifies Enforcement Manager & M&E
```

---

## 5. Bill Delivery (property bill lifecycle)

State machine (advanceDelivery, `POST /property-bills/{id}/delivery-stage`):

```
Logged → Out for Delivery → Delivered
                               ├─ Signed Return Received → Filed
                               └─ Delivered via Email (Overseas) → Filed
                                                                   (email + CBL wire
                                                                    instructions sent,
                                                                    audit: overseas_email_dispatched)
```

Each transition is role-checked (Admin / Account Manager / Enforcement Manager). No delivery history is written to a log table today (see bug #9).

---

## 6. Amendments (statutorily-overdue bills only)

```
PropertyBill [Statutorily Overdue]
   │ POST /property-bills/{id}/amend
   │   { amended_document_id, penalty_amount, interest_amount, notes }
   ▼
BillAmendmentLog (immutable) + audit entry "bill_amended"
   └─ base vs amended document retained; penalty/interest recorded
```

Guards: status must be `Statutorily Overdue`; otherwise rejected.

---

## 7. Appeals

```
Taxpayer appeal created   POST /appeals        (APP-YYYY-#####)
   ▼  [Submitted] → [Under Review]
Assistant Commissioner reviews   POST /appeals/{id}/review
   ├─ Uphold    → behind; records decision
   ├─ Adjust    → new annual_tax propagates:
   │               Appeal │→ TaxAssessment (update annual_tax)
   │                     ▼
   │              unsettled TaxBills recalculated
   └─ Dismiss   → closed
   ── Withdrawn (taxpayer) possible before decision
```

---

## 8. M&E Queries

```
Raise query (Admin / M&E)      POST /me-queries        (MEQ-YYYY-#####)
   │ assign / reassign officer ──► notification sent
   ▼ [Open]
Officer responds                POST /me-queries/{id}/respond  → [Answered]
   │
Close (raiser / M&E / Admin)    POST /me-queries/{id}/close    → [Closed]
```

---

## 9. Support Processes

### Users
- Admin/Account Manager creates user (`POST /users`) → `must_reset_password=true`.
- Intended flow: first login forces a password reset via `POST /auth/reset-password`. **Frontends currently have no reset screen — blocked feature (bug #1).**

### Staff targets
- Manager creates target, `POST /staff-targets/{id}/refresh` recomputes achieved metrics from live data (collections, valuations completed, visits).
- `achieved_value` is manual only for custom metrics.

### Reporting
- `GET /reports/enforcement-activity|compliance|me-summary` → JSON; CSVs via `GET /reports/{report}/export` (note: CSV export currently ignores date filters — bug #12).

---

## 10. Cross-cutting: Audit & Notifications

- **Audit:** every create/update/delete on the 9 audited models writes an `AuditLog` row chained via SHA-256 `previous_hash` → `hash`. Read-only view for Admin/M&E.
- **Notifications:** triggered on assignment/reassignment (M&E), appeal review, verification outcome, escalation, statutory-overdue sweep, broadcast (System Admin). `sendToRole` matches role names exactly.