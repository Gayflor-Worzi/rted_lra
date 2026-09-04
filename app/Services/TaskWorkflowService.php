<?php

namespace App\Services;

use App\Models\PropertyBill;
use App\Models\Task;
use App\Models\TaskEngagement;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Task experience engine — derives the workflow envelope (previous / current
 * status, stage, next required action, deadlines) that drives the task card,
 * and runs the date-gated auto-advance ladder with engagement logging.
 */
class TaskWorkflowService
{
    public function __construct(
        private readonly EscalationService $escalation,
    ) {}

    /** Readable stage label for the current status. */
    public function stage(Task $task, ?PropertyBill $bill = null): string
    {
        return match ($task->status) {
            'Logged' => 'Bill Logged',
            'Awaiting Assignment' => 'Assignment Queue',
            'Assigned', 'Out for Delivery' => 'Bill Delivery',
            'Delivered' => 'Delivered',
            'Payment Follow-up' => 'Payment Follow-up',
            'Payment Claimed', 'Verification Pending', 'Payment Verification' => 'Payment Verification',
            '30-Day Warning' => '30-Day Reminder',
            '72-Hour Warning' => '72-Hour Demand',
            'Escalated' => 'Final Enforcement',
            'Paid' => 'Paid',
            'Resolved' => 'Resolved',
            'Closed' => 'Closure',
            'Partially Paid', 'Outstanding' => 'Outstanding',
            'Payment Rejected' => 'Payment Rejected',
            default => $task->status,
        };
    }

    /**
     * Workflow envelope for the task card.
     *
     * @return array{previous_status: string|null, current_status: string, stage: string, next_action: array, deadline: array, timeline: array}
     */
    public function descriptor(Task $task, ?PropertyBill $bill = null): array
    {
        $bill ??= $this->resolveBill($task);

        return [
            'previous_status' => $task->previousStatus(),
            'current_status' => $task->status,
            'stage' => $this->stage($task, $bill),
            'next_action' => $this->nextAction($task, $bill),
            'deadline' => $this->deadlineInfo($task, $bill),
            'timeline' => $this->timelineSteps($task, $bill),
        ];
    }

    /** Next required action on the task. */
    public function nextAction(Task $task, ?PropertyBill $bill = null): array
    {
        $terminal = in_array($task->status, ['Resolved', 'Closed', 'Paid'], true);

        if ($terminal) {
            return $this->none($task->status === 'Paid' ? 'Bill fully paid.' : 'No further action required.');
        }

        if ($bill && $task->reference_type === 'property_bill') {
            return $this->billAction($task, $bill);
        }

        return $this->genericAction($task);
    }

    /** Deadline / milestone maths for the current stage. */
    public function deadlineInfo(Task $task, ?PropertyBill $bill = null): array
    {
        $bill ??= $this->resolveBill($task);
        $today = now()->startOfDay();

        $due = $task->due_date ? Carbon::parse($task->due_date)->startOfDay() : null;
        $milestone = null;

        if ($bill && $task->reference_type === 'property_bill') {
            $delivery = $bill->delivery_date ? Carbon::parse($bill->delivery_date)->startOfDay() : null;
            $notice = $bill->thirty_day_notice_date ? Carbon::parse($bill->thirty_day_notice_date)->startOfDay() : null;
            $final = $bill->final_notice_date ? Carbon::parse($bill->final_notice_date)->startOfDay() : null;

            if (in_array($task->status, ['Delivered', 'Payment Follow-up'], true)) {
                $milestone = $this->milestone('Collection & reminder due', $delivery?->copy()->addDays(30), $today);
            } elseif ($task->status === '30-Day Warning') {
                $milestone = $this->milestone('72-Hour Demand window', $notice?->copy()->addDays(30), $today);
            } elseif ($task->status === '72-Hour Warning') {
                $milestone = $this->milestone('Final enforcement due', $final?->copy()->addDays(3), $today);
            } elseif ($task->status === 'Escalated') {
                $milestone = $this->milestone('Closure review due', $final?->copy()->addDays(21), $today);
            }
        }

        return [
            'due_date' => $task->due_date?->toDateString(),
            'due_overdue' => $due ? $due->lt($today) : false,
            'milestone' => $milestone,
        ];
    }

