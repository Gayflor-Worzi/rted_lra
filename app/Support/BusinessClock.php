<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Single authoritative clock for RETD business calculations.
 *
 * All dashboard date logic — "today", "overdue", "due this week",
 * reporting-period boundaries, snapshot timestamps — flows through here
 * so that every consumer uses the same Africa/Monrovia timezone and the
 * same instant-in-time.
 *
 * Rule references:
 *   §3   Official system timezone = Africa/Monrovia
 *   §9   Today = current Liberia calendar date
 *   §10  Due Today = due_at date = today AND not terminal
 *   §11  Overdue  = due_at < now Liberia AND not terminal
 *   §12  Overdue by date vs by time
 *   §50  Dashboard snapshot timestamp
 *   §51  Midnight rule
 */
final class BusinessClock
{
    /** Liberia official business timezone. */
    public const TIMEZONE = 'Africa/Monrovia';

    /** Workflow deadline kind (§20). */
    public const DEADLINE_DATE = 'DATE';

    public const DEADLINE_DATETIME = 'DATETIME';

    /** @var self|null Singleton (replaced in tests). */
    private static ?self $instance = null;

    /** @var CarbonImmutable|null Overridable "now" for deterministic testing. */
    private static ?CarbonImmutable $frozenNow = null;

    /** Reset frozen clock between tests. */
    public static function thaw(): void
    {
        self::$frozenNow = null;
    }

    /** Freeze the clock to a fixed instant for test assertions. */
    public static function freeze(CarbonImmutable $at): void
    {
        self::$frozenNow = $at;
    }

    /** Current instant in Liberia timezone. */
    public static function now(): CarbonImmutable
    {
        return self::$frozenNow
            ? self::$frozenNow->copy()->timezone(self::TIMEZONE)
            : CarbonImmutable::now(self::TIMEZONE);
    }

    /** Today's midnight in Liberia timezone (§9, §51). */
    public static function today(): CarbonImmutable
    {
        return self::now()->startOfDay();
    }

    /** Yesterday's midnight in Liberia timezone. */
    public static function yesterday(): CarbonImmutable
    {
        return self::today()->subDay();
    }

    /* ----------------------------------------------------------------
     *  Due-date / overdue rules (§10, §11, §12, §19, §22)
     * ---------------------------------------------------------------- */

    /**
     * Current instant floored to the second.
     *
     * §6: the system must use ONE consistent precision for deadline
     * comparison. Stored deadlines are second-precision, so "overdue"
     * means strictly after the stored second — never at the boundary
     * instant itself.
     */
    public static function nowSeconds(): CarbonImmutable
    {
        return self::now()->floorSecond();
    }

    /**
     * Due Today (date-only): a task with a date-only deadline is "Due
     * Today" throughout its entire due date and only becomes Overdue at
     * the start of the following Liberia calendar day (§3, §5).
     */
    public static function isDueToday(?CarbonInterface $due): bool
    {
        if ($due === null) {
            return false;
        }

        return $due->copy()->setTimezone(self::TIMEZONE)->isSameDay(self::today());
    }

    /**
     * Due Today (exact-time): local_date(due_at) == today AND the deadline
     * has not yet passed (§4, §22).
     *
     * An exact-time task can progress Due Today → Overdue during the same
     * calendar day:
     *   Today = Sep 13 · Due 2:00 PM
     *   1:00 PM → Due Today · 2:01 PM → Overdue
     */
    public static function isDueTodayAt(?CarbonInterface $dueAt): bool
    {
        if ($dueAt === null) {
            return false;
        }

        return $dueAt->copy()->setTimezone(self::TIMEZONE)->isSameDay(self::today())
            && ! self::isBeforeNowAt($dueAt);
    }

    /**
     * Overdue (date-only): due_date < current Liberia business date (§3).
     *
     * If due_date == today  → NOT overdue (still "due today").
     * If due_date < today   → overdue.
     */
    public static function isOverdue(?CarbonInterface $due): bool
    {
        if ($due === null) {
            return false;
        }

        return $due->copy()->setTimezone(self::TIMEZONE)->startOfDay()
            ->isBefore(self::today());
    }

    /**
     * Overdue (exact-time): due_at < now (second-precision, Liberia
     * instant) (§3, §6). "Immediately after the configured deadline."
     */
    public static function isOverdueAt(?CarbonInterface $dueAt): bool
    {
        return self::isBeforeNowAt($dueAt);
    }

