<?php

namespace Tests\Feature;

use App\Models\EnforcementVisit;
use App\Models\Role;
use App\Models\Section;
use App\Models\StaffTarget;
use App\Models\User;
use App\Models\Valuation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `/dashboard/my` → `performance.targets` must carry EVERY approved target for
 * the signed-in staff member (different metrics, sections and periods), each
 * with the target's own dates, the achieved value recomputed from the
 * operational records, and a percentage that agrees with those two numbers.
 *
 * The reported defect was a single-target summary: the endpoint picked the first
 * active approved target and dropped the rest, so the second approved target
 * never reached the Performance tab on either client.
 */
class PerformanceTargetsDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function officer(): User
    {
        return User::where('email', 'val.officer@test.lra')->firstOrFail();
    }

    private function secondOfficer(): User
    {
        return User::create([
            'staff_id' => 'VALOFF8',
            'full_name' => 'Second Valuation Officer',
            'email' => 'val.officer8@test.lra',
            'password' => bcrypt('password'),
            'role_id' => Role::where('name', 'Valuation Officer')->value('id'),
            'section_id' => Section::where('code', 'VAL')->value('id'),
            'is_active' => true,
            'must_reset_password' => false,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function target(User $user, array $overrides = []): StaffTarget
    {
        return StaffTarget::create(array_merge([
            'user_id' => $user->id,
            'section' => 'Valuation Section',
            'metric' => 'valuations',
            'target_value' => 20,
            'measurement_unit' => 'Property',
            'frequency' => 'Monthly',
            'start_date' => now()->subDays(5)->toDateString(),
            'end_date' => now()->addDays(25)->toDateString(),
            'period' => (string) now()->year,
            'status' => 'Approved',
            'approved_at' => now(),
        ], $overrides));
    }

    private function addValuations(User $officer, int $count, string $type = 'regular'): void
    {
        for ($i = 0; $i < $count; $i++) {
            Valuation::create([
                'valuation_reference' => 'VAL-PERF-'.$type.'-'.$i.'-'.$officer->id,
                'valuation_type' => $type,
                'status' => 'Assigned',
                'owner_name' => 'Perf Owner',
                'owner_contact' => '0770-000-222',
                'tin' => 'TIN-PERF-'.$officer->id.'-'.$type.'-'.$i,
                'property_address' => 'Performance Way',
                'property_classification' => 'Residential',
                'gps_coordinate' => '6.315611, -10.807407',
                'assessment_date' => now()->toDateString(),
                'photos' => 'proof://perf',
                'reassessed_value' => 50000,
                'applicable_tax_rate' => 1.5,
                'valuation_officer_id' => $officer->id,
            ]);
        }
    }

    private function addVisits(User $officer, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            EnforcementVisit::create([
                'officer_id' => $officer->id,
                'visit_date' => now()->toDateString(),
                'visit_status' => 'Completed',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function performance(User $user, array $query = []): array
    {
        Sanctum::actingAs($user);
        $url = '/api/v1/dashboard/my'.($query ? '?'.http_build_query($query) : '');
        $response = $this->getJson($url);
        $response->assertOk();

        return $response->json('data.performance');
    }

    /** @param array<int, array<string, mixed>> $targets */
    private function byMetric(array $targets): array
    {
        $out = [];
        foreach ($targets as $t) {
            $out[$t['metric']] = $t;
        }

        return $out;
    }

    public function test_a_staff_member_with_one_approved_target_sees_it(): void
    {
        $officer = $this->officer();
        $this->target($officer, ['metric' => 'valuations', 'target_value' => 10]);
        $this->addValuations($officer, 1);

        $perf = $this->performance($officer);

        $this->assertTrue($perf['has_target']);
        $this->assertCount(1, $perf['targets']);

        $t = $perf['targets'][0];
        $this->assertSame('valuations', $t['metric']);
        $this->assertSame('Valuation Section', $t['section']);
        $this->assertSame('Monthly', $t['frequency']);
        $this->assertSame('Approved', $t['status']);
        $this->assertEquals(10.0, $t['target_value']);
        $this->assertEquals(1.0, $t['achieved_value']);
        $this->assertEquals(10.0, $t['completion_rate']);
        $this->assertSame('Property', $t['measurement_unit']);
    }

    public function test_every_approved_target_is_returned_across_sections(): void
    {
        $officer = $this->officer();

        // The reported case: a Valuations target and a Reassessments target in a
        // different section. The old single-target summary exposed only one.
        $valuations = $this->target($officer, [
            'metric' => 'valuations',
            'section' => 'Valuation Section',
            'target_value' => 20,
            'start_date' => now()->subDays(12)->toDateString(),
            'end_date' => now()->addDays(20)->toDateString(),
        ]);
        $reassessments = $this->target($officer, [
            'metric' => 'reassessments',
            'section' => 'Enforcement',
            'target_value' => 5,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDays(30)->toDateString(),
        ]);

        $this->addValuations($officer, 1);

        $perf = $this->performance($officer);

        $this->assertCount(2, $perf['targets']);
        $this->assertSame(
            [$valuations->id, $reassessments->id],
            collect($perf['targets'])->pluck('id')->sort()->values()->all(),
        );

        $byMetric = $this->byMetric($perf['targets']);

        $this->assertEquals(20.0, $byMetric['valuations']['target_value']);
        $this->assertEquals(1.0, $byMetric['valuations']['achieved_value']);
        $this->assertEquals(5.0, $byMetric['valuations']['completion_rate']);
        $this->assertSame($valuations->start_date->toDateString(), $byMetric['valuations']['start_date']);
        $this->assertSame($valuations->end_date->toDateString(), $byMetric['valuations']['end_date']);

        $this->assertEquals(5.0, $byMetric['reassessments']['target_value']);
        $this->assertEquals(0.0, $byMetric['reassessments']['achieved_value']);
        $this->assertEquals(0.0, $byMetric['reassessments']['completion_rate']);
        $this->assertSame('Enforcement', $byMetric['reassessments']['section']);
        $this->assertSame($reassessments->start_date->toDateString(), $byMetric['reassessments']['start_date']);
    }

    public function test_targets_from_different_periods_are_all_returned_with_their_own_dates(): void
    {
        $officer = $this->officer();

        $this->target($officer, [
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-30',
            'period' => '2026',
        ]);
        $this->target($officer, [
            'metric' => 'reassessments',
            'start_date' => '2026-10-28',
            'end_date' => '2026-11-28',
            'period' => '2026',
        ]);

        $perf = $this->performance($officer);

        $this->assertCount(2, $perf['targets']);
        $this->assertSame(
            ['2026-09-01', '2026-10-28'],
            collect($perf['targets'])->pluck('start_date')->sort()->values()->all(),
        );
        $this->assertSame(
            ['2026-09-30', '2026-11-28'],
            collect($perf['targets'])->pluck('end_date')->sort()->values()->all(),
        );
    }

    public function test_overall_aggregate_includes_approved_past_current_and_future_targets(): void
    {
        $officer = $this->officer();
        $now = now();

        $past = $this->target($officer, [
            'metric' => 'visits',
            'target_value' => 10,
            'start_date' => $now->copy()->subMonth()->startOfMonth()->toDateString(),
            'end_date' => $now->copy()->subMonth()->endOfMonth()->toDateString(),
        ]);
        $current = $this->target($officer, [
            'metric' => 'visits',
            'target_value' => 20,
            'start_date' => $now->copy()->startOfMonth()->toDateString(),
            'end_date' => $now->copy()->endOfMonth()->toDateString(),
        ]);
        $future = $this->target($officer, [
            'metric' => 'visits',
            'target_value' => 30,
            'start_date' => $now->copy()->addMonth()->startOfMonth()->toDateString(),
            'end_date' => $now->copy()->addMonth()->endOfMonth()->toDateString(),
        ]);

        foreach ([
            $now->copy()->subMonth()->startOfMonth()->addDays(2),
            $now->copy()->subMonth()->startOfMonth()->addDays(3),
            $now->copy()->startOfMonth()->addDays(2),
            $now->copy()->startOfMonth()->addDays(3),
            $now->copy()->startOfMonth()->addDays(4),
        ] as $visitDate) {
            EnforcementVisit::create([
                'officer_id' => $officer->id,
                'visit_date' => $visitDate->toDateString(),
                'visit_status' => 'Completed',
            ]);
        }

        $perf = $this->performance($officer);
        $targets = collect($perf['targets'])->keyBy('id');
        $overall = $perf['overall'];

        $this->assertFalse($targets[$past->id]['is_active']);
        $this->assertTrue($targets[$current->id]['is_active']);
        $this->assertFalse($targets[$future->id]['is_active']);
        $this->assertSame(3, $overall['targets_count']);
        $this->assertEquals(60.0, $overall['target_value']);
        $this->assertEquals(5.0, $overall['achieved_value']);
        $this->assertEquals(8.3, $overall['completion_rate']);
        $this->assertEquals(2.0, $targets[$past->id]['achieved_value']);
        $this->assertEquals(3.0, $targets[$current->id]['achieved_value']);
        $this->assertEquals(0.0, $targets[$future->id]['achieved_value']);
    }

    public function test_a_staff_member_with_no_targets_gets_an_empty_list(): void
    {
        $perf = $this->performance($this->officer());

        $this->assertFalse($perf['has_target']);
        $this->assertSame([], $perf['targets']);
    }

    public function test_progress_matches_the_achieved_and_target_values(): void
    {
        $officer = $this->officer();

        // 0% — nothing achieved yet.
        $this->target($officer, ['metric' => 'visits', 'section' => 'Enforcement', 'target_value' => 5]);

        // 40% — 4 of 10 reassessments.
        $this->target($officer, ['metric' => 'reassessments', 'target_value' => 10]);
        $this->addValuations($officer, 4, 'reassessment');

        // 100% — 8 of 8 valuations (the valuations metric counts every valuation
        // for the officer: the 4 reassessments above plus 4 regular assessments).
        $this->target($officer, ['metric' => 'valuations', 'target_value' => 8]);
        $this->addValuations($officer, 4);

        $byMetric = $this->byMetric($this->performance($officer)['targets']);

        $this->assertEquals(0.0, $byMetric['visits']['completion_rate']);
        $this->assertEquals(4.0, $byMetric['reassessments']['achieved_value']);
        $this->assertEquals(40.0, $byMetric['reassessments']['completion_rate']);
        $this->assertEquals(8.0, $byMetric['valuations']['achieved_value']);
        $this->assertEquals(100.0, $byMetric['valuations']['completion_rate']);
    }

    public function test_a_zero_target_value_never_produces_a_division_error(): void
    {
        $this->target($this->officer(), ['target_value' => 0]);

        $perf = $this->performance($this->officer());

        $this->assertCount(1, $perf['targets']);
        $this->assertEquals(0.0, $perf['targets'][0]['completion_rate']);
    }

    public function test_overall_summary_matches_the_reported_two_target_example(): void
    {
        $officer = $this->officer();

        // The reported case: Valuations 1 of 20, Reassessments 0 of 5.
        $this->target($officer, [
            'metric' => 'valuations',
            'section' => 'Valuation Section',
            'target_value' => 20,
        ]);
        $this->target($officer, [
            'metric' => 'reassessments',
            'section' => 'Enforcement',
            'target_value' => 5,
        ]);
        $this->addValuations($officer, 1);

        $perf = $this->performance($officer);
        $overall = $perf['overall'];

        $this->assertTrue($overall['has_targets']);
        $this->assertSame(2, $overall['targets_count']);
        $this->assertEquals(1.0, $overall['achieved_value']);
        $this->assertEquals(25.0, $overall['target_value']);
        $this->assertEquals(4.0, $overall['completion_rate']);

        // The individual percentages are untouched: 5% and 0%, not 4%.
        $byMetric = $this->byMetric($perf['targets']);
        $this->assertEquals(5.0, $byMetric['valuations']['completion_rate']);
        $this->assertEquals(0.0, $byMetric['reassessments']['completion_rate']);
    }

    public function test_overall_summary_is_a_weighted_aggregate_not_an_average_of_percentages(): void
    {
        $officer = $this->officer();

        // A big untouched target and a small fully met target from another
        // section/frequency: 10 / 110 = 9.1%, NOT the 50% arithmetic average.
        $this->target($officer, [
            'metric' => 'valuations',
            'section' => 'Valuation Section',
            'target_value' => 100,
            'frequency' => 'Monthly',
        ]);
        $this->target($officer, [
            'metric' => 'visits',
            'section' => 'Enforcement',
            'target_value' => 10,
            'frequency' => 'Weekly',
        ]);
        $this->addVisits($officer, 10);

        $perf = $this->performance($officer);
        $overall = $perf['overall'];

        $this->assertSame(2, $overall['targets_count']);
        $this->assertEquals(10.0, $overall['achieved_value']);
        $this->assertEquals(110.0, $overall['target_value']);
        $this->assertEquals(9.1, $overall['completion_rate']);
        $this->assertNotEquals(50.0, $overall['completion_rate']);

        $byMetric = $this->byMetric($perf['targets']);
        $this->assertEquals(0.0, $byMetric['valuations']['completion_rate']);
        $this->assertEquals(100.0, $byMetric['visits']['completion_rate']);
    }

    public function test_overall_summary_is_safe_when_the_combined_target_is_zero(): void
    {
        $officer = $this->officer();
        $this->target($officer, ['metric' => 'valuations', 'target_value' => 0]);
        $this->target($officer, ['metric' => 'reassessments', 'target_value' => 0]);

        $perf = $this->performance($officer);
        $overall = $perf['overall'];

        $this->assertTrue($overall['has_targets']);
        $this->assertSame(2, $overall['targets_count']);
        $this->assertEquals(0.0, $overall['target_value']);
        $this->assertEquals(0.0, $overall['achieved_value']);

        // No ratio exists: clients render N/A instead of 0% or a division error.

        $this->assertNull($overall['completion_rate']);
        $this->assertCount(2, $perf['targets']);
    }

    public function test_overall_summary_preserves_achievement_above_the_combined_target(): void
    {
        $officer = $this->officer();
        $this->target($officer, ['metric' => 'valuations', 'target_value' => 3]);
        $this->addValuations($officer, 5);

        $perf = $this->performance($officer);
        $overall = $perf['overall'];

        $this->assertSame(1, $overall['targets_count']);
        $this->assertEquals(5.0, $overall['achieved_value']); // actual achieved kept
        $this->assertEquals(3.0, $overall['target_value']);
        $this->assertEquals(100.0, $overall['completion_rate']); // display clamped

        // Individual target calculation is unchanged.
        $this->assertEquals(5.0, $perf['targets'][0]['achieved_value']);
        $this->assertEquals(166.7, $perf['targets'][0]['completion_rate']);
    }

    public function test_overall_summary_is_empty_when_no_targets_are_visible(): void
    {
        $overall = $this->performance($this->officer())['overall'];

        $this->assertFalse($overall['has_targets']);
        $this->assertSame(0, $overall['targets_count']);
        $this->assertEquals(0.0, $overall['target_value']);
        $this->assertEquals(0.0, $overall['achieved_value']);
        $this->assertNull($overall['completion_rate']);
    }

    public function test_overall_summary_updates_with_the_targets_on_refresh(): void
    {
        $officer = $this->officer();

        $this->assertSame(0, $this->performance($officer)['overall']['targets_count']);

        $this->target($officer, ['metric' => 'valuations', 'target_value' => 20]);
        $this->addValuations($officer, 1);

        // Inside the 45s cache window the summary still matches the cached list.
        $this->assertSame(0, $this->performance($officer)['overall']['targets_count']);

        $fresh = $this->performance($officer, ['fresh' => 1])['overall'];
        $this->assertSame(1, $fresh['targets_count']);
        $this->assertEquals(1.0, $fresh['achieved_value']);
        $this->assertEquals(20.0, $fresh['target_value']);
        $this->assertEquals(5.0, $fresh['completion_rate']);
    }

    public function test_non_approved_targets_keep_their_existing_visibility_rules(): void
    {
        $officer = $this->officer();

        $approved = $this->target($officer);
        $this->target($officer, ['metric' => 'reassessments', 'status' => 'Draft']);
        $this->target($officer, ['metric' => 'visits', 'status' => 'Archived']);

        $perf = $this->performance($officer);

        $this->assertCount(1, $perf['targets']);
        $this->assertSame($approved->id, $perf['targets'][0]['id']);
        // The summary must expose only the same visible (approved) target.
        $this->assertSame(1, $perf['overall']['targets_count']);
        $this->assertEquals((float) $approved->target_value, $perf['overall']['target_value']);
    }

    public function test_another_staff_members_targets_are_never_returned(): void
    {
        $officer = $this->officer();
        $other = $this->secondOfficer();

        $mine = $this->target($officer);
        $theirs = $this->target($other, ['metric' => 'reassessments']);

        $perf = $this->performance($officer);
        $ids = collect($perf['targets'])->pluck('id')->all();

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
        // The aggregate covers only this user's visible target.
        $this->assertSame(1, $perf['overall']['targets_count']);
        $this->assertEquals((float) $mine->target_value, $perf['overall']['target_value']);
    }

    public function test_refresh_bypasses_the_short_dashboard_cache(): void
    {
        $officer = $this->officer();

        // First read populates the 45s cache with "no targets".
        $this->assertSame([], $this->performance($officer)['targets']);

        $this->target($officer);

        // Same cache window — still the cached snapshot.
        $this->assertSame([], $this->performance($officer)['targets']);

        // Explicit refresh re-reads the latest targets.
        $this->assertCount(1, $this->performance($officer, ['fresh' => 1])['targets']);
    }

    public function test_repeated_reads_do_not_duplicate_targets(): void
    {
        $officer = $this->officer();
        $this->target($officer);
        $this->target($officer, ['metric' => 'reassessments']);

        $first = $this->performance($officer, ['fresh' => 1])['targets'];
        $second = $this->performance($officer, ['fresh' => 1])['targets'];

        $this->assertCount(2, $first);
        $this->assertCount(2, $second);
        $this->assertSame(
            collect($first)->pluck('id')->sort()->values()->all(),
            collect($second)->pluck('id')->sort()->values()->all(),
        );
    }

    public function test_the_dashboard_requires_authentication(): void
    {
        $this->getJson('/api/v1/dashboard/my')->assertUnauthorized();
    }
}
