# Dashboard checkpoint — 2026-09-12 (before modification)

Restore by copying these files back over the live ones:

- `officer-dashboard-web.jsx`      → `frontend/src/pages/OfficerDashboard.jsx`
- `officer-home-mobile.jsx`        → `mobile/src/screens/parts/OfficerHome.jsx`
- `OfficerDashboardController.php` → `app/Http/Controllers/Api/OfficerDashboardController.php`

Then rebuild web (`npm run build` in `frontend`) and confirm Metro re-bundles.

## State captured (what "current" looks like)

**Web — OfficerDashboard.jsx** (manual merge applied, no duplicate counting):
1. Cascading filter bar (Year/Quarter/Month/From/To/Stage/Bill status/Payment status)
2. Task register snapshot (enforcement only): My tasks / Active / Overdue / Due today / Escalated / Completed
3. **My position & work queues** (merged, compact tiles `px-3 py-2.5`, values `text-lg`, grid `2/4/6 cols`, `gap-3`): queue cards are register links ("Open register →" on hover), money KPIs + top-line counts w/o a portal (Completed / Bills processed) are read-only "Scope total" tiles.
4. Discovery pipeline (enforcement only): Awaiting review → Sent to account → Requiring valuation → Under valuation → Pending AC approval → Processed in LITAS → Completed
5. Charts: Bills by enforcement stage / case status (bar, drillable) + Bills by payment status (donut, drillable)
6. Detail table (records, drill-filtered, paginated)

**Mobile — OfficerHome.jsx**:
- Header card (title, period, filters)
- Task Register (enforcement only) snapshot card
- **My Position & Work Queues** merged card: queue tiles tap → register (`›`), money tiles read-only; compact `minHeight 58`, value 16
- Charts: Bills by Enforcement Stage (HBars) + Bills by Payment Status (Donut)

**Server — OfficerDashboardController.php**: all-time default range; `my_tasks` snapshot; rangeDisplay real min/max `date_logged`; CSV includes task snapshot; scope includes task-referenced bills (`BillScopeService::taskAssignedBillSub`).

## Build artifacts at checkpoint
- Web `npm run build`: OK — `index-Cs0d8rNU.js` 561.56 kB
- Metro bundle HEAD: HTTP 200, bytes=6116469