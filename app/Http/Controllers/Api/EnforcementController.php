<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ReceiptConflictException;
use App\Http\Controllers\Controller;
use App\Models\EnforcementVisit;
use App\Models\EvidencePhoto;
use App\Models\PaymentVerification;
use App\Models\PropertyBill;
use App\Models\PropertyDiscovery;
use App\Models\ReceiptFingerprint;
use App\Models\Task;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ReceiptDuplicationService;
use App\Services\SectionWorkspaceService;
use App\Services\SyncService;
use App\Services\TaskService;
use App\Services\TaskWorkflowService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Enforcement hub — field assignments, visits, property discovery and
 * payment claims submitted from the field (mobile) or web.
 */
class EnforcementController extends Controller
{
    public function __construct(
        private readonly TaskService $tasks,
        private readonly AuditService $audit,
        private readonly TaskWorkflowService $workflow,
        private readonly SyncService $sync,
        private readonly ReceiptDuplicationService $duplicates,
    ) {}

    /**
     * Assignments for the logged-in officer (mobile task list).
     */
    public function myAssignments(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canPermission('tasks.view_own'), 403, 'Missing permission: tasks.view_own');
        $statusGroup = $request->query('status_group', 'active');
        abort_unless(
            in_array($statusGroup, ['all', 'active', 'pending', 'overdue', 'escalated', 'completed'], true),
            422,
            'Unknown task status group.'
        );

        // Enforcement workspace isolation: the field queue only ever carries
        // enforcement task types — account/valuation/AC desk work is processed
        // inside its own section workspace (§23 / §24).
        $query = Task::where('assigned_to', $user->id)
            ->whereIn('task_type', SectionWorkspaceService::types(SectionWorkspaceService::ENFORCEMENT))
            ->with('assignedTo:id,full_name')
            ->with('assignedBy:id,full_name')
            ->withCount('engagements')
            ->orderByRaw("CASE tasks.status WHEN 'Escalated' THEN 1 WHEN '30-Day Warning' THEN 2 WHEN '72-Hour Warning' THEN 3 WHEN 'Assigned' THEN 4 WHEN 'Out for Delivery' THEN 5 ELSE 6 END DESC")
            ->orderByDesc('id');

        switch ($statusGroup) {
            case 'active':
                $query->whereNotIn('status', Task::COMPLETED_STATUSES);
                break;
            case 'pending':
                $query->whereNotIn('status', [...Task::COMPLETED_STATUSES, 'Escalated']);
                break;
            case 'overdue':
                $query->whereNotIn('status', Task::COMPLETED_STATUSES)->overdue();
                break;
            case 'escalated':
                $query->where('status', 'Escalated');
                break;
            case 'completed':
                $query->whereIn('status', Task::COMPLETED_STATUSES);
                break;
        }

        $rows = $query->paginate($request->query('per_page', 50))->withQueryString();

        // Eliminate N+1: preload all linked bills (incl. soft-deleted) once for this page,
        // along with their staff references, then look them up from a map during transform.
        $billIds = $rows->getCollection()
            ->where('reference_type', 'property_bill')
            ->pluck('reference_id')
            ->filter()
            ->unique()
            ->values();

        $bills = $billIds->isNotEmpty()
            ? PropertyBill::withTrashed()->with(['accountStaff:id,full_name', 'enforcementOfficer:id,full_name'])
                ->whereIn('id', $billIds)->get()->keyBy('id')
            : collect();

