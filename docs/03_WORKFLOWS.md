# RETD Workflows

End-to-end business processes with actors, state machines and API calls.

> **Status:** every section below describes the **implemented** backend and is
> verified against the routes, models, controllers and services in this
> repository. Behaviour that is proposed but not implemented is not described
> here.
>
> The external bill/property registry is referred to as **the source system**.
> RETD records the identifiers it issues; it never generates them.

---

## 1. Identifiers

Three identifiers matter, and they mean different things.

### 1.1 Source Reference ID — permanent property identity

`properties` (model `App\Models\Property`) is an **identity registry**, not a
second copy of the property record. It holds one row per underlying property:

| Column | Meaning |
| --- | --- |
| `source_reference` | RETD's permanent property reference, `PRP-YYYY-#####`, generated once from the row id and never regenerated |
| `property_id` | the source system's Property ID, NULL until the source system issues it, unique once present |

A discovery, a valuation and a property bill each carry `property_ref_id`
pointing at that row. Business data (owner, address, classification, value)
stays on the discovery/valuation/bill rows, which remain the records the
workflow reads and writes.

`App\Services\PropertyIdentityService` is the only authority on linking. Its
rules are deliberately narrow:

1. **The source-system `property_id` is authoritative.** Two records carrying
   the same normalized (trimmed, upper-cased) `property_id` are the same
   property.
2. **An existing relation is authoritative.** A valuation follows its
   discovery, else the bill it reassesses; a bill follows the valuation that
   produced it.
3. **Nothing else is.** Similar owner names, addresses, classifications or
   TINs **never** merge two identities. An unlinked record simply gets its own
   identity — the truthful state for an unregistered property.

`PropertyIdentityService::recordSourcePropertyId()` is called at the
registration step (the first moment the identifier exists) and stamps it on
the identity and every record already linked to it. It refuses to silently
repoint a lifecycle that already carries a *different* source id; if the same
source property is already held by another identity row, the records are
repointed to it and the emptied row is **kept**, never deleted.

Linking is structural, not a business transition, so it is written quietly:
the workflow audit trail records decisions, not identity bookkeeping.

**Backfill** (migration `2026_10_27_000001_create_property_identity`) links
existing rows only on reliable evidence — source `property_id`, then
discovery/valuation/bill relations. Existing open records that cannot be
linked get their own identity rather than being guessed into a merge.

### 1.2 Task ID — one open task per piece of work

A task identifies **one piece of work**:
`(task_type, reference_type, reference_id)`. When that work continues — routed
to another officer, returned for correction, resubmitted, approved — the
**same task row is reused** and its status/history move forward. It is never
re-created with a new identifier.

- `TaskService::createOpenTask()` / `reuseOpenTask()` implement the lookup and
  return the existing open row when one exists.
- The database backstop is `tasks.open_task_key`: a stored generated key that
  is non-NULL only for **open** tasks with a concrete reference, with a unique
  index. Closed and unreferenced tasks are unaffected, so history is never
  constrained.
- Duplicate open rows that predate the backstop are **closed** (not deleted)
  by the migration, each with a history entry naming the surviving task, so
  every identifier still resolves but no duplicate competes in active/overdue
  queues.

`task_reference` (`TASK-YYYY-#####`) is generated once from the row id and is
permanent; it is not mass-assignable.

A **new correction cycle** is new work: after a correction task is closed by
resubmission, a later return of the same record creates a new task. Repeating
a return *while the correction is still open* continues the same task and
appends the new reason to its history.

### 1.3 Other internal references

| Record | Format | Notes |
| --- | --- | --- |
| Property discovery | `ND-YYYY-#####` | generated from the row id |
| Valuation | `VAL-YYYY-#####` | generated from the row id |
| Appeal | `APP-YYYY-#####` | generated from the row id |
| M&E query | `MEQ-YYYY-#####` | generated from the row id |
| Penalty/interest amendment | `BillAmendment` row | immutable snapshot per amendment |

