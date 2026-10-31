<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PropertyDiscovery;
use App\Models\Task;
use App\Models\User;
use App\Services\AssignmentEligibilityService;
use App\Services\TaskService;
use App\Services\TaskWorkflowService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Account & Record Officer — "My Tasks" module (spec §1–§14).
 *
 * Own-scope workspace for the Account & Records section:
 *   - Assigned Tasks: tasks scoped to current_assignee = me whose type belongs
 *     to the account processing set (Account & Record Processing / Account &
 *     Record Manager Review / Discovery Review). RBAC: account-workspace gate +
 *     engine type eligibility; the caller can only ever see records assigned to
 *     them.
 *   - My Discoveries: property records the caller discovered (discovered_by =
 *     me) — discovery ownership stays with the recording officer irrespective
 *     of who currently holds the workflow task.
 *   - Notifications: assignment alerts typed 'account_tasks' with an
 *     action_url into this module. Unread count drives the sidebar badge;
 *     opening the module acknowledges them without deleting the trail.
 *
 * Enforcement is deliberately irrelevant here: an Account & Records Officer
 * needs zero enforcement permissions (acceptance tests 2 & 3).
 */
class AccountTaskController extends Controller
{
    /** Notification type powering the "My Tasks" badge. */
    public const NOTIFICATION_TYPE = 'account_tasks';

    /**
     * Task types that constitute "My Tasks" in the Account & Records section.
     * Payment Verification keeps its own dedicated module and is excluded.
     * Account & Record Officers receive the Processing task only — the review
     * types stay on the Manager's desk.
     */
    public const ACCOUNT_TASK_TYPES = [
        Task::TYPE_ACCOUNT_PROCESSING,
        Task::TYPE_ACCOUNT_MANAGER_REVIEW,
        Task::TYPE_DISCOVERY_REVIEW,
    ];

    public function __construct(
        private readonly TaskService $tasks,
        private readonly TaskWorkflowService $workflow,
    ) {}

    /** KPI summary — Assigned / Completed / Overdue / Due today + My Discoveries + badge. */
    public function summary(Request $request)
    {
        $user = $request->user();
        $this->guardAccountWorkspace($user);

        $base = Task::query()
            ->where('assigned_to', $user->id)
            ->whereIn('task_type', self::ACCOUNT_TASK_TYPES);

        return response()->json(['data' => [
            'assigned' => (clone $base)->whereNotIn('status', Task::COMPLETED_STATUSES)->count(),
            'completed' => (clone $base)->whereIn('status', Task::COMPLETED_STATUSES)->count(),
            'overdue' => (clone $base)->overdue()->count(),
            'due_today' => (clone $base)->dueToday()->count(),
            'discoveries' => PropertyDiscovery::where('discovered_by', $user->id)->count(),
            'unread' => $this->unreadAssignmentCount($user),
        ]]);
    }

    /** Assigned Tasks — assigned_to = me AND account processing types (RBAC + scope enforced). */
    public function assigned(Request $request)
    {
        $user = $request->user();
        $this->guardAccountWorkspace($user);

        $query = Task::query()
            ->with(['assignedTo:id,full_name', 'assignedBy:id,full_name'])
            ->where('assigned_to', $user->id)
            ->whereIn('task_type', self::ACCOUNT_TASK_TYPES);

        // Belt and braces: the task engine's type-visibility gate still applies.
        $viewable = AssignmentEligibilityService::viewableTypes($user);
        if ($viewable !== null) {
            $query->whereIn('tasks.task_type', $viewable);
        }

        $this->applyTaskFilters($request, $query);

        $rows = $query
            ->orderByRaw("CASE tasks.status WHEN 'Escalated' THEN 1 WHEN 'Assigned' THEN 2 WHEN 'Awaiting Assignment' THEN 3 ELSE 4 END")
            ->orderByDesc('tasks.id')
            ->paginate($request->query('per_page', 20))
            ->withQueryString();

        $rows->getCollection()->transform(fn (Task $task) => $this->presentTask($task));

        return response()->json(['data' => $rows]);
    }

