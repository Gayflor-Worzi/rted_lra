<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Unified internal task engine. One task tracks one operational follow-up
 * against a domain record (bill, valuation, verification, query, ...).
 */
class Task extends Model
{
    public const TYPE_BILL_DELIVERY = 'Bill Delivery';
    public const TYPE_PAYMENT_FOLLOWUP = 'Payment Follow-up';
    public const TYPE_PAYMENT_VERIFICATION = 'Payment Verification';
    public const TYPE_ENFORCEMENT_VISIT = 'Enforcement Visit';
    public const TYPE_VALUATION = 'Valuation';
    public const TYPE_VALUATION_REVIEW = 'Valuation Review';
    public const TYPE_AC_APPROVAL = 'AC Approval';
    public const TYPE_ME_QUERY = 'M&E Query';
    public const TYPE_LITAS_PROCESSING = 'LITAS Processing';
    public const TYPE_OTHER = 'Other';

    public const TYPES = [
        'Bill Delivery',
        'Payment Follow-up',
        'Payment Verification',
        'Enforcement Visit',
        'Valuation',
        'Valuation Review',
        'AC Approval',
        'M&E Query',
        'Other',
    ];

    public const PRIORITIES = ['Low', 'Normal', 'High', 'Urgent'];

    public const DEFAULT_SCOPE_COLUMN = 'assigned_to';

    // Lifecycle (§13 of the operating model)
    public const STATUSES = [
        'Logged',
        'Awaiting Assignment',
        'Assigned',
        'Out for Delivery',
        'Delivered',
        'Payment Follow-up',
        'Payment Claimed',
        'Verification Pending',
        'Payment Verification',
        '30-Day Warning',
        '72-Hour Warning',
        'Escalated',
        'Paid',
        'Partially Paid',
        'Outstanding',
        'Resolved',
        'Closed',
    ];

    // Single source of truth for "this task reached its settled end state".
    // Used by every dashboard (web/mobile), analytics, reports and targets.
    public const COMPLETED_STATUSES = ['Resolved', 'Closed', 'Paid', 'Partially Paid'];

    // Allowed transitions — the task state machine. Direction-guarded.
    public const TRANSITIONS = [
        'Logged'                 => ['Awaiting Assignment', 'Assigned', 'Closed'],
        'Awaiting Assignment'    => ['Assigned', 'Closed'],
        'Assigned'               => ['Out for Delivery', 'Delivered', 'Payment Follow-up', 'Escalated'],
        'Out for Delivery'       => ['Delivered', 'Assigned', 'Escalated'],
        'Delivered'              => ['Payment Follow-up', 'Escalated', 'Closed'],
        'Payment Follow-up'      => ['Payment Claimed', 'Verification Pending', '30-Day Warning', 'Escalated', 'Closed'],
        'Payment Claimed'        => ['Verification Pending', 'Payment Follow-up'],
        'Verification Pending'   => ['Payment Verification', 'Paid', 'Partially Paid', 'Outstanding', 'Payment Follow-up', 'Payment Rejected'],
        'Payment Verification'   => ['Paid', 'Partially Paid', 'Outstanding', 'Payment Follow-up'],
        '30-Day Warning'         => ['72-Hour Warning', 'Escalated', 'Payment Follow-up'],
        '72-Hour Warning'        => ['Escalated', 'Payment Follow-up'],
        'Escalated'              => ['Payment Follow-up', 'Closed'],
        'Paid'                   => ['Resolved', 'Closed'],
        'Partially Paid'         => ['Payment Follow-up', 'Closed'],
        'Outstanding'            => ['30-Day Warning', 'Escalated'],
        'Payment Rejected'       => ['Payment Follow-up', 'Escalated'],
        'Resolved'               => ['Closed'],
        'Closed'                 => [],
    ];

