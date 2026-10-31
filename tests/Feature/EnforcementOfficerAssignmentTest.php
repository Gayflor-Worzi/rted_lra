<?php

namespace Tests\Feature;

use App\Models\EnforcementDistrict;
use App\Models\EnforcementOrgRecommendation;
use App\Models\EnforcementTeam;
use App\Models\User;
use App\Services\EnforcementOfficerAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\ApprovedEnforcementAssignments;
use Tests\TestCase;

class EnforcementOfficerAssignmentTest extends TestCase
{
    use ApprovedEnforcementAssignments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_current_approved_assignment_uses_primary_district_region_and_separates_team_coverage(): void
    {
        $officer = User::where('email', 'enf.officer@test.lra')->firstOrFail();
        [$manager, $supervisor, $membership] = $this->approveEnforcementAssignment($officer);
        $team = EnforcementTeam::findOrFail($membership->team_id);
        $primaryDistrict = $membership->primaryDistrict()->firstOrFail();

        $additionalDistrict = EnforcementDistrict::create([
            'name' => 'Additional Coverage '.Str::upper(Str::random(8)),
            'is_active' => true,
            'region_id' => $primaryDistrict->region_id,
        ]);
        $team->districts()->attach($additionalDistrict->id);

        EnforcementOrgRecommendation::create([
            'change_type' => 'assign_officer',
            'team_id' => $team->id,
            'payload' => [
                'officer_id' => $officer->id,
                'team_id' => $team->id,
                'primary_district_id' => $additionalDistrict->id,
            ],
            'submitted_by' => $manager->id,
            'status' => 'pending',
            'submitted_at' => now(),
        ]);

        $assignment = app(EnforcementOfficerAssignmentService::class)->forOfficer($officer);

        $this->assertSame($primaryDistrict->region->name, $assignment['tax_region']);
        $this->assertSame($primaryDistrict->name, $assignment['tax_district']);
        $this->assertSame($team->name, $assignment['enforcement_team']);
        $this->assertSame($membership->team->currentMemberships()->where('is_team_lead', true)->first()->officer->full_name, $assignment['team_lead']);
        $this->assertSame($supervisor->full_name, $assignment['regional_supervisor']);
        $this->assertSame([$additionalDistrict->name], $assignment['additional_coverage_districts']);

        Sanctum::actingAs($officer);
        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.organizational_assignment.tax_region', $primaryDistrict->region->name)
            ->assertJsonPath('data.organizational_assignment.tax_district', $primaryDistrict->name);

        $this->getJson('/api/v1/dashboard/my?fresh=1')
            ->assertOk()
            ->assertJsonPath('data.profile.organizational_assignment.tax_region', $primaryDistrict->region->name)
            ->assertJsonPath('data.profile.organizational_assignment.tax_district', $primaryDistrict->name);
    }

    public function test_unassigned_officer_receives_no_inferred_organizational_values(): void
    {
        $officer = $this->organizationFixtureUser(
            'Enforcement Officer',
            'ENF',
            'test.enforcement.unassigned.'.Str::lower(Str::random(8)).'@test.lra'
        );

        $this->assertSame([
            'tax_region' => null,
            'tax_district' => null,
            'enforcement_team' => null,
            'team_lead' => null,
            'regional_supervisor' => null,
            'additional_coverage_districts' => [],
        ], app(EnforcementOfficerAssignmentService::class)->forOfficer($officer));

        Sanctum::actingAs($officer);
        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.organizational_assignment.tax_region', null)
            ->assertJsonPath('data.organizational_assignment.tax_district', null);
    }

    public function test_other_roles_do_not_receive_enforcement_officer_assignment_fields(): void
    {
        $manager = $this->organizationFixtureUser(
            'Enforcement Manager',
            'ENF',
            'test.enforcement.assignment.manager@test.lra'
        );
        Sanctum::actingAs($manager);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonMissingPath('data.organizational_assignment');
    }
}
