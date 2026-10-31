<?php

namespace Tests\Feature;

use App\Models\EnforcementVisit;
use App\Models\EnforcementDistrict;
use App\Models\EnforcementTeam;
use App\Models\EnforcementTeamMembership;
use App\Models\Role;
use App\Models\Section;
use App\Models\StaffOrganizationHistory;
use App\Models\StaffTarget;
use App\Models\User;
use App\Services\ManagementPerformanceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ManagementPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_supervisor_performance_splits_target_and_visits_across_assignment_periods(): void
    {
        [$firstSupervisor, $secondSupervisor, $officer] = $this->enforcementStaff();
        $start = today()->subDays(10);
        $split = today();
        $end = today()->addDays(9);

        $target = $this->target($officer, $start, $end, 20);
        $this->setAssignment($firstSupervisor, $officer, $start, $split->copy()->subDay());
        $this->setAssignment($secondSupervisor, $officer, $split, null);
        $this->visit($officer, $split->copy()->subDay());
        $this->visit($officer, $split);

        $first = app(ManagementPerformanceService::class)->summarize($firstSupervisor);
        $second = app(ManagementPerformanceService::class)->summarize($secondSupervisor);

        $this->assertSame('supervisor', $first['scope']);
        $this->assertSame('supervisor', $second['scope']);
        $this->assertCount(1, $first['periods']);
        $this->assertCount(1, $second['periods']);
        $this->assertSame($target->metric, $first['periods'][0]['metric']);
        $this->assertSame(1, $first['periods'][0]['contributing_staff_count']);
        $this->assertEquals(10, $first['periods'][0]['target_value']);
        $this->assertEquals(1, $first['periods'][0]['achieved_value']);
        $this->assertEquals(10, $second['periods'][0]['target_value']);
        $this->assertEquals(1, $second['periods'][0]['achieved_value']);
    }

    public function test_section_manager_aggregation_does_not_include_other_sections(): void
    {
        $sectionManager = $this->user('Enforcement Manager', 'ENF', 'ENFMGR8');
        $inSection = $this->user('Enforcement Officer', 'ENF', 'ENFOFF8');
        $otherSection = $this->user('Valuation Officer', 'VAL', 'VALOFF9');
        $start = today();
        $end = today();

        $this->target($inSection, $start, $end, 2);
        $this->target($otherSection, $start, $end, 10);
        $this->visit($inSection, today());
        $this->visit($otherSection, today());

        $summary = app(ManagementPerformanceService::class)->summarize($sectionManager);

        $this->assertSame('section', $summary['scope']);
        $this->assertCount(1, $summary['periods']);
        $this->assertEquals(2, $summary['periods'][0]['target_value']);
        $this->assertEquals(1, $summary['periods'][0]['achieved_value']);
    }

    public function test_assistant_commissioner_aggregates_operational_sections_and_excludes_management_targets(): void
    {
        $commissioner = $this->user('Assistant Commissioner', 'MGT', 'ACMGR8');
        $officer = $this->user('Enforcement Officer', 'ENF', 'ENFOFF9');
        $sectionManager = $this->user('Enforcement Manager', 'ENF', 'ENFMGR9');
        $start = today();
        $end = today();

        $this->target($officer, $start, $end, 2);
        $this->target($sectionManager, $start, $end, 100);
        $this->visit($officer, today());

        $summary = app(ManagementPerformanceService::class)->summarize($commissioner);

        $this->assertSame('division', $summary['scope']);
        $this->assertCount(1, $summary['periods']);
        $this->assertEquals(2, $summary['periods'][0]['target_value']);
        $this->assertEquals(1, $summary['periods'][0]['achieved_value']);
    }

    public function test_unsupported_target_metrics_are_reported_as_unavailable_not_zero(): void
    {
        $supervisor = $this->user('Enforcement Supervisor', 'ENF', 'ENFSUP8');
        $officer = $this->user('Enforcement Officer', 'ENF', 'ENFOFF10', $supervisor);
        $this->target($officer, today(), today(), 4, 'custom');

        $summary = app(ManagementPerformanceService::class)->summarize($supervisor);

        $this->assertSame('unavailable', $summary['status']);
        $this->assertSame(['custom'], $summary['unsupported_metrics']);
        $this->assertSame(1, $summary['unsupported_targets_count']);
        $this->assertSame([], $summary['periods']);
    }

    public function test_same_activity_is_not_counted_twice_for_duplicate_targets_in_one_window(): void
    {
        $supervisor = $this->user('Enforcement Supervisor', 'ENF', 'ENFSUP13');
        $officer = $this->user('Enforcement Officer', 'ENF', 'ENFOFF14', $supervisor);
        $this->target($officer, today(), today(), 2);
        $this->target($officer, today(), today(), 2);
        $this->visit($officer, today());
        $this->visit($officer, today());

        $summary = app(ManagementPerformanceService::class)->summarize($supervisor);

        $this->assertCount(1, $summary['periods']);
        $this->assertEquals(4, $summary['periods'][0]['target_value']);
        $this->assertEquals(2, $summary['periods'][0]['achieved_value']);
        $this->assertSame(2, $summary['periods'][0]['targets_count']);
    }

    public function test_pre_history_days_are_excluded_and_marked_limited(): void
    {
        $supervisor = $this->user('Enforcement Supervisor', 'ENF', 'ENFSUP14');
        $officer = $this->user('Enforcement Officer', 'ENF', 'ENFOFF15', $supervisor);
        $this->target($officer, today()->subDay(), today(), 4);
        $this->visit($officer, today());

        $summary = app(ManagementPerformanceService::class)->summarize($supervisor);

        $this->assertCount(1, $summary['periods']);
        $this->assertEquals(2, $summary['periods'][0]['target_value']);
        $this->assertEquals(1, $summary['periods'][0]['achieved_value']);
        $this->assertTrue($summary['periods'][0]['attribution_limited']);
        $this->assertSame(1, $summary['unattributed_targets_count']);
    }

    public function test_zero_eligible_achievements_are_explicit_not_unavailable(): void
    {
        $supervisor = $this->user('Enforcement Supervisor', 'ENF', 'ENFSUP15');
        $officer = $this->user('Enforcement Officer', 'ENF', 'ENFOFF16', $supervisor);
        $this->target($officer, today(), today(), 3);

        $summary = app(ManagementPerformanceService::class)->summarize($supervisor);

        $this->assertSame('available', $summary['status']);
        $this->assertSame('no_achievements', $summary['periods'][0]['status']);
        $this->assertFalse($summary['periods'][0]['has_achievements']);
        $this->assertSame(0.0, $summary['periods'][0]['completion_rate']);
    }

    public function test_supervisor_changes_close_the_old_history_and_start_a_new_period(): void
    {
        $previousSupervisor = $this->user('Enforcement Supervisor', 'ENF', 'ENFSUP16');
        $newSupervisor = $this->user('Enforcement Supervisor', 'ENF', 'ENFSUP17');
        $officer = $this->user('Enforcement Officer', 'ENF', 'ENFOFF17', $previousSupervisor);
        $history = StaffOrganizationHistory::query()
            ->where('user_id', $officer->id)
            ->whereNull('effective_to')
            ->firstOrFail();
        $history->update(['effective_from' => today()->subDay()->toDateString()]);

        $officer->update(['supervisor_id' => $newSupervisor->id]);

        $periods = StaffOrganizationHistory::query()
            ->where('user_id', $officer->id)
            ->orderBy('effective_from')
            ->get();
        $this->assertCount(2, $periods);
        $this->assertSame(today()->subDay()->toDateString(), $periods[0]->effective_to->toDateString());
        $this->assertSame(today()->toDateString(), $periods[1]->effective_from->toDateString());
        $this->assertSame($newSupervisor->id, $periods[1]->supervisor_id);
    }

    public function test_management_api_does_not_expose_the_manager_personal_target_report(): void
    {
        $manager = $this->user('Enforcement Supervisor', 'ENF', 'ENFSUP9');
        $this->target($manager, today(), today(), 10);
        $officer = $this->user('Enforcement Officer', 'ENF', 'ENFOFF13', $manager);
        $this->target($officer, today(), today(), 2);
        $this->visit($officer, today());
        Sanctum::actingAs($manager);

        $this->getJson('/api/v1/dashboard/my')
            ->assertOk()
            ->assertJsonPath('data.performance.classification', 'management')
            ->assertJsonPath('data.performance.targets', []);

        $this->getJson('/api/v1/reports/my/performance')->assertForbidden();
        $dashboard = $this->getJson('/api/v1/dashboard/performance/management')
            ->assertOk()
            ->assertJsonPath('data.classification', 'management')
            ->json('data');
        $divisionDashboard = $this->getJson('/api/v1/dashboard/division')
            ->assertOk()
            ->json('data');
        $report = $this->getJson('/api/v1/reports/management/performance')
            ->assertOk()
            ->json('data');

        $this->assertSame($dashboard, $report);
        $this->assertSame($dashboard, $divisionDashboard['management_performance']);
        $this->assertSame([], $divisionDashboard['target_averages']);
        $this->assertEquals(1, $dashboard['periods'][0]['achieved_value']);
    }

    public function test_enforcement_team_lead_remains_on_individual_target_performance(): void
    {
        $supervisor = $this->user('Enforcement Supervisor', 'ENF', 'ENFSUP12');
        $manager = $this->user('Enforcement Manager', 'ENF', 'ENFMGR12');
        $teamLead = $this->user('Enforcement Officer', 'ENF', 'ENFOFF12', $supervisor);
        $district = EnforcementDistrict::create(['name' => 'Performance Test District']);
        $team = EnforcementTeam::create([
            'name' => 'Performance Test Team',
            'manager_id' => $manager->id,
            'supervisor_id' => $supervisor->id,
        ]);
        $team->districts()->attach($district->id);
        EnforcementTeamMembership::create([
            'team_id' => $team->id,
            'officer_id' => $teamLead->id,
            'supervisor_id' => $supervisor->id,
            'primary_district_id' => $district->id,
            'prior_supervisor_id' => null,
            'is_team_lead' => true,
            'assigned_by' => $manager->id,
            'started_at' => now(),
        ]);
        $this->target($teamLead, today(), today(), 3);
        $this->visit($teamLead, today());
        Sanctum::actingAs($teamLead);

        $this->getJson('/api/v1/dashboard/my')
            ->assertOk()
            ->assertJsonPath('data.performance.classification', null)
            ->assertJsonPath('data.performance.targets.0.achieved_value', 1);
        $this->getJson('/api/v1/dashboard/performance/management')->assertForbidden();
    }

    private function user(string $roleName, string $sectionCode, string $staffId, ?User $supervisor = null): User
    {
        return User::create([
            'staff_id' => $staffId,
            'full_name' => $staffId,
            'email' => strtolower($staffId).'@test.lra',
            'password' => bcrypt('password'),
            'role_id' => Role::where('name', $roleName)->value('id'),
            'section_id' => Section::where('code', $sectionCode)->value('id'),
            'supervisor_id' => $supervisor?->id,
            'is_active' => true,
            'must_reset_password' => false,
        ]);
    }

    private function enforcementStaff(): array
    {
        $first = $this->user('Enforcement Supervisor', 'ENF', 'ENFSUP10');
        $second = $this->user('Enforcement Supervisor', 'ENF', 'ENFSUP11');
        $officer = $this->user('Enforcement Officer', 'ENF', 'ENFOFF11', $second);

        return [$first, $second, $officer];
    }

    private function target(User $user, Carbon $start, Carbon $end, float $value, string $metric = 'visits'): StaffTarget
    {
        return StaffTarget::create([
            'user_id' => $user->id,
            'section' => $user->section?->name,
            'metric' => $metric,
            'target_value' => $value,
            'achieved_value' => 0,
            'measurement_unit' => 'Property',
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'frequency' => 'Monthly',
            'period' => (string) $start->year,
            'status' => 'Approved',
            'approved_at' => now(),
        ]);
    }

    private function setAssignment(User $supervisor, User $officer, Carbon $from, ?Carbon $to): void
    {
        $current = StaffOrganizationHistory::query()
            ->where('user_id', $officer->id)
            ->whereNull('effective_to')
            ->firstOrFail();
        $current->update([
            'supervisor_id' => $supervisor->id,
            'effective_from' => $from->toDateString(),
            'effective_to' => $to?->toDateString(),
        ]);

        if ($to) {
            $current->update(['effective_to' => $to->toDateString()]);
            $new = $current->replicate();
            $new->supervisor_id = null;
            $new->effective_from = $to->copy()->addDay()->toDateString();
            $new->effective_to = null;
            $new->save();
        }

        StaffOrganizationHistory::query()
            ->where('user_id', $supervisor->id)
            ->whereNull('effective_to')
            ->update(['effective_from' => $from->toDateString()]);
    }

    private function visit(User $officer, Carbon $date): void
    {
        EnforcementVisit::create([
            'officer_id' => $officer->id,
            'visit_date' => $date->toDateString(),
            'visit_status' => 'Completed',
        ]);
    }
}
