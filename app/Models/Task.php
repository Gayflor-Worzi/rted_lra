<?php

namespace App\Models;

use App\Models\Concerns\GeneratesInternalReference;
use App\Services\SlaDeadlineService;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Unified internal task engine. One task tracks one operational follow-up
 * against a domain record (bill, valuation, verification, query, ...).
 */
class Task extends Model
{
    use GeneratesInternalReference;

    public const TYPE_BILL_DELIVERY = 'Bill Delivery';

    public const TYPE_PAYMENT_FOLLOWUP = 'Payment Follow-up';

    public const TYPE_PAYMENT_VERIFICATION = 'Payment Verification';

    public const TYPE_ENFORCEMENT_VISIT = 'Enforcement Visit';

    public const TYPE_VALUATION = 'Valuation';

    public const TYPE_VALUATION_REVIEW = 'Valuation Review';

    public const TYPE_AC_APPROVAL = 'AC Approval';

    public const TYPE_ME_QUERY = 'M&E Query';

    /**
     * The assigned processing task an Account & Record Officer executes: the
     * property is registered in the source system and the property bill record
     * is updated in RETD. It is a processing assignment, never a review.
     */
    public const TYPE_ACCOUNT_PROCESSING = 'Account & Record Processing';

    public const TYPE_OTHER = 'Other';

    // Spec v4 desk task types — property / valuation routing (§15 / §16)
    public const TYPE_DISCOVERY_REVIEW = 'Discovery Review';

    public const TYPE_VALUATION_MANAGER_REVIEW = 'Valuation Manager Review';

    /**
     * The Account & Record MANAGER's final review of an AC-approved valuation.
     * Named for the manager on purpose: Account & Record Officers never review.
     */
    public const TYPE_ACCOUNT_MANAGER_REVIEW = 'Account & Record Manager Review';