    /** Ladder steps for the card progress strip (done / current / upcoming). */
    public function timelineSteps(Task $task, ?PropertyBill $bill = null): array
    {
        $bill ??= $this->resolveBill($task);
        $status = $task->status;

        $doneFrom = function (array $statuses) use ($status) {
            static $order = [
                'Logged', 'Awaiting Assignment', 'Assigned', 'Out for Delivery', 'Delivered',
                'Payment Follow-up', 'Payment Claimed', 'Verification Pending', 'Payment Verification',
                '30-Day Warning', '72-Hour Warning', 'Escalated', 'Closed', 'Resolved', 'Paid',
            ];
            $idx = array_search($status, $order, true);
            $min = min(array_map(fn ($s) => array_search($s, $order, true), $statuses));

            return $idx !== false && $idx >= $min;
        };

        $steps = [
            ['key' => 'logged', 'label' => 'Bill Logged'],
            ['key' => 'assigned', 'label' => 'Assigned'],
            ['key' => 'delivery', 'label' => 'Delivery'],
            ['key' => 'followup', 'label' => 'Payment Follow-up'],
            ['key' => 'reminder30', 'label' => '30-Day Reminder'],
            ['key' => 'demand72', 'label' => '72-Hour Demand'],
            ['key' => 'final', 'label' => 'Final Enforcement'],
            ['key' => 'closure', 'label' => 'Closure'],
            ['key' => 'paid', 'label' => 'Paid / Resolved'],
        ];

        $current = match ($status) {
            'Logged' => 'logged',
            'Awaiting Assignment' => 'assigned',
            'Assigned', 'Out for Delivery' => 'delivery',
            'Delivered' => 'delivery',
            'Payment Follow-up' => 'followup',
            'Payment Claimed', 'Verification Pending', 'Payment Verification' => 'followup',
            '30-Day Warning' => 'reminder30',
            '72-Hour Warning' => 'demand72',
            'Escalated' => 'final',
            'Closed' => 'closure',
            'Resolved', 'Paid' => 'paid',
            default => 'followup',
        };

        $billDone = $bill && $task->reference_type === 'property_bill';
        $notice = $billDone && $bill->thirty_day_notice_date !== null;
        $final = $billDone && $bill->final_notice_date !== null;
        $closed = $billDone && in_array($bill->escalation_stage, ['Closure', 'Escalated', 'Paid', 'Resolved'], true);

        $doneKeys = [];
        if ($status !== 'Logged' && $status !== 'Awaiting Assignment') {
            array_push($doneKeys, 'logged', 'assigned');
        }
        if ($doneFrom(['Delivered', 'Payment Follow-up', '30-Day Warning', '72-Hour Warning', 'Escalated', 'Closed', 'Resolved', 'Paid'])) {
            $doneKeys[] = 'delivery';
        }
        if ($doneFrom(['Payment Follow-up', '30-Day Warning', '72-Hour Warning', 'Escalated', 'Closed', 'Resolved', 'Paid'])) {
            $doneKeys[] = 'followup';
        }
        if ($notice || $doneFrom(['30-Day Warning', '72-Hour Warning', 'Escalated', 'Closed', 'Resolved', 'Paid'])) {
            $doneKeys[] = 'reminder30';
        }
        if ($final || $doneFrom(['72-Hour Warning', 'Escalated', 'Closed', 'Resolved', 'Paid'])) {
            $doneKeys[] = 'demand72';
        }
        if ($doneFrom(['Escalated', 'Closed', 'Resolved', 'Paid'])) {
            $doneKeys[] = 'final';
        }
        if ($closed || $doneFrom(['Closed', 'Resolved', 'Paid'])) {
            $doneKeys[] = 'closure';
        }
        if ($doneFrom(['Resolved', 'Paid'])) {
            $doneKeys[] = 'paid';
        }

        $p = 0;
        foreach ($steps as $i => $s) {
            $state = in_array($s['key'], $doneKeys, true) ? 'done' : 'upcoming';

            if ($s['key'] === $current) {
                $state = 'current';
            }

            $steps[$i]['state'] = $state;
            $steps[$i]['index'] = $p++;
        }

        return $steps;
    }

