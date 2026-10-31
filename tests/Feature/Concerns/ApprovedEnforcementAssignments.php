<?php

namespace Tests\Feature\Concerns;

use App\Models\EnforcementDistrict;
use App\Models\EnforcementTeam;
use App\Models\EnforcementTeamMembership;
use App\Models\EnforcementTaxRegion;
use App\Models\Role;
use App\Models\Section;
use App\Models\User;
use App\Services\EnforcementOrganizationService;
use Illuminate\Support\Str;

trait ApprovedEnforcementAssignments
{
    /**
     * Build an eligible field officer through the same organization approval
     * service used by the manager and Commissioner workflows.
     *
     * @return array{0: User, 1: User, 2: EnforcementTeamMembership}
     */
    private function approveEnforcementAssignment(User $officer): array
    {
        $manager = $this->organizationFixtureUser(
            'Enforcement Manager',
            'ENF',
            'test.enforcement.manager@test.lra'
        );
        $supervisor = $this->organizationFixtureUser(
            'Enforcement Supervisor',
            'ENF',
            'enf.supervisor@test.lra'
        );
        $commissioner = User::where('email', 'ac@test.lra')->firstOrFail();
        $organization = app(EnforcementOrganizationService::class);
        $suffix = Str::upper(Str::random(8));

        $regionRecommendation = $organization->submit($manager, [
            'change_type' => 'create_region',
            'name' => "Test Region {$suffix}",
            'code' => "TEST-REGION-{$suffix}",
            'classification' => 'Urban',
        ]);
        $organization->approve($commissioner, $regionRecommendation);
        $region = EnforcementTaxRegion::where('code', "TEST-REGION-{$suffix}")->firstOrFail();

        $supervisorAssignment = $organization->submit($manager, [
            'change_type' => 'assign_region_supervisor',
            'region_id' => $region->id,
            'supervisor_id' => $supervisor->id,
        ]);
        $this->assertSame('approved', $supervisorAssignment->status);

        $districtRecommendation = $organization->submit($manager, [
            'change_type' => 'create_district',
            'name' => "Test District {$suffix}",
        ]);
        $organization->approve($commissioner, $districtRecommendation);
        $district = EnforcementDistrict::where('name', "Test District {$suffix}")->firstOrFail();

        $districtRegionRecommendation = $organization->submit($manager, [
            'change_type' => 'assign_districts_to_region',
            'region_id' => $region->id,
            'district_ids' => [$district->id],
        ]);
        $organization->approve($commissioner, $districtRegionRecommendation);
        $district->refresh();

        $teamLead = $this->organizationFixtureUser(
            'Enforcement Officer',
            'ENF',
            "test.enforcement.teamlead.{$suffix}@test.lra"
        );
        $teamRecommendation = $organization->submit($manager, [
            'change_type' => 'create_team',
            'name' => "Test Team {$suffix}",
            'district_ids' => [$district->id],
            'members' => [[
                'officer_id' => $teamLead->id,
                'primary_district_id' => $district->id,
                'is_team_lead' => true,
            ]],
        ]);
        $organization->approve($commissioner, $teamRecommendation);
        $team = EnforcementTeam::where('name', "Test Team {$suffix}")->firstOrFail();

        $membershipRecommendation = $organization->submit($manager, [
            'change_type' => 'assign_officer',
            'team_id' => $team->id,
            'officer_id' => $officer->id,
            'primary_district_id' => $district->id,
            'is_team_lead' => false,
        ]);

        $this->assertSame('approved', $membershipRecommendation->status);

        return [
            $manager,
            $supervisor,
            EnforcementTeamMembership::query()
                ->where('officer_id', $officer->id)
                ->whereNull('ended_at')
                ->firstOrFail(),
        ];
    }

    private function organizationFixtureUser(string $roleName, string $sectionCode, string $email): User
    {
        return User::updateOrCreate(
            ['email' => $email],
            [
                'staff_id' => 'TST'.Str::upper(Str::random(7)),
                'full_name' => "Test {$roleName}",
                'password' => bcrypt('officer123'),
                'role_id' => Role::where('name', $roleName)->value('id'),
                'section_id' => Section::where('code', $sectionCode)->value('id'),
                'is_active' => true,
                'must_reset_password' => false,
            ]
        );
    }
}