        $rows->getCollection()->transform(function (Task $task) use ($bills) {
            $bill = $task->reference_type === 'property_bill'
                ? $bills->get($task->reference_id)
                : null;

            $descriptor = [];
            try {
                $descriptor = $this->workflow->descriptor($task);
            } catch (\Throwable $e) {
                $descriptor = ['previous_status' => $task->previousStatus(), 'current_status' => $task->status, 'stage' => null, 'next_action' => null, 'deadline' => null];
            }

            $data = [
                'id' => $task->id,
                'task_reference' => $task->task_reference,
                'task_type' => $task->task_type,
                'section' => $task->section,
                'status' => $task->status,
                'sync_status' => $task->sync_status,
                'sync_source' => $task->sync_source,
                'sync_attempts' => $task->sync_attempts,
                'last_sync_at' => $task->last_sync_at?->toISOString(),
                'priority' => $task->priority,
                'due_date' => $task->due_date?->toDateString(),
                'deadline_status' => $task->presentDeadline(),
                'reference_type' => $task->reference_type,
                'reference_id' => $task->reference_id,
                'property_bill_id' => $bill?->id,
                'assigned_to' => $task->assignedTo?->full_name ?? $task->assigned_to,
                'assigned_to_id' => $task->assigned_to,
                'assigned_by' => $task->assignedBy?->full_name ?? $task->assigned_by,
                'assignment_status' => $task->assigned_to ? 'Assigned' : ($task->status === 'Awaiting Assignment' ? 'Pending Assignment' : 'Unassigned'),
                'previous_status' => $descriptor['previous_status'] ?? null,
                'stage' => $descriptor['stage'] ?? null,
                'next_action' => $descriptor['next_action'] ?? null,
                'deadline' => $descriptor['deadline'] ?? null,
                'engagements_count' => $task->engagements_count ?? null,
                'property_bill' => $bill ? [
                    'id' => $bill->id,
                    'bill_number' => $bill->document_number,
                    'document_number' => $bill->document_number,
                    'property_id' => $bill->property_id,
                    'property_address' => $bill->property_address,
                    'taxpayer_name' => $bill->taxpayer_name,
                    'tin' => $bill->tin,
                    'tax_period' => $bill->tax_period,
                    'property_classification' => $bill->property_classification,
                    'property_type' => $bill->property_type,
                    'assessed_value' => $bill->assessed_value,
                    'tax_amount' => $bill->tax_amount,
                    'interest_charged' => $bill->interest_charged,
                    'penalty_charged' => $bill->penalty_charged,
                    'total_tax_due' => $bill->total_tax_due,
                    'outstanding_balance' => $bill->outstanding_balance,
                    'payment_status' => $bill->payment_status,
                    'delivery_status' => $bill->delivery_status,
                    'case_status' => $bill->case_status,
                    'gps_coordinate' => $bill->gps_coordinate,
                    'gps_accuracy' => $bill->gps_accuracy !== null ? (float) $bill->gps_accuracy : null,
                    'gps_captured_at' => $bill->gps_captured_at?->toISOString(),
                    'date_logged' => $bill->date_logged?->toDateString(),
                    'recipient_type' => $bill->recipient_type,
                    'recipient_name' => $bill->recipient_name,
                    'recipient_contact' => $bill->recipient_contact,
                    'delivery_date' => $bill->delivery_date?->toDateString(),
                    'thirty_day_notice_date' => $bill->thirty_day_notice_date?->toDateString(),
                    'final_notice_date' => $bill->final_notice_date?->toDateString(),
                    'escalation_stage' => $bill->escalation_stage,
                    'escalation_override_reason' => $bill->escalation_override_reason,
                    'approval_status' => $bill->approval_status,
                    'date_logged' => $bill->date_logged?->toDateString(),
                    'remarks' => $bill->remarks,
                    'account_staff' => $bill->accountStaff?->full_name ?: ($bill->account_staff_id ? "#{$bill->account_staff_id}" : null),
                    'enforcement_officer' => $bill->enforcementOfficer?->full_name ?: ($bill->assigned_enforcement_officer_id ? "#{$bill->assigned_enforcement_officer_id}" : null),
                ] : null,
            ];

            return $data;
        });