    /** Advance one bill through the ladder; logs the engagement. */
    public function advanceBill(PropertyBill $bill, ?User $actor = null): ?array
    {
        $result = $this->escalation->evaluate($bill);

        if (! $result['eligible_action']) {
            return null;
        }

        $stage = $this->escalation->autoAdvance($bill, $actor);

        if (! $stage) {
            return null;
        }

        $task = $bill->tasks()->orderByDesc('id')->first();

        if ($task) {
            $this->recordEscalationEngagement($task, $result['eligible_action'], $bill);
        }

        return [
            'stage' => $stage,
            'action' => $result['eligible_action'],
            'reason' => $result['reason'],
        ];
    }

    /** Advance a single task's linked bill. Returns descriptor + result. */
    public function advanceTask(Task $task, ?User $actor = null): array
    {
        $bill = $this->resolveBill($task);

        $result = $bill ? $this->advanceBill($bill, $actor) : null;

        return [
            'advanced' => $result !== null,
            'result' => $result,
            'workflow' => $this->descriptor($task->fresh(['history', 'engagements']), $bill?->fresh()),
        ];
    }

    /** Bulk ladder pass over every bill-linked active task. */
    public function advanceDue(): int
    {
        $tasks = Task::query()
            ->where('reference_type', 'property_bill')
            ->whereNotIn('status', ['Resolved', 'Closed', 'Paid', 'Logged'])
            ->get();

        $moved = 0;
        foreach ($tasks as $task) {
            $bill = $this->resolveBill($task);

            if ($bill && $this->advanceBill($bill)) {
                $moved++;
            }
        }

        return $moved;
    }

    private function billAction(Task $task, PropertyBill $bill): array
    {
        $pendingVerification = $bill->case_status === 'Under Verification'
            || $bill->verifications()->where('verification_status', 'Pending')->exists();

        if ($pendingVerification && ! in_array($task->status, ['Closed', 'Resolved', 'Paid'], true)) {
            return [
                'kind' => 'verify',
                'verb' => 'Verify pending payment claim',
                'permissions' => ['payments.verify'],
                'notes' => 'A payment claim is awaiting Accounts verification.',
                'due_date' => null,
                'overdue' => false,
                'auto_at' => null,
            ];
        }

        if (in_array($task->status, ['Assigned', 'Out for Delivery'], true)) {
            return [
                'kind' => 'manual',
                'verb' => 'Record field delivery',
                'permissions' => ['enforcement.record_visit'],
                'notes' => 'Physically deliver the bill and record the visit outcome.',
                'due_date' => $task->due_date?->toDateString(),
                'overdue' => $task->due_date ? Carbon::parse($task->due_date)->startOfDay()->lt(now()->startOfDay()) : false,
                'auto_at' => null,
            ];
        }

        $evaluated = $this->escalation->evaluate($bill);
        $auto = match ($task->status) {
            'Delivered', 'Payment Follow-up' => [
                'verb' => 'Begin collection follow-up',
                'permissions' => ['enforcement.record_visit'],
                'auto_at' => $bill->delivery_date?->copy()->addDays(30),
            ],
            '30-Day Warning' => [
                'verb' => 'Await 72-Hour Demand window',
                'permissions' => ['tasks.escalate', 'enforcement.escalate'],
                'auto_at' => $bill->thirty_day_notice_date?->copy()->addDays(30),
            ],
            '72-Hour Warning' => [
                'verb' => 'Execute final enforcement',
                'permissions' => ['tasks.escalate', 'enforcement.escalate'],
                'auto_at' => $bill->final_notice_date?->copy()->addDays(3),
            ],
            'Escalated' => [
                'verb' => 'Close case for legal recovery',
                'permissions' => ['tasks.escalate', 'tasks.reopen'],
                'auto_at' => $bill->final_notice_date?->copy()->addDays(21),
            ],
            default => null,
        };

        if (! $auto) {
            return $this->genericAction($task);
        }

        $dueNow = $evaluated['eligible_action'] !== null;
        $autoAt = $auto['auto_at'] ? Carbon::parse($auto['auto_at'])->startOfDay() : null;

        return [
            'kind' => $dueNow ? 'advance' : 'auto',
            'verb' => $dueNow ? 'Run pending automatic step' : $auto['verb'],
            'permissions' => $auto['permissions'],
            'notes' => $dueNow
                ? ($evaluated['reason'] ?? 'This step is now eligible and may be executed.')
                : ($autoAt ? 'Auto-runs on '.$autoAt->toDateString().'.' : null),
            'due_date' => $autoAt?->toDateString(),
            'overdue' => $autoAt ? $autoAt->lt(now()->startOfDay()) : false,
            'auto_at' => $autoAt?->toDateString(),
        ];
    }

