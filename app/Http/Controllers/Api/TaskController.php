<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EvidencePhoto;
use App\Models\Notification;
use App\Models\PropertyBill;
use App\Models\Task;
use App\Models\TaskEngagement;
use App\Models\User;
use App\Services\AssignmentEligibilityService;
use App\Services\AuditService;
use App\Services\SectionWorkspaceService;
use App\Services\SyncService;
use App\Services\TaskService;
use App\Services\TaskWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    public function __construct(
        private readonly TaskWorkflowService $workflow,
        private readonly TaskService $tasks,
        private readonly SyncService $sync,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $scope = $user->scopeLevel();

        $perm = match (true) {
            $scope === 'division' => 'tasks.view_division',
            $scope === 'section' || $scope === 'team' => 'tasks.view_section',
            default => 'tasks.view_own',
        };

        abort_unless($user->canPermission($perm), 403, "Missing permission: {$perm}");

        $query = Task::query()
            ->with(['assignedTo:id,full_name', 'assignedBy:id,full_name', 'bill']);

        $workspace = $request->query('workspace');

        // ---- View selector (personal inbox). Every view is bounded by RBAC. ----
        $isAdmin = $user->isSystemAdministrator();
        $allowedViews = ['mine'];
        if ($isAdmin || in_array($scope, ['team', 'section', 'division'], true)) {
            array_push($allowedViews, 'unassigned', 'team');
        }
        if ($user->canPermission('tasks.view_division')) {
            $allowedViews[] = 'all';
        }
        // enforcement.view_assignments is the read-level enforcement register
        // right: holders (M&E, Enforcement supervisors/managers, the Account
        // Manager) may browse the whole enforcement workspace — including
        // unassigned cases — but remain non-operators that can never assign.
        if ($workspace === 'enforcement' && $user->canPermission('enforcement.view_assignments')) {
            $allowedViews[] = 'all';
        }

        $view = $request->query('view');

        if ($view !== null) {
            if (! in_array($view, $allowedViews, true)) {
                abort(403, "View \"{$view}\" is outside your data scope.");
            }

            switch ($view) {
                case 'mine':
                    $query->where('assigned_to', $user->id);
                    break;
                case 'team':
                    // Respect the caller's data scope: team-scope users see
                    // themselves + their direct reports; section-scope managers
                    // see every active staff member in their section.
                    $user->applyScope($query, 'assigned_to');
                    break;
                case 'unassigned':
                    $query->whereNull('assigned_to');
                    if ($workspace === 'enforcement') {
                        $query->where('task_type', Task::TYPE_BILL_DELIVERY)
                            ->where('reference_type', 'property_bill')
                            ->where('status', 'Awaiting Assignment')
                            ->whereHas('bill', fn ($bill) => $bill
                                ->where('case_status', 'Awaiting Assignment')
                                ->whereNull('assigned_enforcement_officer_id'));
                    }
                    break;
                case 'all':
                    break;
            }
        } else {
            // Legacy behaviour: apply the RBAC data scope directly.
            if (in_array($perm, ['tasks.view_own', 'tasks.view_section'], true)) {
                $user->applyScope($query, 'assigned_to');
            }

            if (($request->query('mine') == 1)) {
                $query->where('assigned_to', $user->id);
            }
        }

        // ---- Filters ----
        if ($request->query('status')) {
            $query->where('tasks.status', $request->query('status'));
        }

        if ($request->query('task_type')) {
            $query->where('tasks.task_type', $request->query('task_type'));
        }

        // Multiple task types (account "My Tasks" surface reuses the engine list).
        if ($types = $request->input('task_types')) {
            $types = array_values(array_filter(array_map('trim', (array) $types), fn ($t) => in_array($t, Task::TYPES, true)));
            if ($types) {
                $query->whereIn('tasks.task_type', $types);
            }
        }

        // Section workspace isolation (§24): a caller asking for a workspace is
        // scoped server-side to that workspace's task types — the frontend never
        // decides which types it may see. Unauthorised workspace requests 403.
        if ($workspace !== null) {
            abort_unless(in_array($workspace, SectionWorkspaceService::all(), true), 422, 'Unknown workspace.');
            abort_unless(
                SectionWorkspaceService::canViewWorkspace($user, $workspace),
                403,
                SectionWorkspaceService::label($workspace).' workspace is outside your scope.'
            );
            $query->whereIn('tasks.task_type', SectionWorkspaceService::types($workspace));
        }

        if ($request->boolean('active')) {
            $query->whereNotIn('tasks.status', ['Resolved', 'Closed', 'Paid', 'Logged']);
        }

        if ($request->boolean('overdue')) {
            // Centralized overdue predicate (§21) — identical to the dashboard KPI.
            $query->overdue();
        }

        if ($request->query('status_group')) {
            switch ($request->query('status_group')) {
                case 'active':
                    $query->whereNotIn('tasks.status', Task::COMPLETED_STATUSES);
                    break;
                case 'completed':
                    $query->whereIn('tasks.status', Task::COMPLETED_STATUSES);
                    break;
                case 'pending':
                    $query->whereNotIn('tasks.status', [...Task::COMPLETED_STATUSES, 'Escalated']);
                    break;
                case 'overdue':
                    $query->overdue();
                    break;
                case 'due_today':
                    $query->dueToday();
                    break;
                case 'escalated':
                    $query->where('tasks.status', 'Escalated');
                    break;
            }
        }

        if ($stage = $request->query('stage')) {
            $stages = [
                'awaiting' => ['Awaiting Assignment'],
                'delivery' => ['Assigned', 'Out for Delivery', 'Delivered'],
                'followup' => ['Payment Follow-up'],
                'verification' => ['Payment Claimed', 'Verification Pending', 'Payment Verification'],
                'reminder30' => ['30-Day Warning'],
                'demand72' => ['72-Hour Warning'],
                'final' => ['Escalated'],
                'closure' => ['Closed'],
                'paid' => ['Paid'],
                'resolved' => ['Resolved'],
                'rejected' => ['Payment Rejected'],
                'outstanding' => ['Partially Paid', 'Outstanding'],
            ];
            if (isset($stages[$stage])) {
                $query->whereIn('tasks.status', $stages[$stage]);
            }
        }

        if ($payment = $request->query('payment')) {
            $query->where('tasks.reference_type', 'property_bill')
                ->whereExists(function ($q) use ($payment) {
                    $q->selectRaw('1')->from('property_bills')
                        ->whereColumn('property_bills.id', 'tasks.reference_id')
                        ->where('payment_status', $payment);
                });
        }

        if ($q = trim((string) $request->query('q'))) {
            $query->where(function ($b) use ($q) {
                $b->where('tasks.task_reference', 'like', like_term($q))
                    ->orWhere(function ($bb) use ($q) {
                        $bb->where('tasks.reference_type', 'property_bill')
                            ->whereExists(function ($sub) use ($q) {
                                $sub->selectRaw('1')->from('property_bills')
                                    ->whereColumn('property_bills.id', 'tasks.reference_id')
                                    ->where(function ($f) use ($q) {
                                        $f->where('property_id', 'like', like_term($q))
                                            ->orWhere('tin', 'like', like_term($q))
                                            ->orWhere('tax_period', 'like', like_term($q))
                                            ->orWhere('document_number', 'like', like_term($q))
                                            ->orWhere('taxpayer_name', 'like', like_term($q))
                                            ->orWhere('property_address', 'like', like_term($q));
                                    });
                            });
                    });
            });
        }

        if ($range = $this->dateRange($request->query('date'))) {
            $query->whereBetween('tasks.due_date', $range);
        }
        if ($from = $request->query('date_from')) {
            $query->whereDate('tasks.due_date', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $query->whereDate('tasks.due_date', '<=', $to);
        }

        // A staff member may only ever list tasks of a type they are eligible
        // to see (RBAC task-type gate). Division/system scopes are unrestricted.
        $viewableTypes = AssignmentEligibilityService::viewableTypes($user);
        if ($viewableTypes !== null) {
            $query->whereIn('tasks.task_type', $viewableTypes);
        }

        $perPage = max(1, min((int) env('PAGINATION_MAX_PAGE', 200), (int) $request->query('per_page', 25)));

        $rows = $query
            ->orderByRaw("CASE tasks.status WHEN 'Escalated' THEN 1 WHEN '30-Day Warning' THEN 2 WHEN '72-Hour Warning' THEN 3 WHEN 'Assigned' THEN 4 WHEN 'Out for Delivery' THEN 5 ELSE 6 END DESC")
            ->orderByDesc('tasks.id')
            ->paginate($perPage)
            ->withQueryString();

        $rows->getCollection()->transform(fn (Task $task) => $this->present($task));

        return response()->json(['data' => $rows]);
    }

    /** Personal task list for the logged-in user (enforcement officer dashboard). */
    public function my(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission([
            'tasks.complete', 'tasks.assign', 'tasks.escalate', 'enforcement.record_visit',
            'me.view', 'payments.verify', 'payments.claim',
        ]), 403, 'Missing permission to view your tasks.');

        $query = Task::where('assigned_to', $request->user()->id)
            ->with(['assignedTo:id,full_name', 'engagements.officer:id,full_name']);

        if ($request->query('active')) {
            $query->whereNotIn('status', Task::COMPLETED_STATUSES);
        }

        $rows = $query->orderByDesc('priority')->orderByDesc('id')->paginate($request->query('per_page', 20));

        $rows->getCollection()->transform(function (Task $task) {
            $bill = $task->reference_type === 'property_bill'
                ? PropertyBill::find($task->reference_id)
                : null;

            $task->bill_name = optional($bill)?->document_number;
            $task->setRelation('bill', $bill);

            return $this->present($task);
        });

        return response()->json(['data' => $rows]);
    }

    public function show(Task $task)
    {
        abort_unless($this->canSee($task), 403, 'Task outside your data scope.');

        $task->load([
            'assignedTo:id,full_name',
            'assignedBy:id,full_name',
            'history',
            'engagements.officer:id,full_name',
        ]);

        if ($task->reference_type === 'property_bill') {
            $task->load([
                'bill.accountStaff:id,full_name',
                'bill.enforcementOfficer:id,full_name',
                'bill.payments.verifier:id,full_name',
                'bill.verifications.verifier:id,full_name',
            ]);
        }

        $task->setRelation('billEvidence', EvidencePhoto::query()
            ->where('task_id', $task->id)
            ->when($task->reference_type === 'property_bill', fn ($q) => $q->orWhere('bill_id', $task->reference_id))
            ->orderByDesc('captured_at')->orderByDesc('id')
            ->get());

        return response()->json(['data' => $this->present($task)]);
    }

    /**
     * Unified task transition — move through the lifecycle with a history record.
     */
    public function transition(Request $request, Task $task)
    {
        $user = $request->user();

        abort_unless($this->canSee($task), 403, 'Task outside your data scope.');

        $perm = $this->transitionPermission($task);
        $allowed = $perm === 'tasks.escalate'
            ? $user->hasAnyPermission(['tasks.escalate', 'enforcement.escalate'])
            : $user->canPermission($perm);
        abort_unless($allowed, 403, 'Missing permission for this transition.');

        $data = $request->validate([
            'to_status' => ['required', Rule::in(Task::STATUSES)],
            'action' => 'required|string|max:60',
            'remarks' => 'nullable|string|max:2000',
        ]);

        if ($task->status === 'Closed') {
            return response()->json(['message' => 'Task is closed and cannot transition.'], 422);
        }

        $bill = $task->reference_type === 'property_bill' ? PropertyBill::find($task->reference_id) : null;

        // 72-HOUR DEMAND is a time-gated escalation — the 30-day notice must have
        // elapsed (or a manager with enforcement.escalation_override approves).
        if ($data['to_status'] === '72-Hour Warning' && $data['action'] !== 'auto') {
            $this->assertSeventyTwoHourEligible($bill, $user);
        }

        $old = $task->only(['status']);

        try {
            $history = $task->transitionTo($data['to_status'], $data['action'], $user, $data['remarks'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->stampEscalationDates($task, $bill);
        (new AuditService)->record($task, 'tasks.transition', $user->id, $old, [
            'to_status' => $task->status,
            'action' => $data['action'],
        ]);

        return response()->json([
            'data' => ['task' => $this->present($task->fresh(['assignedTo:id,full_name', 'history'])), 'history' => $history],
            'message' => 'Task transitioned to '.$task->status.'.',
        ]);
    }

    /** Assign or reassign a task to a staff member. */
    public function assign(Request $request, Task $task)
    {
        $user = $request->user();

        if ($task->task_type === Task::TYPE_BILL_DELIVERY) {
            abort_unless(
                $user->is_active && in_array($user->role?->name, ['Enforcement Supervisor', 'System Administrator'], true),
                403,
                'Only an Enforcement Supervisor may assign unassigned bill-delivery work.'
            );
        }
        abort_unless($user->canPermission('tasks.assign'), 403, 'Missing permission: tasks.assign');
        abort_unless(SectionWorkspaceService::mayAssignTask($user, $task->task_type), 403, 'Assignment outside your workspace authority.');
        abort_unless($this->canSee($task), 403, 'Task outside your data scope.');
        abort_if(in_array($task->status, Task::COMPLETED_STATUSES, true), 422, 'Task is settled and cannot be reassigned.');

        $data = $request->validate([
            'assigned_to' => ['required', 'integer', Rule::exists('users', 'id')],
            'remarks' => 'nullable|string|max:2000',
        ]);

        $assignee = User::find($data['assigned_to']);

        abort_if(
            ! $assignee || ! AssignmentEligibilityService::canExecuteTaskType($assignee, $task->task_type),
            422,
            'Assignment rejected. '.($assignee?->full_name ?? 'User')." does not have permission to perform {$task->task_type}."
        );

        $from = $task->assigned_to;
        $fromStatus = $task->status;
        $toStatus = in_array($fromStatus, ['Logged', 'Awaiting Assignment'], true) ? 'Assigned' : $fromStatus;
        $billDistrict = null;
        if ($task->task_type === Task::TYPE_BILL_DELIVERY) {
            $membership = app(\App\Services\EnforcementOrganizationService::class)->authorizeBillAssignment($user, $assignee, $task);
            if ($task->reference_type === 'property_bill') {
                $billDistrict = $membership->primary_district_id;
            }
        }

        $task->update([
            'assigned_to' => $assignee->id,
            'assigned_by' => $user->id,
            'status' => $toStatus,
            'due_date' => $task->due_date ?? now()->addDays(14)->toDateString(),
        ]);

        $task->history()->create([
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'action' => 'assign',
            'performed_by' => $user->id,
            'remarks' => ($from ? "Reassigned from #{$from} " : 'Assigned ')."to {$assignee->full_name}. ".($data['remarks'] ?? ''),
        ]);

        if ($billDistrict !== null) {
            PropertyBill::whereKey($task->reference_id)->update([
                'assigned_enforcement_officer_id' => $assignee->id,
                'primary_tax_district_id' => $billDistrict,
            ]);
        }

        $this->tasks->notify($assignee, 'Task assigned', "Task {$task->task_reference} assigned to you.", 'tasks');

        if (in_array($task->task_type, AccountTaskController::ACCOUNT_TASK_TYPES, true)) {
            $this->tasks->notify(
                $assignee,
                'New task assigned',
                "Task {$task->task_reference} ({$task->task_type}) assigned to you.",
                Notification::TYPE_ACCOUNT_TASKS,
                '/account/my-tasks',
            );
        }

        return response()->json([
            'data' => $this->present($task->fresh(['assignedTo:id,full_name', 'history'])),
            'message' => 'Task assigned.',
        ]);
    }

    /** Engagement list for the card timeline. */
    public function engagements(Task $task)
    {
        abort_unless($this->canSee($task), 403, 'Task outside your data scope.');

        $rows = $task->engagements()->with('officer:id,full_name')->get();

        return response()->json(['data' => $rows->map(fn ($e) => $this->presentEngagement($e))->values()]);
    }

    /** Record a manual engagement (visit attempt, follow-up, note, notice). */
    public function recordEngagement(Request $request, Task $task)
    {
        $user = $request->user();
        abort_unless($this->canSee($task), 403, 'Task outside your data scope.');
        abort_unless($user->hasAnyPermission([
            'tasks.complete', 'tasks.assign', 'tasks.reassign', 'tasks.escalate',
            'enforcement.record_visit', 'me.view', 'payments.verify',
        ]), 403, 'Missing permission for this engagement.');

        $syncId = $this->sync->localId($request);
        if ($syncId && ($replay = $this->sync->findForReplay($user->id, $syncId))) {
            return $this->sync->replayResponse($replay);
        }

        $data = $request->validate([
            'engagement_type' => ['required', Rule::in(TaskEngagement::TYPES)],
            'outcome' => 'nullable|string|max:80',
            'notes' => 'nullable|string|max:2000',
            'occurred_at' => 'nullable|date',
            'local_transaction_id' => 'nullable|string|max:80',
        ]);

        $result = DB::transaction(function () use ($task, $data, $user, $syncId) {
            $engagement = $task->recordEngagement(
                $data['engagement_type'],
                $data['outcome'] ?? null,
                $data['notes'] ?? null,
                $user,
                isset($data['occurred_at']) ? Carbon::parse($data['occurred_at']) : now(),
            );
            $responseData = $this->presentEngagement($engagement->load('officer:id,full_name'));

            if ($syncId) {
                $this->sync->record($user->id, $syncId, 'engagement', (int) $engagement->id, $responseData);
            }

            return $responseData;
        });

        return response()->json([
            'data' => $result,
            'message' => 'Engagement recorded.',
        ], 201);
    }

    /** Run the pending auto step for a bill-linked task (scheduler escape hatch). */
    public function advance(Request $request, Task $task)
    {
        $user = $request->user();
        abort_unless($this->canSee($task), 403, 'Task outside your data scope.');
        abort_unless($user->hasAnyPermission([
            'tasks.complete', 'tasks.escalate', 'enforcement.escalate', 'enforcement.record_visit',
        ]), 403, 'Missing permission to run this step.');

        abort_if($task->reference_type !== 'property_bill', 422, 'Advance runs on bill-linked tasks.');

        $result = $this->workflow->advanceTask($task, $user);

        $task = $task->fresh();
        $task->load([
            'assignedTo:id,full_name',
            'assignedBy:id,full_name',
            'history',
            'engagements.officer:id,full_name',
        ]);

        if ($task->reference_type === 'property_bill') {
            $task->load(['bill', 'bill.verifications']);
        }

        return response()->json([
            'data' => [
                'advanced' => $result['advanced'],
                'result' => $result['result'],
                'task' => $this->present($task),
            ],
            'message' => $result['advanced']
                ? 'Advanced to '.$result['result']['stage'].'.'
                : 'No pending step is eligible yet.',
        ]);
    }

    private function dateRange(?string $date): ?array
    {
        if (! $date) {
            return null;
        }

        $now = now();
        $range = match ($date) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'quarter' => [$now->copy()->startOfQuarter(), $now->copy()->endOfQuarter()],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            default => null,
        };

        return $range ? [$range[0]->toDateString(), $range[1]->toDateString()] : null;
    }

    private function canSee(Task $task): bool
    {
        $user = request()->user();

        if ($user->isSystemAdministrator()) {
            return true;
        }

        if ($user->role?->name === 'Enforcement Supervisor'
            && $task->task_type === Task::TYPE_BILL_DELIVERY
            && $task->reference_type === 'property_bill'
            && $task->assigned_to === null
            && $task->status === 'Awaiting Assignment'
            && $task->bill?->case_status === 'Awaiting Assignment'
            && $task->bill?->assigned_enforcement_officer_id === null) {
            return true;
        }

        $scope = $user->scopeLevel();

        if ($scope === 'division' || $scope === 'system') {
            return true;
        }

        if (! AssignmentEligibilityService::canViewTaskType($user, $task->task_type)) {
            return false;
        }

        if ($scope === 'section') {
            return $task->assigned_to
                && in_array($task->assigned_to, User::where('section_id', $user->section_id)->where('is_active', true)->pluck('id')->all());
        }

        if ($scope === 'team') {
            return $task->assigned_to === $user->id
                || in_array($task->assigned_to, User::where('supervisor_id', $user->id)->where('is_active', true)->pluck('id')->all());
        }

        // own
        return $task->assigned_to == $user->id || $task->assigned_by == $user->id;
    }

    private function assertSeventyTwoHourEligible(?PropertyBill $bill, User $user): void
    {
        if ($user->canPermission('enforcement.escalation_override')) {
            return;
        }

        if (! $bill?->thirty_day_notice_date) {
            abort(422, 'A 30-Day Warning must be recorded before the 72-Hour Demand can be issued.');
        }

        $elapsed = now()->startOfDay()->diffInDays(Carbon::parse($bill->thirty_day_notice_date)->startOfDay());

        if ($elapsed < 30) {
            abort(422, "72-Hour Demand not yet eligible — {$elapsed} days since the 30-day notice (30 required). Ask a manager to override.");
        }
    }

    private function stampEscalationDates(Task $task, ?PropertyBill $bill): void
    {
        if (! $bill) {
            return;
        }

        $stamps = [
            'Delivered' => ['delivery_date' => true],
            '30-Day Warning' => ['thirty_day_notice_date' => true, 'escalation_stage' => '30-Day Warning'],
            '72-Hour Warning' => ['final_notice_date' => true, 'escalation_stage' => '72-Hour Warning'],
            'Escalated' => ['escalation_stage' => 'Escalated'],
            'Resolved' => ['escalation_stage' => 'Resolved'],
            'Closed' => ['escalation_stage' => 'Closed'],
            'Paid' => ['escalation_stage' => 'Paid'],
        ];

        $updates = [];
        foreach (($stamps[$task->status] ?? []) as $key => $value) {
            if ($key === 'escalation_stage') {
                if ($bill->escalation_stage !== $value) {
                    $updates['escalation_stage'] = $value;
                }

                continue;
            }
            if (empty($bill->{$key})) {
                $value === true ? $updates[$key] = now()->toDateString() : $updates[$key] = $value;
            }
        }

        if ($task->status === 'Closed' && ! $bill->case_status) {
            $updates['case_status'] = 'Closed';
        }

        if ($updates) {
            $bill->update($updates);
        }
    }

    private function escalationInfo(Task $task): ?array
    {
        $bill = PropertyBill::find($task->reference_id);
        if (! $bill) {
            return null;
        }

        return [
            'delivery_date' => $bill->delivery_date?->toDateString(),
            'thirty_day_notice_date' => $bill->thirty_day_notice_date?->toDateString(),
            'final_notice_date' => $bill->final_notice_date?->toDateString(),
            'escalation_stage' => $bill->escalation_stage,
        ];
    }

    private function transitionPermission(Task $task): string
    {
        $action = request()->input('action');
        $toStatus = request()->input('to_status');

        // Reopening a settled case is its own permission; never derivable from
        // the generic completion checklist.
        if ($action === 'reopen') {
            return 'tasks.reopen';
        }

        // Financial settlement statuses are owned by the payment verification
        // pipeline: a task may only be driven into them by an actor who can
        // actually verify payments — never by a bare `tasks.complete` holder.
        $financial = [
            'Paid',
            'Partially Paid',
            'Outstanding',
            'Verification Pending',
            'Payment Verification',
            'Payment Rejected',
        ];

        if (in_array($toStatus, $financial, true)) {
            return 'payments.verify';
        }

        // Escalation ladder entries are gated by the escalation permission set.
        if ($action === 'escalate' || in_array($toStatus, ['Escalated', '30-Day Warning', '72-Hour Warning'], true)) {
            return 'tasks.escalate';
        }

        return 'tasks.complete';
    }

    private function present(Task $task): array
    {
        $workflow = $this->workflow->descriptor($task);

        return [
            'id' => $task->id,
            'task_reference' => $task->task_reference,
            'task_type' => $task->task_type,
            'section' => $task->section,
            'reference_type' => $task->reference_type,
            'reference_id' => $task->reference_id,
            'bill_name' => $task->reference_type === 'property_bill'
                ? ($task->bill?->document_number ?? ($task->bill_name ?? null))
                : null,
            'bill' => $task->relationLoaded('bill') && $task->bill ? [
                'id' => $task->bill->id,
                'document_number' => $task->bill->document_number,
                'property_id' => $task->bill->property_id,
                'taxpayer_name' => $task->bill->taxpayer_name,
                'tin' => $task->bill->tin,
                'tax_period' => $task->bill->tax_period,
                'property_address' => $task->bill->property_address,
                'property_classification' => $task->bill->property_classification,
                'property_type' => $task->bill->property_type,
                'assessed_value' => $task->bill->assessed_value,
                'tax_amount' => $task->bill->tax_amount,
                'interest_charged' => $task->bill->interest_charged,
                'penalty_charged' => $task->bill->penalty_charged,
                'total_tax_due' => $task->bill->total_tax_due,
                'outstanding_balance' => $task->bill->outstanding_balance,
                'payment_status' => $task->bill->payment_status,
                'delivery_status' => $task->bill->delivery_status,
                'case_status' => $task->bill->case_status,
                'gps_coordinate' => $task->bill->gps_coordinate,
                'gps_accuracy' => $task->bill->gps_accuracy !== null ? (float) $task->bill->gps_accuracy : null,
                'gps_captured_at' => $task->bill->gps_captured_at?->toISOString(),
                'approval_status' => $task->bill->approval_status,
                'recipient_type' => $task->bill->recipient_type,
                'recipient_name' => $task->bill->recipient_name,
                'recipient_contact' => $task->bill->recipient_contact,
                'date_logged' => $task->bill->date_logged?->toDateString(),
                'delivery_date' => $task->bill->delivery_date?->toDateString(),
                'thirty_day_notice_date' => $task->bill->thirty_day_notice_date?->toDateString(),
                'final_notice_date' => $task->bill->final_notice_date?->toDateString(),
                'escalation_stage' => $task->bill->escalation_stage,
                'escalation_override_reason' => $task->bill->escalation_override_reason,
                'remarks' => $task->bill->remarks,
                'account_staff' => $task->bill->accountStaff?->full_name,
                'enforcement_officer' => $task->bill->enforcementOfficer?->full_name,
            ] : null,
            'payments' => $task->bill && $task->bill->relationLoaded('payments')
                ? $task->bill->payments->map(fn ($p) => [
                    'id' => $p->id,
                    'amount' => $p->amount,
                    'payment_period' => $p->payment_period,
                    'receipt_number' => $p->receipt_number,
                    'source_reference' => $p->source_reference,
                    'verified_by' => $p->verifier?->full_name,
                    'verified_at' => $p->verified_at?->toISOString(),
                ])->values()
                : null,
            'verifications' => $task->bill && $task->bill->relationLoaded('verifications')
                ? $task->bill->verifications->map(fn ($v) => [
                    'id' => $v->id,
                    'amount_claimed' => $v->amount_claimed,
                    'receipt_number' => $v->receipt_number,
                    'receipt_bill_number' => $v->receipt_bill_number,
                    'payment_period' => $v->payment_period,
                    'receipt_date' => $v->receipt_date?->toDateString(),
                    'receipt_attachment' => $v->receipt_attachment,
                    'match_status' => $v->match_status,
                    'verification_status' => $v->verification_status,
                    'rejection_reason' => $v->rejection_reason,
                    'verified_by' => $v->verifier?->full_name,
                    'created_at' => $v->created_at?->toISOString(),
                ])->values()
                : null,
            'evidence' => $task->relationLoaded('billEvidence')
                ? $task->billEvidence->map(fn ($ph) => [
                    'id' => $ph->id,
                    'photo_reference' => $ph->photo_reference,
                    'photo_type' => $ph->photo_type,
                    'file_path' => $ph->file_path,
                    'mime' => $ph->mime,
                    'captured_at' => $ph->captured_at?->toISOString(),
                ])->values()
                : null,
            'escalation' => $task->reference_type === 'property_bill' ? $this->escalationInfo($task) : null,
            'assigned_to' => $task->relationLoaded('assignedTo') ? ($task->assignedTo?->full_name ?? $task->assigned_to) : $task->assigned_to,
            'assigned_to_id' => $task->assigned_to,
            'assigned_by' => $task->relationLoaded('assignedBy') ? ($task->assignedBy?->full_name ?? $task->assigned_by) : $task->assigned_by,
            'assignment_status' => $task->assigned_to ? 'Assigned' : ($task->status === 'Awaiting Assignment' ? 'Pending Assignment' : 'Unassigned'),
            'priority' => $task->priority,
            'status' => $task->status,
            'sync_status' => $task->sync_status,
            'sync_source' => $task->sync_source,
            'sync_attempts' => $task->sync_attempts,
            'last_sync_at' => $task->last_sync_at?->toISOString(),
            'due_date' => $task->due_date?->toDateString(),
            'deadline_status' => $task->presentDeadline(),
            'started_at' => $task->started_at?->toISOString(),
            'completed_at' => $task->completed_at?->toISOString(),
            'completed_by' => $task->completed_by,
            'remarks' => $task->remarks,
            'history' => $task->relationLoaded('history') ? $task->history : null,
            'previous_status' => $workflow['previous_status'],
            'stage' => $workflow['stage'],
            'next_action' => $workflow['next_action'],
            'deadline' => $workflow['deadline'],
            'timeline' => $workflow['timeline'],
            'engagements_count' => $task->relationLoaded('engagements') ? $task->engagements->count() : 0,
            'engagements' => $task->relationLoaded('engagements') ? $task->engagements->map(fn ($e) => $this->presentEngagement($e))->values() : null,
            'created_at' => $task->created_at?->toISOString(),
        ];
    }

    private function presentEngagement($engagement): array
    {
        return [
            'id' => $engagement->id,
            'engagement_type' => $engagement->engagement_type,
            'outcome' => $engagement->outcome,
            'notes' => $engagement->notes,
            'officer' => $engagement->relationLoaded('officer') ? ($engagement->officer?->full_name ?? $engagement->officer_id) : $engagement->officer_id,
            'officer_id' => $engagement->officer_id,
            'occurred_at' => $engagement->occurred_at?->toISOString(),
            'meta' => $engagement->meta,
        ];
    }
}
