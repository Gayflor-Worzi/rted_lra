<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Staff performance target. Indicators are configurable per staff function;
 * achieved values are recomputed from the operational records by
 * TargetsController@refresh (targets.refresh permission).
 *
 * Staff Target is real achieved metric vs approved target; the achieved value
 * is refreshed from the underlying records so AC/M&E dashboards compare
 * actual performance against the approved target.
 */
class StaffTarget extends Model
{
    public const METRICS = [
        // Account & Record
        'bills_logged',
        'bills_processed',
        'payment_verifications',
        'records_amended',
        'data_quality_completed',
        // Enforcement
        'bills_delivered',
        'visits',
        'payment_followups',
        'reminder_notices',
        'hour_72_demands',
        'enforcement_cases',
        'completed_tasks',
        // Valuation
        'valuations',
        'reassessments',
        'valuation_corrections',
        'approved_valuations',
        // M&E
        'reports_completed',
        'tasks_reviewed',
        'monitoring_activities',
        'data_quality_checks',
        'performance_reports',
        'walkin_assignments',
        // Financial
        'collections_amount',
        'custom',
    ];

    public const FREQUENCIES = ['Daily', 'Weekly', 'Monthly', 'Quarterly', 'Annual'];

    protected $fillable = [
        'user_id',
        'section',
        'metric',
        'target_value',
        'achieved_value',
        'measurement_unit',
        'start_date',
        'end_date',
        'frequency',
        'period',
        'status',
        'created_by',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'target_value' => 'decimal:2',
            'achieved_value' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function progressPercent(): float
    {
        $target = (float) $this->target_value;

        if ($target <= 0) {
            return 0;
        }

        return round(((float) $this->achieved_value / $target) * 100, 1);
    }

    /**
     * The record window a target's achieved value is measured against. Uses
     * explicit dates when set, otherwise the naturally applicable period for
     * the target frequency (today / current week / month / quarter / year).
     *
     * @return array{\Illuminate\Support\Carbon, \Illuminate\Support\Carbon}
     */
    public function effectiveWindow(): array
    {
        if ($this->start_date && $this->end_date) {
            return [$this->start_date->copy()->startOfDay(), $this->end_date->copy()->endOfDay()];
        }

        if ($this->start_date) {
            return [$this->start_date->copy()->startOfDay(), ($this->end_date ?? $this->start_date)->copy()->endOfDay()];
        }

        if ($this->end_date) {
            return [($this->start_date ?? $this->end_date)->copy()->startOfDay(), $this->end_date->copy()->endOfDay()];
        }

        $now = now()->startOfDay();

        return match ($this->frequency) {
            'Daily' => [$now, $now->copy()->endOfDay()],
            'Weekly' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'Quarterly' => [$now->copy()->startOfQuarter(), $now->copy()->endOfQuarter()],
            'Annual' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            default => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
        };
    }

    /**
     * Achieved value recomputed live from the operational records scoped to the
     * target's applicable period window — the single source of truth used by
     * both the targets refresh job and the mobile Home performance summary.
     */
    public function computeAchievedValue(): float
    {
        $userId = $this->user_id;
        [$start, $end] = $this->effectiveWindow();

        $within = fn (Builder $q, string $col) => $q->whereBetween($col, [$start, $end]);

        return match ($this->metric) {
            'bills_logged' => (float) PropertyBill::query()->tap(fn ($q) => $within($q, 'date_logged'))->where('account_staff_id', $userId)->count(),
            'bills_processed' => (float) PropertyBill::query()->tap(fn ($q) => $within($q, 'date_logged'))->where('account_staff_id', $userId)->whereNotNull('document_number')->count(),
            'payment_verifications' => (float) PaymentVerification::query()->tap(fn ($q) => $within($q, 'created_at'))->where('verified_by', $userId)->whereIn('verification_status', ['Verified', 'Confirmed'])->count(),
            'records_amended' => (float) PaymentVerification::query()->tap(fn ($q) => $within($q, 'created_at'))->where('verified_by', $userId)->count(),
            'data_quality_completed' => (float) DataQualityFlag::query()->tap(fn ($q) => $within($q, 'resolved_at'))->where('resolved_by', $userId)->count(),
            'bills_delivered' => (float) EnforcementVisit::query()->tap(fn ($q) => $within($q, 'visit_date'))->where('officer_id', $userId)->where('delivery_status', EnforcementVisit::DELIVERY_DELIVERED)->count(),
            'visits' => (float) EnforcementVisit::query()->tap(fn ($q) => $within($q, 'visit_date'))->where('officer_id', $userId)->count(),
            'payment_followups' => (float) Task::query()->tap(fn ($q) => $within($q, 'updated_at'))->where('assigned_to', $userId)->where('task_type', 'Payment Follow-up')->whereNotIn('status', ['Closed', 'Resolved'])->count(),
            'reminder_notices' => (float) Task::query()->tap(fn ($q) => $within($q, 'updated_at'))->where('assigned_to', $userId)->where('status', '30-Day Warning')->count(),
            'hour_72_demands' => (float) Task::query()->tap(fn ($q) => $within($q, 'updated_at'))->where('assigned_to', $userId)->where('status', '72-Hour Warning')->count(),
            'enforcement_cases' => (float) Task::query()->tap(fn ($q) => $within($q, 'created_at'))->where('assigned_to', $userId)->where('section', 'Enforcement')->count(),
            'completed_tasks' => (float) Task::query()->tap(fn ($q) => $within($q, 'completed_at'))->where('assigned_to', $userId)->whereIn('status', Task::COMPLETED_STATUSES)->count(),
            'valuations' => (float) Valuation::query()->tap(fn ($q) => $within($q, 'created_at'))->where('valuation_officer_id', $userId)->count(),
            'reassessments' => (float) Valuation::query()->tap(fn ($q) => $within($q, 'created_at'))->where('valuation_officer_id', $userId)->where('valuation_type', 'reassessment')->count(),
            'valuation_corrections' => (float) Valuation::query()->tap(fn ($q) => $within($q, 'updated_at'))->where('valuation_officer_id', $userId)->where('status', 'Approved')->count(),
            'approved_valuations' => (float) Valuation::query()->tap(fn ($q) => $within($q, 'updated_at'))->where('valuation_officer_id', $userId)->where('status', 'Approved')->count(),
            'collections_amount' => (float) PaymentVerification::query()->tap(fn ($q) => $within($q, 'created_at'))->where('verified_by', $userId)->whereIn('verification_status', ['Verified', 'Confirmed'])->sum('amount_claimed'),
            default => 0.0,
        };
    }

    public function isActive(): bool
    {
        $now = now();

        if ($this->status !== 'Approved') {
            return false;
        }
        if ($this->start_date && $now->lt($this->start_date->startOfDay())) {
            return false;
        }

        return ! $this->end_date || $now->lte($this->end_date->endOfDay());
    }

    public function approve(?User $by = null): void
    {
        $this->status = 'Approved';
        $this->approved_by = $by?->id;
        $this->approved_at = now();
        $this->save();
    }
}