Document # and Property ID are **source-system identifiers**: RETD records them
at Account & Record processing; it does not invent them.

---

## 2. Property Discovery — controlled routing

Discoveries enter as **Draft**, are **classified automatically** on submission,
and are routed to a management desk. The `property_discoveries` record is never
duplicated; only the task/queue moves.

```
Enforcement Officer (mobile / field)     POST /enforcement/discover
  │  or ACCT desk                        POST /discoveries
  ▼
PropertyDiscovery [Draft]
  │  recorder submits                    POST /discoveries/{id}/submit
  │  auto-classification:
  │    value_declaration_type SELF_DECLARED / DOCUMENTARY_VALUE → "account"
  │    value_declaration_type VALUATION_VALUE                  → "valuation"
  │    (no declaration) config/discovery.php classification lists
  │      Residential · Vacant Land · Agricultural · Government
  │        · Community                                        → "account"
  │      Commercial · Industrial · Mixed Use · Institutional   → "valuation"
  │    anything else → default_path "valuation"
  ▼
  ├─ "account"   → [Awaiting Account Manager Review]  + Discovery Review task
  │     POST /discoveries/{id}/acct-review   (Account & Record Manager)
  │       approve (+ officer_id)             → [Assigned to Account & Record]
  │                                            + Account & Record Processing task
  │                                            (Discovery Review task closed)
  │       refer_to_valuation                 → [Referred to Valuation]
  │                                            + Valuation Manager Review task
  │       return                             → [Returned for Correction]
  │                                            + correction task on the recorder
  │       reject                             → [Rejected]
  │
  └─ "valuation" → [Awaiting Valuation]  + Valuation Manager Review task
        POST /discoveries/{id}/val-review   (Valuation Manager)
          assign / self  → Valuation created or reopened, discovery
                           → [Under Valuation] + Valuation task
                           (Valuation Manager Review task closed)
          return         → [Returned for Correction] + correction task
```

### Statuses actually written

`Draft` → `Awaiting Account Manager Review` **or** `Awaiting Valuation`
(`Awaiting Valuation` for a record referred by the A&R Manager becomes
`Referred to Valuation`) → `Under Valuation` → `Awaiting AC Approval` →
`Approved` → `Assigned to Account & Record` → `Registered` → `Completed`.

Alternate states: `Returned for Correction`, `Rejected`, `Closed`.

`Submitted` and `Classified` are **legacy vocabulary**. `submit()` classifies
and routes in one step, so it stores `Awaiting Account Manager Review` or
`Awaiting Valuation`; neither `Submitted` nor `Classified` is ever written by
the current workflow, and no counter or queue depends on them.

### Guards on submission

`submit()` and `resubmit()` both require:

- permission `discovery.create`, and the caller must be the recorder
  (`canMutateDiscovery`);
- **Owner Name** and **Property Classification** present — otherwise `422`;
- a TIN is **not** required. A missing TIN is recorded as the audit event
  `discovery.missing_information_flagged` so it must be captured before the
  bill is logged;
- `VALUATION_VALUE` must carry **no** declared amount — the amount comes from
  the authorised valuation workflow only;
- `DOCUMENTARY_VALUE` requires ownership/deed evidence when
  `config('discovery.evidence.require_ownership_document_when_documentary')`
  is on;
- a draft (or, for `resubmit()`, a `Returned for Correction` record).

`submit()` writes `discovery.submitted` with `old.status = Draft` and
`new.status` = the **actual** status written, plus `workflow_stage` and
`decision_path`, followed by `discovery.classified`.

A failed submission writes nothing: the record stays Draft and no task or
valuation exists.

### Rank split in Account & Record

The Manager **reviews and assigns**; the Officer only **processes**. The
Officer never receives a review task. Once the record reads `Assigned to
Account & Record`, the officer holds an assigned `Account & Record Processing`
task whose only action is to record the source-system identifiers and update
the property bill record.

### Separation of duties

Neither `acct-review` nor `val-review` may be performed by the officer who
recorded the discovery (`422`).

### Returning for correction