    protected $fillable = [
        'task_reference',
        'task_type',
        'section',
        'reference_type',
        'reference_id',
        'assigned_to',
        'assigned_by',
        'priority',
        'status',
        'due_date',
        'started_at',
        'completed_at',
        'completed_by',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Task $task) {
            if (empty($task->task_reference)) {
                $task->task_reference = self::nextReference();
            }
            if (empty($task->status)) {
                $task->status = 'Logged';
            }
            if (empty($task->section)) {
                $task->section = $task->assignedTo?->section?->name;
            }
        });
    }

    /** Internal-only reference TASK-YYYY-##### — never confused with LITAS ids. */
    public static function nextReference(): string
    {
        $year = date('Y');
        $last = self::where('task_reference', 'like', "TASK-{$year}-%")
            ->max('id');

        $seq = $last ?: 0;

        return sprintf('TASK-%s-%05d', $year, $seq + 1);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function history()
    {
        return $this->hasMany(TaskHistory::class, 'task_id')->orderBy('id');
    }

    public function bill()
    {
        return $this->belongsTo(PropertyBill::class, 'reference_id');
    }

    public function valuation()
    {
        return $this->belongsTo(Valuation::class, 'reference_id');
    }

    public function engagements()
    {
        return $this->hasMany(TaskEngagement::class, 'task_id')
            ->orderByDesc('occurred_at')->orderByDesc('id');
    }

    public function recordEngagement(
        string $type,
        ?string $outcome = null,
        ?string $notes = null,
        ?User $officer = null,
        ?\DateTimeInterface $occurredAt = null,
        array $meta = []
    ): TaskEngagement {
        $officer ??= auth()->user();

        return $this->engagements()->create([
            'bill_id' => $this->reference_type === 'property_bill' ? $this->reference_id : null,
            'engagement_type' => $type,
            'outcome' => $outcome,
            'notes' => $notes,
            'officer_id' => $officer?->id,
            'occurred_at' => $occurredAt ?? now(),
            'meta' => $meta ?: null,
        ]);
    }

    public function previousStatus(): ?string
    {
        $history = $this->relationLoaded('history') ? $this->history : $this->history()->get();

        return $history->last()?->from_status;
    }

    public function canTransitionTo(string $target): bool
    {
        $allowed = self::TRANSITIONS[$this->status] ?? [];

        return in_array($target, $allowed, true);
    }

    /**
     * Move to a target status, guard the transition, and record history.
     * Throws \InvalidArgumentException on illegal moves.
     */
    public function transitionTo(string $target, string $action, ?User $actor = null, ?string $remarks = null): TaskHistory
    {
        $actor ??= auth()->user();

        if (! in_array($target, self::STATUSES, true)) {
            throw new \InvalidArgumentException("Unknown task status: {$target}");
        }

        if (! $this->canTransitionTo($target)) {
            throw new \InvalidArgumentException(
                "Illegal task transition: {$this->status} -> {$target}"
            );
        }

        $from = $this->status;

        $this->status = $target;

        if (! $this->started_at && $target !== 'Logged' && $target !== 'Awaiting Assignment') {
            $this->started_at = now();
        }

        if (in_array($target, self::COMPLETED_STATUSES, true)) {
            $this->completed_at = now();
            $this->completed_by = $actor?->id;
        } else {
            $this->completed_at = null;
            $this->completed_by = null;
        }

        $this->save();

        return $this->history()->create([
            'from_status' => $from,
            'to_status' => $target,
            'action' => $action,
            'performed_by' => $actor?->id,
            'remarks' => $remarks,
            'created_at' => now(),
        ]);
    }

    /**
     * Move to a target status bypassing the state-machine guards but still
     * recording history. Reserved for exception/fallback paths (e.g. a payment
     * verified against a task that is already past the advanceable states).
     */
    public function forceTransition(string $target, string $action, ?User $actor = null, ?string $remarks = null): TaskHistory
    {
        $actor ??= auth()->user();

        if (! in_array($target, self::STATUSES, true)) {
            throw new \InvalidArgumentException("Unknown task status: {$target}");
        }

        $from = $this->status;

        $this->status = $target;

        if (in_array($target, self::COMPLETED_STATUSES, true)) {
            $this->completed_at = now();
            $this->completed_by = $actor?->id;
        }

        $this->save();

        return $this->history()->create([
            'from_status' => $from,
            'to_status' => $target,
            'action' => $action,
            'performed_by' => $actor?->id,
            'remarks' => $remarks,
            'created_at' => now(),
        ]);
    }
}