    /** My Discoveries — records the caller discovered (discovered_by = me). */
    public function discoveries(Request $request)
    {
        $user = $request->user();
        $this->guardAccountWorkspace($user);

        $query = PropertyDiscovery::query()
            ->with('discoverer:id,full_name')
            ->where('discovered_by', $user->id);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($q = trim((string) $request->query('q'))) {
            $query->where(function (Builder $b) use ($q) {
                $b->where('discovery_reference', 'like', like_term($q))
                    ->orWhere('owner_name', 'like', like_term($q))
                    ->orWhere('property_address', 'like', like_term($q))
                    ->orWhere('property_id', 'like', like_term($q));
            });
        }

        $rows = $query->orderByDesc('id')->paginate($request->query('per_page', 20))->withQueryString();
        $rows->getCollection()->transform(fn ($d) => $this->presentDiscovery($d));

        return response()->json(['data' => $rows]);
    }

    /** Lightweight badge for the sidebar (polled) — unacknowledged assignments. */
    public function badge(Request $request)
    {
        $this->guardAccountWorkspace($request->user());

        return response()->json(['data' => ['unread' => $this->unreadAssignmentCount($request->user())]]);
    }

    /** Acknowledge assignment alerts when the module is opened (history preserved). */
    public function readAll(Request $request)
    {
        $this->guardAccountWorkspace($request->user());

        $request->user()->notifications()
            ->where('type', self::NOTIFICATION_TYPE)
            ->unread()
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'Assignment alerts acknowledged.']);
    }

    /* ------------------------------------------------------------------ */
    /*  Guards */
    /* ------------------------------------------------------------------ */

    /**
     * The Account workspace gate. Account & Records staff pass via section or
     * account-processing permissions; enforcement and valuation-only roles are
     * rejected — account "My Tasks" must never depend on enforcement access.
     */
    private function guardAccountWorkspace(User $user): void
    {
        if ($user->isSystemAdministrator()) {
            return;
        }
        if ($user->section?->code === 'ACCT') {
            return;
        }

        abort_unless($user->hasAnyPermission([
            'discovery.property_registration',
            'discovery.aarm_review',
            'valuation.account_review',
        ]), 403, 'Account workspace access is outside your scope.');
    }

    private function unreadAssignmentCount(User $user): int
    {
        return $user->notifications()->where('type', self::NOTIFICATION_TYPE)->unread()->count();
    }

    private function applyTaskFilters(Request $request, Builder $query): void
    {
        $today = now()->toDateString();

        switch ($request->query('status_group')) {
            case 'active':
                $query->whereNotIn('tasks.status', Task::COMPLETED_STATUSES);
                break;
            case 'completed':
                $query->whereIn('tasks.status', Task::COMPLETED_STATUSES);
                break;
            case 'overdue':
                $query->whereNotIn('tasks.status', Task::COMPLETED_STATUSES)
                    ->whereDate('tasks.due_date', '<', $today);
                break;
        }

        if ($taskType = $request->query('task_type')) {
            $query->where('tasks.task_type', $taskType);
        }

        if ($q = trim((string) $request->query('q'))) {
            $query->where(function (Builder $b) use ($q) {
                $b->where('tasks.task_reference', 'like', like_term($q))
                    ->orWhere(function (Builder $bb) use ($q) {
                        $bb->where('tasks.reference_type', 'property_bill')
                            ->whereExists(function ($sub) use ($q) {
                                $sub->selectRaw('1')->from('property_bills')
                                    ->whereColumn('property_bills.id', 'tasks.reference_id')
                                    ->where(function ($f) use ($q) {
                                        $f->where('document_number', 'like', like_term($q))
                                            ->orWhere('property_id', 'like', like_term($q))
                                            ->orWhere('taxpayer_name', 'like', like_term($q))
                                            ->orWhere('property_address', 'like', like_term($q));
                                    });
                            });
                    });
            });
        }
    }

    /**
     * Compact task card for the Account "My Tasks" list. The bill relation is
     * only meaningful for property_bill references; discovery / valuation
     * references resolve their own label + address.
     */
    private function presentTask(Task $task): array
    {
        $label = null;
        $propertyAddress = null;

        if ($task->reference_type === 'property_bill' && ($bill = $task->bill)) {
            $label = $bill->document_number;
            $propertyAddress = $bill->property_address;
        } elseif ($task->reference_type === 'property_discovery') {
            $discovery = PropertyDiscovery::find($task->reference_id);
            $label = $discovery?->discovery_reference;
            $propertyAddress = $discovery?->property_address;
        } elseif ($task->reference_type === 'valuation' && ($valuation = $task->valuation)) {
            $label = $valuation->valuation_reference;
            $propertyAddress = $valuation->property_address;
        }

        $descriptor = $this->workflow->descriptor($task);

        return [
            'id' => $task->id,
            'task_reference' => $task->task_reference,
            'task_type' => $task->task_type,
            'section' => $task->section,
            'reference_type' => $task->reference_type,
            'reference_id' => $task->reference_id,
            'label' => $label,
            'property_address' => $propertyAddress,
            'status' => $task->status,
            'priority' => $task->priority,
            'due_date' => $task->due_date?->toDateString(),
            'deadline_status' => $task->presentDeadline(),
            'completed_at' => $task->completed_at?->toISOString(),
            'assignee' => $task->relationLoaded('assignedTo') ? ($task->assignedTo?->full_name ?? $task->assigned_to) : $task->assigned_to,
            'stage' => $descriptor['stage'] ?? null,
            'next_action' => $descriptor['next_action'] ?? null,
            'created_at' => $task->created_at?->toISOString(),
        ];
    }

    private function presentDiscovery(PropertyDiscovery $d): array
    {
        $route = match ($d->status) {
            PropertyDiscovery::STATUS_AWAITING_ACCT_REVIEW => 'Account & Record Manager queue',
            PropertyDiscovery::STATUS_AWAITING_VALUATION,
            PropertyDiscovery::STATUS_REFERRED_VALUATION => 'Valuation Manager queue',
            PropertyDiscovery::STATUS_UNDER_VALUATION => 'Valuation in progress',
            PropertyDiscovery::STATUS_AWAITING_AC_APPROVAL => 'Assistant Commissioner',
            PropertyDiscovery::STATUS_APPROVED => 'Approved',
            PropertyDiscovery::STATUS_ASSIGNED_ACCT => 'Assigned to Account & Record — processing',
            PropertyDiscovery::STATUS_REGISTERED => 'Registered',
            PropertyDiscovery::STATUS_COMPLETED => 'Completed',
            PropertyDiscovery::STATUS_RETURNED => 'Returned for correction',
            PropertyDiscovery::STATUS_REJECTED => 'Rejected',
            PropertyDiscovery::STATUS_DRAFT => 'Draft',
            PropertyDiscovery::STATUS_SUBMITTED,
            PropertyDiscovery::STATUS_CLASSIFIED => 'Classified',
            default => $d->decision_path ?? 'not set',
        };

        return [
            'id' => $d->id,
            'discovery_reference' => $d->discovery_reference,
            'status' => $d->status,
            'route' => $route,
            'decision_path' => $d->decision_path,
            'owner_name' => $d->owner_name,
            'property_address' => $d->property_address,
            'property_classification' => $d->property_classification,
            'property_type' => $d->property_type,
            'property_id' => $d->property_id,
            'document_number' => $d->document_number,
            'declared_value' => $d->declared_value !== null ? (float) $d->declared_value : null,
            'discovery_date' => $d->discovery_date?->toDateString(),
            'created_at' => $d->created_at?->toISOString(),
            'discoverer' => $d->relationLoaded('discoverer')
                ? ($d->discoverer?->full_name ?? "User #{$d->discovered_by}")
                : $d->discovered_by,
        ];
    }
}