Every return — A&R Manager (`acct-review`), Valuation Manager (`val-review`),
Assistant Commissioner (valuation `decide`) — raises (or continues) **one**
correction task:

- type `Other`, anchored to `property_discovery`;
- assigned to the **recorder**, with `assigned_by` = the returning officer;
- 7-day processing deadline (`RETD_PROCESS_7_DAY`), so it can age;
- the return reason in `remarks` and in a `returned_for_correction` history
  entry, naming the returning desk.

`POST /discoveries/{id}/resubmit` closes that task
(`resubmitted_for_review`, engagement recorded) and routes the record again;
the review-desk task raised by the resubmission becomes the only open work.

A rejection is terminal by policy and raises **no** correction task.
`POST /discoveries/{id}/reopen` (permission `discovery.reopen`) moves a
`Rejected` or `Returned for Correction` record back to `Draft`.

Where the correction task is visible: it appears in the recorder's own task
list (`GET /tasks?view=mine`) and can be opened directly. The Account & Record
workspace's Assigned Tasks tab is deliberately limited to the three account
task types (`Account & Record Processing`, `Account & Record Manager Review`,
`Discovery Review`), so an `Other` correction task surfaces in the unified task
list and in **My Discoveries**, not in that tab.

---

## 3. Valuation

```
Valuation Officer            POST /valuations/{id}/begin        → Assessment in Progress
                             POST /valuations/{id}/descriptions (description rows roll up totals)
                             PUT  /valuations/{id}             (photos, owner contact, …)
                             POST /valuations/{id}/submit      → Submitted to Manager
                                                               + Valuation Review task
Valuation Manager            POST /valuations/{id}/review
                               confirm_ac → Submitted to AC    + AC Approval task
                               return     → Returned for Correction
                                                               + Valuation task on the officer
Assistant Commissioner       POST /valuations/{id}/decide
                               approve → AC Approved           + Account Manager Review task
                                         (+ approved figures propagated to the linked bill)
                               return  → AC Returned            + recorder correction task
                               reject  → AC Rejected            (terminal; no correction task)
Account & Record Manager     POST /valuations/{id}/account-review
                               approve (+ officer_id) → Sent to Account & Record
                                                        + Account & Record Processing task
                               return                 → Returned for Correction
                                                        + Valuation task (or manager review task
                                                          when no officer is set)
```

### Statuses actually written

`Assigned` → `Assessment in Progress` → `Submitted to Manager` → `Submitted to
AC` → `AC Approved` → `Sent to Account & Record` → `Registered` → `Completed`,
with `Returned for Correction`, `AC Returned` and `AC Rejected` as alternate
states.

`Under Manager Review`, `Awaiting AC Approval`, `Draft`, `Submitted`,
`Manager Review`, `AC Approval`, `Approved` and `Rejected` exist only as
legacy aliases/constants. The workflow writes one review state,
`Submitted to Manager`, and one AC state, `Submitted to AC`; counters read the
stage map rather than the unused aliases.

### Stage map (single interpretation)

`Valuation::STAGE_TABS` partitions every status into exactly one stage, and
every dashboard counter, register tab and drill-down reads it:

| Stage | Statuses |
| --- | --- |
| Assigned | `Assigned` |
| Assessment in Progress | `Assessment in Progress` |
| Awaiting Review | `Submitted to Manager`, `Under Manager Review` |
| Awaiting AC | `Submitted to AC`, `Awaiting AC Approval` |
| Awaiting Account & Record | `AC Approved`, `Sent to Account & Record`, `Registered` |
| Returned for Correction | `Returned for Correction`, `AC Returned`, `AC Rejected` |
| Completed | `Completed` |

`Registered` belongs to Account & Record (the officer's `complete()` step is
still outstanding); the three return statuses are one stage because, to the
officer, they all mean "it came back".

### Discovery mirroring

A valuation created from a discovery is **always** processed on the discovery,
so the source identifiers and the property bill record are written exactly
once, from one place. `POST /valuations/{id}/processing` rejects
discovery-linked valuations with `422`; the web and mobile valuation screens
hide that action for them.

