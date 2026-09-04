<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appeal;
use App\Models\DataQualityFlag;
use App\Models\MeQuery;
use App\Models\PropertyBill;
use App\Models\Task;
use App\Models\User;
use App\Services\AssignmentEligibilityService;
use App\Services\TaskService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * M&E queries + taxpayer appeals. M&E raises/responds/closes queries;
 * appeals are raised against bills and decided by management.
 */
class MEController extends Controller
{
    public function __construct(
        private readonly TaskService $tasks,
    ) {
    }

    /* ---------- M&E queries ---------- */

    public function queries(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['me.view', 'me.create']), 403, 'Missing permission: me.view');

        $query = MeQuery::query()->with(['assignee:id,full_name']);

        if ($status = $request->query('status')) {
            if (in_array($status, ['Open', 'Answered', 'Closed'], true)) {
                $query->where('status', $status);
            }
        }

        if ($user->scopeLevel() === 'own') {
            $query->where('raised_by', $user->id);
        }

        $rows = $query->orderByDesc('id')->paginate($request->query('per_page', 20))->withQueryString();
        $rows->getCollection()->transform(fn ($q) => $this->presentQuery($q));

        return response()->json(['data' => $rows]);
    }

    public function createQuery(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canPermission('me.create'), 403, 'Missing permission: me.create');

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string|max:4000',
            'priority' => ['nullable', Rule::in(['Low', 'Normal', 'High', 'Urgent'])],
            'assigned_to' => 'nullable|integer|exists:users,id',
            'reference_type' => 'nullable|string|max:60',
            'reference_id' => 'nullable|integer',
        ]);

        $query = MeQuery::create([
            'title' => $data['title'],
            'description' => $data['description'],
            'priority' => $data['priority'] ?? 'Normal',
            'raised_by' => $user->id,
            'assigned_to' => $data['assigned_to'] ?? null,
            'reference_type' => $data['reference_type'] ?? null,
            'reference_id' => $data['reference_id'] ?? null,
        ]);

        if ($query->assigned_to) {
            $assignee = \App\Models\User::find($query->assigned_to);
            $this->tasks->notify($assignee, 'Query assigned', "M&E query {$query->query_reference} assigned to you.", 'me');
        }

        return response()->json([
            'data' => $this->presentQuery($query->fresh(['assignee:id,full_name'])),
            'message' => 'Query raised.',
        ], 201);
    }

    public function respondQuery(Request $request, MeQuery $query)
    {
        $user = $request->user();
        abort_unless($user->canPermission('me.respond'), 403, 'Missing permission: me.respond');
        abort_if($query->status === 'Closed', 422, 'Query is closed.');

        $data = $request->validate([
            'response' => 'required|string|max:4000',
        ]);

        $query->update([
            'response' => $data['response'],
            'status' => 'Answered',
            'responded_by' => $user->id,
            'responded_at' => now(),
        ]);

        $this->tasks->notify(\App\Models\User::find($query->raised_by), 'Query answered', "Query {$query->query_reference} has a response.", 'me');

        return response()->json([
            'data' => $this->presentQuery($query->fresh()),
            'message' => 'Query answered.',
        ]);
    }

    public function closeQuery(Request $request, MeQuery $query)
    {
        $user = $request->user();
        abort_unless($user->canPermission('me.close'), 403, 'Missing permission: me.close');

        $query->update([
            'status' => 'Closed',
            'closed_by' => $user->id,
            'closed_at' => now(),
        ]);

        return response()->json([
            'data' => $this->presentQuery($query->fresh()),
            'message' => 'Query closed.',
        ]);
    }

    /* ---------- M&E operational powers ---------- */

    /** Assign a walk-in taxpayer bill's task to an enforcement officer. */
    public function assignWalkIn(Request $request, Task $task)
    {
        $user = $request->user();
        abort_unless($user->canPermission('me.assign_walkin'), 403, 'Missing permission: me.assign_walkin');
        abort_unless($task->reference_type === 'property_bill', 422, 'Task is not linked to a bill.');

        $data = $request->validate([
            'officer_id' => 'required|integer|exists:users,id',
            'notes' => 'nullable|string|max:1000',
        ]);

        $bill = PropertyBill::find($task->reference_id);
        abort_if(! $bill || $bill->recipient_type !== PropertyBill::RECIPIENT_WALK_IN, 422, 'Only walk-in taxpayer bills can be assigned this way.');

        $officer = User::find($data['officer_id']);
        abort_if(! $officer, 422, 'Officer not found.');
        abort_if(
            ! AssignmentEligibilityService::canExecuteTaskType($officer, Task::TYPE_BILL_DELIVERY),
            422,
            'Assignment rejected. '.$officer->full_name.' does not have permission to perform Bill Delivery.'
        );

        $this->tasks->assignBillTask($bill, $officer, $user);

        return response()->json([
            'data' => ['task_id' => $task->id, 'task_reference' => $task->task_reference, 'status' => 'Assigned'],
            'message' => 'Walk-in case assigned to '.$officer->full_name.'.',
        ]);
    }

    /** Reassign a task to another officer (supervision / M&E / managers). */
    public function reassignTask(Request $request, Task $task)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['me.reassign', 'tasks.reassign']), 403, 'Missing permission: me.reassign');
        abort_if(in_array($task->status, Task::COMPLETED_STATUSES, true), 422, 'Completed tasks cannot be reassigned.');

        $data = $request->validate([
            'officer_id' => 'required|integer|exists:users,id',
            'notes' => 'nullable|string|max:1000',
        ]);

        $target = User::find($data['officer_id']);
        abort_if(
            ! $target || ! AssignmentEligibilityService::canExecuteTaskType($target, $task->task_type),
            422,
            'Assignment rejected. '.($target?->full_name ?? 'User')." does not have permission to perform {$task->task_type}."
        );

        $from = $task->assigned_to;
        $fromStatus = $task->status;
        $task->update([
            'assigned_to' => $data['officer_id'],
            'assigned_by' => $user->id,
            'status' => 'Assigned',
        ]);

        $task->history()->create([
            'from_status' => $fromStatus,
            'to_status' => 'Assigned',
            'action' => 'reassign',
            'performed_by' => $user->id,
            'remarks' => $data['notes'] ?? ('Reassigned to '.$target->full_name.' (from user #'.($from ?? 'none').').'),
        ]);

        $task->recordEngagement('assignment', 'reassigned', $data['notes'] ?? "Reassigned to ".$target->full_name.'.', $user);

        $this->tasks->notify($target, 'Task reassigned', "{$task->task_reference} reassigned to you.", 'me');

        return response()->json([
            'data' => ['task_id' => $task->id, 'task_reference' => $task->task_reference, 'assigned_to' => $data['officer_id']],
            'message' => 'Task reassigned.',
        ]);
    }

    /** Task revision — M&E revises due-date / priority / remarks with a trail. */
    public function reviseTask(Request $request, Task $task)
    {
        $user = $request->user();
        abort_unless($user->canPermission('me.revise'), 403, 'Missing permission: me.revise');
        abort_if(in_array($task->status, Task::COMPLETED_STATUSES, true), 422, 'Completed tasks cannot be revised.');

        $data = $request->validate([
            'due_date' => 'nullable|date',
            'priority' => ['nullable', Rule::in(Task::PRIORITIES)],
            'notes' => 'nullable|string|max:1000',
        ]);

        $task->update([
            'due_date' => $data['due_date'] ?? $task->due_date,
            'priority' => $data['priority'] ?? $task->priority,
            'remarks' => $data['notes'] ?? $task->remarks,
        ]);

        $task->history()->create([
            'from_status' => $task->status,
            'to_status' => $task->status,
            'action' => 'revise',
            'performed_by' => $user->id,
            'remarks' => 'Revised: '.trim(implode(', ', array_filter([
                isset($data['due_date']) ? 'due '.$data['due_date'] : null,
                isset($data['priority']) ? 'priority '.$data['priority'] : null,
                $data['notes'] ?? null,
            ]))).'.',
        ]);

        return response()->json([
            'data' => ['task_id' => $task->id, 'task_reference' => $task->task_reference, 'due_date' => $task->due_date?->toDateString(), 'priority' => $task->priority],
            'message' => 'Task revised.',
        ]);
    }

    /** M&E task review board — walk-in cases and overdue/aging tasks. */
    public function reviewBoard(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['me.view', 'me.assign_walkin', 'me.review']), 403, 'Missing permission.');

        $query = Task::query()->where('reference_type', 'property_bill')->with(['assignedTo:id,full_name', 'bill:id,document_number,property_id,taxpayer_name,recipient_type,outstanding_balance,case_status'])
            ->when($request->query('walkin') === '1', fn ($q) => $q->whereHas('bill', fn ($b) => $b->where('recipient_type', PropertyBill::RECIPIENT_WALK_IN)))
            ->when($request->query('aging') === '1', fn ($q) => $q->whereNotNull('due_date')->whereDate('due_date', '<', now())->whereNotIn('status', Task::COMPLETED_STATUSES));

        $rows = $query->orderByDesc('id')->limit(100)->get();

        return response()->json(['data' => $rows->map(fn ($t) => [
            'id' => $t->id,
            'task_reference' => $t->task_reference,
            'task_type' => $t->task_type,
            'status' => $t->status,
            'priority' => $t->priority,
            'due_date' => $t->due_date?->toDateString(),
            'assigned_to' => $t->assignedTo?->full_name,
            'bill' => $t->bill ? [
                'id' => $t->bill->id,
                'document_number' => $t->bill->document_number,
                'property_id' => $t->bill->property_id,
                'taxpayer_name' => $t->bill->taxpayer_name,
                'recipient_type' => $t->bill->recipient_type,
                'outstanding_balance' => $t->bill->outstanding_balance,
                'case_status' => $t->bill->case_status,
            ] : null,
        ])]);
    }

    /* ---------- Data quality flags ---------- */

    public function flags(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['me.view', 'me.flag_data_quality']), 403, 'Missing permission.');

        $query = DataQualityFlag::query()->with(['bill:id,document_number,property_id,taxpayer_name', 'flaggedBy:id,full_name']);

        if ($status = $request->query('status')) {
            if (in_array($status, DataQualityFlag::STATUSES, true)) {
                $query->where('status', $status);
            }
        }

        $rows = $query->orderByDesc('id')->paginate($request->query('per_page', 20))->withQueryString();

        return response()->json(['data' => $rows]);
    }

    public function flag(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canPermission('me.flag_data_quality'), 403, 'Missing permission: me.flag_data_quality');

        $data = $request->validate([
            'bill_id' => 'required|integer|exists:property_bills,id',
            'issue' => 'required|string|max:255',
            'severity' => ['nullable', Rule::in(DataQualityFlag::SEVERITIES)],
        ]);

        $flag = DataQualityFlag::create([
            'bill_id' => $data['bill_id'],
            'issue' => $data['issue'],
            'severity' => $data['severity'] ?? 'Moderate',
            'status' => 'Open',
            'flagged_by' => $user->id,
            'flagged_at' => now(),
        ]);

        $bill = PropertyBill::find($data['bill_id']);
        $this->tasks->notifySection('ACCT', 'Data-quality flag', "Flagged: {$data['issue']} on bill {$bill->document_number}.", 'me');

        return response()->json([
            'data' => ['id' => $flag->id, 'bill_id' => $flag->bill_id, 'issue' => $flag->issue, 'status' => $flag->status, 'severity' => $flag->severity],
            'message' => 'Data-quality issue flagged.',
        ], 201);
    }

    public function resolveFlag(Request $request, DataQualityFlag $flag)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['me.flag_data_quality', 'me.responsible']), 403, 'Missing permission.');
        abort_if($flag->status === 'Resolved', 422, 'Flag already resolved.');

        $data = $request->validate(['remarks' => 'nullable|string|max:2000']);

        $flag->update([
            'status' => 'Resolved',
            'resolved_by' => $user->id,
            'resolved_at' => now(),
            'resolution_remarks' => $data['remarks'] ?? null,
        ]);

        return response()->json(['data' => ['id' => $flag->id, 'status' => 'Resolved'], 'message' => 'Flag resolved.']);
    }

    /* ---------- Appeals ---------- */

    public function appeals(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['me.view', 'valuation.approve']), 403, 'Missing permission.');

        $query = Appeal::query()->with(['bill:id,document_number,property_id']);

        if ($status = $request->query('status')) {
            if (in_array($status, ['Submitted', 'Under Review', 'Upheld', 'Adjusted', 'Dismissed', 'Withdrawn'], true)) {
                $query->where('status', $status);
            }
        }

        $rows = $query->orderByDesc('id')->paginate($request->query('per_page', 20))->withQueryString();
        $rows->getCollection()->transform(fn ($a) => $this->presentAppeal($a));

        return response()->json(['data' => $rows]);
    }

    public function createAppeal(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['me.create', 'valuation.approve']), 403, 'Missing permission: me.create');

        $data = $request->validate([
            'bill_id' => 'required|integer|exists:property_bills,id',
            'reason' => 'required|string|max:255',
            'description' => 'required|string|max:4000',
        ]);

        $bill = PropertyBill::find($data['bill_id']);

        $appeal = Appeal::create([
            'bill_id' => $bill->id,
            'document_number' => $bill->document_number,
            'property_id' => $bill->property_id,
            'taxpayer_name' => $bill->taxpayer_name,
            'reason' => $data['reason'],
            'description' => $data['description'],
        ]);

        $this->tasks->notifySection('MGT', 'Appeal filed', "Appeal {$appeal->appeal_reference} filed for bill {$bill->document_number}.", 'me');

        return response()->json([
            'data' => $this->presentAppeal($appeal->fresh(['bill:id,document_number,property_id'])),
            'message' => 'Appeal filed.',
        ], 201);
    }

    public function decideAppeal(Request $request, Appeal $appeal)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['me.view', 'valuation.approve']), 403, 'Missing permission.');

        $data = $request->validate([
            'decision' => ['required', Rule::in(['upheld', 'adjusted', 'dismissed'])],
            'notes' => 'required|string|max:4000',
        ]);

        abort_if(in_array($appeal->status, ['Upheld', 'Adjusted', 'Dismissed', 'Withdrawn'], true), 422, 'Appeal already decided.');

        $appeal->update([
            'status' => ucfirst($data['decision']),
            'decision' => $data['decision'],
            'decision_notes' => $data['notes'],
            'decided_by' => $user->id,
            'decided_at' => now(),
        ]);

        return response()->json([
            'data' => $this->presentAppeal($appeal->fresh()),
            'message' => 'Appeal decided.',
        ]);
    }

    private function presentQuery(MeQuery $q): array
    {
        return [
            'id' => $q->id,
            'query_reference' => $q->query_reference,
            'title' => $q->title,
            'description' => $q->description,
            'priority' => $q->priority,
            'status' => $q->status,
            'raised_by' => $q->raised_by,
            'assigned_to' => $q->relationLoaded('assignee') ? $q->assignee?->full_name : $q->assigned_to,
            'response' => $q->response,
            'created_at' => $q->created_at?->toISOString(),
        ];
    }

    private function presentAppeal(Appeal $a): array
    {
        return [
            'id' => $a->id,
            'appeal_reference' => $a->appeal_reference,
            'bill_id' => $a->bill_id,
            'document_number' => $a->document_number,
            'property_id' => $a->property_id,
            'taxpayer_name' => $a->taxpayer_name,
            'reason' => $a->reason,
            'description' => $a->description,
            'status' => $a->status,
            'decision' => $a->decision,
            'decision_notes' => $a->decision_notes,
            'decided_by' => $a->decided_by,
            'decided_at' => $a->decided_at?->toISOString(),
            'created_at' => $a->created_at?->toISOString(),
        ];
    }
}
