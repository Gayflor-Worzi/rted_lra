# Dashboard Data Integrity Audit

## Scope and method

Inspected the authenticated dashboard routes, their backend query/calculation paths, and the web and mobile components that render those responses. The audit covered the general dashboard, officer dashboards, valuation work, target performance, analytics, manager dashboards, and the Assistant Commissioner dashboard. It did not create or alter business records outside isolated automated tests.

## Dashboard inventory

| Surface | Web renderer | Mobile renderer | Data source and displayed groups |
|---|---|---|---|
| General dashboard | `frontend/src/pages/Dashboard.jsx` with `frontend/src/components/Analytics.jsx` and `frontend/src/components/PerformanceTargets.jsx` | `mobile/src/screens/HomeScreen.jsx` | `/dashboard/my` supplies profile, tasks, bill-area data, activity, approved performance targets, and role-aware priorities; `/dashboard/analytics` supplies filtered metric KPIs, bars, pies, and paginated records. Mobile also reads `/enforcement-assignments/my` for the personal assignment preview. |
| Account & Records Officer | `frontend/src/pages/OfficerDashboard.jsx` | `mobile/src/screens/parts/OfficerHome.jsx` | `/dashboard/officer` returns scoped KPIs, queues, bar/pie data, records, personal tasks, discovery count, and (for enforcement only) today's work. The Account & Records view additionally renders financial summaries. |
| Enforcement Officer | `frontend/src/pages/OfficerDashboard.jsx` | `mobile/src/screens/parts/OfficerHome.jsx` | Same `/dashboard/officer` contract, with enforcement-stage KPIs, today's work, assignments, charts, queues, and detail records. |
| Valuation Officer | `frontend/src/pages/ValuationHome.jsx` | `mobile/src/screens/parts/ValuationOfficerHome.jsx` within Home | `/dashboard/my` supplies `valuation_work`: personal valuation KPIs and records. Both clients use the same backend stage mapping. |
| Account & Records Manager | `frontend/src/pages/ManagerDashboard.jsx` | `mobile/src/screens/parts/ManagerHome.jsx` | `/dashboard/manager/account` supplies current KPIs, current-month event counts, financial totals, payment-status donut, pipeline, officer workload, charts, priority queue, activity, alerts, and permission-gated actions. |
| Valuation Manager | Same shared manager renderer | Same shared manager renderer | `/dashboard/manager/valuation` supplies current stage KPIs, monthly event counts, discovery pipeline, valuation-status donut, workload, review queue, quality/GPS/AC approval sections, charts, priority queue, activity, alerts, and actions. |
| Enforcement Manager / Supervisor | Same shared manager renderer | Same shared manager renderer | `/dashboard/manager/enforcement` supplies bill lifecycle KPIs and pipeline, visit outcomes, officer workload, today's field activity, payment follow-up/security, notice/escalation summaries, charts, priority queue, activity, alerts, and actions. |
| Assistant Commissioner | Same shared manager renderer | Same shared manager renderer | `/dashboard/ac` supplies division-wide KPIs, monthly event counts, financial totals, payment-status donut, workflow pipeline, cross-section workload, charts, priority queue, activity, alerts, and actions. |
| Generic analytics | `frontend/src/components/Analytics.jsx` | Analytics section in `mobile/src/screens/HomeScreen.jsx` | `/dashboard/analytics` supports tasks, bills, collections, payment verifications, discoveries, valuations, visits, and targets. KPI, bar, pie, and records queries share the same range, filters, and caller scope. |
| Central dashboard data route | No consumer found | No consumer found | Authenticated `/dashboard/data` is routed to `DashboardDataService`, whose dataset and derivation stages remain placeholders. It is not a source for the live dashboard components above. |

## Data sources, scope, and refresh findings