The linked discovery mirrors each valuation step:

| Valuation | Discovery |
| --- | --- |
| `Assigned`, `Assessment in Progress`, `Submitted to Manager`, `Returned for Correction` | `Under Valuation` |
| `Submitted to AC` | `Awaiting AC Approval` |
| `AC Approved` | `Approved` (value and approved owner/contact/TIN/classification/address copied) |
| `AC Returned` | `Returned for Correction` |
| `AC Rejected` | `Rejected` |
| `Sent to Account & Record` | `Assigned to Account & Record` |
| `Registered` | `Registered` |
| `Completed` | `Completed` |

A **Valuation Section** return (`Returned for Correction`) keeps the discovery
`Under Valuation`: that is the section correcting its own officer. Only a
decision taken against the property record (`AC Returned` / `AC Rejected`)
moves the discovery into the recorder's correction lane.

### Approved-valuation propagation to the register

When the Assistant Commissioner approves a valuation that is anchored to a
bill, the approved assessment is copied onto the bill: `assessed_value`,
`property_classification`, and — when corrected during valuation —
`taxpayer_name`, `property_address`, `tin`. When `annual_tax` is present it
becomes the bill's `tax_amount`, and `total_tax_due` is recomputed as
`tax_amount + interest_charged + penalty_charged`. The change is audited as
`bill.assessment_updated_from_valuation` with before/after values.

### Assignment and ownership

- `begin`, edit and `submit` belong to the assigned Valuation Officer; another
  officer holding the same permission is refused (`403`).
- `assign` re-selects the officer and accepts `Assigned`, `Returned for
  Correction` and `AC Returned` assessments. An `AC Returned` assessment is
  reopened to `Assigned` in place (same Valuation ID), because `submit()` only
  accepts `Assigned`, `Assessment in Progress` and `Returned for Correction` —
  handing it to an officer without reopening would give them work they cannot
  act on.
- `val-review` decision `self` ("conduct personally") is allowed **only** when
  the caller holds `valuation.submit`; the server rejects it with `422`
  otherwise, because `begin()` and `submit()` both require that permission.
- A discovery already carrying a valuation is routed by **reusing and
  reopening that valuation** (same Valuation ID and reference, reset to
  `Assigned` with the chosen officer) rather than recording a second valuation
  for one discovery.
- Valuations already `Sent to Account & Record`, `Registered` or `Completed`
  cannot be routed again (`422`).

---

## 4. Account & Record processing → property bill register

```
A&R Officer   POST /discoveries/{id}/account-processing
                 requires: property_id, document_number,
                           property_tax_amount, tax_period
              ┌─ discovery → Registered; processed_by/at stamped
              ├─ identity: source Property ID stamped on the permanent
              │  property identity and every linked record
              ├─ linked valuation (if any) → Registered, REGISTRATION_COMPLETED
              ├─ open Account & Record Processing tasks → Closed
              │  (registration_completed, engagement recorded)
              ├─ property bill on the register:
              │    create when (Property ID + Tax Period) is new
              │    update in place when it exists (see §5)
              └─ audit: discovery.registration_recorded
                            (+ bill.updated_from_processing on update)
A&R Officer   POST /discoveries/{id}/complete       → Completed
                 (also completes a linked Registered valuation)
```

Permissions: `discovery.property_registration` or `discovery.aarm_review`, and
the caller must be the assigned owner (`current_owner_id`) unless they hold
`discovery.aarm_review`. Only `Assigned to Account & Record` records can be
processed; an already `Registered`/`Completed` record is refused with `422`.

### What the bill receives

- The **approved valuation is the authority**: `taxpayer_name` (owner),
  `tin`, `property_classification`, `property_address`, `assessed_value`.
  Discovery data is only the fallback for a record that never went through
  valuation.
- When the linked valuation carries `annual_tax`, the officer's entered
  `property_tax_amount` must match it within 0.01, otherwise `422` — the
  register cannot be given a figure no authorised record supports. The
  approved figure is then written.