    private function genericAction(Task $task): array
    {
        $map = [
            'Logged' => ['verb' => 'Register the case', 'permissions' => ['tasks.complete'], 'notes' => 'Record the originating event for this task.'],
            'Awaiting Assignment' => ['verb' => 'Assign an officer', 'permissions' => ['tasks.assign', 'me.assign_walkin'], 'notes' => 'Assign this case from the queue.'],
            'Assigned', 'Out for Delivery' => ['verb' => 'Progress the assignment', 'permissions' => ['tasks.complete'], 'notes' => 'Complete or record the assigned work.'],
            'Delivered' => ['verb' => 'Move to payment follow-up', 'permissions' => ['tasks.complete'], 'notes' => 'Bill delivered; begin the collection follow-up.'],
            'Payment Follow-up' => ['verb' => 'Escalate or claim payment', 'permissions' => ['tasks.escalate', 'enforcement.escalate'], 'notes' => 'Escalate for the 30-day notice, or record a payment claim.'],
        ];

        $def = $map[$task->status] ?? null;

        if ($def) {
            return [
                'kind' => 'manual',
                'verb' => $def['verb'],
                'permissions' => $def['permissions'],
                'notes' => $def['notes'],
                'due_date' => $task->due_date?->toDateString(),
                'overdue' => $task->due_date ? Carbon::parse($task->due_date)->startOfDay()->lt(now()->startOfDay()) : false,
                'auto_at' => null,
            ];
        }

        return $this->none('No required action for this status.');
    }

    private function none(string $notes): array
    {
        return [
            'kind' => 'none',
            'verb' => null,
            'permissions' => [],
            'notes' => $notes,
            'due_date' => null,
            'overdue' => false,
            'auto_at' => null,
        ];
    }

    private function milestone(string $label, ?Carbon $date, Carbon $today): ?array
    {
        if (! $date) {
            return null;
        }

        $overdue = $date->lt($today);
        $daysLeft = $overdue ? null : $today->diffInDays($date);

        return [
            'label' => $label,
            'date' => $date->toDateString(),
            'overdue' => $overdue,
            'days_left' => $daysLeft,
        ];
    }

    private function resolveBill(Task $task): ?PropertyBill
    {
        if ($task->reference_type !== 'property_bill' || ! $task->reference_id) {
            return null;
        }

        if ($task->relationLoaded('bill') && $task->bill) {
            return $task->bill;
        }

        return PropertyBill::find($task->reference_id);
    }

    private function recordEscalationEngagement(Task $task, string $action, PropertyBill $bill): void
    {
        $notesPrefix = "Auto {$bill->document_number}: ";

        $map = [
            'issue_thirty_day' => [TaskEngagement::TYPE_REMINDER_30_DAY, 'notice_issued', $notesPrefix.'30-Day Reminder issued.'],
            'issue_seventy_two_hour' => [TaskEngagement::TYPE_DEMAND_72_HOUR, 'notice_issued', $notesPrefix.'72-Hour Demand issued.'],
            'final_enforcement' => [TaskEngagement::TYPE_FINAL_ENFORCEMENT, 'notice_served', $notesPrefix.'Final enforcement engaged.'],
            'closure' => [TaskEngagement::TYPE_CLOSURE, 'closed', $notesPrefix.'Case closed after prolonged escalation — legal recovery.'],
        ];

        [$type, $outcome, $notes] = $map[$action] ?? [TaskEngagement::TYPE_NOTE, null, null];

        $task->recordEngagement($type, $outcome, $notes, null, now(), ['auto' => true]);
    }
}