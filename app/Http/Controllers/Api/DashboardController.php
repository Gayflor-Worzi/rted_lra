<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appeal;
use App\Models\AuditLog;
use App\Models\DataQualityFlag;
use App\Models\EnforcementVisit;
use App\Models\Payment;
use App\Models\PaymentVerification;
use App\Models\PropertyBill;
use App\Models\PropertyDiscovery;
use App\Models\StaffTarget;
use App\Models\Task;
use App\Models\TaskHistory;
use App\Models\User;
use App\Models\Valuation;
use App\Services\DataIntegrityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
        return response()->json([
            'data' => Cache::remember("dash:my:{$user->id}:{$scope}", 45, fn () => $this->buildMy($user, $scope)),
        ]);
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

        // Financial snapshot — only for users authorised to view bills.
        if ($isEnforcement || $user->hasAnyPermission(['bills.view', 'records.view', 'bills.create', 'reports.view'])) {
            $data['bills_area'] = $this->dashboardBillArea($billRows);
        }

        // Section-level panels
        if ($user->canPermission('payments.view_queue')) {
            $data['payment_verifications'] = [
                'pending' => PaymentVerification::where('verification_status', 'Pending')->count(),
            ];
        }

        if ($user->canPermission('valuation.approve') || $user->canPermission('valuation.review') || $user->canPermission('valuation.forward_ac')) {
            $data['valuations'] = [
                'manager_review' => Valuation::whereIn('status', ['Submitted', 'Manager Review'])->count(),
                'ac_approval' => Valuation::where('status', 'AC Approval')->count(),
                'approved' => Valuation::where('status', 'Approved')->count(),
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

        return $data;
    }

    /** Optional query-slashing helpers used by my(). */

    private function dashboardTaskRows(User $user, string $scope): Collection
    {
        $tasks = Task::query();

        if (in_array($scope, ['own', 'team', 'section'], true)) {
            $user->applyScope($tasks, 'assigned_to');
        }

        return $tasks->get(['id', 'status', 'task_type', 'task_reference', 'due_date', 'assigned_to', 'completed_at', 'updated_at']);
    }

    private function dashboardTaskCounts(User $user, Collection $rows): array
    {
        $today = now()->toDateString();

        return [
            'total_active' => $rows->reject(fn ($t) => in_array($t->status, Task::COMPLETED_STATUSES, true))->count(),
            'overdue' => $rows->whereNotIn('status', Task::COMPLETED_STATUSES)
                ->filter(fn ($t) => $t->due_date && $t->due_date->toDateString() < $today)->count(),
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
        $today = now()->toDateString();
        $soon = now()->addDays(3)->toDateString();
        $inProgress = ['Out for Delivery', 'Delivered', 'Payment Follow-up', 'Payment Claimed', 'Verification Pending', 'Payment Verification', '30-Day Warning', '72-Hour Warning', 'Outstanding'];

        return [
            'assigned' => $rows->where('status', 'Assigned')->count(),
            'in_progress' => $rows->whereIn('status', $inProgress)->count(),
            'due_today' => $active($rows)->filter(fn ($t) => $t->due_date && $t->due_date->toDateString() === $today)->count(),
            'due_soon' => $active($rows)->filter(fn ($t) => $t->due_date && $t->due_date->toDateString() > $today && $t->due_date->toDateString() <= $soon)->count(),
            'overdue' => $rows->whereNotIn('status', Task::COMPLETED_STATUSES)
                ->filter(fn ($t) => $t->due_date && $t->due_date->toDateString() < $today)->count(),
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
        $target = StaffTarget::where('user_id', $user->id)->where('status', 'Approved')
            ->orderByDesc('start_date')->orderByDesc('id')->get()
            ->first(fn (StaffTarget $t) => $t->isActive())
            ?? StaffTarget::where('user_id', $user->id)->where('status', 'Approved')->orderByDesc('id')->first();

        [$start, $end] = $target?->effectiveWindow() ?: [now()->startOfMonth(), now()];
        $achieved = $target ? (float) round($target->computeAchievedValue(), 2) : 0.0;

        $summary = [
            'has_target' => (bool) $target,
            'window' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'indicators' => $this->performanceIndicators($user, $start, $end, $taskRows, $billRows),
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

        return collect($actions)->filter(fn ($a) => $user->hasAnyPermission($a['perms']))
            ->map(fn ($a) => ['key' => $a['key'], 'label' => $a['label'], 'icon' => $a['icon'], 'route' => $a['route']])
            ->values()->all();
    }

    /** Permission-filtered analytics metrics offered on the Home charts. */
    private function chartMetrics(User $user): array
    {
        $metrics = [
            'tasks' => ['perm' => ['tasks.view_division', 'tasks.view_section', 'tasks.view_own'], 'label' => 'Tasks'],
            'bills' => ['perm' => ['bills.view'], 'label' => 'Bills'],
            'collections' => ['perm' => ['reports.view', 'bills.view'], 'label' => 'Collections'],
            'discoveries' => ['perm' => ['discovery.view', 'discovery.create'], 'label' => 'Discoveries'],
            'visits' => ['perm' => ['enforcement.view_assignments', 'enforcement.record_visit'], 'label' => 'Visits'],
            'valuations' => ['perm' => ['valuation.view_history', 'valuation.review', 'valuation.create'], 'label' => 'Valuations'],
            'payments' => ['perm' => ['payments.view_queue', 'payments.view_history'], 'label' => 'Payment Verification'],
            'targets' => ['perm' => ['targets.view'], 'label' => 'Target vs Actual'],
        ];

        $available = collect($metrics)->filter(fn ($m) => $user->hasAnyPermission($m['perm']))
            ->map(fn ($m) => $m['label'])->all();

        return $available;
    }

    /**
     * Priority items surfaced on the Home page. Each carries the action label,
     * urgency colour hint and the mobile route to take when tapped.
     */
    private function priorityActions(User $user, Collection $rows): array
    {
        $today = now()->toDateString();
        $active = fn ($r) => $r->reject(fn ($t) => in_array($t->status, Task::COMPLETED_STATUSES, true));
        $overdue = $active($rows)->filter(fn ($t) => $t->due_date && $t->due_date->toDateString() < $today)->count();
        $dueToday = $active($rows)->filter(fn ($t) => $t->due_date && $t->due_date->toDateString() === $today)->count();
        $escalated = $rows->where('status', 'Escalated')->count();

        $out = [];

        if ($overdue > 0) {
            $out[] = ['key' => 'overdue', 'label' => 'Overdue', 'urgency' => 'overdue', 'count' => $overdue,
                'detail' => "{$overdue} task(s) require follow-up.", 'action' => 'View', 'route' => 'Tasks', 'params' => null];
        }

        if ($dueToday > 0) {
            $out[] = ['key' => 'due_today', 'label' => 'Due Today', 'urgency' => 'today', 'count' => $dueToday,
                'detail' => "{$dueToday} delivery/follow-up task(s) are due today.", 'action' => 'Continue', 'route' => 'Tasks', 'params' => null];
        }

        if ($escalated > 0) {
            $out[] = ['key' => 'escalated', 'label' => 'Escalated', 'urgency' => 'escalated', 'count' => $escalated,
                'detail' => "{$escalated} case(s) require enforcement action.", 'action' => 'Take Action', 'route' => 'Tasks', 'params' => null];
        }

        if ($user->canPermission('payments.view_queue')) {
            $pending = PaymentVerification::where('verification_status', 'Pending')->count();
            if ($pending > 0) {
                $out[] = ['key' => 'verify', 'label' => 'Payment Verification', 'urgency' => 'verify', 'count' => $pending,
                    'detail' => "{$pending} taxpayer claim(s) require verification.", 'action' => 'Verify Payment', 'route' => 'Verifications', 'params' => null];
            }
        }

        if ($user->canPermission('valuation.review')) {
            $review = Valuation::query();
            $user->applyScope($review, 'valuation_officer_id');
            $n = (clone $review)->where('status', 'Submitted')->count();
            if ($n > 0) {
                $out[] = ['key' => 'valuation_review', 'label' => 'Valuation Review', 'urgency' => 'action', 'count' => $n,
                    'detail' => "{$n} valuation(s) awaiting manager review.", 'action' => 'Review', 'route' => 'Valuations', 'params' => null];
            }
        }

        if ($user->canPermission('discovery.review')) {
            $discoveries = PropertyDiscovery::query();
            $user->applyScope($discoveries, 'discovered_by');
            $n = (clone $discoveries)->whereIn('status', [PropertyDiscovery::STATUS_SUBMITTED, PropertyDiscovery::STATUS_UNDER_REVIEW, PropertyDiscovery::STATUS_RESUBMITTED])->count();
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

        $data = [
            'scope' => $scope,
            'role' => $user->role?->name,
            'as_of' => now()->toISOString(),
            'range' => ['start' => $rangeStart->toDateString(), 'end' => $rangeEnd->toDateString()],
            'kpis' => [
                'tasks' => [
                    'active' => (clone $tasks)->whereNotIn('status', Task::COMPLETED_STATUSES)->count(),
                    'awaiting_assignment' => (clone $tasks)->where('status', 'Awaiting Assignment')->count(),
                    'overdue' => (clone $tasks)->whereNotIn('status', Task::COMPLETED_STATUSES)->whereNotNull('due_date')->whereDate('due_date', '<', now())->count(),
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
                    'in_review' => (clone $valuations)->whereIn('status', ['Submitted', 'Manager Review'])->count(),
                    'ac_approval' => (clone $valuations)->where('status', 'AC Approval')->count(),
                    'approved' => (clone $valuations)->where('status', 'Approved')->whereBetween('ac_reviewed_at', [$rangeStart, $rangeEnd])->count(),
                    'approved_30d' => (clone $valuations)->where('status', 'Approved')->whereBetween('ac_reviewed_at', [$rangeStart, $rangeEnd])->count(),
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
            'target_averages' => $this->targetAverages(),
            'task_statuses' => (clone $tasks)
                ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->toArray(),
            'section_performance' => $this->sectionPerformance($user, $sectionRows, $tasks, $rangeStart, $rangeEnd),
            'focus' => $this->divisionFocus($user, $scope, $tasks, $bills, $valuations, $payments),
        ];

        if ($scope === 'division' || $scope === 'system') {
            $logs = AuditLog::query()
                ->whereIn('action', ['task.assigned', 'task.completed', 'task.escalated', 'bill.logged', 'visit.recorded', 'valuation.submitted', 'valuation.approved', 'payment.verified', 'discovery.created', 'discovery.approved', 'target.approved'])
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
        'tasks'      => ['tasks.view_own', 'tasks.view_section', 'tasks.view_division'],
        'bills'      => ['bills.view', 'records.view'],
        'valuations' => ['valuation.view_history', 'valuation.review', 'valuation.approve'],
        'visits'     => ['tasks.view_own', 'enforcement.record_visit'],
        'discoveries'=> ['discovery.view', 'discovery.create', 'discovery.review'],
        'staff'      => ['staff.view'],
        'targets'    => ['targets.view'],
        'payments'   => ['payments.view_queue', 'payments.view_history'],
        'queries'    => ['dashboard.view_division', 'dashboard.view_section', 'dashboard.view_own'],
        'flags'      => ['dashboard.view_division', 'dashboard.view_section', 'dashboard.view_own'],
    ];

    public function drill(Request $request)
    {
        $user = $request->user();

        $table = $request->query('table');
        abort_unless(in_array($table, array_keys(self::DRILL_PERMS), true), 422, 'Unknown drill table.');

        abort_unless(
            $user->hasAnyPermission(self::DRILL_PERMS[$table]),
            403,
            'Missing permission to drill into '.$table
        );

        $rows = match ($table) {
            'tasks'      => $this->drillTasks($request),
            'bills'      => $this->drillBills($request),
            'valuations' => $this->drillValuations($request),
            'visits'     => $this->drillVisits($request),
            'discoveries'=> $this->drillDiscoveries($request),
            'staff'      => $this->drillStaff($request),
            'targets'    => $this->drillTargets($request),
            'payments'   => $this->drillPayments($request),
            'queries'    => $this->drillQueries($request),
            'flags'      => $this->drillFlags($request),
        };

        $filtered = $this->applyDrillFilters($request, $rows);

        $page = $filtered->orderBy($table === 'bills' ? 'tin' : 'id', $table === 'bills' ? 'asc' : 'desc')
            ->orderByDesc('id')
            ->paginate($request->query('per_page', 25))
            ->withQueryString();

        $page->getCollection()->transform(fn ($r) =>
            $table === 'bills'
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
            $user->hasAnyPermission(['manage:analytics', 'view_all_dashboard', 'manage:data_quality']),
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
            ->when($request->query('decision_path'), fn ($b, $s) => $b->where('decision_path', $s));
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
        return \App\Models\MeQuery::query()
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
                'overdue' => Task::where('assigned_to', $u->id)->whereNotIn('status', Task::COMPLETED_STATUSES)
                    ->whereNotNull('due_date')->whereDate('due_date', '<', now())->count(),
            ];
        })->values()->all();
    }

    /** Per-section output within the selected range — for the BI chart band. */
    private function visibleSections(User $user): \Illuminate\Database\Eloquent\Collection
    {
        $rows = \App\Models\Section::query()->where('is_active', true)->orderBy('name');

        if (in_array($user->scopeLevel(), ['own', 'team', 'section'], true) && $user->section_id) {
            $rows->where('id', $user->section_id);
        }

        return $rows->get(['id', 'name', 'code']);
    }

    /** Pipeline stages counted only against the stages valid for each path. */
    private function discoveryPipeline(Builder $discoveries): array
    {
        $s = PropertyDiscovery::class;

        return [
            'draft' => (clone $discoveries)->where('status', $s::STATUS_DISCOVERED)->count(),
            'pending_review' => (clone $discoveries)->whereIn('status', [$s::STATUS_SUBMITTED, $s::STATUS_UNDER_REVIEW, $s::STATUS_RESUBMITTED])->count(),
            'pending_classification' => (clone $discoveries)->where('status', $s::STATUS_CLASSIFIED)->count(),
            'awaiting_account' => (clone $discoveries)->where('decision_path', 'account')->whereIn('status', [$s::STATUS_SENT_TO_ACCOUNT, $s::STATUS_SENT_TO_ACCOUNT_MANAGER, $s::STATUS_PROCESSED_IN_LITAS])->count(),
            'awaiting_valuation' => (clone $discoveries)->where('decision_path', 'valuation')->whereIn('status', [$s::STATUS_VALUATION_REQUIRED, $s::STATUS_VALUATION_ASSIGNED])->count(),
            'under_valuation' => (clone $discoveries)->where('decision_path', 'valuation')->whereIn('status', [$s::STATUS_UNDER_VALUATION, $s::STATUS_VALUATION_MANAGER_REVIEW, $s::STATUS_PENDING_AC_APPROVAL])->count(),
            'completed' => (clone $discoveries)->whereIn('status', [$s::STATUS_AC_APPROVED, $s::STATUS_COMPLETED])->count(),
            'rejected' => (clone $discoveries)->where('status', $s::STATUS_AC_REJECTED)->count(),
        ];
    }

    /** Per-section breakdown cards, derived from the database section list. */
    private function sectionBreaks(User $user, $sectionRows, Builder $tasks, Builder $bills, $start, $end): array
    {
        $openMeOnly = in_array($user->scopeLevel(), ['division', 'system'], true) || $user->canPermission('query.manage');

        $out = [];
        foreach ($sectionRows as $section) {
            $row = [
                'id' => $section->id,
                'code' => $section->code,
                'staff' => User::where('section_id', $section->id)->where('is_active', true)->count(),
                'active_tasks' => (clone $tasks)->where('section', $section->name)->whereNotIn('status', Task::COMPLETED_STATUSES)->count(),
                'completed' => (clone $tasks)->where('section', $section->name)->whereIn('status', Task::COMPLETED_STATUSES)
                    ->whereBetween('completed_at', [$start, $end])->count(),
                'overdue' => (clone $tasks)->where('section', $section->name)->whereNotIn('status', Task::COMPLETED_STATUSES)
                    ->whereNotNull('due_date')->whereDate('due_date', '<', now())->count(),
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
                    ->whereIn('status', ['Submitted', 'Manager Review'])->count();
            }
            if ($section->code === 'M&A' || $section->code === 'M&E') {
                if ($openMeOnly) {
                    $row['open_queries'] = \App\Models\MeQuery::whereNotIn('status', ['Closed', 'Resolved'])->count();
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
                    'overdue' => (clone $tasks)->tap($active)->whereNotNull('due_date')->whereDate('due_date', '<', now())->count(),
                ],
            ];
        }

        if ($user->canPermission('valuation.review') || $user->canPermission('valuation.approve') || $user->canPermission('valuation.forward_ac')) {
            $focus['panels'][] = [
                'key' => 'valuation_review',
                'label' => 'Valuation Review Queue',
                'metrics' => [
                    'submitted' => (clone $valuations)->where('status', 'Submitted')->count(),
                    'manager_review' => (clone $valuations)->where('status', 'Manager Review')->count(),
                    'ac_approval' => (clone $valuations)->where('status', 'AC Approval')->count(),
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
                    'pending_review' => PropertyDiscovery::whereIn('status', [PropertyDiscovery::STATUS_SUBMITTED, PropertyDiscovery::STATUS_UNDER_REVIEW, PropertyDiscovery::STATUS_RESUBMITTED])->count(),
                    'awaiting_valuation' => PropertyDiscovery::where('decision_path', 'valuation')->whereIn('status', [PropertyDiscovery::STATUS_VALUATION_REQUIRED, PropertyDiscovery::STATUS_VALUATION_ASSIGNED])->count(),
                    'awaiting_account' => PropertyDiscovery::where('decision_path', 'account')->whereIn('status', [PropertyDiscovery::STATUS_SENT_TO_ACCOUNT, PropertyDiscovery::STATUS_PROCESSED_IN_LITAS])->count(),
                ],
            ];
        }

        if ($user->section && in_array($user->section->code, ['M&E', 'M&A'], true)) {
            $focus['panels'][] = [
                'key' => 'quality',
                'label' => 'Data Quality & Queries',
                'metrics' => [
                    'open_queries' => \App\Models\MeQuery::whereNotIn('status', ['Closed', 'Resolved'])->count(),
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
                'overdue' => (clone $tasks)->where('section', $section->name)->whereNotIn('status', Task::COMPLETED_STATUSES)
                    ->whereNotNull('due_date')->whereDate('due_date', '<', now())->count(),
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
}