- `interest_charged` and `penalty_charged` are taken from the processing form;
  `total_tax_due = tax_amount + interest_charged + penalty_charged`.
- `account_staff_id` is the officer who processed the record.

### Standalone valuations

A valuation with no discovery (`Path D`, e.g. a reassessment anchored to an
existing bill) keeps its own processing step, `POST /valuations/{id}/processing`.
It records the source identifiers, sets `Registered` /
`REGISTRATION_COMPLETED`, and closes its processing task. It does **not**
create a property bill.

---

## 5. Bill register

`PropertyBill` is RETD's record of an assessment **as issued by the source
system**. It carries the source Document #, Property ID, TIN, tax period,
values, delivery and payment state.

Key columns: `document_number`, `property_id`, `tin`,
`property_classification`, `property_address`, `assessed_value`, `tax_amount`,
`interest_charged`, `penalty_charged`, `total_tax_due`,
`outstanding_balance`, `tax_period`, `delivery_status`, `delivery_date`,
`payment_status`, `case_status`, `escalation_stage`,
`assigned_enforcement_officer_id`, `account_staff_id`.

Routes: `GET|POST /property-bills`, `GET /property-bills/lookup`,
`GET|PUT /property-bills/{bill}`, `POST /property-bills/{bill}/assign`,
`POST /property-bills/{bill}/amend`, `GET
/property-bills/{bill}/amendments`, `GET /bill-amendments`, `GET /search`.

### One assessment per property and period

Logging a bill (`POST /property-bills`) rejects a duplicate as `422`
(and audits the blocked attempt). Two assessments are the same when
they share the same **Property ID + Tax Period**; the **TIN is compared only
when both sides carry one**. A discovery with no TIN therefore still finds —
and updates — the bill already logged for that property instead of raising a
second one.

The database backstop is the stored generated `assessment_key` column with a
unique index: two officers logging the same assessment concurrently cannot
both succeed; the loser is re-surfaced as the same friendly `422`.

Processing a discovery that carries no TIN must not erase the TIN the logged
bill already holds: an update never blanks it.

### Delivery and case state

- `delivery_status`: `Logged` → `Out for Delivery` → `Delivered`
  (→ `Returned` / `Filed` are defined states).
- `payment_status`: `Unpaid` → `Payment Claimed` → `Verification Pending` →
  `Partially Paid` / `Paid`, with `Payment Rejected` and `Payment Mismatch`.
- `case_status`: `Logged` → `Awaiting Assignment` → `Assigned` →
  `Out for Delivery` → `Delivered` → `Payment Follow-up` → `30-Day Warning` /
  `72-Hour Warning` / `Escalated` → `Under Verification` → `Resolved` /
  `Closed`, plus the sweep state `Statutorily Overdue`.

There is **no** `POST /property-bills/{id}/delivery-stage` endpoint. Delivery
advances through the bill's delivery task and through field visits (§6), which
write the bill's delivery and case status.

### Assigning for delivery

`POST /property-bills/{bill}/assign` requires `bills.assign` and an assignee
eligible for the `Bill Delivery` task type; it creates or continues that bill's
open delivery task (`POST /property-bills` already does this at logging time —
direct recipient → `Assigned`, walk-in → `Awaiting Assignment`).

---

## 6. Enforcement and payment verification

```
Bill logged
  ├─ direct recipient  → Bill Delivery task [Assigned]  (14-day SLA)
  └─ walk-in / queue   → Bill Delivery task [Awaiting Assignment]
Enforcement Manager / M&E   POST /property-bills/{bill}/assign   → assigned
Field Officer               GET  /enforcement-assignments/my      (own tasks)
                            POST /enforcement-assignments/{task}/action
                              action=completed → [Delivered]
                              action=escalate  → [Escalated]
                            POST /enforcement-visits
                              records visit + GPS + snapshot of the bill,
                              evidence photo, optional on-the-spot claim
                              Assigned → Out for Delivery; hand-over → Delivered
```

