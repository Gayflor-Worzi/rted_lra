<?php

namespace App\Support;

/**
 * Reporting-period helpers for dashboard KPIs.
 *
 * §7   Default reporting period = Current Month.
 * §8   Date range definition — half-open [start, end).
 * §46  Date range filter behavior.
 * §52–55 Calendar week/month/quarter/year.
 */
final class ReportingPeriod
{
    /**
     * Return the standard period metadata block appended to every dashboard
     * payload so frontend and drill-downs always know the window.
     *
     * @return array{key: string, label: string, start: string, end: string, start_date: string, end_date: string, timezone: string}
     */
    public static function currentMonth(): array
    {
        $range = BusinessClock::monthRange();
        $start = $range['start'];
        $end = $range['end']; // exclusive

        return [
            'key' => 'month',
            'label' => $range['label'],
            'period' => BusinessClock::rangeLabel($start, $end),
            'start' => $start->toDateTimeString(),
            'end' => $end->subSecond()->toDateTimeString(), // inclusive for display
            'start_date' => $start->toDateString(),
            'end_date' => $end->subDay()->toDateString(), // inclusive calendar date
            'timezone' => BusinessClock::TIMEZONE,
        ];
    }

    /**
     * Build the `reporting` block that every dashboard payload must include.
     */
    public static function meta(): array
    {
        $month = self::currentMonth();
        $now = BusinessClock::now();

        return [
            'period_label' => 'Current Month',
            'timezone' => BusinessClock::TIMEZONE,
            'as_of' => BusinessClock::formatTimestamp($now),
            'as_of_utc' => $now->toIso8601String(),
            'period' => $month,
        ];
    }
}
