<?php

namespace App\Services;

use App\Models\Task;
use App\Support\BusinessCalendar;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;

/**
 * Single authoritative deadline engine for every RETD task (§17).
 *
 * All consumers — dashboards, task registers, notifications, reports,
 * exports, mobile task screens and KPI drill-downs — MUST use this
 * service (or the Task model scopes it powers) to answer:
 *
 *   Is this task overdue?
 *   Is this task due today?
 *   Was this task completed late?
 *   What is the effective deadline?
 *
 * The service never recalculates persisted deadlines — it reads the
 * `due_date`, `due_at`, `deadline_type`, `sla_due_at`, and `status`
 * columns already stored on the task record (§14, §18).
 */
final class SlaDeadlineService
{
    /* ----------------------------------------------------------------
     *  Deadline type & effective deadline (§14, §19, §20)
     * ---------------------------------------------------------------- */

    /**
     * Resolve which deadline kind this task carries.
     *
     * A task with `due_at` set is treated as DATETIME; otherwise DATE.
     * The `deadline_type` column is an informative hint for the UI and
     * migrators — `due_at` presence is the runtime truth.
     */
    public function deadlineType(Task $task): string
    {
        if ($task->due_at !== null) {
            return BusinessClock::DEADLINE_DATETIME;
        }

        return BusinessClock::DEADLINE_DATE;
    }

    /**
     * Resolve the effective deadline for the overdue engine (§19):
     *
     *   - DATETIME: exact `due_at` (with second precision)
     *   - DATE:     end-of-day(due_date) in Africa/Monrovia
     */
    public function effectiveDeadline(Task $task): ?CarbonImmutable
    {
        if ($task->due_at !== null) {
            return $task->due_at instanceof CarbonImmutable
                ? $task->due_at
                : CarbonImmutable::parse($task->due_at, BusinessClock::TIMEZONE);
        }

        return BusinessClock::endOfDay($task->due_date);
    }

    /* ----------------------------------------------------------------
     *  Status predicates (§3, §4, §7, §10, §11, §21, §22)
     * ---------------------------------------------------------------- */

    /** Active = not terminal (§7, §11). */
    public function isActive(Task $task): bool
    {
        return ! in_array($task->status, Task::COMPLETED_STATUSES, true);
    }

    /**
     * Overdue: effective deadline has passed AND task is not terminal (§3, §7).
     *
     * Date-only:   due_date < today Liberia
     * Exact-time:  due_at  < now Liberia (second precision)
     */
    public function isOverdue(Task $task, ?CarbonImmutable $now = null): bool
    {
        if (! $this->isActive($task)) {
            return false;
        }

        $now ??= BusinessClock::nowSeconds();

        if ($task->due_at !== null) {
            return BusinessClock::isBeforeNowAt($task->due_at);
        }

        return BusinessClock::isOverdue($task->due_date);
    }

    /**
     * Due Today (§4, §5, §6, §22).
     *
     * Date-only:  due_date == today Liberia (remain Due Today all day).
     * Exact-time: local_date(due_at) == today AND not yet passed deadline.
     */
    public function isDueToday(Task $task, ?CarbonImmutable $now = null): bool
    {
        if (! $this->isActive($task)) {
            return false;
        }

        if ($task->due_at !== null) {
            return BusinessClock::isDueTodayAt($task->due_at);
        }

        return BusinessClock::isDueToday($task->due_date);
    }

    /**
     * Completed Late (§10): task reached a terminal state after its
     * effective deadline.
     */
    public function isCompletedLate(Task $task): bool
    {
        if (in_array($task->status, Task::COMPLETED_STATUSES, true)
            && $task->completed_at !== null) {
            return $this->isLateComparedToDeadline(
                $task,
                $task->completed_at instanceof CarbonImmutable
                    ? $task->completed_at
                    : CarbonImmutable::parse($task->completed_at, BusinessClock::TIMEZONE)
            );
        }

        return false;
    }

    /** Did a given instant happen AFTER the effective deadline? */
    private function isLateComparedToDeadline(Task $task, CarbonImmutable $instant): bool
    {
        $deadline = $this->effectiveDeadline($task);

        if ($deadline === null) {
            return false;
        }

        return $this->deadlineType($task) === BusinessClock::DEADLINE_DATETIME
            ? $deadline->floorSecond()->isBefore($instant->floorSecond())
            : $deadline->isBefore($instant);
    }

    /* ----------------------------------------------------------------
     *  Overdue age & windows (§36, §37)
     * ---------------------------------------------------------------- */

    /**
     * Whole Liberia-business days a date-only deadline is overdue (§36).
     * Returns 0 when not overdue.
     */
    public function overdueDays(Task $task, ?CarbonImmutable $now = null): int
    {
        if (! $this->isOverdue($task, $now) || $task->due_date === null || $task->due_at !== null) {
            return 0;
        }

        $due = CarbonImmutable::parse($task->due_date, BusinessClock::TIMEZONE)->startOfDay();
        $today = ($now ?? BusinessClock::nowSeconds())->startOfDay();

        return (int) abs($today->diffInDays($due));
    }

    /** Instant at which the deadline first exceeded (overdue_since, §40). */
    public function overdueSince(Task $task): ?CarbonImmutable
    {
        if (! $this->isOverdue($task)) {
            return null;
        }

        return $this->effectiveDeadline($task);
    }

    /* ----------------------------------------------------------------
     *  SLA deadline calculation (§14–§17, §48)
     * ---------------------------------------------------------------- */

    /**
     * Calculate a persisted deadline from an SLA definition. This is
     * called exactly once, at SLA assignment, and the result is stored
     * in `due_at` (DATETIME) or `due_date` (DATE).
     */
    public function calculateDeadline(
        CarbonImmutable $start,
        int $value,
        string $unit = 'day', // day | hour
        string $calendarType = BusinessCalendar::CALENDAR_DAYS,
    ): CarbonImmutable {
        return match (true) {
            $unit === 'hour' => $start->addHours($value),
            $calendarType === BusinessCalendar::BUSINESS_DAYS => BusinessCalendar::addBusinessDays($start, $value),
            default => $start->addDays($value),
        };
    }

    /* ----------------------------------------------------------------
     *  §45 contract — backend authoritative response fields
     * ---------------------------------------------------------------- */

    /**
     * Task API / present() should include this alongside each task payload
     * so that mobile, web dashboards, registers, notifications and reports
     * share exactly the same numbers (§39, §40, §45).
     */
    public function presentDeadline(Task $task, ?CarbonImmutable $now = null): array
    {
        $now ??= BusinessClock::nowSeconds();
        $dueAt = $task->due_at;

        return [
            'due_date' => $task->due_date?->toDateString(),
            'due_at' => $dueAt !== null
                ? ($dueAt instanceof CarbonImmutable ? $dueAt->toIso8601String() : $dueAt)
                : null,
            'deadline_type' => $this->deadlineType($task),
            'is_due_today' => $this->isDueToday($task, $now),
            'is_overdue' => $this->isOverdue($task, $now),
            'is_completed_late' => $this->isCompletedLate($task),
            'overdue_since' => $this->overdueSince($task)?->toIso8601String(),
            'overdue_days' => $this->overdueDays($task, $now),
        ];
    }
}
