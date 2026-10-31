<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Small authoritative business calendar used for calendar-day vs
 * business-day SLA arithmetic (§15, §16).
 *
 * The list of official LRA/RETD holidays belongs here (single place) —
 * never recomputed independently in controllers, React components or the
 * mobile app (§17). Business days currently account for weekends only;
 * add RETD holidays to `holidaysFor()` when LRA publishes them.
 */
final class BusinessCalendar
{
    public const CALENDAR_DAYS = 'CALENDAR';

    public const BUSINESS_DAYS = 'BUSINESS';

    /** Official RETD holidays keyed by date string. Empty until LRA publishes them. */
    public const HOLIDAYS = [];

    /**
     * Add working days to a start instant. Skips weekends (Sat/Sun) and any
     * configured RETD holiday. Only whole business days are counted, so a
     * start on the weekend rolls forward to Monday.
     */
    public static function addBusinessDays(
        CarbonImmutable $start,
        int $days,
        array $holidays = self::HOLIDAYS
    ): CarbonImmutable {
        $cursor = $start->copy();
        $remaining = max($days, 0);

        while ($remaining > 0) {
            $cursor = $cursor->addDay();
            $isWeekend = $cursor->isWeekend();
            $isHoliday = in_array($cursor->toDateString(), $holidays, true);
            if (! $isWeekend && ! $isHoliday) {
                $remaining--;
            }
        }

        return $cursor;
    }

    /** Is $date an RETD working day (not weekend and not holiday)? */
    public static function isBusinessDay(
        CarbonImmutable $date,
        array $holidays = self::HOLIDAYS
    ): bool {
        return ! $date->isWeekend()
            && ! in_array($date->toDateString(), $holidays, true);
    }
}
