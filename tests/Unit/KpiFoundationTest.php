<?php

namespace Tests\Unit;

use App\Services\KpiRegistry;
use App\Support\BusinessClock;
use App\Support\ReportingPeriod;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Verify the three KPI-foundation classes behave correctly so every
 * consumer (ManagerDashboardService, OfficerDashboardController,
 * reports, charts, drill-downs) uses the same definitions.
 */
class KpiFoundationTest extends TestCase
{
    /* ----------------------------------------------------------------
     *  BusinessClock
     * ---------------------------------------------------------------- */

    public function test_now_returns_liberia_timezone(): void
    {
        $clock = BusinessClock::now();
        $this->assertSame('Africa/Monrovia', $clock->timezoneName);
    }

    public function test_today_is_midnight_in_liberia(): void
    {
        $today = BusinessClock::today();
        $this->assertSame('00:00:00', $today->format('H:i:s'));
        $this->assertSame('Africa/Monrovia', $today->timezoneName);
    }

    public function test_overdue_by_date_only(): void
    {
        BusinessClock::freeze(CarbonImmutable::parse('2026-09-13 10:00', 'Africa/Monrovia'));

        $this->assertFalse(BusinessClock::isOverdue(CarbonImmutable::parse('2026-09-13')));
        $this->assertFalse(BusinessClock::isOverdue(CarbonImmutable::parse('2026-09-14')));
        $this->assertTrue(BusinessClock::isOverdue(CarbonImmutable::parse('2026-09-12')));

        BusinessClock::thaw();
    }

    public function test_overdue_by_datetime_precision(): void
    {
        BusinessClock::freeze(CarbonImmutable::parse('2026-09-13 14:01', 'Africa/Monrovia'));

        $this->assertTrue(BusinessClock::isOverdueAt(CarbonImmutable::parse('2026-09-13 14:00', 'Africa/Monrovia')));
        $this->assertFalse(BusinessClock::isOverdueAt(CarbonImmutable::parse('2026-09-13 14:02', 'Africa/Monrovia')));
        $this->assertFalse(BusinessClock::isOverdueAt(null));

        BusinessClock::thaw();
    }

    public function test_due_today(): void
    {
        BusinessClock::freeze(CarbonImmutable::parse('2026-09-13 08:30', 'Africa/Monrovia'));

        $this->assertTrue(BusinessClock::isDueToday(CarbonImmutable::parse('2026-09-13 23:59')));
        $this->assertFalse(BusinessClock::isDueToday(CarbonImmutable::parse('2026-09-14 00:01')));
        $this->assertFalse(BusinessClock::isDueToday(null));

        BusinessClock::thaw();
    }

    public function test_in_range_is_half_open(): void
    {
        $start = CarbonImmutable::parse('2026-09-01');
        $end = CarbonImmutable::parse('2026-10-01'); // exclusive

        $this->assertTrue(BusinessClock::inRange(CarbonImmutable::parse('2026-09-01 00:00:00'), $start, $end));
        $this->assertTrue(BusinessClock::inRange(CarbonImmutable::parse('2026-09-30 23:59:59'), $start, $end));
        $this->assertFalse(BusinessClock::inRange(CarbonImmutable::parse('2026-10-01 00:00:00'), $start, $end));
        $this->assertFalse(BusinessClock::inRange(null, $start, $end));
    }

    public function test_month_range_is_correct(): void
    {
        BusinessClock::freeze(CarbonImmutable::parse('2026-09-13', 'Africa/Monrovia'));
        $r = BusinessClock::monthRange();

        $this->assertSame('2026-09-01', $r['start']->toDateString());
        $this->assertSame('2026-10-01', $r['end']->toDateString());
        $this->assertSame('September 2026', $r['label']);

        BusinessClock::thaw();
    }

    public function test_week_range_starts_monday(): void
    {
        // Sep 13 2026 is a Sunday.
        BusinessClock::freeze(CarbonImmutable::parse('2026-09-13', 'Africa/Monrovia'));
        $r = BusinessClock::weekRange();

        $this->assertSame('Monday', $r['start']->format('l'));
        $this->assertSame('2026-09-07', $r['start']->toDateString());
        $this->assertSame('2026-09-14', $r['end']->toDateString()); // next Monday

        BusinessClock::thaw();
    }

    public function test_quarter_range(): void
    {
        BusinessClock::freeze(CarbonImmutable::parse('2026-09-13', 'Africa/Monrovia'));
        $r = BusinessClock::quarterRange();

        $this->assertSame('2026-07-01', $r['start']->toDateString());
        $this->assertSame('2026-10-01', $r['end']->toDateString());
        $this->assertSame('Q3 2026', $r['label']);

        BusinessClock::thaw();
    }