    public const TYPES = [
        'Bill Delivery',
        'Payment Follow-up',
        'Payment Verification',
        'Enforcement Visit',
        'Valuation',
        'Valuation Review',
        'Valuation Manager Review',
        'AC Approval',
        'Discovery Review',
        'Account & Record Manager Review',
        'M&E Query',
        'Account & Record Processing',
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
        'Payment Rejected',
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
        'Logged' => ['Awaiting Assignment', 'Assigned', 'Closed'],
        'Awaiting Assignment' => ['Assigned', 'Closed'],
        'Assigned' => ['Out for Delivery', 'Delivered', 'Payment Claimed', 'Payment Follow-up', 'Escalated'],
        'Out for Delivery' => ['Delivered', 'Assigned', 'Payment Claimed', 'Escalated'],
        'Delivered' => ['Payment Claimed', 'Payment Follow-up', 'Escalated', 'Closed', 'Payment Rejected'],
        'Payment Follow-up' => ['Payment Claimed', 'Verification Pending', '30-Day Warning', '72-Hour Warning', 'Escalated', 'Closed', 'Payment Rejected'],
        'Payment Claimed' => ['Verification Pending', 'Payment Follow-up', 'Payment Rejected'],
        'Verification Pending' => ['Payment Verification', 'Paid', 'Partially Paid', 'Outstanding', 'Payment Follow-up', 'Payment Rejected'],
        'Payment Verification' => ['Paid', 'Partially Paid', 'Outstanding', 'Payment Follow-up', 'Payment Rejected'],
        '30-Day Warning' => ['72-Hour Warning', 'Escalated', 'Payment Follow-up'],
        '72-Hour Warning' => ['Escalated', 'Payment Follow-up'],
        'Escalated' => ['Payment Follow-up', 'Closed'],
        'Paid' => ['Resolved', 'Closed'],
        'Partially Paid' => ['Payment Follow-up', 'Closed'],
        'Outstanding' => ['30-Day Warning', 'Escalated'],
        'Payment Rejected' => ['Payment Follow-up', 'Escalated'],
        'Resolved' => ['Closed'],
        'Closed' => [],
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
        'deadline_type',
        'due_at',
        'sla_code',
        'sla_version',
        'sla_unit',
        'sla_value',
        'sla_calendar_type',
        'sla_started_at',
        'sla_due_at',
        'started_at',
        'completed_at',
        'completed_by',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'due_at' => 'datetime',
            'sla_started_at' => 'datetime',
            'sla_due_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /* ----------------------------------------------------------------
     *  Deadline rules — the SINGLE authoritative engine (§3–§22, §45).
     *  All helpers delegate to SlaDeadlineService so dashboards, registers,
     *  notifications, reports, exports and the mobile API use identical logic.
     * ---------------------------------------------------------------- */

    private function sla(): SlaDeadlineService
    {
        return app(SlaDeadlineService::class);
    }

    /** DATE or DATETIME (§20). */
    public function deadlineType(): string
    {
        return $this->sla()->deadlineType($this);
    }

    /** Effective deadline instant (§19). */
    public function effectiveDeadline(): ?CarbonImmutable
    {
        return $this->sla()->effectiveDeadline($this);
    }

    /** Is this task currently overdue? (§3, §7, §21) */
    public function isOverdue(?CarbonImmutable $now = null): bool
    {
        return $this->sla()->isOverdue($this, $now);
    }

    /** Is this task due today? (§4, §5, §6, §22) */
    public function isDueToday(?CarbonImmutable $now = null): bool
    {
        return $this->sla()->isDueToday($this, $now);
    }

    /** Task reached a terminal state after its effective deadline (§10). */
    public function isCompletedLate(): bool
    {
        return $this->sla()->isCompletedLate($this);
    }

    /** Instant the deadline first passed, when overdue (§40). */
    public function overdueSince(): ?CarbonImmutable
    {
        return $this->sla()->overdueSince($this);
    }

    /** Whole Liberia-business days overdue for date-only deadlines (§36). */
    public function overdueDays(?CarbonImmutable $now = null): int
    {
        return $this->sla()->overdueDays($this, $now);
    }

    /** §45 JSON contract the API exposes along with every task. */
    public function presentDeadline(?CarbonImmutable $now = null): array
    {
        return $this->sla()->presentDeadline($this, $now);
    }

    /* ----------------------------------------------------------------
     *  Query scopes — SQL mirrors of the authoritative rules, so the
     *  "Task Register Overdue filter" can never disagree with the KPI.
     * ---------------------------------------------------------------- */

    /** Active (non-terminal) tasks (§7, §11). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('status', self::COMPLETED_STATUSES);
    }

    /**
     * Overdue tasks exactly as the KPI defines them (§21):
     *   terminal = false AND effective_deadline < current Liberia time.
     */
    public function scopeOverdue(Builder $query, ?CarbonImmutable $now = null): Builder
    {
        $now ??= BusinessClock::nowSeconds();

        return $query
            ->active()
            ->where(function (Builder $q) use ($now) {
                $q->whereNotNull('due_at')->where('due_at', '<', $now->toDateTimeString())
                    ->orWhere(function (Builder $q2) use ($now) {
                        $q2->whereNull('due_at')
                            ->whereNotNull('due_date')
                            ->whereDate('due_date', '<', $now->toDateString());
                    });
            });
    }

    /**
     * Due Today (§22):
     *   terminal = false
     *   AND local_business_date(effective_deadline) == today
     *   AND effective_deadline >= now          (exact-time only)
     */
    public function scopeDueToday(Builder $query, ?CarbonImmutable $now = null): Builder
    {
        $now ??= BusinessClock::nowSeconds();

        return $query
            ->active()
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('due_at')
                    ->whereNotNull('due_date')
                    ->whereDate('due_date', $now->toDateString())
                    ->orWhere(function (Builder $q2) use ($now) {
                        $q2->whereNotNull('due_at')
                            ->where('due_at', '>=', $now->toDateTimeString())
                            ->whereDate('due_at', $now->toDateString());
                    });
            });
    }

    /**
     * Completed Late (§10): terminal tasks whose completion ran past the
     * effective deadline.
     */
    public function scopeCompletedLate(Builder $query): Builder
    {
        return $query
            ->whereIn('status', self::COMPLETED_STATUSES)
            ->whereNotNull('completed_at')
            ->where(function (Builder $q) {
                $q->whereNotNull('due_at')->whereColumn('completed_at', '>', 'due_at')
                    ->orWhere(function (Builder $q2) {
                        $q2->whereNull('due_at')
                            ->whereNotNull('due_date')
                            ->whereRaw('DATE(completed_at) > due_date');
                    });
            });
    }

    protected static function booted(): void
    {
        static::creating(function (Task $task) {
            if (empty($task->status)) {
                $task->status = 'Logged';
            }
            if (empty($task->section)) {
                $task->section = $task->assignedTo?->section?->name;
            }
            if (empty($task->deadline_type)) {
                $task->deadline_type = BusinessClock::DEADLINE_DATE;
            }
        });
    }

    public static function referencePrefix(): string
    {
        return 'TASK';
    }

    public static function referenceField(): string
    {
        return 'task_reference';
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
            'occurred_at' => $occurredAt ?? BusinessClock::now(),
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

        // Same-state requests are safe no-ops (mobile posts the visit and then a
        // "completed" action — after a delivered visit the task is already where
        // the action wants it). Recorded as idempotent history for the audit trail.
        if ($this->status === $target) {
            return $this->history()->create([
                'from_status' => $target,
                'to_status' => $target,
                'action' => $action,
                'performed_by' => $actor?->id,
                'remarks' => $remarks,
                'created_at' => BusinessClock::now(),
            ]);
        }

        if (! $this->canTransitionTo($target)) {
            throw new \InvalidArgumentException(
                "Illegal task transition: {$this->status} -> {$target}"
            );
        }

        $from = $this->status;

        $this->status = $target;

        if (! $this->started_at && $target !== 'Logged' && $target !== 'Awaiting Assignment') {
            $this->started_at = BusinessClock::now();
        }

        if (in_array($target, self::COMPLETED_STATUSES, true)) {
            $this->completed_at = BusinessClock::now();
            $this->completed_by = $actor?->id;
        } else {
            $this->completed_at = null;
            $this->completed_by = null;
        }

        // Optimistic status guard: commit only while the row is still at the
        // status we transitioned from, so two concurrent transitions from the
        // same state cannot both write (and duplicate) transition history.
        $updated = static::whereKey($this->id)->where('status', $from)->update([
            'status' => $target,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
            'completed_by' => $this->completed_by,
        ]);

        if ($updated !== 1) {
            $this->refresh();

            throw new \InvalidArgumentException(
                "Task {$this->task_reference} changed concurrently — refresh and retry the transition."
            );
        }

        return $this->history()->create([
            'from_status' => $from,
            'to_status' => $target,
            'action' => $action,
            'performed_by' => $actor?->id,
            'remarks' => $remarks,
            'created_at' => BusinessClock::now(),
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
            $this->completed_at = BusinessClock::now();
            $this->completed_by = $actor?->id;
        }

        $this->save();

        return $this->history()->create([
            'from_status' => $from,
            'to_status' => $target,
            'action' => $action,
            'performed_by' => $actor?->id,
            'remarks' => $remarks,
            'created_at' => BusinessClock::now(),
        ]);
    }
}
