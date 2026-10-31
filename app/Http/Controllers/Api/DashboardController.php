<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appeal;
use App\Models\AuditLog;
use App\Models\DataQualityFlag;
use App\Models\EnforcementVisit;
use App\Models\MeQuery;
use App\Models\Payment;
use App\Models\PaymentVerification;
use App\Models\PropertyBill;
use App\Models\PropertyDiscovery;
use App\Models\Section;
use App\Models\StaffTarget;
use App\Models\Task;
use App\Models\TaskHistory;
use App\Models\User;
use App\Models\Valuation;
use App\Services\AssignmentEligibilityService;
use App\Services\BillScopeService;
use App\Services\DataIntegrityService;
use App\Services\EnforcementOfficerAssignmentService;
use App\Services\ManagementPerformanceService;
use App\Support\BusinessClock;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    /**
     * Personal, role-aware Home payload for the mobile app. All counts are
     * computed live from the operational records and scoped to the user's
     * section/scope level; every panel is gated by the user's permissions.
     */
    public function my(Request $request)
    {
        $user = $request->user();
        $scope = $user->scopeLevel();

        // The Supabase transaction pooler costs ~0.5s per round-trip, so the
        // payload is built from a handful of snapshot queries and cached briefly
        // per user — the response stays correct (45s is fine for a dashboard)
        // while the first paint becomes fast enough for the mobile timeout.
        // An explicit refresh (`?fresh=1`) bypasses the short cache so the
        // Performance tab re-reads targets/achievements on demand.
        $fresh = $request->boolean('fresh');

        $data = $fresh
            ? $this->buildMy($user, $scope)
            : Cache::remember("dash:my:v2:{$user->id}:{$scope}", 45, fn () => $this->buildMy($user, $scope));

        if ($user->role?->name === 'Enforcement Officer') {
            $data['profile']['organizational_assignment'] = app(EnforcementOfficerAssignmentService::class)
                ->forOfficer($user);
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Role-scoped PDF export of the Valuation Officer's own dashboard. Built
     * from the exact same valuation_work payload as the web dashboard, mobile
     * home part and register — one source of truth for the entire screen.
     */
    public function exportMy(Request $request)
    {
        $user = $request->user();
        $scope = $user->scopeLevel();

        $data = Cache::remember("dash:my:v2:{$user->id}:{$scope}", 45, fn () => $this->buildMy($user, $scope));
        $work = $data['valuation_work'] ?? null;

        abort_unless($work !== null, 403, 'This report is only available to Valuation Officers.');

        $html = $this->valuationPdfHtml($data, $work);

        return Pdf::setOption('isPhpEnabled', true)
            ->loadHTML($html)
            ->setPaper('a4', 'portrait')
            ->download('retd_valuation_dashboard_'.date('Ymd_His').'.pdf');
    }

    private function buildMy(User $user, string $scope): array
    {
        $isEnforcement = $user->hasAnyPermission(['enforcement.record_visit', 'enforcement.upload_evidence'])
            || ($user->section && $user->section->code === 'ENF');

        // Minimal per-table snapshots fetched ONCE; every panel figure is
        // derived in PHP from these rows instead of issuing ~60 COUNT queries.
        $taskRows = $this->dashboardTaskRows($user, $scope);
        $billRows = $this->dashboardBillRows($user, $scope, $isEnforcement);

        $data = [
            'role' => $user->role?->name,
            'scope' => $scope,
            'profile' => [
                'full_name' => $user->full_name,
                'first_name' => strtok((string) $user->full_name, ' '),
                'staff_id' => $user->staff_id,
                'role' => $user->role?->name,
                'section' => $user->section?->name,
                'section_code' => $user->section?->code,
                'date' => now()->format('l, F j, Y'),
                'scope' => $scope,
            ],
            'notifications' => [
                'unread' => $user->notifications()->unread()->count(),
            ],
            'tasks' => $this->dashboardTaskCounts($user, $taskRows),
            'task_overview' => $this->dashboardTaskOverview($user, $taskRows),
            'bills' => $this->dashboardBillCounts($billRows),
        ];

        // Financial snapshot — only for users who actually handle bills
        // (enforcement delivers/follows up, account staff lodge/process, and
        // managers with records/reports authority). Read-only bill viewers
        // (e.g. Valuation Officers) do NOT receive the bills money block.
        if ($isEnforcement || $this->canViewBillLedger($user)) {
            $data['bills_area'] = $this->dashboardBillArea($billRows);
        }

        // Section-level panels — counts honour the caller's record scope so a
        // section/team user never sees a national pend queue.
        if ($user->canPermission('payments.view_queue')) {
            $verification = PaymentVerification::query()->where('verification_status', 'Pending');
            $this->applyBillScope($verification, $user);

            $data['payment_verifications'] = [
                'pending' => (clone $verification)->count(),
            ];
        }

        if ($user->canPermission('valuation.approve') || $user->canPermission('valuation.review') || $user->canPermission('valuation.forward_ac')) {
            $valuation = Valuation::query();
            $this->applyValuationScope($valuation, $user);

            $data['valuations'] = [
                'manager_review' => (clone $valuation)->whereIn('status', [Valuation::STATUS_SUBMITTED_TO_MANAGER, Valuation::STATUS_UNDER_MANAGER_REVIEW])->count(),
                'ac_approval' => (clone $valuation)->whereIn('status', [Valuation::STATUS_SUBMITTED_TO_AC, Valuation::STATUS_AWAITING_AC_APPROVAL])->count(),
                'approved' => (clone $valuation)->where('status', Valuation::STATUS_AC_APPROVED)->count(),
            ];
        }

        if ($user->canPermission('bills.create') && $scope === 'system') {
            $data['appeals'] = ['pending' => Appeal::whereNotIn('status', ['Dismissed', 'Withdrawn'])->count()];
        }

        // Performance for connected scope
        $data['staff'] = [
            'total' => $user->section ? $user->section->users()->count() : 0,
        ];

        // Target-based performance summary + supporting indicators.
        $data['performance'] = $this->performanceSummary($user, $taskRows, $billRows);

        // Things needing the user's attention right now.
        $data['priority_actions'] = $this->priorityActions($user, $taskRows);

        // Most recent field engagement (enforcement staff).
        $data['current_engagement'] = $this->currentEngagement($user, $taskRows);

        // Compact recent-activity feed from the user's own operations.
        $data['recent_activity'] = $this->recentActivity($user, $taskRows);

        // Authorised quick actions for the role.
        $data['quick_actions'] = $this->quickActions($user);

        // Permission-filtered chart metrics for the Home charts.
        $data['chart_metrics'] = $this->chartMetrics($user);

        // Valuation Officer workload — their own assessment pipeline as a
        // single snapshot that powers the KPI cards, the status/type charts,
        // the "My Valuation Tasks" register, the mobile home part and the PDF
        // export. One query, authorization-scoped to exactly the user's rows.
        if ($user->role?->name === 'Valuation Officer') {
            $data['valuation_work'] = $this->valuationWork($user);
        }

        return $data;
    }

    /**
     * The Valuation Officer's own assessment pipeline. Mirrors the register's
     * own semantic (valuation_officer_id = me) so the dashboard KPI/chart
     * counts and the task register below them are guaranteed to match.
     */
    private function valuationWork(User $user): array
    {
        $valuations = Valuation::where('valuation_officer_id', $user->id)
            ->orderByDesc('updated_at')
            ->get(['id', 'valuation_reference', 'valuation_type', 'document_number', 'property_id', 'property_classification', 'property_address', 'status', 'submitted_at', 'updated_at', 'created_at']);

        $deadlineByValuation = $valuations->isEmpty()
            ? collect()
            : Task::where('reference_type', 'valuation')
                ->whereIn('reference_id', $valuations->pluck('id')->all())
                ->where('assigned_to', $user->id)
                ->whereNotIn('status', Task::COMPLETED_STATUSES)
                ->whereNotNull('due_date')
                ->orderBy('due_date')
                ->get(['id', 'reference_id', 'due_date', 'due_at', 'deadline_type', 'status'])
                ->groupBy('reference_id')
                ->map(fn ($g) => $g->first());

        $V = Valuation::class;
        $statuses = [
            $V::STATUS_ASSIGNED, $V::STATUS_ASSESSMENT_IN_PROGRESS, $V::STATUS_RETURNED,
            $V::STATUS_SUBMITTED_TO_MANAGER, $V::STATUS_UNDER_MANAGER_REVIEW,
            $V::STATUS_SUBMITTED_TO_AC, $V::STATUS_AWAITING_AC_APPROVAL, $V::STATUS_AC_APPROVED,
            $V::STATUS_AC_RETURNED, $V::STATUS_AC_REJECTED, $V::STATUS_SENT_TO_ACCT,
            $V::STATUS_REGISTERED, $V::STATUS_COMPLETED,
        ];
        $byStatus = array_fill_keys($statuses, 0);
        $byStage = array_fill_keys(array_keys($V::STAGE_TABS), 0);
        $byType = [];

        $records = $valuations->map(function ($v) use (&$byStatus, &$byType, &$byStage, $deadlineByValuation, $V) {
            $byStatus[$v->status] = ($byStatus[$v->status] ?? 0) + 1;
            $stage = $V::stageFor($v->status);
            if ($stage !== null) {
                $byStage[$stage] = ($byStage[$stage] ?? 0) + 1;
            }
            $type = $v->valuation_type ?: 'Unspecified';
            $byType[$type] = ($byType[$type] ?? 0) + 1;
            $deadlineTask = $deadlineByValuation->get($v->id);
            $deadline = $deadlineTask ? $deadlineTask->presentDeadline() : null;

            return [
                'id' => (int) $v->id,
                'valuation_reference' => $v->valuation_reference,
                'property_id' => $v->property_id,
                'document_number' => $v->document_number,
                'valuation_type' => $v->valuation_type,
                'property_classification' => $v->property_classification,
                'property_address' => $v->property_address,
                'status' => $v->status,
                'stage' => $stage,
                'terminal' => in_array($v->status, $V::TERMINAL_STATUSES, true),
                'due_date' => $deadlineTask?->due_date?->toDateString(),
                'deadline_status' => $deadline,
                'due_today' => $deadline['is_due_today'] ?? false,
                'submitted' => $v->submitted_at !== null,
                'last_action' => $v->submitted_at ? 'Submitted' : 'Updated',
                'updated_at' => $v->updated_at?->toISOString(),
            ];
        })->values()->all();

        // "Due today" is a statement about work that is still outstanding. Completing a
        // valuation closes the valuation but not its task row, so a finished assessment
        // with a task still carrying today's date would otherwise be counted here and in
        // the officer's own workload. Terminal records are excluded.
        $dueToday = count(array_filter(
            $records,
            fn ($r) => $r['due_today'] && ! $r['terminal'],
        ));

        return [
            // Each counter is the size of the stage it names, so the register tab a
            // counter drills to holds exactly the records the counter counted.
            'kpis' => [
                'my_assigned' => $byStage['Assigned'] ?? 0,
                'in_progress' => $byStage['Assessment in Progress'] ?? 0,
                'returned' => $byStage['Returned for Correction'] ?? 0,
                'submitted' => $byStage['Awaiting Review'] ?? 0,
                'awaiting_ac' => $byStage['Awaiting AC'] ?? 0,
                'account_record' => $byStage['Awaiting Account & Record'] ?? 0,
                'due_today' => $dueToday,
                'completed' => $byStage['Completed'] ?? 0,
            ],
            'total' => $valuations->count(),
            'by_status' => $byStatus,
            'by_stage' => $byStage,
            'by_type' => $byType,
            'records' => $records,
        ];
    }

    /** PDF body for the Valuation Officer dashboard export. */
    private function valuationPdfHtml(array $data, array $work): string
    {
        $k = $work['kpis'];
        $rowTone = fn ($st) => in_array($st, [Valuation::STATUS_AC_REJECTED, Valuation::STATUS_RETURNED, Valuation::STATUS_AC_RETURNED], true)
            ? '#b91c1c'
            : (in_array($st, [Valuation::STATUS_ASSIGNED, Valuation::STATUS_ASSESSMENT_IN_PROGRESS], true) ? '#b45309' : '#1f2937');
        $typeLabel = fn ($t) => $t === 'new_property' ? 'New property' : ($t === 'reassessment' ? 'Reassessment' : $t);

        $statusRows = '';
        foreach ($work['by_status'] as $st => $n) {
            if ($n <= 0) {
                continue;
            }
            $statusRows .= '<tr><td style="padding:6px;border-bottom:1px solid #e5e7eb;color:'.$rowTone($st).'">'.e($st).'</td>'
                .'<td style="padding:6px;border-bottom:1px solid #e5e7eb;text-align:right">'.$n.'</td></tr>';
        }

        $typeRows = '';
        foreach ($work['by_type'] as $t => $n) {
            $typeRows .= '<tr><td style="padding:6px;border-bottom:1px solid #e5e7eb">'.e($typeLabel($t)).'</td>'
                .'<td style="padding:6px;border-bottom:1px solid #e5e7eb;text-align:right">'.$n.'</td></tr>';
        }

        $recordRows = '';
        foreach ($work['records'] as $r) {
            $recordRows .= '<tr>'
                .'<td style="padding:5px;border-bottom:1px solid #eef2f7">'.e($r['valuation_reference']).'</td>'
                .'<td style="padding:5px;border-bottom:1px solid #eef2f7">'.e($r['property_id'] ?: '-').'</td>'
                .'<td style="padding:5px;border-bottom:1px solid #eef2f7">'.e($r['document_number'] ?: '-').'</td>'
                .'<td style="padding:5px;border-bottom:1px solid #eef2f7">'.e($r['property_classification'] ?: '-').'</td>'
                .'<td style="padding:5px;border-bottom:1px solid #eef2f7;color:'.$rowTone($r['status']).'">'.e($r['status']).'</td>'
                .'<td style="padding:5px;border-bottom:1px solid #eef2f7">'.e($r['due_date'] ?: '-').'</td>'
                .'</tr>';
        }

        $header = $data['profile'] ?? [];
        $kpi = fn ($value, $label) => '<div class="kpi"><b>'.$value.'</b>'.$label.'</div>';

        return '<html><head><style>
            body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; }
            h1 { font-size: 16px; margin: 0; }
            h2 { font-size: 11px; margin: 18px 0 6px; color: #0f4c81; border-bottom: 2px solid #0f4c81; padding-bottom: 3px; }
            .muted { color: #6b7280; font-size: 9px; margin-top: 2px; }
            .kpi { display: inline-block; width: 15.2%; margin-right: 1%; padding: 8px 4px; border: 1px solid #e5e7eb; border-radius: 6px; text-align: center; font-size: 9px; color: #4b5563; }
            .kpi b { display: block; font-size: 18px; color: #0f4c81; }
            table { border-collapse: collapse; width: 100%; }
            th { text-align: left; padding: 6px; background: #f1f5f9; border-bottom: 2px solid #cbd5e1; font-size: 9px; text-transform: uppercase; letter-spacing: 0.03em; color: #334155; }
        </style></head><body>'
        .'<h1>Valuation Officer Dashboard</h1>'
        .'<div class="muted">'.e($header['full_name'] ?? '').' &middot; '.e($header['section'] ?? '').' &middot; Generated '.e(now()->format('F d, Y g:i A')).'</div><br/>'
        .$kpi($k['my_assigned'], 'Assigned').$kpi($k['in_progress'], 'In Progress').$kpi($k['returned'], 'Returned')
        .$kpi($k['submitted'], 'Submitted').$kpi($k['due_today'], 'Due Today').$kpi($k['completed'], 'Completed')
        .'<h2>By status</h2><table><tr><th>Status</th><th style="text-align:right">Count</th></tr>'.($statusRows ?: '<tr><td colspan="2">No valuations.</td></tr>').'</table>'
        .'<h2>By type</h2><table><tr><th>Type</th><th style="text-align:right">Count</th></tr>'.($typeRows ?: '<tr><td colspan="2">No valuations.</td></tr>').'</table>'
        .'<h2>My Valuation Tasks ('.count($work['records']).')</h2>'
        .'<table><tr><th>Reference</th><th>Property ID</th><th>Document #</th><th>Classification</th><th>Status</th><th>Due date</th></tr>'
        .($recordRows ?: '<tr><td colspan="6">No valuations.</td></tr>').'</table>'
        .'</body></html>';
    }

    /** Optional query-slashing helpers used by my(). */

    /**
     * Bill-ledger authority: roles that lodge, process, verify, deliver or
     * report on bills. Pure bill.view readers (Valuation Officers, etc.) are
     * NOT ledger owners — their dashboards stay focused on their own workflow.
     */
    private function canViewBillLedger(User $user): bool
    {
        if ($user->hasAnyPermission([
            'bills.create', 'bills.edit', 'bills.assign', 'bills.amend_document', 'bills.amend_penalty_interest',
            'records.view', 'records.edit',
            'payments.view_queue', 'payments.verify', 'payments.reject',
            'enforcement.bill_delivery', 'enforcement.visit',
            'reports.view',
        ])) {
            return true;
        }

        // Section+ scopes are accountable for the ledger even without a
        // specific acting permission (their numbers are their mandate).
        return in_array($user->scopeLevel(), [User::SCOPE_SECTION, User::SCOPE_DIVISION, User::SCOPE_SYSTEM], true);
    }

    private function dashboardTaskRows(User $user, string $scope): Collection
    {
        $tasks = Task::query();

        if (in_array($scope, ['own', 'team', 'section'], true)) {
            $user->applyScope($tasks, 'assigned_to');
        }

        // Same task-type boundary as the tasks register: Bill Delivery and
        // other enforcement-only types can never surface on a Valuation /
        // Account officer's dashboard, even from stale assignment data.
        $viewable = AssignmentEligibilityService::viewableTypes($user);
        if ($viewable !== null) {
            $tasks->whereIn('task_type', $viewable);
        }

        return $tasks->get(['id', 'status', 'task_type', 'task_reference', 'due_date', 'due_at', 'assigned_to', 'completed_at', 'updated_at']);
    }

    private function dashboardTaskCounts(User $user, Collection $rows): array
    {
        return [
            'total_active' => $rows->reject(fn ($t) => in_array($t->status, Task::COMPLETED_STATUSES, true))->count(),
            'overdue' => $rows->filter(fn ($t) => $t->isOverdue())->count(),
            'escalated' => $rows->where('status', 'Escalated')->count(),
            'awaiting_assignment' => $rows->where('status', 'Awaiting Assignment')->count(),
            'my_active' => $rows->where('assigned_to', $user->id)
                ->reject(fn ($t) => in_array($t->status, Task::COMPLETED_STATUSES, true))->count(),
            'completed' => $rows->whereIn('status', Task::COMPLETED_STATUSES)->count(),
            'statuses' => $rows->countBy('status')->sortKeys()->toArray(),
        ];
    }

    private function dashboardTaskOverview(User $user, Collection $rows): array
    {
        $active = fn ($r) => $r->reject(fn ($t) => in_array($t->status, Task::COMPLETED_STATUSES, true));
        $today = BusinessClock::today();
        $soon = $today->addDays(3);
        $deadlineDate = fn ($t) => $t->effectiveDeadline()?->toDateString();
        $inProgress = ['Out for Delivery', 'Delivered', 'Payment Follow-up', 'Payment Claimed', 'Verification Pending', 'Payment Verification', '30-Day Warning', '72-Hour Warning', 'Outstanding'];

        return [
            'assigned' => $rows->where('status', 'Assigned')->count(),
            'in_progress' => $rows->whereIn('status', $inProgress)->count(),
            'due_today' => $active($rows)->filter(fn ($t) => $t->isDueToday())->count(),
            'due_soon' => $active($rows)->filter(function ($t) use ($today, $soon, $deadlineDate) {
                $d = $deadlineDate($t);

                return $d !== null && $d > $today->toDateString() && $d <= $soon->toDateString();
            })->count(),
            'overdue' => $active($rows)->filter(fn ($t) => $t->isOverdue())->count(),
            'escalated' => $rows->where('status', 'Escalated')->count(),
            'completed' => $rows->whereIn('status', Task::COMPLETED_STATUSES)->count(),
            'by_type' => $active($rows)->countBy('task_type')->sortKeys()->toArray(),
        ];
    }

    private function dashboardBillRows(User $user, string $scope, bool $isEnforcement): Collection
    {
        $bills = PropertyBill::query();

        if (in_array($scope, ['own', 'team', 'section'], true)) {
            $user->applyScope($bills, $isEnforcement ? 'assigned_enforcement_officer_id' : 'account_staff_id');
        }

        return $bills->get(['id', 'outstanding_balance', 'payment_status', 'case_status', 'total_tax_due', 'property_id', 'property_address', 'property_classification', 'account_staff_id', 'date_logged']);
    }

    private function dashboardBillCounts(Collection $rows): array
    {
        return [
            'total' => $rows->count(),
            'outstanding' => $rows->filter(fn ($b) => (float) $b->outstanding_balance > 0)->count(),
            'paid' => $rows->where('payment_status', 'Paid')->count(),
            'awaiting_assignment' => $rows->where('case_status', 'Awaiting Assignment')->count(),
        ];
    }

    private function dashboardBillArea(Collection $rows): array
    {
        return [
            'total_bills' => $rows->count(),
            'total_tax_due' => round((float) $rows->sum(fn ($b) => (float) $b->total_tax_due), 2),
            'outstanding' => round((float) $rows->sum(fn ($b) => (float) $b->outstanding_balance), 2),
            'amount_paid' => round((float) Payment::whereIn('bill_id', $rows->pluck('id')->all())->sum('amount'), 2),
            'amount_verified' => round((float) PaymentVerification::whereIn('bill_id', $rows->pluck('id')->all())
                ->whereIn('verification_status', ['Verified', 'Confirmed'])->sum('amount_claimed'), 2),
            'properties' => $rows->pluck('property_id')->filter(fn ($v) => $v !== null)->unique()->count(),
            'areas' => $rows->pluck('property_address')->filter(fn ($v) => $v !== null)->unique()->count(),
            'by_classification' => $rows->groupBy(fn ($b) => ($b->property_classification ?: '-'))->map->count()->sortKeys()->toArray(),
        ];
    }

    /** Target + live-achieved performance summary for the user. */
    private function performanceSummary(User $user, Collection $taskRows, Collection $billRows): array
    {
        if (app(\App\Services\ManagementPerformanceService::class)->isManagementRole($user)) {
            return [
                'classification' => 'management',
                'has_target' => false,
                'targets' => [],
                'overall' => [
                    'has_targets' => false,
                    'targets_count' => 0,
                    'target_value' => 0,
                    'achieved_value' => 0,
                    'completion_rate' => null,
                ],
            ];
        }

        // EVERY approved target for the user, not just one: a staff member can
        // hold several approved targets (different metrics/sections/periods) and
        // all of them belong in the Performance tab. The first active target (then
        // the latest approved one) keeps the legacy single-target summary fields
        // below intact for older clients.
        $targets = StaffTarget::where('user_id', $user->id)->where('status', 'Approved')
            ->orderByDesc('start_date')->orderByDesc('id')->get();

        $target = $targets->first(fn (StaffTarget $t) => $t->isActive()) ?? $targets->first();

        [$start, $end] = $target?->effectiveWindow() ?: [now()->startOfMonth(), now()];
        $achieved = $target ? (float) round($target->computeAchievedValue(), 2) : 0.0;

        $presentedTargets = $targets
            ->map(fn (StaffTarget $t) => $this->presentPerformanceTarget($t))
            ->values();

        $summary = [
            'has_target' => (bool) $target,
            'window' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'indicators' => $this->performanceIndicators($user, $start, $end, $taskRows, $billRows),
            'targets' => $presentedTargets->all(),
            // Weighted accumulated performance across exactly the targets listed
            // above, derived from the same presented target/achieved values.
            'overall' => $this->presentOverallPerformance($presentedTargets),
        ];

        if ($target) {
            $summary += [
                'metric' => $target->metric,
                'target_value' => (float) $target->target_value,
                'achieved_value' => $achieved,
                'completion_rate' => (float) $target->target_value > 0 ? round(($achieved / (float) $target->target_value) * 100, 1) : 0.0,
                'measurement_unit' => $target->measurement_unit,
                'frequency' => $target->frequency,
                'period' => $target->period,
            ];
        }

        return $summary;
    }

    /**
     * One approved target in the shape both clients render: identity, metric,
     * section, frequency, the target's own period dates, target/achieved values,
     * progress % and workflow status. Achieved is recomputed live with the
     * authoritative StaffTarget::computeAchievedValue() so the percentage can
     * never disagree with the number shown beside it.
     */
    private function presentPerformanceTarget(StaffTarget $target): array
    {
        $targetValue = (float) $target->target_value;
        $achieved = (float) round($target->computeAchievedValue(), 2);
        [$start, $end] = $target->effectiveWindow();

        return [
            'id' => $target->id,
            'metric' => $target->metric,
            'section' => $target->section,
            'frequency' => $target->frequency,
            'period' => $target->period,
            'start_date' => $target->start_date?->toDateString(),
            'end_date' => $target->end_date?->toDateString(),
            'window' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'target_value' => $targetValue,
            'achieved_value' => $achieved,
            'completion_rate' => $targetValue > 0 ? round(($achieved / $targetValue) * 100, 1) : 0.0,
            'measurement_unit' => $target->measurement_unit,
            'status' => $target->status,
            'is_active' => $target->isActive(),
        ];
    }

    /**
     * Weighted accumulated performance across every target the user can see.
     *
     * Combined % is Σachieved / Σtarget (never an average of the per-target
     * percentages), so differently sized targets contribute proportionately.
     * Achieved quantities are preserved as recorded even when they exceed the
     * target; only the displayed percentage is clamped to 0–100. A combined
     * target of zero has no meaningful ratio and is returned as null (the
     * clients render N/A) instead of dividing by zero.
     *
     * @param  Collection<int, array<string, mixed>>  $targets
     * @return array<string, mixed>
     */
    private function presentOverallPerformance(Collection $targets): array
    {
        $count = $targets->count();
        $targetTotal = round((float) $targets->sum('target_value'), 2);
        $achievedTotal = round((float) $targets->sum('achieved_value'), 2);

        $percentage = $targetTotal > 0
            ? round(max(0, min(($achievedTotal / $targetTotal) * 100, 100)), 1)
            : null;

        return [
            'has_targets' => $count > 0,
            'targets_count' => $count,
            'target_value' => $targetTotal,
            'achieved_value' => $achievedTotal,
            'completion_rate' => $percentage,
        ];
    }

    /** Role-relevant achievement indicators within the given window. */
    private function performanceIndicators(User $user, $start, $end, Collection $taskRows, Collection $billRows): array
    {
        $out = [
            'completed_tasks' => [
                'value' => $taskRows->where('assigned_to', $user->id)->whereIn('status', Task::COMPLETED_STATUSES)
                    ->filter(fn ($t) => $t->completed_at && $t->completed_at->between($start, $end))->count(),
                'label' => 'Tasks Completed',
            ],
        ];

        if ($user->hasAnyPermission(['enforcement.record_visit', 'enforcement.view_assignments'])) {
            $visits = EnforcementVisit::where('officer_id', $user->id)->whereBetween('visit_date', [$start, $end])
                ->get(['delivery_status']);

            $out['bills_delivered'] = [
                'value' => $visits->where('delivery_status', EnforcementVisit::DELIVERY_DELIVERED)->count(),
                'label' => 'Bills Delivered',
            ];
            $out['visits'] = [
                'value' => $visits->count(),
                'label' => 'Properties Visited',
            ];
            $out['payment_followups'] = [
                'value' => $taskRows->where('assigned_to', $user->id)->where('task_type', 'Payment Follow-up')
                    ->reject(fn ($t) => in_array($t->status, Task::COMPLETED_STATUSES, true))->count(),
                'label' => 'Payment Follow-ups',
            ];
        }

        if ($user->hasAnyPermission(['payments.view_queue', 'payments.verify', 'payments.view_history'])) {
            $verified = PaymentVerification::where('verified_by', $user->id)
                ->whereIn('verification_status', ['Verified', 'Confirmed'])->whereBetween('created_at', [$start, $end])
                ->get(['amount_claimed']);

            $out['payments_verified'] = [
                'value' => $verified->count(),
                'label' => 'Payments Verified',
            ];
            $out['collections_amount'] = [
                'value' => round((float) $verified->sum('amount_claimed'), 2),
                'label' => 'Amount Verified',
            ];
        }

        if ($user->hasAnyPermission(['discovery.create', 'discovery.view'])) {
            $out['discoveries'] = [
                'value' => PropertyDiscovery::where('discovered_by', $user->id)
                    ->whereBetween('discovery_date', [$start, $end])->count(),
                'label' => 'Properties Discovered',
            ];
        }

        if ($user->hasAnyPermission(['valuation.create', 'valuation.review', 'valuation.view_history'])) {
            $out['valuations'] = [
                'value' => Valuation::where('valuation_officer_id', $user->id)->whereBetween('created_at', [$start, $end])->count(),
                'label' => 'Valuations',
            ];
        }

        if ($user->canPermission('bills.create')) {
            $out['bills_logged'] = [
                'value' => $billRows->where('account_staff_id', $user->id)
                    ->filter(fn ($b) => $b->date_logged && $b->date_logged->between($start, $end))->count(),
                'label' => 'Bills Logged',
            ];
        }

        return $out;
    }

    /** Role-appropriate quick actions — hidden, never disabled, when unauthorised. */
    private function quickActions(User $user): array
    {
        $actions = [
            ['key' => 'tasks', 'label' => 'View My Tasks', 'icon' => '📋', 'perms' => ['tasks.view_own'], 'route' => 'Tasks'],
            ['key' => 'discovery', 'label' => 'New Property Discovery', 'icon' => '📍', 'perms' => ['discovery.create'], 'route' => 'NewDiscovery'],
            ['key' => 'visit', 'label' => 'Record Visit', 'icon' => '🚗', 'perms' => ['enforcement.record_visit'], 'route' => 'Tasks'],
            ['key' => 'receipt', 'label' => 'Submit Payment Receipt', 'icon' => '🧾', 'perms' => ['payments.claim'], 'route' => 'Tasks'],
            ['key' => 'verify', 'label' => 'Payment Verification', 'icon' => '✅', 'perms' => ['payments.verify', 'payments.reject', 'payments.view_queue'], 'route' => 'Verifications'],
            ['key' => 'valuations', 'label' => 'Valuations', 'icon' => '🏷️', 'perms' => ['valuation.create', 'valuation.review', 'valuation.view_history'], 'route' => 'Valuations'],
        ];

        if ($user->canPermission('gps.capture') && $user->canPermission('gps.certify')) {
            $actions[] = ['key' => 'gps', 'label' => 'Certify GPS', 'icon' => '📡', 'perms' => ['gps.capture'], 'route' => 'GpsCertify'];
        }

        return collect($actions)->filter(fn ($a) => $user->hasAnyPermission($a['perms']))
            ->map(fn ($a) => ['key' => $a['key'], 'label' => $a['label'], 'icon' => $a['icon'], 'route' => $a['route']])
            ->values()->all();
    }

    /** Permission-filtered analytics metrics offered on the Home charts. */
    private function chartMetrics(User $user): array
    {
        $metrics = [
            'tasks' => ['perm' => ['tasks.view_division', 'tasks.view_section', 'tasks.view_own'], 'label' => 'Tasks'],
            'bills' => ['perm' => ['bills.view'], 'label' => 'Bills', 'ledger' => true],
            'collections' => ['perm' => ['reports.view', 'bills.view'], 'label' => 'Collections'],
            'discoveries' => ['perm' => ['discovery.view', 'discovery.create'], 'label' => 'Discoveries'],
            'visits' => ['perm' => ['enforcement.view_assignments', 'enforcement.record_visit'], 'label' => 'Visits'],
            'valuations' => ['perm' => ['valuation.view_history', 'valuation.review', 'valuation.create'], 'label' => 'Valuations'],
            'payments' => ['perm' => ['payments.view_queue', 'payments.view_history'], 'label' => 'Payment Verification'],
            'targets' => ['perm' => ['targets.view'], 'label' => 'Target vs Actual'],
        ];

        $available = collect($metrics)
            ->filter(fn ($m) => ($m['ledger'] ?? false) ? $this->canViewBillLedger($user) : $user->hasAnyPermission($m['perm']))
            ->map(fn ($m) => $m['label'])->all();

        return $available;
    }

    /**
     * Priority items surfaced on the Home page. Each carries the action label,
     * urgency colour hint and the mobile route to take when tapped.
     */
    private function priorityActions(User $user, Collection $rows): array
    {
        $active = fn ($r) => $r->reject(fn ($t) => in_array($t->status, Task::COMPLETED_STATUSES, true));
        $overdue = $active($rows)->filter(fn ($t) => $t->isOverdue())->count();
        $dueToday = $active($rows)->filter(fn ($t) => $t->isDueToday())->count();
        $escalated = $rows->where('status', 'Escalated')->count();

        $out = [];

        if ($overdue > 0) {
            $out[] = ['key' => 'overdue', 'label' => 'Overdue', 'urgency' => 'overdue', 'count' => $overdue,
                'detail' => "{$overdue} task(s) are overdue.", 'action' => 'View', 'route' => 'Tasks', 'params' => null];
        }

        if ($dueToday > 0) {
            $out[] = ['key' => 'due_today', 'label' => 'Due Today', 'urgency' => 'today', 'count' => $dueToday,
                'detail' => "{$dueToday} task(s) are due today.", 'action' => 'Continue', 'route' => 'Tasks', 'params' => null];
        }

        if ($escalated > 0) {
            $out[] = ['key' => 'escalated', 'label' => 'Escalated', 'urgency' => 'escalated', 'count' => $escalated,
                'detail' => "{$escalated} case(s) are escalated.", 'action' => 'Take Action', 'route' => 'Tasks', 'params' => null];
        }

        if ($user->canPermission('payments.view_queue')) {
            $pendingQuery = PaymentVerification::where('verification_status', 'Pending');
            $this->applyBillScope($pendingQuery, $user);
            $pending = $pendingQuery->count();
            if ($pending > 0) {
                $out[] = ['key' => 'verify', 'label' => 'Payment Verification', 'urgency' => 'verify', 'count' => $pending,
                    'detail' => "{$pending} taxpayer claim(s) require verification.", 'action' => 'Verify Payment', 'route' => 'Verifications', 'params' => null];
            }
        }

        if ($user->canPermission('valuation.review')) {
            $review = Valuation::query();
            $user->applyScope($review, 'valuation_officer_id');
            $n = (clone $review)->where('status', Valuation::STATUS_SUBMITTED_TO_MANAGER)->count();
            if ($n > 0) {
                $out[] = ['key' => 'valuation_review', 'label' => 'Valuation Review', 'urgency' => 'action', 'count' => $n,
                    'detail' => "{$n} valuation(s) awaiting manager review.", 'action' => 'Review', 'route' => 'Valuations', 'params' => null];
            }
        }

        if ($user->canPermission('discovery.review')) {
            $discoveries = PropertyDiscovery::query();
            $user->applyScope($discoveries, 'discovered_by');
            $n = (clone $discoveries)->whereIn('status', [PropertyDiscovery::STATUS_AWAITING_ACCT_REVIEW, PropertyDiscovery::STATUS_AWAITING_VALUATION, PropertyDiscovery::STATUS_REFERRED_VALUATION])->count();
            if ($n > 0) {
                $out[] = ['key' => 'discovery_review', 'label' => 'Discovery Review', 'urgency' => 'action', 'count' => $n,
                    'detail' => "{$n} new discovery(ies) awaiting review.", 'action' => 'Review', 'route' => 'Discover', 'params' => null];
            }
        }

        if ($user->hasAnyPermission(['bills.create', 'records.view'])) {
            $pending = $rows->where('status', 'Awaiting Assignment')->count();
            if ($pending > 0) {
                $out[] = ['key' => 'awaiting_assignment', 'label' => 'Awaiting Assignment', 'urgency' => 'action', 'count' => $pending,
                    'detail' => "{$pending} bill(s) awaiting assignment.", 'action' => 'View', 'route' => 'Tasks', 'params' => null];
            }
        }

        return $out;
    }

    /** Most recent active field engagement for the officer's current task. */
    private function currentEngagement(User $user, Collection $rows): ?array
    {
        if (! $user->hasAnyPermission(['tasks.view_own', 'tasks.view_section', 'enforcement.record_visit', 'enforcement.view_assignments'])) {
            return null;
        }

        $task = $rows->where('assigned_to', $user->id)
            ->reject(fn ($t) => in_array($t->status, Task::COMPLETED_STATUSES, true))
            ->sortByDesc(fn ($t) => $t->updated_at ? $t->updated_at->timestamp : 0)->first();

        if (! $task) {
            return null;
        }

        $task->loadMissing('bill');
        $lastAction = $task->history()->orderByDesc('id')->first();
        $performer = $lastAction?->performed_by ? User::find($lastAction->performed_by)?->full_name : null;
        $visit = EnforcementVisit::where('task_id', $task->id)->orderByDesc('visit_date')->first();

        return [
            'task' => [
                'id' => $task->id,
                'task_reference' => $task->task_reference,
                'task_type' => $task->task_type,
                'status' => $task->status,
                'due_date' => $task->due_date?->toDateString(),
                'updated_at' => $task->updated_at?->toISOString(),
            ],
            'bill' => $task->bill ? [
                'id' => $task->bill->id,
                'property_id' => $task->bill->property_id,
                'tin' => $task->bill->tin,
                'property_address' => $task->bill->property_address,
                'document_number' => $task->bill->document_number,
                'total_tax_due' => (float) $task->bill->total_tax_due,
                'outstanding_balance' => (float) $task->bill->outstanding_balance,
                'payment_status' => $task->bill->payment_status,
                'case_status' => $task->bill->case_status,
            ] : null,
            'last_action' => [
                'label' => $lastAction?->action ?: ($visit ? 'Field visit recorded' : null),
                'performed_by' => $performer,
                'at' => $lastAction?->created_at?->toISOString() ?: $visit?->visit_date?->toISOString(),
            ],
        ];
    }

    /** Recent activity generated from the user's own operations, newest first. */
    private function recentActivity(User $user, Collection $rows): array
    {
        $items = [];

        $taskIds = $rows->where('assigned_to', $user->id)->pluck('id')->values()->all();

        if (! empty($taskIds)) {
            $history = TaskHistory::whereIn('task_id', $taskIds)->orderByDesc('id')->limit(8)->get();
            $refs = $rows->whereIn('id', $history->pluck('task_id')->unique()->values()->all())->pluck('task_reference', 'id')->all();

            $performerIds = $history->pluck('performed_by')->filter()->unique()->values()->all();
            $actors = collect($performerIds)->isNotEmpty()
                ? User::whereIn('id', $performerIds)->pluck('full_name', 'id')
                : collect();

            foreach ($history as $h) {
                $items[] = [
                    'type' => 'task', 'label' => $h->action ?: 'Task updated',
                    'ref' => $refs[$h->task_id] ?? null, 'status' => $h->to_status,
                    'user' => $actors[$h->performed_by] ?? null, 'at' => $h->created_at?->toISOString(),
                ];
            }
        }

        foreach (EnforcementVisit::where('officer_id', $user->id)->orderByDesc('visit_date')->limit(4)->get() as $v) {
            $items[] = [
                'type' => 'visit', 'label' => 'Visit recorded', 'ref' => $v->property_id,
                'status' => $v->visit_status ?: $v->delivery_status, 'user' => null, 'at' => $v->visit_date?->toISOString(),
            ];
        }

        foreach (PaymentVerification::where('verified_by', $user->id)->whereIn('verification_status', ['Verified', 'Confirmed'])
            ->orderByDesc('id')->limit(4)->get() as $p) {
            $items[] = [
                'type' => 'payment', 'label' => 'Payment verified', 'ref' => $p->document_number,
                'status' => $p->verification_status, 'user' => null, 'at' => $p->verified_at?->toISOString() ?: $p->created_at?->toISOString(),
            ];
        }

        foreach (PropertyDiscovery::where('discovered_by', $user->id)->orderByDesc('id')->limit(4)->get() as $d) {
            $items[] = [
                'type' => 'discovery', 'label' => 'Property discovered', 'ref' => $d->discovery_reference,
                'status' => $d->status, 'user' => null, 'at' => $d->created_at?->toISOString(),
            ];
        }

        foreach (Valuation::where('valuation_officer_id', $user->id)->orderByDesc('id')->limit(4)->get() as $v) {
            $items[] = [
                'type' => 'valuation', 'label' => 'Valuation activity', 'ref' => $v->valuation_reference,
                'status' => $v->status, 'user' => null, 'at' => $v->created_at?->toISOString(),
            ];
        }

        usort($items, fn ($a, $b) => strcmp((string) ($b['at'] ?? ''), (string) ($a['at'] ?? '')));

        return array_slice($items, 0, 12);
    }

    /**
     * Division Command dashboard — KPI snapshot, section splits, discovery
     * pipeline, staff performance and target achievement. Managers receive the
     * same shape scoped to their section; staff see their own numbers.
     */
    public function division(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['dashboard.view_division', 'dashboard.view_section']), 403, 'Missing permission to view command dashboard.');

        $scope = $user->scopeLevel();

        $tasks = Task::query();
        $bills = PropertyBill::query();
        $valuations = Valuation::query();
        $discoveries = PropertyDiscovery::query();
        $visits = EnforcementVisit::query();
        $payments = PaymentVerification::query();

        if (in_array($scope, ['own', 'team', 'section'], true)) {
            $user->applyScope($tasks, 'assigned_to');
            $isEnforcement = $user->hasAnyPermission(['enforcement.record_visit', 'enforcement.upload_evidence'])
                || ($user->section && $user->section->code === 'ENF');
            $user->applyScope($bills, $isEnforcement ? 'assigned_enforcement_officer_id' : 'account_staff_id');
            $user->applyScope($discoveries, 'discovered_by');
            $user->applyScope($visits, 'officer_id');
            $user->applyScope($payments, 'verified_by');
            if ($scope === 'own') {
                $valuations->where('valuation_officer_id', $user->id);
            } elseif ($scope === 'section') {
                $valuations->whereHas('valuationOfficer', fn ($q) => $q->where('section_id', $user->section_id));
            } else {
                $valuations->where('valuation_officer_id', $user->id)
                    ->orWhereHas('valuationOfficer', fn ($q) => $q->where('supervisor_id', $user->id));
            }
        }

        [$rangeStart, $rangeEnd] = $this->rangeBounds($request);

        // Sections are pulled from the database, never hard-coded. Section-level
        // users only see their own section; managers/division see every active one.
        $sectionRows = $this->visibleSections($user);
        $managementPerformance = app(ManagementPerformanceService::class);
        $managementSummary = $managementPerformance->isManagementRole($user)
            ? $managementPerformance->summarize($user)
            : null;

        $data = [
            'scope' => $scope,
            'role' => $user->role?->name,
            'as_of' => now()->toISOString(),
            'range' => ['start' => $rangeStart->toDateString(), 'end' => $rangeEnd->toDateString()],
            'kpis' => [
                'tasks' => [
                    'active' => (clone $tasks)->whereNotIn('status', Task::COMPLETED_STATUSES)->count(),
                    'awaiting_assignment' => (clone $tasks)->where('status', 'Awaiting Assignment')->count(),
                    'overdue' => (clone $tasks)->overdue()->count(),
                    'escalated' => (clone $tasks)->where('status', 'Escalated')->count(),
                    'completed' => (clone $tasks)->whereIn('status', Task::COMPLETED_STATUSES)->whereBetween('completed_at', [$rangeStart, $rangeEnd])->count(),
                ],
                'bills' => [
                    'total' => (clone $bills)->count(),
                    'outstanding' => (clone $bills)->where('outstanding_balance', '>', 0)->count(),
                    'awaiting_assignment' => (clone $bills)->where('case_status', 'Awaiting Assignment')->count(),
                    'logged' => (clone $bills)->whereBetween('created_at', [$rangeStart, $rangeEnd])->count(),
                    'amount_verified' => (float) round((clone $payments)->whereIn('verification_status', ['Verified', 'Confirmed'])->sum('amount_claimed'), 2),
                ],
                'valuations' => [
                    'in_review' => (clone $valuations)->whereIn('status', [Valuation::STATUS_SUBMITTED_TO_MANAGER, Valuation::STATUS_UNDER_MANAGER_REVIEW])->count(),
                    'ac_approval' => (clone $valuations)->whereIn('status', [Valuation::STATUS_SUBMITTED_TO_AC, Valuation::STATUS_AWAITING_AC_APPROVAL])->count(),
                    'approved' => (clone $valuations)->where('status', Valuation::STATUS_AC_APPROVED)->whereBetween('ac_reviewed_at', [$rangeStart, $rangeEnd])->count(),
                    'approved_30d' => (clone $valuations)->where('status', Valuation::STATUS_AC_APPROVED)->whereBetween('ac_reviewed_at', [$rangeStart, $rangeEnd])->count(),
                    'total_assessed' => (float) round((clone $valuations)->whereBetween('submitted_at', [$rangeStart, $rangeEnd])->sum('total_property_value'), 2),
                    'total_assessed_30d' => (float) round((clone $valuations)->whereBetween('submitted_at', [$rangeStart, $rangeEnd])->sum('total_property_value'), 2),
                    'tax_impact' => (float) round((clone $valuations)->whereBetween('submitted_at', [$rangeStart, $rangeEnd])->sum('total_tax_payable'), 2),
                    'tax_impact_30d' => (float) round((clone $valuations)->whereBetween('submitted_at', [$rangeStart, $rangeEnd])->sum('total_tax_payable'), 2),
                ],
                'payments' => [
                    'pending' => (clone $payments)->where('verification_status', 'Pending')->count(),
                    'verified' => (clone $payments)->whereIn('verification_status', ['Verified', 'Confirmed'])->whereBetween('updated_at', [$rangeStart, $rangeEnd])->count(),
                    'amount_verified' => (float) round((clone $payments)->whereIn('verification_status', ['Verified', 'Confirmed'])->whereBetween('updated_at', [$rangeStart, $rangeEnd])->sum('amount_claimed'), 2),
                ],
                'visits' => [
                    'today' => (clone $visits)->whereDate('visit_date', today())->count(),
                    'delivered' => (clone $visits)->where('delivery_status', EnforcementVisit::DELIVERY_DELIVERED)->count(),
                ],
            ],
            'discovery_pipeline' => $this->discoveryPipeline($discoveries),
            'sections' => $this->sectionBreaks($user, $sectionRows, $tasks, $bills, $rangeStart, $rangeEnd),
            'staff_performance' => $this->staffPerformance($request),
            'target_averages' => $managementSummary === null ? $this->targetAverages() : [],
            'management_performance' => $managementSummary,
            'task_statuses' => (clone $tasks)
                ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->toArray(),
            'section_performance' => $this->sectionPerformance($user, $sectionRows, $tasks, $rangeStart, $rangeEnd),
            'focus' => $this->divisionFocus($user, $scope, $tasks, $bills, $valuations, $payments),
        ];

        if ($scope === 'division' || $scope === 'system') {
            $logs = AuditLog::query()
                ->whereIn('action', ['task.assigned', 'task.completed', 'task.escalated', 'bill.logged', 'visit.recorded', 'valuation.submitted', 'valuation.decided', 'valuation.account_approved', 'payment.verified', 'discovery.created', 'discovery.approved', 'target.approved'])
                ->orderByDesc('id')->limit(12)->get();

            $actorIds = $logs->pluck('actor_id')->filter()->unique()->values()->all();
            $actors = User::whereIn('id', $actorIds)->pluck('full_name', 'id');

            $data['recent_activity'] = $logs->map(fn ($a) => [
                'id' => $a->id,
                'user' => $actors[$a->actor_id] ?? 'System',
                'action' => $a->action,
                'context' => is_array($a->new_values)
                    ? ($a->new_values['discovery_reference']
                        ?? $a->new_values['valuation_reference']
                        ?? $a->new_values['task_reference']
                        ?? $a->new_values['photo_reference']
                        ?? $a->auditable_type)
                    : $a->auditable_type,
                'created_at' => $a->created_at?->toISOString(),
            ])->all();
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Drill listing used by every dashboard tab — tables, filters and flat
     * paginated rows are driven entirely from the client-supplied parameters.
     */
    private const DRILL_PERMS = [
        'tasks' => ['tasks.view_own', 'tasks.view_section', 'tasks.view_division'],
        'bills' => ['bills.view', 'records.view'],
        'valuations' => ['valuation.view_history', 'valuation.review', 'valuation.approve'],
        'visits' => ['tasks.view_own', 'enforcement.record_visit'],
        'discoveries' => ['discovery.view', 'discovery.create', 'discovery.review'],
        'staff' => ['staff.view'],
        'targets' => ['targets.view'],
        'payments' => ['payments.view_queue', 'payments.view_history'],
        'queries' => ['dashboard.view_division', 'dashboard.view_section', 'dashboard.view_own'],
        'flags' => ['dashboard.view_division', 'dashboard.view_section', 'dashboard.view_own'],
    ];

    public function drill(Request $request)
    {
        $user = $request->user();

        $table = $request->query('table');
        abort_unless(in_array($table, array_keys(self::DRILL_PERMS), true), 422, 'Unknown drill table.');

        // Permission check: ensure the user has a relevant permission for the table.
        abort_unless(
            $user->hasAnyPermission(self::DRILL_PERMS[$table]),
            403,
            'Missing permission to drill into '.$table
        );

        // Build the base query (without scope restrictions initially, so we can apply them below).
        $rows = match ($table) {
            'tasks' => $this->drillTasks($request),
            'bills' => $this->drillBills($request),
            'valuations' => $this->drillValuations($request),
            'visits' => $this->drillVisits($request),
            'discoveries' => $this->drillDiscoveries($request),
            'staff' => $this->drillStaff($request),
            'targets' => $this->drillTargets($request),
            'payments' => $this->drillPayments($request),
            'queries' => $this->drillQueries($request),
            'flags' => $this->drillFlags($request),
        };

        // Apply the user's data scope — this is the critical boundary.
        // Without this, a tasks.view_own officer can paginate national data.
        $this->applyDrillScope($table, $rows, $user);

        $filtered = $this->applyDrillFilters($request, $rows);

        $page = $filtered->orderBy($table === 'bills' ? 'tin' : 'id', $table === 'bills' ? 'asc' : 'desc')
            ->orderByDesc('id')
            ->paginate($request->query('per_page', 25))
            ->withQueryString();

        $page->getCollection()->transform(fn ($r) => $table === 'bills'
                ? $this->presentDrillBill($r)
                : $this->flattenDrillRow($r)
        );

        return response()->json(['data' => $page]);
    }

    /**
     * On-demand data integrity audit. Recomputes every stored dashboard
     * figure from the authoritative transactional records and reports any
     * drift, so the dashboard can be verified as the single source of truth.
     */
    public function integrity(Request $request)
    {
        $user = $request->user();

        abort_unless(
            $user->canPermission('audit.view'),
            403,
            'Missing permission to run the data integrity audit.'
        );

        $result = DataIntegrityService::run();

        return response()->json(['data' => $result]);
    }

    /* ---------- drill row builders ---------- */

    private function drillTasks(Request $request)
    {
        $rows = Task::query()->with('assignedTo:id,full_name');
        $rows->when($request->query('section'), fn ($b, $s) => $b->where('section', $s))
            ->when($request->query('status'), fn ($b, $s) => $b->where('status', $s))
            ->when($request->query('my') === '1', fn ($b) => $b->where('assigned_to', $request->user()->id));

        return $rows;
    }

    private function drillBills(Request $request)
    {
        $rows = PropertyBill::query()->with(['accountStaff:id,full_name', 'enforcementOfficer:id,full_name']);
        $rows->when($request->query('case_status'), fn ($b, $s) => $b->where('case_status', $s))
            ->when($request->query('recipient_type'), fn ($b, $s) => $b->where('recipient_type', $s))
            ->when($request->query('logged_by'), fn ($b, $id) => $b->where('account_staff_id', (int) $id))
            ->when($request->query('assigned_to'), fn ($b, $id) => $b->where('assigned_enforcement_officer_id', (int) $id))
            ->when($request->query('q'), fn ($b, $q) => $b->where(function ($i) use ($q) {
                $i->where('property_id', 'like', like_term($q))->orWhere('tin', 'like', like_term($q))->orWhere('taxpayer_name', 'like', like_term($q))->orWhere('document_number', 'like', like_term($q));
            }));

        return $rows;
    }

    private function presentDrillBill(PropertyBill $b): array
    {
        return [
            'id' => $b->id,
            'tin' => $b->tin,
            'property_id' => $b->property_id,
            'document_number' => $b->document_number,
            'taxpayer_name' => $b->taxpayer_name,
            'property_address' => $b->property_address,
            'property_classification' => $b->property_classification,
            'property_type' => $b->property_type,
            'tax_period' => $b->tax_period,
            'assessed_value' => $b->assessed_value,
            'tax_amount' => $b->tax_amount,
            'interest_charged' => $b->interest_charged,
            'penalty_charged' => $b->penalty_charged,
            'total_tax_due' => $b->total_tax_due,
            'outstanding_balance' => $b->outstanding_balance,
            'case_status' => $b->case_status,
            'payment_status' => $b->payment_status,
            'account_staff' => $b->relationLoaded('accountStaff') ? $b->accountStaff?->full_name : null,
            'enforcement_officer' => $b->relationLoaded('enforcementOfficer') ? $b->enforcementOfficer?->full_name : null,
            'date_logged' => $b->date_logged?->toDateString(),
        ];
    }

    /**
     * Flatten a drill row for non-bill tables so that any eager-loaded
     * "person" relationship ({id, full_name}) is serialised as a plain string
     * (the person's display name or email) instead of a raw object. This keeps
     * the drill tables renderable by the SPA and mirrors the bill presenter,
     * which already flattens accountStaff/enforcementOfficer.
     */
    private function flattenDrillRow($row): array
    {
        $out = $row->toArray();

        $nameKeys = [
            'assigned_to', 'assignedTo', 'account_staff', 'accountStaff',
            'enforcement_officer', 'enforcementOfficer', 'valuation_officer',
            'valuationOfficer', 'officer', 'discovered_by', 'discoverer',
            'created_by', 'createdBy', 'staff', 'user',
        ];

        foreach ($nameKeys as $key) {
            if (isset($out[$key]) && is_array($out[$key]) && array_key_exists('full_name', $out[$key])) {
                $out[$key === 'assigned_to' || $key === 'assignedTo' ? 'assigned_to' : $key] = $out[$key]['full_name'] ?? ($out[$key]['email'] ?? null);
            }
        }

        // role / section relations → name string
        foreach (['role', 'section'] as $key) {
            if (isset($out[$key]) && is_array($out[$key])) {
                $out[$key] = $out[$key]['name'] ?? ($out[$key]['code'] ?? ($out[$key]['title'] ?? null));
            }
        }

        // flags: collapse the bill relation to its number
        if (isset($out['bill']) && is_array($out['bill'])) {
            $out['bill'] = $out['bill']['document_number'] ?? ($out['bill']['property_id'] ?? null);
        }

        // targets: derive achievement % from achieved vs target when absent
        if (! isset($out['achievement_pct']) && (float) ($out['target_value'] ?? 0) > 0) {
            $out['achievement_pct'] = round(((float) ($out['achieved_value'] ?? 0) / (float) $out['target_value']) * 100);
        }

        return $out;
    }

    private function drillValuations(Request $request)
    {
        return Valuation::query()->with('valuationOfficer:id,full_name')
            ->when($request->query('status'), fn ($b, $s) => $b->where('status', $s));
    }

    private function drillVisits(Request $request)
    {
        return EnforcementVisit::query()->with('officer:id,full_name')
            ->when($request->query('officer_id'), fn ($b, $id) => $b->where('officer_id', $id))
            ->when($request->query('delivery_status'), fn ($b, $s) => $b->where('delivery_status', $s));
    }

    private function drillDiscoveries(Request $request)
    {
        return PropertyDiscovery::query()->with('discoverer:id,full_name')
            ->when($request->query('status'), function ($b, $s) {
                $statuses = array_filter(array_map('trim', explode(',', (string) $s)));
                count($statuses) > 1
                    ? $b->whereIn('status', $statuses)
                    : $b->where('status', reset($statuses));
            })
            ->when($request->query('decision_path'), fn ($b, $s) => $b->where('decision_path', $s))
            ->when($request->query('value_declaration_type'), fn ($b, $s) => $b->where('value_declaration_type', $s));
    }

    private function drillStaff(Request $request)
    {
        return User::query()->with('role:id,name', 'section:id,name,code')->where('is_active', true)
            ->when($request->query('section'), fn ($b, $s) => $b->whereHas('section', fn ($x) => $x->where('code', $s)))
            ->when($request->query('role'), fn ($b, $r) => $b->whereHas('role', fn ($x) => $x->where('name', $r)));
    }

    private function drillTargets(Request $request)
    {
        $rows = StaffTarget::query()->with('user:id,full_name');
        $rows->when($request->query('status'), fn ($b, $s) => $b->where('status', $s))
            ->when($request->query('metric'), fn ($b, $s) => $b->where('metric', $s))
            ->when($request->query('period'), fn ($b, $s) => $b->where('period', $s));

        return $rows;
    }

    private function drillPayments(Request $request)
    {
        return PaymentVerification::query()
            ->when($request->query('verification_status'), fn ($b, $s) => $b->where('verification_status', $s));
    }

    private function drillQueries(Request $request)
    {
        return MeQuery::query()
            ->when($request->query('status'), fn ($b, $s) => $b->where('status', $s));
    }

    private function drillFlags(Request $request)
    {
        return DataQualityFlag::query()->with('bill:id,document_number,property_id,taxpayer_name')
            ->when($request->query('status'), fn ($b, $s) => $b->where('status', $s));
    }

    private function applyDrillFilters(Request $request, $query)
    {
        if ($from = $request->query('from')) {
            $query->whereDate($request->query('date_col', 'created_at'), '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->whereDate($request->query('date_col', 'created_at'), '<=', $to);
        }

        return $query;
    }

    /** Shared temporal window for all range-aware dashboard sections. */
    private function rangeBounds(Request $request): array
    {
        $range = $request->query('range', 'month'); // today|week|month|quarter|year|custom
        $start = match ($range) {
            'today' => today(),
            'week' => now()->startOfWeek(),
            'quarter' => now()->startOfQuarter(),
            'year' => now()->startOfYear(),
            'custom' => $request->query('from') ?: now()->startOfMonth(),
            default => now()->startOfMonth(),
        };

        return [$start, $request->query('to') ?: now()];
    }

    private function staffPerformance(Request $request): array
    {
        $user = $request->user();
        $scope = $user->scopeLevel();
        [$start, $end] = $this->rangeBounds($request);

        $users = User::query()->where('is_active', true);
        if ($scope === 'section') {
            $users->where('section_id', $user->section_id);
        } elseif ($scope === 'team') {
            $users->where(fn ($b) => $b->where('id', $user->id)->orWhere('supervisor_id', $user->id));
        } elseif ($scope === 'own') {
            $users->where('id', $user->id);
        }

        return $users->limit(60)->get()->map(function ($u) use ($start, $end) {
            return [
                'id' => $u->id,
                'name' => $u->full_name,
                'role' => $u->role?->name,
                'section' => $u->section?->code,
                'deliveries' => EnforcementVisit::where('officer_id', $u->id)->where('delivery_status', EnforcementVisit::DELIVERY_DELIVERED)->whereBetween('visit_date', [$start, $end])->count(),
                'visits' => EnforcementVisit::where('officer_id', $u->id)->whereBetween('visit_date', [$start, $end])->count(),
                'valuations' => Valuation::where('valuation_officer_id', $u->id)->whereBetween('created_at', [$start, $end])->count(),
                'verifications' => PaymentVerification::where('verified_by', $u->id)->whereBetween('created_at', [$start, $end])->count(),
                'completed_tasks' => Task::where('assigned_to', $u->id)->whereIn('status', Task::COMPLETED_STATUSES)
                    ->whereBetween('completed_at', [$start, $end])->count(),
                'overdue' => Task::where('assigned_to', $u->id)->overdue()->count(),
            ];
        })->values()->all();
    }

    /** Per-section output within the selected range — for the BI chart band. */
    private function visibleSections(User $user): \Illuminate\Database\Eloquent\Collection
    {
        $rows = Section::query()->where('is_active', true)->orderBy('name');

        if (in_array($user->scopeLevel(), ['own', 'team', 'section'], true) && $user->section_id) {
            $rows->where('id', $user->section_id);
        }

        return $rows->get(['id', 'name', 'code']);
    }

    /** Pipeline stages counted only against the stages valid for each path. */
    private function discoveryPipeline(Builder $discoveries): array
    {
        $s = PropertyDiscovery::class;

        // No classification stage: submit() classifies and routes in one step, so
        // `Classified` is never written and its counter could only ever read zero.
        return [
            'draft' => (clone $discoveries)->where('status', $s::STATUS_DRAFT)->count(),
            'pending_review' => (clone $discoveries)->whereIn('status', [$s::STATUS_AWAITING_ACCT_REVIEW, $s::STATUS_AWAITING_VALUATION, $s::STATUS_REFERRED_VALUATION])->count(),
            'awaiting_account' => (clone $discoveries)->where('decision_path', 'account')->whereIn('status', [$s::STATUS_AWAITING_ACCT_REVIEW, $s::STATUS_APPROVED, $s::STATUS_ASSIGNED_ACCT, $s::STATUS_REGISTERED])->count(),
            'awaiting_valuation' => (clone $discoveries)->where('decision_path', 'valuation')->whereIn('status', [$s::STATUS_AWAITING_VALUATION, $s::STATUS_REFERRED_VALUATION])->count(),
            'under_valuation' => (clone $discoveries)->where('decision_path', 'valuation')->whereIn('status', [$s::STATUS_UNDER_VALUATION, $s::STATUS_AWAITING_AC_APPROVAL])->count(),
            'completed' => (clone $discoveries)->whereIn('status', [$s::STATUS_APPROVED, $s::STATUS_REGISTERED, $s::STATUS_COMPLETED])->count(),
            'rejected' => (clone $discoveries)->where('status', $s::STATUS_REJECTED)->count(),
        ];
    }

    /** Per-section breakdown cards, derived from the database section list. */
    private function sectionBreaks(User $user, $sectionRows, Builder $tasks, Builder $bills, $start, $end): array
    {
        $openMeOnly = in_array($user->scopeLevel(), ['division', 'system'], true) || $user->canPermission('dashboard.view_division');

        $out = [];
        foreach ($sectionRows as $section) {
            $row = [
                'id' => $section->id,
                'code' => $section->code,
                'staff' => User::where('section_id', $section->id)->where('is_active', true)->count(),
                'active_tasks' => (clone $tasks)->where('section', $section->name)->whereNotIn('status', Task::COMPLETED_STATUSES)->count(),
                'completed' => (clone $tasks)->where('section', $section->name)->whereIn('status', Task::COMPLETED_STATUSES)
                    ->whereBetween('completed_at', [$start, $end])->count(),
                'overdue' => (clone $tasks)->where('section', $section->name)->overdue()->count(),
            ];

            if ($section->code === 'ENF') {
                $row['deliveries'] = EnforcementVisit::whereIn('officer_id', User::where('section_id', $section->id)->select('id'))
                    ->where('delivery_status', EnforcementVisit::DELIVERY_DELIVERED)->whereBetween('visit_date', [$start, $end])->count();
            }
            if ($section->code === 'ACCT') {
                $row['awaiting_assignment'] = (clone $bills)->where('case_status', 'Awaiting Assignment')->count();
            }
            if ($section->code === 'VAL') {
                $row['in_review'] = Valuation::whereHas('valuationOfficer', fn ($q) => $q->where('section_id', $section->id))
                    ->whereIn('status', [Valuation::STATUS_SUBMITTED_TO_MANAGER, Valuation::STATUS_UNDER_MANAGER_REVIEW])->count();
            }
            if ($section->code === 'M&A' || $section->code === 'M&E') {
                if ($openMeOnly) {
                    $row['open_queries'] = MeQuery::whereNotIn('status', ['Closed', 'Resolved'])->count();
                    $row['open_flags'] = DataQualityFlag::where('status', 'Open')->count();
                }
            }

            $out[$section->name] = $row;
        }

        return $out;
    }

    /**
     * Role-specific focus for the current user — enforcement shows deliverability,
     * valuation managers the review queue, accountants the assignment backlog,
     * M&E the open queries/flags and discovery officers the pipeline.
     */
    private function divisionFocus(User $user, string $scope, Builder $tasks, Builder $bills, Builder $valuations, Builder $payments): array
    {
        $focus = ['role' => $user->role?->name, 'scope' => $scope, 'panels' => []];

        $active = fn (Builder $q) => $q->whereNotIn('status', Task::COMPLETED_STATUSES);

        if ($user->hasAnyPermission(['enforcement.view_assignments', 'enforcement.record_visit', 'enforcement.view_section'])) {
            $focus['panels'][] = [
                'key' => 'delivery',
                'label' => 'Deliverability',
                'metrics' => [
                    'out_for_delivery' => (clone $tasks)->whereIn('status', ['Out for Delivery', 'Assigned'])->count(),
                    'delivered' => (clone $tasks)->where('status', 'Delivered')->count(),
                    'overdue' => (clone $tasks)->tap($active)->overdue()->count(),
                ],
            ];
        }

        if ($user->canPermission('valuation.review') || $user->canPermission('valuation.approve') || $user->canPermission('valuation.forward_ac')) {
            $focus['panels'][] = [
                'key' => 'valuation_review',
                'label' => 'Valuation Review Queue',
                'metrics' => [
                    // The workflow writes one review state — an officer's submit() moves
                    // the assessment to `Submitted to Manager` — and the manager acts on
                    // it from there. `Under Manager Review` is never written, so a metric
                    // built on it, split off from the records actually waiting, could only
                    // read zero while the queue was full. One stage, one metric.
                    'manager_review' => (clone $valuations)->whereIn('status', Valuation::statusesForStage('Awaiting Review'))->count(),
                    'ac_approval' => (clone $valuations)->whereIn('status', [Valuation::STATUS_SUBMITTED_TO_AC, Valuation::STATUS_AWAITING_AC_APPROVAL])->count(),
                ],
            ];
        }

        if ($user->hasAnyPermission(['bills.view', 'records.view', 'bills.create'])) {
            $focus['panels'][] = [
                'key' => 'assignments',
                'label' => 'Assignment Backlog',
                'metrics' => [
                    'awaiting_assignment' => (clone $bills)->where('case_status', 'Awaiting Assignment')->count(),
                    'outstanding' => (clone $bills)->where('outstanding_balance', '>', 0)->count(),
                    'pending_verification' => (clone $payments)->where('verification_status', 'Pending')->count(),
                ],
            ];
        }

        if ($user->hasAnyPermission(['discovery.review', 'discovery.create', 'discovery.view'])) {
            $focus['panels'][] = [
                'key' => 'discovery',
                'label' => 'Discovery Pipeline',
                'metrics' => [
                    'pending_review' => PropertyDiscovery::whereIn('status', [PropertyDiscovery::STATUS_AWAITING_ACCT_REVIEW, PropertyDiscovery::STATUS_AWAITING_VALUATION, PropertyDiscovery::STATUS_REFERRED_VALUATION])->count(),
                    'awaiting_valuation' => PropertyDiscovery::where('decision_path', 'valuation')->whereIn('status', [PropertyDiscovery::STATUS_AWAITING_VALUATION, PropertyDiscovery::STATUS_REFERRED_VALUATION])->count(),
                    'awaiting_account' => PropertyDiscovery::where('decision_path', 'account')->whereIn('status', [PropertyDiscovery::STATUS_AWAITING_ACCT_REVIEW, PropertyDiscovery::STATUS_APPROVED, PropertyDiscovery::STATUS_ASSIGNED_ACCT, PropertyDiscovery::STATUS_REGISTERED])->count(),
                ],
            ];
        }

        if ($user->section && in_array($user->section->code, ['M&E', 'M&A'], true)) {
            $focus['panels'][] = [
                'key' => 'quality',
                'label' => 'Data Quality & Queries',
                'metrics' => [
                    'open_queries' => MeQuery::whereNotIn('status', ['Closed', 'Resolved'])->count(),
                    'open_flags' => DataQualityFlag::where('status', 'Open')->count(),
                ],
            ];
        }

        return $focus;
    }

    /** Per-section output within the selected range — for the BI chart band. */
    private function sectionPerformance(User $user, $sectionRows, Builder $tasks, $start, $end): array
    {
        $out = [];
        foreach ($sectionRows as $section) {
            $out[$section->name] = [
                'id' => $section->id,
                'code' => $section->code,
                'staff' => User::where('section_id', $section->id)->where('is_active', true)->count(),
                'active_tasks' => (clone $tasks)->where('section', $section->name)->whereNotIn('status', Task::COMPLETED_STATUSES)->count(),
                'completed' => (clone $tasks)->where('section', $section->name)->whereIn('status', Task::COMPLETED_STATUSES)
                    ->whereBetween('completed_at', [$start, $end])->count(),
                'overdue' => (clone $tasks)->where('section', $section->name)->overdue()->count(),
            ];
        }

        return $out;
    }

    private function targetAverages(): array
    {
        $rows = StaffTarget::where('status', 'Approved')->get();
        $grouped = $rows->groupBy('metric');

        return $grouped->map(function ($group) {
            $t = $group->first();
            $achieved = round($group->sum(fn (StaffTarget $x) => $x->computeAchievedValue()), 2);

            return [
                'metric' => $t->metric,
                'users' => $group->count(),
                'target_total' => round($group->sum('target_value'), 2),
                'achieved_total' => $achieved,
                'pct' => $t->target_value > 0 ? round(($achieved / max(0, $group->sum('target_value'))) * 100, 1) : 0,
                'measurement_unit' => $t->measurement_unit,
            ];
        })->values()->all();
    }

    /**
     * Drill listing must respect the caller's data scope, matching each
     * module's own scoping semantics. Division/system and dashboard-wide
     * permissions see everything; everyone else sees only their slice.
     */
    private function applyDrillScope(string $table, $rows, User $user): void
    {
        $scope = $user->scopeLevel();

        // Division/system scope users see all records — no further restriction needed.
        if (in_array($scope, ['division', 'system'], true)
            || $user->canPermission('dashboard.view_division')) {
            return;
        }

        // For all other scopes (own, team, section), restrict the drill results
        // to the user's authorized data range. This prevents a tasks.view_own
        // officer from paginating the entire national dataset.

        $applyUsers = function ($q, string $col) use ($user, $scope) {
            $ids = match ($scope) {
                'section' => array_values(User::where('section_id', $user->section_id)->where('is_active', true)->pluck('id')->all()),
                'team' => array_values(array_merge([$user->id], User::where('supervisor_id', $user->id)->where('is_active', true)->pluck('id')->all())),
                default => [$user->id],
            };

            return $q->whereIn($col, $ids);
        };

        // Ensure the base query builder has the appropriate WHERE clauses applied.
        // We re-apply the scope by re-building the query with scope filters where
        // the drill builder didn't fully restrict the dataset.

        switch ($table) {
            case 'tasks':
                // DrillTasks already has a 'my' filter; ensure own/team/scope is also applied.
                if ($scope === 'own') {
                    $rows->where('assigned_to', $user->id);
                } elseif ($scope === 'team') {
                    $rows->where(function ($q) use ($user) {
                        $q->where('assigned_to', $user->id)
                            ->orWhereIn('assigned_to', function ($qq) {
                                $qq->select('id')->from('users')
                                    ->where('supervisor_id', $user->id)
                                    ->where('is_active', true);
                            });
                    });
                }
                // section scope: tasks in the user's section
                else {
                    $rows->where('section', $user->section_id);
                }
                break;

            case 'bills':
                // Restrict bills to those associated with the user's authorized tasks/property.
                $rows->whereIn('id', $this->drillBillIds($user, $scope));
                break;

            case 'payments':
            case 'flags':
                // Restrict payments/flags to bills within the user's authorized scope.
                $rows->whereIn('bill_id', $this->drillBillIds($user, $scope));
                break;

            case 'valuations':
                // Restrict valuations to those assigned to the user or their team.
                if ($scope === 'own') {
                    $rows->where('valuation_officer_id', $user->id);
                } elseif ($scope === 'team') {
                    $rows->where(function ($q) use ($user) {
                        $q->where('valuation_officer_id', $user->id)
                            ->orWhereIn('valuation_officer_id', User::where('supervisor_id', $user->id)->where('is_active', true)->pluck('id')->all());
                    });
                }
                // section scope keeps the valuation module's own semantics
                break;

            case 'visits':
                // Restrict visits to those recorded by the user or their team.
                if ($scope === 'own') {
                    $rows->where('officer_id', $user->id);
                } elseif ($scope === 'team') {
                    $rows->where(function ($q) use ($user) {
                        $q->where('officer_id', $user->id)
                            ->orWhereIn('officer_id', User::where('supervisor_id', $user->id)->where('is_active', true)->pluck('id')->all());
                    });
                }
                break;

            case 'discoveries':
                // Restrict discoveries to those discovered by the user or their team.
                if ($scope === 'own') {
                    $rows->where('discovered_by', $user->id);
                } elseif ($scope === 'team') {
                    $rows->where(fn ($b) => $b->where('discovered_by', $user->id)
                        ->orWhereIn('discovered_by', User::where('supervisor_id', $user->id)->where('is_active', true)->pluck('id')->all()));
                }
                // section scope keeps the discovery module's own semantics
                break;

            case 'staff':
                // Restrict staff to the user and their supervised team.
                $rows->whereIn('id', array_merge([$user->id], User::where('supervisor_id', $user->id)->where('is_active', true)->pluck('id')->all()));
                break;

            case 'targets':
                // Restrict targets to the user and their supervised team.
                $rows->whereIn('user_id', array_merge([$user->id], User::where('supervisor_id', $user->id)->where('is_active', true)->pluck('id')->all()));
                break;

            case 'queries':
                // Restrict queries to those raised by the user or their team.
                if ($scope === 'own') {
                    $rows->where('raised_by', $user->id);
                } elseif ($scope === 'team') {
                    $rows->where(function ($q) use ($user) {
                        $q->where('raised_by', $user->id)
                            ->orWhereIn('raised_by', User::where('supervisor_id', $user->id)->where('is_active', true)->pluck('id')->all());
                    });
                }
                break;
        }
    }

    private function drillBillIds(User $user, string $scope): array
    {
        $bills = PropertyBill::query()->select('id');
        $bills->where('account_staff_id', $user->id);
        if ($scope === 'own') {
            $bills->orWhere('assigned_enforcement_officer_id', $user->id);
        } elseif ($scope === 'team') {
            $bills->orWhereIn('assigned_enforcement_officer_id', User::where('supervisor_id', $user->id)->where('is_active', true)->pluck('id')->all());
        } else {
            $bills->orWhereIn('assigned_enforcement_officer_id', User::where('section_id', $user->section_id)->where('is_active', true)->pluck('id')->all());
        }

        return $bills->pluck('id')->all();
    }

    /** Bound a query to the caller's bill register scope (null = unrestricted). */
    private function applyBillScope($query, User $user): void
    {
        $ids = BillScopeService::visibleBillIds($user);

        if ($ids !== null) {
            $query->whereIn('bill_id', $ids);
        }
    }

    /** Bound a query to the valuations the caller may legitimately count. */
    private function applyValuationScope($query, User $user): void
    {
        $billIds = BillScopeService::visibleBillIds($user);

        if ($billIds === null) {
            return;
        }

        $staffIds = [];
        $scope = $user->scopeLevel();

        if ($scope === 'section') {
            $staffIds = User::where('section_id', $user->section_id)->where('is_active', true)->pluck('id')->all();
        } elseif ($scope === 'team') {
            $staffIds = User::where('supervisor_id', $user->id)->where('is_active', true)->pluck('id')->all();
        } else {
            $staffIds = [$user->id];
        }

        $query->where(function ($q) use ($billIds, $staffIds) {
            $q->whereIn('bill_id', $billIds)
                ->orWhereIn('valuation_officer_id', $staffIds);
        });
    }
}