- Dashboard routes are inside the Sanctum-authenticated API group. Officer, manager, and Assistant Commissioner endpoints also enforce their role gates.
- `/dashboard/my` caches per user and scope for 45 seconds. `?fresh=1` bypasses that cache. Mobile Home requests fresh data on pull-to-refresh; the analytics request is reissued when its selected filters or refresh tick changes.
- `/dashboard/officer` applies `BillScopeService` to bill populations and uses `date_logged` for its date range. Task, visit, and assignment details use their corresponding owner or bill scopes. The web officer page refreshes on visibility return; mobile refreshes on navigation focus and app foreground.
- Manager dashboard queries are role-specific and use section officer IDs, the shared bill scope, or division scope as appropriate. Web has an explicit refresh action. Mobile manager data previously remained stale when the Home refresh refreshed only `/dashboard/my`; Home refresh/focus now triggers the manager endpoint too, and app foreground reloads it.
- Analytics authorizes each metric by permission, applies `User::applyScope()` to the metric owner, then uses cloned versions of the same filtered query for KPIs, charts, and records. Staff and section filters further narrow that authorized query.
- Performance targets are selected by authenticated `user_id` and `Approved` status. Each target achievement is recomputed using `StaffTarget::computeAchievedValue()` over its effective date window. The aggregate is weighted (`sum achieved / sum target`), not an average of target percentages.
- The target payload includes every approved target for the user regardless of whether its explicit end date has passed; `isActive()` is used to select the legacy single-target window but does not filter the target list or aggregate. The authorized business decision for this audit is to include all approved targets, including past, current, and future periods, in the weighted accumulated overall figure. Each target retains its own effective window for achievement calculation and remains visible as an individual card.
- `OfficerDashboardController::filterOptions()` falls back to register-wide year/status/payment dropdown options only when the officer has no scoped options. Its KPI, chart, queue, and record queries remain scoped; the fallback exposes option values, not other users' records.
- `DashboardDataService` is an unused placeholder and was not substituted for the live implementations.
- The external source system is declared disconnected in route metadata. This audit verifies consistency within RETD records and calculations only; it cannot reconcile RETD values to external authoritative records.

## Confirmed defects corrected

1. **Personal dashboard payment-verification priority count was unscoped.** The corresponding pending-verification metric already used the caller's bill scope, while the `verify` priority action counted globally. The priority action now uses the existing `applyBillScope()` helper. A focused test creates one in-scope and one out-of-scope pending verification and confirms both displayed counts are one.
2. **Enforcement delivery donut mixed bill and visit populations.** The chart now represents field visits only, using the model's mutually exclusive `Delivered`, `Out for Delivery`, and `Returned` statuses. The donut center is the count of eligible visits, and the category sum reconciles to it. The manager alert's existing failed-visit count continues to use returned visits.
3. **Mobile manager dashboard did not refresh its own endpoint with Home refresh.** The parent refresh updated `/dashboard/my` and assignments, while the manager panel's request was only made on its initial focus. It now reloads on the parent's refresh tick and when the app returns to the foreground.

The enforcement donut still includes a `Returned` bill count alongside visit-level outcome counts. These are distinct source populations; the counts are not a mutually exclusive partition of one entity set. This audit preserves the existing bill-level figure and removes only the proven duplicate. If the donut is intended to show proportions of visits or bills, its population should be made explicit in a separately authorized presentation change.

## Tests and verification

- `php artisan test --filter="DashboardDataIntegrityTest|DashboardStatusMappingTest|PerformanceTargetsDashboardTest|ValuationDashboardAccuracyTest|ManagerDashboardTest|AssistantCommissionerDashboardTest|OverdueConsistencyTest|ReportingReconciliationTest"` — passed, 74 tests and 579 assertions.
- PHP syntax checks for the changed service, manager test, dashboard controller, and dashboard-integrity test — passed.
- Pint check for those PHP files — passed.
- Babel parser syntax validation for the changed Home and ManagerHome JSX — passed.
- `expo export --platform android` — passed; output was directed to a temporary session directory and was not written to the project. No deployment or release was performed.