        return response()->json(['data' => $rows]);
    }

    /**
     * Officer completes or escalates their assignment.
     */
    public function assignmentAction(Request $request, Task $task)
    {
        $user = $request->user();
        abort_unless($task->assigned_to == $user->id, 403, 'Task not assigned to you.');

        $syncId = $this->sync->localId($request);
        if ($syncId && ($replay = $this->sync->findForReplay($user->id, $syncId))) {
            return $this->sync->replayResponse($replay);
        }

        $data = $request->validate([
            'action' => ['required', Rule::in(['completed', 'complete', 'escalate'])],
            'visit_date' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
            'local_transaction_id' => 'nullable|string|max:80',
        ]);

        $completing = $data['action'] !== 'escalate';
        if ($completing && $task->task_type === Task::TYPE_BILL_DELIVERY) {
            $latestDeliveryOutcome = EnforcementVisit::query()
                ->where('task_id', $task->id)
                ->orderByDesc('id')
                ->value('delivery_status');
            abort_unless(
                $latestDeliveryOutcome === EnforcementVisit::DELIVERY_DELIVERED,
                422,
                'A bill delivery task can only be completed after a visit records a Delivered outcome.'
            );
        }

        try {
            $payload = DB::transaction(function () use ($data, $task, $user, $syncId, $completing) {
                $wasDelivered = $task->status === 'Delivered';
                if ($data['action'] === 'escalate') {
                    $task->transitionTo('Escalated', 'escalate', $user, $data['notes'] ?? null);
                } elseif (! $wasDelivered) {
                    $task->transitionTo('Delivered', 'complete', $user, $data['notes'] ?? null);
                }

                if ($completing && ! $wasDelivered) {
                    $task->recordEngagement('bill_delivered', 'delivered', $data['notes'] ?? 'Assignment completed — bill handed over.', $user, isset($data['visit_date']) ? Carbon::parse($data['visit_date']) : now());
                } elseif (! $completing) {
                    $task->recordEngagement('follow_up', 'escalated', $data['notes'] ?? 'Assignment escalated by the assigned officer.', $user);
                }

                // Sync bill case status with the task when present.
                if ($task->reference_type === 'property_bill') {
                    $bill = PropertyBill::find($task->reference_id);
                    if ($bill) {
                        $bill->case_status = $completing ? 'Delivered' : 'Escalated';
                        $bill->save();
                    }
                }

                $payload = ['task_id' => $task->id, 'task_reference' => $task->task_reference, 'status' => $task->status];
                if ($syncId) {
                    $this->sync->record($user->id, $syncId, 'action', (int) $task->id, $payload);
                }

                return $payload;
            });
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $payload,
            'message' => $completing ? 'Assignment completed.' : 'Assignment escalated.',
        ]);
    }

    /**
     * Record a field visit (enforcement evidence + status snapshot).
     */
    public function storeVisit(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canPermission('enforcement.record_visit'), 403, 'Missing permission: enforcement.record_visit');

        $syncId = $this->sync->localId($request);
        if ($syncId && ($replay = $this->sync->findForReplay($user->id, $syncId))) {
            return $this->sync->replayResponse($replay);
        }

        $data = $request->validate([
            'assignment_id' => 'nullable|integer|exists:tasks,id',
            'task_id' => 'nullable|integer|exists:tasks,id',
            'property_bill_id' => 'nullable|integer|exists:property_bills,id',
            'bill_id' => 'nullable|integer|exists:property_bills,id',
            'status' => 'required|string|max:60',
            'visit_status' => 'nullable|string|max:60',
            'bill_delivery_status' => ['nullable', 'string', Rule::in([
                ...EnforcementVisit::DELIVERY_STATUSES,
                'Returned to Office',
            ])],
            'delivery_status' => ['nullable', 'string', Rule::in([
                ...EnforcementVisit::DELIVERY_STATUSES,
                'Returned to Office',
            ])],
            'notes' => 'nullable|string|max:2000',
            'remarks' => 'nullable|string|max:2000',
            'gps_lat' => 'nullable|numeric',
            'gps_lng' => 'nullable|numeric',
            'gps_coordinate' => 'nullable|string|max:100',
            'gps_accuracy' => 'nullable|numeric|min:0',
            'gps_captured_at' => 'nullable|date',
            'proof_photo' => 'nullable|string',
            'visit_photo' => 'nullable|string',
            'recipient_name' => 'nullable|string|max:255',
            'recipient_contact' => 'nullable|string|max:100',
            'photo_type' => 'nullable|string|max:40',
            // optional on-the-spot payment claim
            'claim_receipt_number' => 'nullable|string|max:80',
            'claim_amount' => 'nullable|numeric|min:0',
            'claim_payment_date' => 'nullable|date',
            'claim_receipt_photo' => 'nullable|string',
            'claim_remarks' => 'nullable|string|max:2000',
            'claim_property_id' => 'nullable|string|max:60',
            'claim_tin' => 'nullable|string|max:40',
            'claim_tax_due_date' => 'nullable|date',
            'local_transaction_id' => 'nullable|string|max:80',
        ]);

        abort_if(
            ! empty($data['delivery_status'])
                && ! empty($data['bill_delivery_status'])
                && $data['delivery_status'] !== $data['bill_delivery_status'],
            422,
            'Delivery status fields must agree.'
        );
        $deliveryStatus = $data['delivery_status'] ?? $data['bill_delivery_status'] ?? null;
        if ($deliveryStatus === 'Returned to Office') {
            $deliveryStatus = EnforcementVisit::DELIVERY_RETURNED;
        }

        $taskId = $data['assignment_id'] ?? $data['task_id'] ?? null;
        $billId = $data['property_bill_id'] ?? $data['bill_id'] ?? null;

        $task = $taskId ? Task::with('assignedTo')->find($taskId) : null;
        $bill = $billId ? PropertyBill::with('tasks.assignedTo')->find($billId) : null;

        // Resolve bill from task if bill_id not provided but task references a bill.
        if (! $bill && $task && $task->reference_type === 'property_bill') {
            $bill = PropertyBill::find($task->reference_id);
        }

        if (! $bill && ! $task) {
            abort(422, 'Either a task or a bill must be provided to record a visit.');
        }

        // ---- AUTHORIZATION: user must be scoped to the task or bill ----
        // Determine the "effective bill" the user is authorized to visit.
        $effectiveBill = null;
        $effectiveTask = null;

        if ($task) {
            // User must be assigned to this task or have supervisory scope.
            $taskAuthorized = $task->assigned_to === $user->id
                || $user->isSystemAdministrator();

            if (! $taskAuthorized) {
                abort_unless(false, 403, 'You can only record visits for tasks assigned to you.');
            }

            // The bill referenced by the task must match any bill_id provided.
            if ($billId && $task->reference_type === 'property_bill' && $task->reference_id != $billId) {
                abort_unless(false, 422, 'Visit bill does not match the task record.');
            }

            $effectiveTask = $task;
            $effectiveBill = $bill ?? PropertyBill::find($task->reference_id);
        } elseif ($bill) {
            // User must have a task assigned to this bill, or be system admin.
            $billHasTask = Task::where('reference_type', 'property_bill')
                ->where('reference_id', $bill->id)
                ->where('assigned_to', $user->id)
                ->exists();

            if (! $billHasTask && ! $user->isSystemAdministrator()) {
                abort_unless(false, 403, 'You can only record visits for bills assigned to you.');
            }

            $effectiveBill = $bill;
        } else {
            // Neither task nor bill provides scope — deny by default.
            abort(403, 'You can only record visits for work assigned to you.');
        }

        // Ensure we have a valid bill after authorization.
        if (! $effectiveBill) {
            abort(422, 'A bill is required to record a visit.');
        }

        $task = $effectiveTask;

        $gps = $data['gps_coordinate'] ?? (isset($data['gps_lat'], $data['gps_lng'])
            ? trim($data['gps_lat'].','.$data['gps_lng'])
            : null);

        // Evidence photo: decode base64 data URIs to a stored file FIRST so the
        // huge base64 string never touches the (VARCHAR) visit_photo column —
        // only the small file path is persisted. A device/reference path is kept
        // verbatim as capture evidence.
        $proofSource = $data['visit_photo'] ?? $data['proof_photo'] ?? null;
        $storedPhoto = null;
        $photoMime = null;
        $photoSize = null;
        if ($proofSource) {
            [$storedPhoto, $photoMime, $photoSize] = $this->storePhotoBytes($proofSource);
            // storePhotoBytes returns the raw input verbatim when it was not a
            // decodable base64 capture (decode failure). Do not leak that into
            // the DB column — evidence is then path-only.
            if ($storedPhoto && str_contains($storedPhoto, 'base64,')) {
                $storedPhoto = null;
            }
        }

        $visitPayload = DB::transaction(function () use ($data, $user, $syncId, $task, $bill, $billId, $taskId, $storedPhoto, $photoMime, $photoSize, $gps, $deliveryStatus) {
            $visit = EnforcementVisit::create([
                'task_id' => $task?->id ?? $taskId,
                'bill_id' => $bill?->id ?? $billId,
                'document_number' => $bill?->document_number,
                'property_id' => $bill?->property_id,
                'officer_id' => $user->id,
                'visit_date' => now()->toDateString(),
                'visit_status' => $data['visit_status'] ?? $data['status'],
                'delivery_status' => $deliveryStatus,
                'recipient_name' => $data['recipient_name'] ?? null,
                'recipient_contact' => $data['recipient_contact'] ?? null,
                'gps_coordinate' => $gps,
                'gps_accuracy' => $data['gps_accuracy'] ?? null,
                'gps_captured_at' => $data['gps_captured_at'] ?? now(),
                'visit_photo' => $storedPhoto,
                'remarks' => $data['remarks'] ?? $data['notes'] ?? null,
                'snapshot_outstanding' => $bill?->outstanding_balance,
                'snapshot_payment_status' => $bill?->payment_status,
                'snapshot_case_status' => $bill?->case_status,
            ]);

            if ($storedPhoto) {
                EvidencePhoto::create([
                    'photo_type' => $data['photo_type'] ?? 'PROPERTY_FULL_VIEW',
                    'bill_id' => $bill?->id ?? $billId,
                    'task_id' => $taskId,
                    'visit_id' => $visit->id,
                    'property_id' => $bill?->property_id,
                    'officer_id' => $user->id,
                    'file_path' => $storedPhoto,
                    'mime' => $photoMime,
                    'size_bytes' => $photoSize,
                    'gps_coordinate' => $gps,
                    'captured_at' => $data['gps_captured_at'] ?? now(),
                    'remarks' => 'Captured at visit '.$visit->visit_reference.'.',
                ]);
            }

            $this->audit->record($visit, 'enforcement.visit_recorded', $user->id, [], [
                'visit_reference' => $visit->visit_reference,
                'bill_id' => $visit->bill_id,
                'gps_accuracy' => $visit->gps_accuracy,
            ]);

            // Advance task + bill where possible.
            if ($task) {
                $handedOver = $deliveryStatus === EnforcementVisit::DELIVERY_DELIVERED;

                if ($task && $task->status === 'Assigned') {
                    try {
                        $task->transitionTo('Out for Delivery', 'visit', $user, 'Field visit recorded.');
                    } catch (\InvalidArgumentException $e) {
                        // Non-blocking — visit is still persisted.
                    }
                }

                // A handed-over delivery completes the delivery stage.
                if ($task && $handedOver && $task->status === 'Out for Delivery') {
                    try {
                        $task->transitionTo('Delivered', 'delivered', $user, 'Bill handed over during field visit.');
                    } catch (\InvalidArgumentException $e) {
                        // Non-blocking — visit is still persisted.
                    }
                }

                if ($task) {
                    $outcome = $handedOver ? 'delivered' : $this->visitOutcome((string) ($data['visit_status'] ?? $data['status'] ?? ''));
                    $task->recordEngagement(
                        $handedOver ? 'bill_delivered' : 'delivery_attempt',
                        $outcome,
                        ($data['remarks'] ?? $data['notes'] ?? null),
                        $user,
                        now(),
                        array_filter(['gps' => $gps, 'recipient' => $data['recipient_name'] ?? null, 'visit' => $visit->visit_reference]),
                    );
                }
            }

            if ($bill) {
                $handedOver = $deliveryStatus === EnforcementVisit::DELIVERY_DELIVERED;

                if ($bill->case_status === 'Logged' || $bill->case_status === 'Awaiting Assignment' || $bill->case_status === 'Assigned') {
                    $bill->case_status = $handedOver ? 'Delivered' : 'Out for Delivery';
                }
                if ($deliveryStatus !== null && in_array($bill->delivery_status, ['Logged', 'Out for Delivery'], true)) {
                    $bill->delivery_status = $deliveryStatus;
                } elseif ($bill->delivery_status === 'Logged') {
                    $bill->delivery_status = EnforcementVisit::DELIVERY_OUT_FOR_DELIVERY;
                }
                $bill->save();

                // Stamp the formal delivery/notice trail when the officer reports a hand-over.
                if ($handedOver) {
                    if (! $bill->delivery_date) {
                        $bill->delivery_date = now()->toDateString();
                        $bill->save();
                    }
                }
            }

            // Optional on-the-spot payment claim (receipt from the field).
            if ($bill && ! empty($data['claim_receipt_number']) && $data['claim_amount'] !== null) {
                // Receipt photo goes through the same decode-to-file path so the
                // base64 string is never written into the VARCHAR attachment column.
                $claimReceiptPath = null;
                if (! empty($data['claim_receipt_photo'])) {
                    $maybePath = $this->storePhotoBytes($data['claim_receipt_photo'])[0];
                    if ($maybePath && ! str_contains($maybePath, 'base64,')) {
                        $claimReceiptPath = $maybePath;
                    }
                }

                $verification = PaymentVerification::create([
                    'bill_id' => $bill->id,
                    'property_id' => $data['claim_property_id'] ?? $bill->property_id,
                    'tin' => $data['claim_tin'] ?? $bill->tin,
                    'tax_due_date' => $data['claim_tax_due_date'] ?? null,
                    'document_number' => $bill->document_number,
                    'receipt_number' => $data['claim_receipt_number'],
                    'receipt_bill_number' => $bill->document_number,
                    'amount_claimed' => $data['claim_amount'],
                    'payment_period' => null,
                    'receipt_attachment' => $claimReceiptPath ?? $storedPhoto,
                    'match_status' => PaymentVerification::MATCH_PENDING,
                    'verification_status' => PaymentVerification::STATUS_PENDING,
                ]);

                $bill->case_status = 'Under Verification';
                $bill->payment_status = 'Payment Claimed';
                $bill->save();

                // Use the authorized task if one exists for this bill; otherwise look up by task_id.
                $claimTask = $task ?? Task::where('reference_type', 'property_bill')
                    ->where('reference_id', $bill->id)
                    ->where('assigned_to', $user->id)
                    ->first();

                if ($claimTask) {
                    if (in_array($claimTask->status, ['Assigned', 'Out for Delivery', 'Delivered'], true)) {
                        try {
                            $claimTask->transitionTo('Payment Claimed', 'payment_claim', $user, 'Claimed on the spot during field visit.');
                        } catch (\InvalidArgumentException $e) {
                            // non-blocking
                        }
                    }
                    $claimTask->recordEngagement('payment_claim', 'claim_submitted', "On-the-spot claim for bill {$bill->document_number} awaiting verification.", $user, now());
                }

                $this->tasks->notifySection('ACCT', 'Payment claim', "On-the-spot claim for bill {$bill->document_number} awaiting verification.", 'payments');
                $this->audit->record($bill, 'payments.claimed_onspot', $user->id, [], ['verification_id' => $verification->id]);
            }

            $this->audit->record($visit, 'enforcement.visit_complete', $user->id, [], ['task_id' => $visit->task_id]);

            $payload = [
                'id' => $visit->id,
                'visit_reference' => $visit->visit_reference,
                'task_id' => $visit->task_id,
                'bill_id' => $visit->bill_id,
                'gps_accuracy' => $visit->gps_accuracy,
            ];
            if ($syncId) {
                $this->sync->record($user->id, $syncId, 'visit', (int) $visit->id, $payload);
            }

            return $payload;
        });

        return response()->json([
            'data' => $visitPayload,
            'message' => 'Visit recorded.',
        ], 201);
    }

    /**
     * Field visits recorded by the officer — engagement history for a bill/task.
     * Scoped to the caller's own visits; a wider-view permission is not required.
     */
    public function visitsIndex(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['enforcement.record_visit', 'enforcement.view_assignments']), 403, 'Missing permission.');

        $rows = EnforcementVisit::query()
            ->with('assignedTo:id,full_name')
            ->where('officer_id', $user->id)
            ->when($request->query('bill_id'), fn ($q, $v) => $q->where('bill_id', $v))
            ->when($request->query('task_id'), fn ($q, $v) => $q->where('task_id', $v))
            ->orderByDesc('id')
            ->limit(min((int) $request->query('per_page', 50), 200))
            ->get()
            ->map(fn (EnforcementVisit $v) => [
                'id' => $v->id,
                'visit_reference' => $v->visit_reference,
                'task_id' => $v->task_id,
                'bill_id' => $v->bill_id,
                'document_number' => $v->document_number,
                'property_id' => $v->property_id,
                'visit_date' => $v->visit_date?->toDateString(),
                'visit_status' => $v->visit_status,
                'sync_status' => $v->sync_status,
                'sync_source' => $v->sync_source,
                'sync_attempts' => $v->sync_attempts,
                'last_sync_at' => $v->last_sync_at?->toISOString(),
                'delivery_status' => $v->delivery_status,
                'recipient_name' => $v->recipient_name,
                'recipient_contact' => $v->recipient_contact,
                'gps_coordinate' => $v->gps_coordinate,
                'gps_accuracy' => $v->gps_accuracy,
                'gps_captured_at' => $v->gps_captured_at?->toISOString(),
                'gps_verified' => $v->gps_verified,
                'gps_certified_at' => $v->gps_certified_at?->toISOString(),
                'gps_cert_remarks' => $v->gps_cert_remarks,
                'remarks' => $v->remarks,
                'next_action' => $v->next_action,
                'next_followup_date' => $v->next_followup_date?->toDateString(),
                'snapshot_outstanding' => $v->snapshot_outstanding,
                'snapshot_payment_status' => $v->snapshot_payment_status,
                'snapshot_case_status' => $v->snapshot_case_status,
                'officer' => $v->assignedTo?->full_name ?? null,
                'recorded_at' => $v->created_at?->toISOString(),
            ]);

        return response()->json(['data' => $rows]);
    }

    /**
     * Field discovery of an unregistered property (mobile). Creates a
     * first-class PropertyDiscovery record (ND-... reference) — no property ID
     * or document number is generated here, and no tax bill is produced.
     */
    public function discover(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['enforcement.upload_evidence', 'discovery.create']), 403, 'Missing permission.');

        $syncId = $this->sync->localId($request);
        if ($syncId && ($replay = $this->sync->findForReplay($user->id, $syncId))) {
            return $this->sync->replayResponse($replay);
        }

        $data = $request->validate([
            'property_address' => 'required|string|max:255',
            'gps_lat' => 'required|numeric',
            'gps_lng' => 'required|numeric',
            'property_classification' => 'nullable|string|max:100',
            'owner_name' => 'nullable|string|max:255',
            'documents' => 'nullable|array',
            'local_transaction_id' => 'nullable|string|max:80',
        ]);

        $gps = trim($data['gps_lat'].','.$data['gps_lng']);

        $discoveryPayload = DB::transaction(function () use ($data, $user, $syncId, $gps) {
            $discovery = PropertyDiscovery::create([
                'owner_name' => $data['owner_name'] ?: null,
                'property_address' => $data['property_address'],
                'property_classification' => $data['property_classification'] ?? null,
                'gps_coordinate' => $gps,
                'status' => PropertyDiscovery::STATUS_DRAFT,
                'discovered_by' => $user->id,
                'remarks' => 'Discovery submission by user #'.$user->id.'.',
            ]);

            foreach ($data['documents'] ?? [] as $label => $dataUri) {
                $this->storeDiscoveryPhoto($discovery, is_string($dataUri) ? $dataUri : ($dataUri['data'] ?? null), (string) $label, $user->id, $gps);
            }

            $this->audit->record($discovery, 'discovery.created', $user->id, [], [
                'discovery_reference' => $discovery->discovery_reference,
            ]);

            $this->tasks->notifySection('VAL', 'Property discovery', "Discovery {$discovery->discovery_reference} for {$data['property_address']} is queued for review.", 'discovery');

            $payload = ['id' => $discovery->id, 'discovery_reference' => $discovery->discovery_reference, 'status' => $discovery->status];
            if ($syncId) {
                $this->sync->record($user->id, $syncId, 'discovery', (int) $discovery->id, $payload);
            }

            return $payload;
        });

        return response()->json([
            'data' => $discoveryPayload,
            'message' => 'Property discovery registered for downstream processing.',
        ], 201);
    }

    private function visitOutcome(string $visitStatus): string
    {
        $status = strtolower($visitStatus);
        $map = [
            'delivered' => 'delivered',
            'handed' => 'handed_over',
            'received' => 'received',
            'no answer' => 'no_answer',
            'no access' => 'no_access',
            'nobody' => 'no_answer',
            'refused' => 'refused',
            'vacant' => 'vacant',
            'closed' => 'closed',
            'promise' => 'promised_payment',
            'contacted' => 'contact_made',
            'identified' => 'identified',
            'located' => 'located',
        ];

        foreach ($map as $key => $value) {
            if (str_contains($status, $key)) {
                return $value;
            }
        }

        return 'completed';
    }

    private function storeDiscoveryPhoto(PropertyDiscovery $discovery, ?string $dataUri, string $label, int $userId, string $gps): void
    {
        if (! $dataUri) {
            return;
        }

        [$filePath, $mime, $sizeBytes] = $this->storePhotoBytes($dataUri);

        EvidencePhoto::create([
            'photo_type' => 'PROPERTY_FULL_VIEW',
            'discovery_id' => $discovery->id,
            'officer_id' => $userId,
            'file_path' => $filePath,
            'original_name' => $label,
            'mime' => $mime,
            'size_bytes' => $sizeBytes,
            'gps_coordinate' => $gps,
            'captured_at' => now(),
            'remarks' => $label,
        ]);
    }

    /**
     * Accept a photo as a base64 data URI (decode + persist bytes) or as a
     * device/reference path (kept verbatim — evidence of capture only).
     */
    private function storePhotoBytes(?string $photo): array
    {
        if (! $photo || ! str_contains($photo, 'base64,')) {
            return [$photo, null, null];
        }

        $mime = 'image/jpeg';
        if (str_starts_with($photo, 'data:')) {
            $mime = Str::between($photo, 'data:', ';');
        }
        $bytes = base64_decode(trim(Str::after($photo, 'base64,')), true);
        if ($bytes === false || $bytes === '') {
            return [$photo, null, null];
        }

        $ext = Str::after($mime, '/');
        if (! preg_match('/^[a-z0-9+.-]+$/i', $ext)) {
            $ext = 'jpg';
        }
        $name = 'evidence/'.Str::lower(Str::random(1)).'/'.date('Ymd_His').'_'.Str::random(16).'.'.$ext;
        Storage::disk('local')->put($name, $bytes);

        return [$name, $mime, strlen($bytes)];
    }

    /**
     * Payment claim from the field (mobile receipt submission).
     *
     * Runs as a single transaction: the bill is locked, the receipt's
     * fingerprint is checked against every claim (active or approved), the
     * claim is created and the fingerprint reserved under a unique constraint —
     * so two officers submitting the same receipt at the same instant cannot
     * both win, and a receipt already approved for one property can never be
     * claimed for another.
     */
    public function submitReceipt(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canPermission('payments.claim'), 403, 'Missing permission: payments.claim');

        $syncId = $this->sync->localId($request);
        if ($syncId && ($replay = $this->sync->findForReplay($user->id, $syncId))) {
            return $this->sync->replayResponse($replay);
        }

        $data = $request->validate([
            'billing_number' => 'required|string|max:80',
            'bill_id' => 'nullable|integer|exists:property_bills,id',
            'amount' => 'required|numeric|min:0',
            'period' => 'nullable|string|max:50',
            'payment_period' => 'nullable|string|max:50',
            'receipt_number' => 'required|string|max:80',
            'receipt_date' => 'nullable|date',
            'payment_date' => 'nullable|date',
            'receipt_photo' => 'nullable|string',
            'receipt_attachment' => 'nullable|string',
            'property_id' => 'nullable|string|max:60',
            'tin' => 'nullable|string|max:40',
            'tax_due_date' => 'nullable|date',
            'local_transaction_id' => 'nullable|string|max:80',
        ]);

        try {
            $verification = DB::transaction(function () use ($user, $data, $syncId) {
                $bill = PropertyBill::where('document_number', $data['billing_number'])->first()
                    ?? (($data['bill_id'] ?? null) ? PropertyBill::find($data['bill_id']) : null);

                if (! $bill) {
                    abort(422, 'No bill matches the reference provided.');
                }
                abort_unless($data['billing_number'] === $bill->document_number, 422, 'The bill reference does not match the selected bill.');

                // Lock the bill row so concurrent submissions for the same bill are
                // serialized; the fingerprint's unique constraint is the backstop
                // for simultaneous submissions against different bills.
                $bill = PropertyBill::whereKey($bill->id)->lockForUpdate()->first();

                // Ownership gate: only the officer assigned to the bill may claim payment.
                $claimTask = $bill->tasks()
                    ->where('assigned_to', $user->id)
                    ->whereIn('status', ['Assigned', 'Out for Delivery', 'Delivered', 'Payment Follow-up', 'Payment Rejected'])
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first();

                if (! $user->isSystemAdministrator()) {
                    abort_unless(
                        $bill->tasks()->where('assigned_to', $user->id)->exists(),
                        403,
                        'You can only submit payment claims for bills assigned to you.',
                    );
                }

                if ($data['bill_id'] ?? null) {
                    abort_unless((int) $data['bill_id'] === (int) $bill->id, 422, 'The bill reference does not match the selected bill.');
                }
                if ($data['property_id'] ?? null) {
                    abort_unless($bill->property_id && $data['property_id'] === $bill->property_id, 422, 'The property reference does not match the selected bill.');
                }

                $period = $data['payment_period'] ?? $data['period'] ?? null;

                // The duplicate service searches the complete receipt dataset but
                // this path surfaces only the block reason back to the officer.
                $assess = $this->duplicates->assess([
                    'receipt_number' => $data['receipt_number'],
                    'amount' => $data['amount'],
                    'period' => $period,
                    'bill_id' => $bill->id,
                    'property_id' => $data['property_id'] ?? $bill->property_id,
                ]);

                if ($assess['hard_block']) {
                    throw ReceiptConflictException::make((string) $assess['block_reason'], [
                        'bill_id' => $bill->id,
                        'receipt_number' => $assess['normalized_receipt_number'],
                        'fingerprint' => $assess['fingerprint'],
                    ]);
                }
                if (! $user->isSystemAdministrator()) {
                    abort_unless($claimTask, 422, 'This bill has no active task available for a new payment claim.');
                }

                // Receipt photo: base64 data URIs are decoded to real stored bytes so
                // the artifact is downloadable; device/reference paths stay verbatim.
                [$attachmentPath, $attachMime, $attachSize] = $this->storePhotoBytes($data['receipt_attachment'] ?? $data['receipt_photo'] ?? null);

                $verification = PaymentVerification::create([
                    'task_id' => $claimTask?->id,
                    'bill_id' => $bill->id,
                    'claimed_by' => $user->id,
                    'property_id' => $data['property_id'] ?? $bill->property_id,
                    'tin' => $data['tin'] ?? $bill->tin,
                    'tax_due_date' => $data['tax_due_date'] ?? null,
                    'receipt_date' => $data['receipt_date'] ?? $data['payment_date'] ?? null,
                    'document_number' => $bill->document_number,
                    'receipt_number' => $assess['normalized_receipt_number'],
                    'receipt_bill_number' => $data['billing_number'],
                    'amount_claimed' => $data['amount'],
                    'payment_period' => $period,
                    'receipt_attachment' => $attachmentPath,
                    'receipt_fingerprint' => $assess['fingerprint'],
                    'duplicate_warnings' => $assess['warnings'] ?: null,
                    'match_status' => PaymentVerification::MATCH_PENDING,
                    'verification_status' => PaymentVerification::STATUS_PENDING,
                ]);

                // Reserve the fingerprint under a unique constraint. If another
                // officer won the same receipt in the same instant, this insert
                // violates the constraint and the whole transaction rolls back.
                $this->reserveReceipt($verification);
                $this->audit->record($verification, 'payment.claim_submitted', $user->id, [], [
                    'bill_id' => $bill->id,
                    'task_id' => $claimTask?->id,
                    'verification_status' => PaymentVerification::STATUS_PENDING,
                ]);

                if ($attachmentPath) {
                    EvidencePhoto::create([
                        'photo_type' => 'OTHER',
                        'bill_id' => $bill->id,
                        'officer_id' => $user->id,
                        'file_path' => $attachmentPath,
                        'mime' => $attachMime,
                        'size_bytes' => $attachSize,
                        'remarks' => 'Payment receipt for bill '.$bill->document_number.' (claim #'.$verification->id.').',
                    ]);
                }

                // Mark the bill + task so the Account section can action the queue.
                $bill->update(['case_status' => 'Under Verification', 'payment_status' => 'Payment Claimed']);

                if ($claimTask) {
                    $claimTask->recordEngagement('payment_claim', 'claim_submitted', "Payment claim for bill {$bill->document_number} submitted via receipt.", $user);
                    if ($claimTask->status === 'Payment Rejected') {
                        $claimTask->transitionTo('Payment Follow-up', 'resubmitted', $user, 'A corrected payment claim was submitted.');
                    }
                    $claimTask->transitionTo('Payment Claimed', 'payment_claim', $user, 'Payment claim submitted.');
                }

                $this->tasks->notifySection('ACCT', 'Payment claim', "Payment claim for bill {$bill->document_number} awaiting verification.", 'payments');

                if ($syncId) {
                    $this->sync->record($user->id, $syncId, 'receipt', (int) $verification->id, ['id' => $verification->id, 'bill_id' => $bill->id, 'document_number' => $bill->document_number]);
                }

                return $verification;
            });
        } catch (ReceiptConflictException $e) {
            $this->auditBlockedClaim($data, $user, 'receipt.duplicate_blocked', $e->context);

            abort(422, $e->getMessage());
        } catch (QueryException $e) {
            // The only integrity violation this path can hit is the fingerprint
            // reservation (SQLSTATE 23000). Anything else is a genuine database
            // error and must surface as-is.
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }

            $this->auditBlockedClaim($data, $user, 'receipt.duplicate_conflict', [
                'bill_id' => $data['bill_id'] ?? null,
                'receipt_number' => $data['receipt_number'],
            ]);

            abort(422, 'A conflicting claim for this receipt was submitted at almost the same moment. Please retry.');
        }

        return response()->json([
            'data' => ['id' => $verification->id, 'bill_id' => $verification->bill_id, 'document_number' => $verification->document_number],
            'message' => 'Payment claim submitted for verification.',
        ], 201);
    }

    /**
     * Reserve the write-time uniqueness row for a freshly created claim.
     */
    private function reserveReceipt(PaymentVerification $verification): void
    {
        ReceiptFingerprint::create([
            'fingerprint' => $verification->receipt_fingerprint,
            'claim_id' => $verification->id,
            'bill_id' => $verification->bill_id,
            'property_id' => $verification->property_id,
            'receipt_number' => ReceiptDuplicationService::normalizeReceiptNumber($verification->receipt_number),
            'amount' => $verification->amount_claimed,
            'payment_period' => $verification->payment_period ? ReceiptDuplicationService::normalizePeriod($verification->payment_period) : null,
            'verification_status' => PaymentVerification::STATUS_PENDING,
        ]);
    }

    /**
     * Persist a blocked/conflicted receipt attempt on the audit trail. Runs
     * after the rollback so the evidence outlives the failed transaction.
     */
    private function auditBlockedClaim(array $data, User $user, string $action, array $context): void
    {
        $bill = PropertyBill::where('document_number', $data['billing_number'])->first();

        $this->audit->record($bill ?? $user, $action, $user->id, [], $context);
    }
}
