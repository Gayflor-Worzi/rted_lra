<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PropertyDiscovery;
use App\Models\Task;
use App\Models\User;
use App\Services\AssignmentEligibilityService;
use App\Services\SectionWorkspaceService;
use App\Services\TaskWorkflowService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Reusable own-scope task workspace controller.
 *
 * A workspace is a section's personal task surface (assigned_to = me) plus its
 * desk queue (Awaiting Assignment). Everything is bounded server-side by the
 * section workspace registry + the task engine's type-visibility gate; a caller
 * can never see a task outside their own section's workspace.
 *
 * Concrete subclasses only declare which workspace they serve
 * (AssistantCommissionerTaskController, ValuationManagerTaskController).
 */
abstract class SectionTaskController extends Controller
{
    public function __construct(
        private readonly TaskWorkflowService $workflow,
    ) {}

    abstract protected function workspace(): string;

    protected function taskTypes(): array
    {
        return SectionWorkspaceService::types($this->workspace());
    }

    protected function notificationType(): string
    {
        return SectionWorkspaceService::notificationType($this->workspace());
    }

    /** KPI summary — Personal / Completed / Overdue / Due today + desk queue + badge. */
    public function summary(Request $request)
    {
        $user = $request->user();
        $this->guard($user);

        $base = Task::query()
            ->where('assigned_to', $user->id)
            ->whereIn('task_type', $this->taskTypes());

        return response()->json(['data' => [
            'personal' => (clone $base)->whereNotIn('status', Task::COMPLETED_STATUSES)->count(),
            'completed' => (clone $base)->whereIn('status', Task::COMPLETED_STATUSES)->count(),
            'overdue' => (clone $base)->overdue()->count(),
            'due_today' => (clone $base)->dueToday()->count(),
            'queue' => $this->queueQuery()->count(),
            'unread' => $this->unreadCount($user),
        ]]);
    }

    /** Personal tasks — assigned_to = me within this workspace's task types. */
    public function assigned(Request $request)
    {
        $user = $request->user();
        $this->guard($user);

        $query = Task::query()
            ->with(['assignedTo:id,full_name', 'assignedBy:id,full_name'])
            ->where('assigned_to', $user->id)
            ->whereIn('task_type', $this->taskTypes());

        // Belt and braces: the engine's type-visibility gate still applies.
        $viewable = AssignmentEligibilityService::viewableTypes($user);
        if ($viewable !== null) {
            $query->whereIn('tasks.task_type', $viewable);
        }

        $this->applyFilters($request, $query);

        $rows = $query
            ->orderByRaw("CASE tasks.status WHEN 'Escalated' THEN 1 WHEN 'Assigned' THEN 2 WHEN 'Awaiting Assignment' THEN 3 ELSE 4 END")
            ->orderByDesc('tasks.id')
            ->paginate($request->query('per_page', 20))
            ->withQueryString();

        $rows->getCollection()->transform(fn (Task $task) => $this->presentTask($task));

        return response()->json(['data' => $rows]);
    }

    /** Desk queue — unassigned tasks of this workspace's types awaiting assignment. */
    public function queue(Request $request)
    {
        $user = $request->user();
        $this->guard($user);

        $query = $this->queueQuery()->with(['assignedBy:id,full_name']);

        if ($q = trim((string) $request->query('q'))) {
            $query->where('task_reference', 'like', like_term($q));
        }

        $rows = $query->orderByDesc('tasks.id')
            ->paginate($request->query('per_page', 20))
            ->withQueryString();

        $rows->getCollection()->transform(fn (Task $task) => $this->presentTask($task));

        return response()->json(['data' => $rows]);
    }

    /** Lightweight badge for the sidebar — unacknowledged workspace assignments. */
    public function badge(Request $request)
    {
        $this->guard($request->user());

        return response()->json(['data' => ['unread' => $this->unreadCount($request->user())]]);
    }

    /** Acknowledge assignment alerts when the workspace is opened. */
    public function readAll(Request $request)
    {
        $this->guard($request->user());

        $request->user()->notifications()
            ->where('type', $this->notificationType())
            ->unread()
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'Assignment alerts acknowledged.']);
    }

    private function queueQuery(): Builder
    {
        $query = Task::query()
            ->whereNull('assigned_to')
            ->whereIn('task_type', $this->taskTypes());

        // Belt and braces: the engine's type-visibility gate still applies.
        $viewable = AssignmentEligibilityService::viewableTypes(request()->user());
        if ($viewable !== null) {
            $query->whereIn('tasks.task_type', $viewable);
        }

        return $query;
    }

    /** The workspace gate — registry-based, so ownership can never slip through. */
    protected function guard(User $user): void
    {
        abort_unless(
            SectionWorkspaceService::canAccess($user, $this->workspace()),
            403,
            SectionWorkspaceService::label($this->workspace()).' workspace is outside your scope.'
        );
    }

    private function unreadCount(User $user): int
    {
        return $user->notifications()->where('type', $this->notificationType())->unread()->count();
    }

    private function applyFilters(Request $request, Builder $query): void
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
}