A visit is authorised only for work assigned to the caller (or a System
Administrator); the effective bill must match the task's bill.

### Payment claim → verification

```
Field officer / officer     POST /enforcement/submit-receipt
  (or on-the-spot claim within a visit)
  ▼
PaymentVerification [Pending]     bill → case_status "Under Verification",
                                        payment_status "Payment Claimed"
  ▼
Account & Record officer    POST /payments/verifications/{id}/confirm
                              permissions: payments.verify
                              separation of duties: not the claimant
                              re-locks claim + bill, re-checks receipt
                              uniqueness and data scope, then:
                                Payment created (ledger)
                                receipt fingerprint reserved permanently
                                bill.recalculateOutstanding()
                                bill.case_status → Resolved when settled,
                                                   else Payment Follow-up
                                linked task → Paid (Closed when the state
                                              machine cannot advance it)
                            POST /payments/verifications/{id}/reject
                              permissions: payments.reject
                              reason required; fingerprint released
                              bill → case_status "Payment Follow-up",
                                     payment_status "Payment Rejected"
                              task → Payment Rejected (else Payment Follow-up)
```

`outstanding_balance` is always `total_tax_due` minus verified payments; it is
recalculated on every confirmed/rejected claim and on every save that changes a
total. `GET /payments/queue` and `GET /payments/history` expose the work; a
duplicate receipt is detected before it can be confirmed
(`POST /payments/duplicate-check`).

### Statutory overdue and escalation

- `php artisan lra:statutory-overdue` flags eligible unpaid bills
  `case_status = Statutorily Overdue` after the deadline and notifies the
  Enforcement Manager and M&E.
- `php artisan enforcement:escalate` (also `--dry-run`) advances delivered,
  unpaid bills through the statutory ladder:
  30-day reminder → 72-hour demand → final enforcement → closure, recording
  `escalation_stage` and stamping `thirty_day_notice_date` /
  `final_notice_date`.
- `72-Hour Warning` is time-gated: the 30-day notice must have elapsed, or a
  manager with `enforcement.escalation_override` must approve.

---

## 7. Amendments

There is exactly one correction path for a registered bill:

```
PropertyBill
   │ POST /property-bills/{bill}/amend
   │   document_number and/or penalty_charged and/or interest_charged
   ▼
BillAmendment (immutable snapshot: previous/new values, original document #,
               amended_by, reason, amended_at)
   + audit event bill.amended
   + total_tax_due recomputed; outstanding_balance and payment_status
     recalculated by the model on save
```

Guards:

- permission `bills.amend_document` for a Document # change and
  `bills.amend_penalty_interest` for penalty/interest, checked per field;
- at least one field must actually change (`422` otherwise);
- a Document # must stay unique across bills.

The amendment path is **not** gated on `Statutorily Overdue`; bills can be
amended at any status by an actor holding the relevant permission.

`POST /property-bills/lookup` is the targeted way to find a bill for amendment
outside one's own scope: it requires an amendment permission and locates by the
exact Property ID + Tax Period pair.

---

## 8. Appeals

```
Taxpayer appeal     POST /appeals           → [Submitted]
M&E / AC review     POST /appeals/{appeal}/decide
                      decision: upheld | adjusted | dismissed
                      notes required
                      status → Upheld | Adjusted | Dismissed
                      decided_by / decided_at recorded
```

Guards: `valuation.approve`; an appeal already in `Upheld`, `Adjusted`,
`Dismissed` or `Withdrawn` cannot be decided again (`422`).

**Implemented behaviour only:** `decideAppeal` records the decision, its notes
and the decision metadata on `appeals`. An `adjusted` decision does **not**
rewrite a bill, a valuation or an `annual_tax` figure, and there is no
withdraw endpoint — `Withdrawn` is recognised as a terminal status but nothing
in the current API sets it.

---

## 9. M&E queries and flags