    /** due_at < now with one consistent second precision (§6). */
    public static function isBeforeNowAt(?CarbonInterface $dueAt): bool
    {
        if ($dueAt === null) {
            return false;
        }

        return self::toFromNowZone($dueAt)->floorSecond()->isBefore(self::nowSeconds());
    }

    /**
     * Effective deadline for a date-only deadline: the end of that Liberia
     * calendar day (§19). Conceptually:
     *   effective_deadline = exact due_at
     *     OR end-of-day(due_date, Africa/Monrovia)
     */
    public static function endOfDay(?CarbonInterface $date, string $timezone = self::TIMEZONE): ?CarbonImmutable
    {
        if ($date === null) {
            return null;
        }

        return CarbonImmutable::instance($date)->setTimezone($timezone)->endOfDay();
    }

    /** Fields from an arbitrary timezone into the Liberia zone for rule tests. */
    public static function toFromNowZone(CarbonInterface $value): CarbonImmutable
    {
        return CarbonImmutable::instance($value)->setTimezone(self::TIMEZONE);
    }

    /* ----------------------------------------------------------------
     *  Period boundary helpers (§8, §46, §52-§55)
     * ---------------------------------------------------------------- */

    /**
     * Half-open interval for a reporting period: [start, end).
     *
     * §8: ">= start AND < next_day_after_end"
     * §53: Month = calendar month.
     */
    public static function monthRange(?CarbonImmutable $ref = null): array
    {
        $ref = ($ref ?? self::today())->startOfDay();
        $start = $ref->startOfMonth();
        $end = $ref->startOfMonth()->addMonth(); // exclusive

        return [
            'start' => $start->toImmutable(),
            'end' => $end->toImmutable(),
            'label' => $start->format('F Y'),
        ];
    }

    public static function weekRange(?CarbonImmutable $ref = null): array
    {
        // §52: Week = Monday through Sunday.
        $ref = ($ref ?? self::today())->startOfDay();
        $start = $ref->startOfWeek(); // Monday (Carbon default)
        $end = $ref->startOfWeek()->addWeek(); // exclusive

        return [
            'start' => $start->toImmutable(),
            'end' => $end->toImmutable(),
            'label' => $start->format('M j').'–'.$end->subDay()->format('M j, Y'),
        ];
    }

    public static function quarterRange(?CarbonImmutable $ref = null): array
    {
        // §54: Calendar quarters.
        $ref = ($ref ?? self::today())->startOfDay();
        $start = $ref->startOfQuarter();
        $end = $ref->startOfQuarter()->addQuarter(); // exclusive

        return [
            'start' => $start->toImmutable(),
            'end' => $end->toImmutable(),
            'label' => 'Q'.$start->quarter.' '.$start->year,
        ];
    }

    public static function yearRange(?CarbonImmutable $ref = null): array
    {
        // §55: Calendar year.
        $ref = ($ref ?? self::today())->startOfDay();
        $start = $ref->startOfYear();
        $end = $ref->startOfYear()->addYear(); // exclusive

        return [
            'start' => $start->toImmutable(),
            'end' => $end->toImmutable(),
            'label' => (string) $start->year,
        ];
    }

    /* ----------------------------------------------------------------
     *  Event-in-period test (§77)
     * ---------------------------------------------------------------- */

    /** Does $event fall inside the half-open interval [$start, $end)? */
    public static function inRange(?CarbonInterface $event, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        if ($event === null) {
            return false;
        }

        return ! $event->lt($start) && $event->lt($end);
    }

    /* ----------------------------------------------------------------
     *  Display helpers
     * ---------------------------------------------------------------- */

    /** Pretty "Data as of: Sep 13, 2026, 10:24 AM" (§82). */
    public static function formatTimestamp(?CarbonInterface $dt = null): string
    {
        $dt = $dt ?? self::now();

        return $dt->format('M j, Y, g:i A');
    }

    /** Human label for a half-open range, e.g. "Sep 1–Sep 13, 2026" (§82). */
    public static function rangeLabel(CarbonImmutable $start, CarbonImmutable $end): string
    {
        $endDisplay = $end->subSecond(); // inclusive display

        if ($start->isSameDay($endDisplay)) {
            return $start->format('M j, Y');
        }

        if ($start->isSameMonth($endDisplay) && $start->isSameYear($endDisplay)) {
            return $start->format('M j').'–'.$endDisplay->format('j, Y');
        }

        return $start->format('M j').'–'.$endDisplay->format('M j, Y');
    }
}