    public function test_year_range(): void
    {
        BusinessClock::freeze(CarbonImmutable::parse('2026-09-13', 'Africa/Monrovia'));
        $r = BusinessClock::yearRange();

        $this->assertSame('2026-01-01', $r['start']->toDateString());
        $this->assertSame('2027-01-01', $r['end']->toDateString());
        $this->assertSame('2026', $r['label']);

        BusinessClock::thaw();
    }

    public function test_format_timestamp_default_now(): void
    {
        BusinessClock::freeze(CarbonImmutable::parse('2026-09-13 10:24:00', 'Africa/Monrovia'));
        $this->assertSame('Sep 13, 2026, 10:24 AM', BusinessClock::formatTimestamp());
        BusinessClock::thaw();
    }

    public function test_range_label_same_day(): void
    {
        $day = CarbonImmutable::parse('2026-09-13');
        $end = $day->addDay(); // half-open
        $this->assertSame('Sep 13, 2026', BusinessClock::rangeLabel($day, $end));
    }

    public function test_range_label_different_days_same_month(): void
    {
        $start = CarbonImmutable::parse('2026-09-01');
        $end = $start->copy()->addMonth(); // Oct 1 exclusive
        $this->assertSame('Sep 1–30, 2026', BusinessClock::rangeLabel($start, $end));
    }

    public function test_thaw_resets_frozen_clock(): void
    {
        BusinessClock::freeze(CarbonImmutable::parse('2020-01-01', 'Africa/Monrovia'));
        BusinessClock::thaw();
        $this->assertGreaterThanOrEqual((int) date('Y'), (int) BusinessClock::now()->format('Y'));
    }

    /* ----------------------------------------------------------------
     *  ReportingPeriod
     * ---------------------------------------------------------------- */

    public function test_current_month_has_required_keys(): void
    {
        $m = ReportingPeriod::currentMonth();

        $this->assertArrayHasKey('key', $m);
        $this->assertArrayHasKey('label', $m);
        $this->assertArrayHasKey('period', $m);
        $this->assertArrayHasKey('start', $m);
        $this->assertArrayHasKey('end', $m);
        $this->assertArrayHasKey('start_date', $m);
        $this->assertArrayHasKey('end_date', $m);
        $this->assertArrayHasKey('timezone', $m);
        $this->assertSame('month', $m['key']);
        $this->assertSame('Africa/Monrovia', $m['timezone']);
    }

    public function test_meta_has_required_top_level_keys(): void
    {
        $meta = ReportingPeriod::meta();

        $this->assertArrayHasKey('period_label', $meta);
        $this->assertArrayHasKey('as_of', $meta);
        $this->assertArrayHasKey('as_of_utc', $meta);
        $this->assertArrayHasKey('period', $meta);
        $this->assertSame('Current Month', $meta['period_label']);
    }

    /* ----------------------------------------------------------------
     *  KpiRegistry
     * ---------------------------------------------------------------- */

    public function test_all_definitions_are_array(): void
    {
        $all = KpiRegistry::all();
        $this->assertIsArray($all);
        $this->assertNotEmpty($all);

        foreach ($all as $code => $def) {
            $this->assertSame($code, $def['code']);
            $this->assertArrayHasKey('name', $def);
            $this->assertArrayHasKey('kind', $def);
            $this->assertContains($def['kind'], ['current', 'period']);
            $this->assertArrayHasKey('module', $def);
        }
    }

    public function test_filter_by_module(): void
    {
        $account = KpiRegistry::all('account');
        $this->assertNotEmpty($account);
        foreach ($account as $def) {
            $this->assertSame('account', $def['module']);
        }
    }

    public function test_definition_lookup(): void
    {
        $def = KpiRegistry::definition('AR_OVERDUE_TASKS');
        $this->assertNotNull($def);
        $this->assertSame('Overdue Tasks', $def['name']);
        $this->assertSame('current', $def['kind']);
        $this->assertStringContainsString('due_date < today', $def['condition']);

        $this->assertNull(KpiRegistry::definition('NONEXISTENT_CODE'));
    }

    public function test_codes_returns_array_for_module(): void
    {
        $codes = KpiRegistry::codes('enforcement');
        $this->assertContains('ENF_OVERDUE', $codes);
        $this->assertContains('ENF_UNASSIGNED', $codes);
        $this->assertContains('ENF_DELIVERED_PERIOD', $codes);
    }
}