```
Raise query (M&E / Admin)   POST /me/queries          → [Open]   MEQ-YYYY-#####
Officer responds            POST /me/queries/{id}/respond → [Answered]
Close (raiser / M&E / Admin) POST /me/queries/{id}/close  → [Closed]
```

A closed query cannot be answered (`422`).

Operational powers: `GET /me/review-board`, `POST /me/tasks/{task}/assign-walkin`,
`/reassign`, `/revise`, and the flag set `GET|POST /me/flags`,
`PATCH /me/flags/{flag}/resolve` (`Open` → `Resolved`).

---

## 10. Task engine

### Task types and who may execute them

Eligibility is `role → permission → section → assignment → workflow`, enforced
by `AssignmentEligibilityService`. Being a staff member is never enough.

| Task type | Execution permission | Section(s) |
| --- | --- | --- |
| `Bill Delivery` | `enforcement.bill_delivery` | ENF |
| `Enforcement Visit` | `enforcement.visit` | ENF |
| `Payment Follow-up` | `enforcement.payment_followup` | ENF |
| `Payment Verification` | `payments.verify` | ACCT |
| `Valuation` | `valuation.prepare`, `valuation.create` | VAL |
| `Valuation Review` | `valuation.review` | VAL |
| `Valuation Manager Review` | `valuation.review` | VAL |
| `AC Approval` | `valuation.approve` | VAL, MGT |
| `Discovery Review` | `discovery.aarm_review`, `discovery.review` | ACCT, VAL |
| `Account & Record Manager Review` | `discovery.aarm_review`, `valuation.account_review` | ACCT |
| `M&E Query` | `me.respond` | ENF, MGT |
| `Account & Record Processing` | `valuation.property_registration`, `discovery.property_registration` | ACCT, VAL |
| `Other` (discovery correction) | unrestricted | — |

System Administrators are fully view-capable but are **not** auto-eligible to
be assigned operational field tasks.

### Task states

`Logged`, `Awaiting Assignment`, `Assigned`, `Out for Delivery`, `Delivered`,
`Payment Follow-up`, `Payment Claimed`, `Verification Pending`,
`Payment Verification`, `Payment Rejected`, `30-Day Warning`,
`72-Hour Warning`, `Escalated`, `Paid`, `Partially Paid`, `Outstanding`,
`Resolved`, `Closed`.

Settled/terminal: `Resolved`, `Closed`, `Paid`, `Partially Paid`
(`Task::COMPLETED_STATUSES`) — the single definition used by every dashboard,
report and target.

### Transition guards

`Task::TRANSITIONS` is the direction-guarded state machine, e.g.:

```
Logged              → Awaiting Assignment | Assigned | Closed
Awaiting Assignment → Assigned | Closed
Assigned            → Out for Delivery | Delivered | Payment Follow-up | Escalated
Out for Delivery    → Delivered | Assigned | Escalated
Delivered           → Payment Follow-up | Escalated | Closed | Payment Rejected
Payment Follow-up   → Payment Claimed | Verification Pending | 30-Day Warning
                      | 72-Hour Warning | Escalated | Closed | Payment Rejected
Payment Claimed     → Verification Pending | Payment Follow-up | Payment Rejected
Verification Pending→ Payment Verification | Paid | Partially Paid | Outstanding
                      | Payment Follow-up | Payment Rejected
Payment Verification→ Paid | Partially Paid | Outstanding | Payment Follow-up
                      | Payment Rejected
30-Day Warning      → 72-Hour Warning | Escalated | Payment Follow-up
72-Hour Warning     → Escalated | Payment Follow-up
Escalated           → Payment Follow-up | Closed
Paid                → Resolved | Closed
Partially Paid      → Payment Follow-up | Closed
Outstanding         → 30-Day Warning | Escalated
Payment Rejected    → Payment Follow-up | Escalated
Resolved            → Closed
Closed              → (none)
```

`transitionTo()` rejects a transition not in the map with `422` and writes a
`task_histories` row (`from_status`, `to_status`, `action`, `performed_by`,
`remarks`). `forceTransition()` exists for exception paths (e.g. a verified
payment against a task past the advanceable states, or closing a stage task
after registration): it still records history but bypasses the guard map.
Concurrent modification is detected and refused rather than silently
overwritten.

### Permissions on transitions

| Transition | Permission |
| --- | --- |
| any financial settlement (`Paid`, `Partially Paid`, `Outstanding`, `Verification Pending`, `Payment Verification`, `Payment Rejected`) | `payments.verify` |
| escalation (`30-Day Warning`, `72-Hour Warning`, `Escalated`) | `tasks.escalate` (or `enforcement.escalate`) |
| reopening a settled case (`action=reopen`) | `tasks.reopen` |
| everything else | `tasks.complete` |

Assignment/reassignment (`POST /tasks/{task}/assign`) requires `tasks.assign`,
workspace authority for the task type, data scope over the task, a non-settled
task, and an assignee eligible for the type.

### Task views and workspaces

| Surface | Route | Scope |
| --- | --- | --- |
| Unified list | `GET /tasks?view=mine|unassigned|team|all` | view permissions by scope; `all` needs `tasks.view_division` |
| Personal inbox | `GET /tasks/my` | own tasks; requires one of `tasks.complete`, `tasks.assign`, `tasks.escalate`, `enforcement.record_visit`, `me.view`, `payments.verify`, `payments.claim` |
| Account & Record workspace | `GET /account/my-tasks` | three account task types only |
| Account & Record discoveries | `GET /account/my-tasks/discoveries` | `discovered_by = me` |
| AC workspace | `GET /ac/tasks`, `/ac/tasks/queue` | AC's own section |
| Valuation workspace | `GET /valuation/tasks`, `/valuation/tasks/queue` | Valuation section |

Viewing a task is broader than executing it: managers keep oversight through
data scope even where they cannot be assigned the type.

### Stage tasks are retired when the decision is made

A desk task is not left open after its decision. Approving/rejecting/returning
at any desk closes that desk's task for the record (with history), so a
completed property cannot keep counting as active work at a desk that has
already acted. Registration (`accountProcessing` / valuation `processing`)
closes the officer's open `Account & Record Processing` task.

`php artisan lra:close-completed-stage-tasks` retires stage tasks left open
beside a record that has already moved past them. It reports only; it writes
nothing unless `--execute` is passed, and it never deletes a task row.

---

## 11. Support processes

### Users

- `POST /users` requires `staff.create`; a new user is created with
  `must_reset_password = true`.
- Login returns `must_reset`; `POST /auth/reset-password` completes the
  forced reset.
- Activation, password-reset forcing and per-user permission overrides are
  separate, permissioned endpoints.

### Staff targets

- A manager creates a target and calls `POST /targets/refresh/{target?}` to
  recompute achieved metrics from live records (collections, valuations
  completed, visits).
- `POST /targets/{target}/approve` approves a target.

### Reporting

`GET /reports/{kind}` for `bills`, `collections`, `enforcement`, `valuations`,
`discoveries`, `payment-queue`; export via `GET /reports/{kind}/export`.

---

## 12. Cross-cutting: audit and notifications

- **Audit:** every create/update/delete on the audited models writes an
  `AuditLog` row chained with SHA-256 (`previous_hash` → `hash`). Workflow
  events are named (`discovery.submitted`, `discovery.classified`,
  `discovery.returned`, `discovery.referred_to_valuation`,
  `discovery.registration_recorded`, `valuation.account_approved`,
  `valuation.registration_recorded`, `bill.updated_from_processing`,
  `bill.assessment_updated_from_valuation`, `bill.amended`,
  `payments.claimed_onspot`, …) with before/after values. Audit history is not
  rewritten.
- **Notifications:** raised on assignment/reassignment, submission, returns,
  referrals, registration, payment claims and their outcomes, escalation,
  the statutory-overdue sweep, and broadcasts. Section broadcasts target the
  desk that must act; a return is additionally sent directly to the recorder,
  because only they can correct and resubmit.
