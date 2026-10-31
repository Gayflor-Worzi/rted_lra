<?php

namespace Tests\Feature;

use App\Models\EnforcementDistrict;
use App\Models\EnforcementOrgRecommendation;
use App\Models\EnforcementTaxRegion;
use App\Models\EnforcementTeam;
use App\Models\EnforcementTeamDistrictHistory;
use App\Models\EnforcementTeamMembership;
use App\Models\Notification;
use App\Models\PropertyBill;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\LocalDemoUsers;
use Tests\TestCase;

class EnforcementOrganizationTest extends TestCase
{
    use LocalDemoUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function manager(): User
    {
        return $this->localRoleUser('org.manager@test.lra', 'Enforcement Manager', 'ENF');
    }

    private function supervisor(): User
    {
        return $this->localEnforcementSupervisor();
    }

    private function officer(): User
    {
        return User::where('email', 'enf.officer@test.lra')->firstOrFail();
    }

    private function commissioner(): User
    {
        return User::where('email', 'ac@test.lra')->firstOrFail();
    }

    private function recommend(User $submitter, array $payload): EnforcementOrgRecommendation
    {
        Sanctum::actingAs($submitter);
        $response = $this->postJson('/api/v1/enforcement/organization/recommendations', $payload)
            ->assertCreated();

        return EnforcementOrgRecommendation::findOrFail($response->json('data.id'));
    }

    private function approve(EnforcementOrgRecommendation $recommendation): void
    {
        Sanctum::actingAs($this->commissioner());
        $this->postJson("/api/v1/enforcement/organization/recommendations/{$recommendation->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
    }

    private function approveAsManager(EnforcementOrgRecommendation $recommendation): void
    {
        Sanctum::actingAs($this->manager());
        $this->postJson("/api/v1/enforcement/organization/recommendations/{$recommendation->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
    }

    private function createRegion(string $name, ?User $supervisor = null): EnforcementTaxRegion
    {
        $code = strtoupper(str_replace(' ', '-', $name));
        $recommendation = $this->recommend($this->manager(), [
            'change_type' => 'create_region',
            'name' => $name,
            'code' => $code,
            'classification' => 'Urban',
        ]);
        $this->approve($recommendation);
        $region = EnforcementTaxRegion::where('code', $code)->firstOrFail();
        $assignment = $this->recommend($this->manager(), [
            'change_type' => 'assign_region_supervisor',
            'region_id' => $region->id,
            'supervisor_id' => ($supervisor ?? $this->supervisor())->id,
        ]);
        $this->assertSame('approved', $assignment->status);

        return $region->fresh();
    }

    private function createDistrict(string $name, ?EnforcementTaxRegion $region = null, ?User $supervisor = null): EnforcementDistrict
    {
        $recommendation = $this->recommend($this->manager(), [
            'change_type' => 'create_district',
            'name' => $name,
        ]);
        $this->assertDatabaseMissing('enforcement_districts', ['name' => $name]);
        $this->approve($recommendation);
        $district = EnforcementDistrict::where('name', $name)->firstOrFail();
        $region ??= $this->createRegion("{$name} Region", $supervisor);
        $assignRecommendation = $this->recommend($this->manager(), [
            'change_type' => 'assign_districts_to_region',
            'region_id' => $region->id,
            'district_ids' => [$district->id],
        ]);
        $this->approve($assignRecommendation);

        return $district->fresh();
    }

    private function createTeam(EnforcementDistrict $district): EnforcementTeam
    {
        $lead = $this->localRoleUser(
            "org.team.lead.{$district->id}@test.lra",
            'Enforcement Officer',
            'ENF'
        );
        $recommendation = $this->recommend($this->manager(), [
            'change_type' => 'create_team',
            'name' => 'Organization Test Team',
            'district_ids' => [$district->id],
            'members' => [[
                'officer_id' => $lead->id,
                'primary_district_id' => $district->id,
                'is_team_lead' => true,
            ]],
        ]);
        $this->approve($recommendation);

        return EnforcementTeam::where('name', 'Organization Test Team')->firstOrFail();
    }

    private function assignOfficer(EnforcementTeam $team, EnforcementDistrict $district): EnforcementTeamMembership
    {
        $recommendation = $this->recommend($this->manager(), [
            'change_type' => 'assign_officer',
            'team_id' => $team->id,
            'officer_id' => $this->officer()->id,
            'primary_district_id' => $district->id,
            'is_team_lead' => true,
        ]);
        $this->assertSame('approved', $recommendation->status);

        return EnforcementTeamMembership::where('officer_id', $this->officer()->id)
            ->whereNull('ended_at')
            ->firstOrFail();
    }

    public function test_organization_changes_take_effect_only_after_commissioner_approval(): void
    {
        $recommendation = $this->recommend($this->manager(), [
            'change_type' => 'create_district',
            'name' => 'Approval Test District',
        ]);

        $this->assertSame('pending', $recommendation->status);
        $this->assertDatabaseMissing('enforcement_districts', ['name' => 'Approval Test District']);
        $this->approve($recommendation);
        $this->assertDatabaseHas('enforcement_districts', ['name' => 'Approval Test District']);
    }

    public function test_only_authorized_organizational_roles_can_access_recommendation_api(): void
    {
        Sanctum::actingAs($this->officer());
        $this->getJson('/api/v1/enforcement/organization')->assertForbidden();
        $this->postJson('/api/v1/enforcement/organization/recommendations', [
            'change_type' => 'create_district',
            'name' => 'Unauthorized District',
        ])->assertForbidden();
    }

    public function test_manager_supervisor_and_commissioner_can_access_team_management_api(): void
    {
        Sanctum::actingAs($this->manager());
        $this->getJson('/api/v1/enforcement/organization')
            ->assertOk()
            ->assertJsonPath('data.can_manage', true)
            ->assertJsonPath('data.can_administer_geography', true)
            ->assertJsonPath('data.can_review_membership', true);

        Sanctum::actingAs($this->supervisor());
        $this->getJson('/api/v1/enforcement/organization')
            ->assertOk()
            ->assertJsonPath('data.can_recommend', true);

        Sanctum::actingAs($this->commissioner());
        $this->getJson('/api/v1/enforcement/organization')
            ->assertOk()
            ->assertJsonPath('data.can_review', true);
    }

    public function test_tax_region_and_district_assignments_require_commissioner_approval_and_keep_history(): void
    {
        $regionRecommendation = $this->recommend($this->manager(), [
            'change_type' => 'create_region',
            'name' => 'Rural North Region',
            'code' => 'RURAL-NORTH',
            'classification' => 'Rural',
            'description' => 'Test region description',
        ]);
        $this->assertDatabaseMissing('enforcement_tax_regions', ['code' => 'RURAL-NORTH']);
        $this->approve($regionRecommendation);
        $region = EnforcementTaxRegion::where('code', 'RURAL-NORTH')->firstOrFail();
        $this->assertSame('Rural', $region->classification);

        $districtRecommendation = $this->recommend($this->manager(), [
            'change_type' => 'create_district',
            'name' => 'North Example District',
        ]);
        $this->approve($districtRecommendation);
        $district = EnforcementDistrict::where('name', 'North Example District')->firstOrFail();
        $this->assertNull($district->region_id, 'district geography remains unconfigured until explicitly assigned');

        $assignment = $this->recommend($this->manager(), [
            'change_type' => 'assign_districts_to_region',
            'region_id' => $region->id,
            'district_ids' => [$district->id],
        ]);
        $this->assertNull($district->fresh()->region_id, 'district-region assignment waits for AC approval');
        $this->approve($assignment);

        $this->assertSame($region->id, $district->fresh()->region_id);
        $this->assertDatabaseHas('enforcement_district_region_history', [
            'district_id' => $district->id,
            'region_id' => $region->id,
            'ended_at' => null,
        ]);
    }

    public function test_manager_assigns_regional_supervisor_and_teams_inherit_region_scope(): void
    {
        $firstDistrict = $this->createDistrict('Multi District One');
        $region = EnforcementTaxRegion::findOrFail($firstDistrict->region_id);
        $secondDistrict = $this->createDistrict('Multi District Two', $region);
        $lead = $this->localRoleUser('org.multi.district.lead@test.lra', 'Enforcement Officer', 'ENF');
        $recommendation = $this->recommend($this->manager(), [
            'change_type' => 'create_team',
            'name' => 'Multi District Regional Team',
            'district_ids' => [$firstDistrict->id, $secondDistrict->id],
            'members' => [[
                'officer_id' => $lead->id,
                'primary_district_id' => $firstDistrict->id,
                'is_team_lead' => true,
            ]],
        ]);

        $this->assertSame($this->supervisor()->id, (int) $recommendation->payload['supervisor_id']);
        $this->approve($recommendation);
        $team = EnforcementTeam::where('name', 'Multi District Regional Team')->firstOrFail();

        $this->assertSame($region->supervisor_id, $team->supervisor_id);
        $this->assertSame(2, $team->districts()->count());
    }

    public function test_team_cannot_span_multiple_tax_regions(): void
    {
        $firstDistrict = $this->createDistrict('First Region District');
        $secondDistrict = $this->createDistrict('Second Region District');

        Sanctum::actingAs($this->manager());
        $this->postJson('/api/v1/enforcement/organization/recommendations', [
            'change_type' => 'create_team',
            'name' => 'Cross Region Team',
            'district_ids' => [$firstDistrict->id, $secondDistrict->id],
        ])->assertStatus(422);
    }

    public function test_team_district_assignments_are_historised_and_preserved_when_a_team_moves(): void
    {
        $district = $this->createDistrict('History Origin District');
        $team = $this->createTeam($district);

        $initial = EnforcementTeamDistrictHistory::query()
            ->where('team_id', $team->id)
            ->whereNull('ended_at')
            ->sole();
        $this->assertSame($district->id, (int) $initial->district_id);
        $this->assertSame($this->manager()->id, (int) $initial->assigned_by);

        $region = EnforcementTaxRegion::findOrFail($district->region_id);
        $destination = $this->createDistrict('History Destination District', $region);
        $expand = $this->recommend($this->manager(), [
            'change_type' => 'update_team',
            'team_id' => $team->id,
            'district_ids' => [$district->id, $destination->id],
            'reason' => 'Move the team to the adjoining district.',
        ]);
        $this->approve($expand);

        $lead = User::where('email', "org.team.lead.{$district->id}@test.lra")->firstOrFail();
        $this->recommend($this->manager(), [
            'change_type' => 'assign_officer',
            'team_id' => $team->id,
            'officer_id' => $lead->id,
            'primary_district_id' => $destination->id,
            'is_team_lead' => true,
        ]);
        $move = $this->recommend($this->manager(), [
            'change_type' => 'update_team',
            'team_id' => $team->id,
            'district_ids' => [$destination->id],
            'reason' => 'Move the team to the adjoining district.',
        ]);
        $this->approve($move);

        $this->assertSame(
            [$destination->id],
            $team->fresh()->districts()->pluck('enforcement_districts.id')->map(fn ($id) => (int) $id)->all()
        );
        $this->assertNotNull($initial->fresh()->ended_at);
        $this->assertSame($this->commissioner()->id, (int) $initial->fresh()->ended_by);
        // The ended row keeps the reason it was created with; the move reason is
        // recorded on the new assignment, matching the other organization histories.
        $this->assertNull($initial->fresh()->reason);

        $this->assertDatabaseHas('enforcement_team_district_history', [
            'team_id' => $team->id,
            'district_id' => $destination->id,
            'ended_at' => null,
            'reason' => 'Move the team to the adjoining district.',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => EnforcementTeam::class,
            'auditable_id' => $team->id,
            'action' => 'enforcement.team.updated',
        ]);
    }

    public function test_team_creation_can_capture_the_team_lead_and_members_at_submission(): void
    {
        $region = $this->createRegion('Lead Capture Region');
        $district = $this->createDistrict('Lead Capture District', $region);
        $lead = $this->localRoleUser('org.capture.lead@test.lra', 'Enforcement Officer', 'ENF');
        $officer = $this->officer();

        $recommendation = $this->recommend($this->manager(), [
            'change_type' => 'create_team',
            'name' => 'Lead Capture Team',
            'district_ids' => [$district->id],
            'reason' => 'Initial team setup.',
            'members' => [
                ['officer_id' => $lead->id, 'primary_district_id' => $district->id, 'is_team_lead' => true],
                ['officer_id' => $officer->id, 'primary_district_id' => $district->id, 'is_team_lead' => false],
            ],
        ]);

        // Nothing becomes operational until the Commissioner approves.
        $this->assertDatabaseMissing('enforcement_teams', ['name' => 'Lead Capture Team']);
        $this->assertSame(0, EnforcementTeamMembership::query()->whereIn('officer_id', [$lead->id, $officer->id])->count());

        $this->approve($recommendation);

        $team = EnforcementTeam::where('name', 'Lead Capture Team')->firstOrFail();
        $this->assertSame($region->supervisor_id, $team->supervisor_id);

        $leadMembership = EnforcementTeamMembership::query()
            ->where('team_id', $team->id)
            ->where('officer_id', $lead->id)
            ->whereNull('ended_at')
            ->firstOrFail();
        $this->assertTrue($leadMembership->is_team_lead);
        $this->assertSame($district->id, (int) $leadMembership->primary_district_id);
        $this->assertSame($this->commissioner()->id, (int) $leadMembership->assigned_by);

        $this->assertDatabaseHas('enforcement_team_memberships', [
            'team_id' => $team->id,
            'officer_id' => $officer->id,
            'primary_district_id' => $district->id,
            'is_team_lead' => false,
            'ended_at' => null,
        ]);
        $this->assertSame($team->supervisor_id, $lead->fresh()->supervisor_id);
        $this->assertSame($team->supervisor_id, $officer->fresh()->supervisor_id);

        $this->assertDatabaseHas('enforcement_team_district_history', [
            'team_id' => $team->id,
            'district_id' => $district->id,
            'reason' => 'Initial team setup.',
            'ended_at' => null,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => EnforcementTeam::class,
            'auditable_id' => $team->id,
            'action' => 'enforcement.team.created',
        ]);
        // Membership does not assign every bill in the district.
        $this->assertSame(0, PropertyBill::where('assigned_enforcement_officer_id', $lead->id)->count());
    }

    public function test_team_creation_rejects_members_without_exactly_one_team_lead(): void
    {
        $district = $this->createDistrict('No Lead District');
        Sanctum::actingAs($this->manager());

        $this->postJson('/api/v1/enforcement/organization/recommendations', [
            'change_type' => 'create_team',
            'name' => 'No Lead Team',
            'district_ids' => [$district->id],
            'members' => [
                ['officer_id' => $this->officer()->id, 'primary_district_id' => $district->id, 'is_team_lead' => false],
            ],
        ])->assertStatus(422);

        $this->postJson('/api/v1/enforcement/organization/recommendations', [
            'change_type' => 'create_team',
            'name' => 'No Members Team',
            'district_ids' => [$district->id],
        ])->assertStatus(422);

        $this->assertDatabaseMissing('enforcement_teams', ['name' => 'No Lead Team']);
        $this->assertDatabaseMissing('enforcement_teams', ['name' => 'No Members Team']);
    }

    public function test_team_creation_rejects_a_member_already_assigned_to_another_team(): void
    {
        $district = $this->createDistrict('Existing Team District');
        $team = $this->createTeam($district);
        $this->assignOfficer($team, $district);

        $region = EnforcementTaxRegion::findOrFail($district->region_id);
        $destination = $this->createDistrict('New Team District', $region);
        Sanctum::actingAs($this->manager());

        $this->postJson('/api/v1/enforcement/organization/recommendations', [
            'change_type' => 'create_team',
            'name' => 'Conflicting Team',
            'district_ids' => [$destination->id],
            'members' => [
                ['officer_id' => $this->officer()->id, 'primary_district_id' => $destination->id, 'is_team_lead' => true],
            ],
        ])->assertStatus(422);

        $this->assertDatabaseMissing('enforcement_teams', ['name' => 'Conflicting Team']);
        $this->assertSame(1, EnforcementTeamMembership::query()
            ->where('officer_id', $this->officer()->id)
            ->whereNull('ended_at')
            ->count());
    }

    public function test_reassigning_a_district_updates_supervision_without_erasing_membership_history(): void
    {
        $district = $this->createDistrict('Reassignment Source District');
        $team = $this->createTeam($district);
        $membership = $this->assignOfficer($team, $district);
        $newSupervisor = $this->localRoleUser('org.region.new.supervisor@test.lra', 'Enforcement Supervisor', 'ENF');
        $destinationDistrict = $this->createDistrict('Reassignment Destination District', null, $newSupervisor);
        $destinationRegion = EnforcementTaxRegion::findOrFail($destinationDistrict->region_id);

        $move = $this->recommend($this->manager(), [
            'change_type' => 'assign_districts_to_region',
            'region_id' => $destinationRegion->id,
            'district_ids' => [$district->id],
            'reason' => 'Approved test reassignment',
        ]);
        $this->approve($move);

        $this->assertSame($destinationRegion->id, $district->fresh()->region_id);
        $this->assertSame($newSupervisor->id, $team->fresh()->supervisor_id);
        $this->assertNotNull($membership->fresh()->ended_at);
        $this->assertDatabaseHas('enforcement_team_memberships', [
            'team_id' => $team->id,
            'officer_id' => $this->officer()->id,
            'supervisor_id' => $newSupervisor->id,
            'ended_at' => null,
        ]);
        $this->assertDatabaseHas('enforcement_district_region_history', [
            'district_id' => $district->id,
            'region_id' => $destinationRegion->id,
            'ended_at' => null,
        ]);
    }

    public function test_supervisor_can_see_pending_team_recommendation_addressed_to_them(): void
    {
        $district = $this->createDistrict('Pending Team Visibility District');
        $supervisor = $this->supervisor();
        $otherSupervisor = $this->localRoleUser('org.unassigned.supervisor@test.lra', 'Enforcement Supervisor', 'ENF');
        $lead = $this->localRoleUser('org.pending.team.lead@test.lra', 'Enforcement Officer', 'ENF');
        $recommendation = $this->recommend($this->manager(), [
            'change_type' => 'create_team',
            'name' => 'Pending Supervisor Team',
            'supervisor_id' => $supervisor->id,
            'district_ids' => [$district->id],
            'members' => [[
                'officer_id' => $lead->id,
                'primary_district_id' => $district->id,
                'is_team_lead' => true,
            ]],
        ]);

        Sanctum::actingAs($supervisor);
        $this->getJson('/api/v1/enforcement/organization')
            ->assertOk()
            ->assertJsonPath('data.teams', [])
            ->assertJsonPath('data.recommendations.0.id', $recommendation->id)
            ->assertJsonPath('data.recommendations.0.status', 'pending')
            ->assertJsonPath('data.recommendations.0.payload.supervisor_id', $supervisor->id);

        Sanctum::actingAs($otherSupervisor);
        $this->getJson('/api/v1/enforcement/organization')
            ->assertOk()
            ->assertJsonPath('data.recommendations', []);

        $this->approve($recommendation);

        Sanctum::actingAs($supervisor);
        $this->getJson('/api/v1/enforcement/organization')
            ->assertOk()
            ->assertJsonPath('data.teams.0.name', 'Pending Supervisor Team');
    }

    public function test_system_administrator_can_view_team_management_without_assuming_org_decision_authority(): void
    {
        $administrator = $this->localRoleUser('org.admin@test.lra', 'System Administrator', 'SYS');

        Sanctum::actingAs($administrator);
        $this->getJson('/api/v1/enforcement/organization')
            ->assertOk()
            ->assertJsonPath('data.can_manage', false)
            ->assertJsonPath('data.can_administer_geography', true)
            ->assertJsonPath('data.can_recommend', false)
            ->assertJsonPath('data.can_review', false)
            ->assertJsonPath('data.can_review_membership', false);
    }

    public function test_supervisor_can_recommend_membership_changes_only_within_their_scope(): void
    {
        $district = $this->createDistrict('Supervisor Scope District');
        $team = $this->createTeam($district);
        $otherSupervisor = $this->localRoleUser('org.other.supervisor@test.lra', 'Enforcement Supervisor', 'ENF');
        $otherDistrict = $this->createDistrict('Other Supervisor Scope District', null, $otherSupervisor);
        $otherLead = $this->localRoleUser('org.other.team.lead@test.lra', 'Enforcement Officer', 'ENF');
        $otherTeamRecommendation = $this->recommend($this->manager(), [
            'change_type' => 'create_team',
            'name' => 'Other Supervisor Team',
            'district_ids' => [$otherDistrict->id],
            'members' => [[
                'officer_id' => $otherLead->id,
                'primary_district_id' => $otherDistrict->id,
                'is_team_lead' => true,
            ]],
        ]);
        $this->approve($otherTeamRecommendation);
        $otherTeam = EnforcementTeam::where('name', 'Other Supervisor Team')->firstOrFail();

        $membershipPayload = [
            'change_type' => 'assign_officer',
            'team_id' => $team->id,
            'officer_id' => $this->officer()->id,
            'primary_district_id' => $district->id,
            'is_team_lead' => false,
        ];
        Sanctum::actingAs($this->supervisor());
        $recommendation = EnforcementOrgRecommendation::findOrFail($this->postJson('/api/v1/enforcement/organization/recommendations', $membershipPayload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->json('data.id'));

        $this->postJson('/api/v1/enforcement/organization/recommendations', [
            ...$membershipPayload,
            'team_id' => $otherTeam->id,
        ])->assertForbidden();
        $this->postJson('/api/v1/enforcement/organization/recommendations', [
            'change_type' => 'create_district',
            'name' => 'Supervisor Cannot Create District',
        ])->assertForbidden();

        Sanctum::actingAs($this->supervisor());
        $this->postJson("/api/v1/enforcement/organization/recommendations/{$recommendation->id}/approve")
            ->assertForbidden();
        $this->approveAsManager($recommendation);
        $this->assertDatabaseHas('enforcement_team_memberships', [
            'officer_id' => $this->officer()->id,
            'team_id' => $team->id,
            'ended_at' => null,
        ]);
    }

    public function test_supervisor_can_recommend_a_team_update_within_their_tax_region(): void
    {
        $district = $this->createDistrict('Supervisor Update Origin District');
        $region = EnforcementTaxRegion::findOrFail($district->region_id);
        $team = $this->createTeam($district);
        $destination = $this->createDistrict('Supervisor Update Destination District', $region);

        $recommendation = $this->recommend($this->supervisor(), [
            'change_type' => 'update_team',
            'team_id' => $team->id,
            'name' => 'Renamed By Supervisor Team',
            'district_ids' => [$district->id, $destination->id],
            'reason' => 'Supervisor recommends the adjoining district.',
        ]);

        $this->assertSame('pending', $recommendation->status);
        $this->assertSame('Organization Test Team', $team->fresh()->name);
        $this->assertSame(
            [$district->id],
            $team->fresh()->districts()->pluck('enforcement_districts.id')->map(fn ($id) => (int) $id)->all()
        );

        Sanctum::actingAs($this->manager());
        $this->getJson('/api/v1/enforcement/organization')
            ->assertOk()
            ->assertJsonFragment(['id' => $recommendation->id, 'can_review' => true]);

        Sanctum::actingAs($this->commissioner());
        $this->postJson("/api/v1/enforcement/organization/recommendations/{$recommendation->id}/approve")
            ->assertForbidden();
        $this->approveAsManager($recommendation);

        $this->assertSame('Renamed By Supervisor Team', $team->fresh()->name);
        $this->assertSame(
            [$district->id, $destination->id],
            $team->fresh()->districts()->pluck('enforcement_districts.id')->map(fn ($id) => (int) $id)->all()
        );
        $this->assertDatabaseHas('enforcement_team_district_history', [
            'team_id' => $team->id,
            'district_id' => $destination->id,
            'reason' => 'Supervisor recommends the adjoining district.',
            'ended_at' => null,
        ]);
    }

    public function test_supervisor_cannot_update_a_team_outside_their_tax_region(): void
    {
        $otherSupervisor = $this->localRoleUser('org.tranche2.supervisor@test.lra', 'Enforcement Supervisor', 'ENF');
        $otherDistrict = $this->createDistrict('Tranche Two Other District', null, $otherSupervisor);
        $otherLead = $this->localRoleUser('org.tranche2.team.lead@test.lra', 'Enforcement Officer', 'ENF');
        $otherTeamRecommendation = $this->recommend($this->manager(), [
            'change_type' => 'create_team',
            'name' => 'Tranche Two Other Team',
            'district_ids' => [$otherDistrict->id],
            'members' => [[
                'officer_id' => $otherLead->id,
                'primary_district_id' => $otherDistrict->id,
                'is_team_lead' => true,
            ]],
        ]);
        $this->approve($otherTeamRecommendation);
        $otherTeam = EnforcementTeam::where('name', 'Tranche Two Other Team')->firstOrFail();

        Sanctum::actingAs($this->supervisor());
        $this->postJson('/api/v1/enforcement/organization/recommendations', [
            'change_type' => 'update_team',
            'team_id' => $otherTeam->id,
            'name' => 'Unauthorized Rename Team',
        ])->assertForbidden();

        $this->assertSame('Tranche Two Other Team', $otherTeam->fresh()->name);
    }

    public function test_supervisor_cannot_move_a_team_into_another_tax_region(): void
    {
        $district = $this->createDistrict('Tranche Two Origin District');
        $team = $this->createTeam($district);
        $otherSupervisor = $this->localRoleUser('org.tranche2.destination@test.lra', 'Enforcement Supervisor', 'ENF');
        $destination = $this->createDistrict('Tranche Two Destination District', null, $otherSupervisor);

        Sanctum::actingAs($this->supervisor());
        $this->postJson('/api/v1/enforcement/organization/recommendations', [
            'change_type' => 'update_team',
            'team_id' => $team->id,
            'district_ids' => [$destination->id],
        ])->assertForbidden();

        $this->assertSame(
            [$district->id],
            $team->fresh()->districts()->pluck('enforcement_districts.id')->map(fn ($id) => (int) $id)->all()
        );
    }

    public function test_supervisor_cannot_create_a_team(): void
    {
        $district = $this->createDistrict('Tranche Two Create District');

        Sanctum::actingAs($this->supervisor());
        $this->postJson('/api/v1/enforcement/organization/recommendations', [
            'change_type' => 'create_team',
            'name' => 'Supervisor Created Team',
            'district_ids' => [$district->id],
        ])->assertForbidden();

        $this->assertDatabaseMissing('enforcement_teams', ['name' => 'Supervisor Created Team']);
    }

    public function test_bill_assignment_is_rejected_without_an_approved_active_membership(): void
    {
        $manager = $this->manager();
        $bill = PropertyBill::create([
            'document_number' => 'ORG-BILL-NO-TEAM',
            'property_id' => 'ORG-PROP-NO-TEAM',
            'taxpayer_name' => 'Unassigned officer taxpayer',
            'tin' => 'ORG-TIN-NO-TEAM',
            'property_address' => 'No team address',
            'recipient_type' => PropertyBill::RECIPIENT_WALK_IN,
            'delivery_status' => 'Logged',
            'case_status' => 'Awaiting Assignment',
            'payment_status' => 'Unpaid',
            'date_logged' => now()->toDateString(),
            'tax_amount' => 100,
            'total_tax_due' => 100,
            'outstanding_balance' => 100,
        ]);
        $task = Task::create([
            'task_type' => Task::TYPE_BILL_DELIVERY,
            'section' => 'Enforcement',
            'reference_type' => 'property_bill',
            'reference_id' => $bill->id,
            'status' => 'Awaiting Assignment',
        ]);

        Sanctum::actingAs($this->supervisor());
        $this->postJson("/api/v1/me/tasks/{$task->id}/assign-walkin", ['officer_id' => $this->officer()->id])
            ->assertStatus(422);

        $this->assertNull($task->fresh()->assigned_to);
        $this->assertNull($bill->fresh()->assigned_enforcement_officer_id);
        $this->assertNull($bill->fresh()->primary_tax_district_id);
    }

    public function test_bill_assignment_captures_primary_district_without_backfilling_or_reallocation(): void
    {
        $firstDistrict = $this->createDistrict('Primary District One');
        $team = $this->createTeam($firstDistrict);
        $this->assignOfficer($team, $firstDistrict);

        $bill = PropertyBill::create([
            'document_number' => 'ORG-BILL-1',
            'property_id' => 'ORG-PROP-1',
            'taxpayer_name' => 'Organization test taxpayer',
            'tin' => 'ORG-TIN-1',
            'property_address' => 'Organization test address',
            'recipient_type' => PropertyBill::RECIPIENT_WALK_IN,
            'delivery_status' => 'Logged',
            'case_status' => 'Awaiting Assignment',
            'payment_status' => 'Unpaid',
            'date_logged' => now()->toDateString(),
            'tax_amount' => 100,
            'total_tax_due' => 100,
            'outstanding_balance' => 100,
        ]);
        $this->assertNull($bill->primary_tax_district_id, 'existing bills are not inferred or backfilled');

        $task = Task::create([
            'task_type' => Task::TYPE_BILL_DELIVERY,
            'section' => 'Enforcement',
            'reference_type' => 'property_bill',
            'reference_id' => $bill->id,
            'status' => 'Awaiting Assignment',
        ]);

        Sanctum::actingAs($this->supervisor());
        $this->postJson("/api/v1/me/tasks/{$task->id}/assign-walkin", ['officer_id' => $this->officer()->id])
            ->assertOk();
        $bill->refresh();
        $this->assertSame($firstDistrict->id, $bill->primary_tax_district_id);

        $secondDistrict = $this->createDistrict(
            'Primary District Two',
            EnforcementTaxRegion::findOrFail($firstDistrict->region_id)
        );
        $teamUpdate = $this->recommend($this->manager(), [
            'change_type' => 'update_team',
            'team_id' => $team->id,
            'district_ids' => [$firstDistrict->id, $secondDistrict->id],
        ]);
        $this->approve($teamUpdate);
        $this->assignOfficer($team->fresh(), $secondDistrict);

        $this->assertSame($firstDistrict->id, $bill->fresh()->primary_tax_district_id, 'changing the officer primary district does not reallocate existing bills');
        $this->assertSame(2, EnforcementTeamMembership::where('officer_id', $this->officer()->id)->count());
    }

    public function test_supervisor_cannot_assign_an_officer_outside_their_team_scope(): void
    {
        $district = $this->createDistrict('Manager Scope District');
        $team = $this->createTeam($district);
        $this->assignOfficer($team, $district);
        $bill = PropertyBill::create([
            'document_number' => 'ORG-BILL-SCOPE',
            'property_id' => 'ORG-PROP-SCOPE',
            'taxpayer_name' => 'Manager scope taxpayer',
            'tin' => 'ORG-TIN-SCOPE',
            'property_address' => 'Manager scope address',
            'recipient_type' => PropertyBill::RECIPIENT_WALK_IN,
            'delivery_status' => 'Logged',
            'case_status' => 'Awaiting Assignment',
            'payment_status' => 'Unpaid',
            'date_logged' => now()->toDateString(),
            'tax_amount' => 100,
            'total_tax_due' => 100,
            'outstanding_balance' => 100,
        ]);
        $task = Task::create([
            'task_type' => Task::TYPE_BILL_DELIVERY,
            'section' => 'Enforcement',
            'reference_type' => 'property_bill',
            'reference_id' => $bill->id,
            'status' => 'Awaiting Assignment',
        ]);
        $otherSupervisor = $this->localRoleUser('org.other.supervisor@test.lra', 'Enforcement Supervisor', 'ENF');

        Sanctum::actingAs($otherSupervisor);
        $this->postJson("/api/v1/me/tasks/{$task->id}/assign-walkin", ['officer_id' => $this->officer()->id])
            ->assertForbidden();

        $this->assertNull($bill->fresh()->assigned_enforcement_officer_id);
        $this->assertNull($bill->tasks()->firstOrFail()->assigned_to);
    }

    public function test_organization_review_notifications_reach_the_commissioner_and_the_submitter(): void
    {
        Notification::query()->delete();

        $recommendation = $this->recommend($this->manager(), [
            'change_type' => 'create_district',
            'name' => 'Notification Review District',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->commissioner()->id,
            'type' => Notification::TYPE_ORGANIZATION,
            'title' => 'Enforcement organization change awaiting review',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $this->manager()->id,
            'type' => Notification::TYPE_ORGANIZATION,
        ]);

        $this->approve($recommendation);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->manager()->id,
            'type' => Notification::TYPE_ORGANIZATION,
            'title' => 'Enforcement organization request approved',
        ]);
    }

    public function test_supervisor_team_update_notifies_the_responsible_manager_and_decision_reaches_the_supervisor(): void
    {
        $district = $this->createDistrict('Notification Scope District');
        $team = $this->createTeam($district);
        Notification::query()->delete();

        $recommendation = $this->recommend($this->supervisor(), [
            'change_type' => 'update_team',
            'team_id' => $team->id,
            'name' => 'Notification Renamed Team',
            'district_ids' => [$district->id],
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $team->manager_id,
            'type' => Notification::TYPE_ORGANIZATION,
            'title' => 'Enforcement organization change awaiting review',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $this->commissioner()->id,
            'type' => Notification::TYPE_ORGANIZATION,
        ]);

        $this->approveAsManager($recommendation);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->supervisor()->id,
            'type' => Notification::TYPE_ORGANIZATION,
            'title' => 'Enforcement organization request approved',
        ]);
    }

    public function test_organization_notifications_link_to_the_team_management_workspace(): void
    {
        Notification::query()->delete();
        $this->recommend($this->manager(), [
            'change_type' => 'create_district',
            'name' => 'Notification Link District',
        ]);

        Sanctum::actingAs($this->commissioner());
        $response = $this->getJson('/api/v1/notifications')->assertOk();
        $row = collect($response->json('data.data'))->firstWhere('type', Notification::TYPE_ORGANIZATION);

        $this->assertNotNull($row);
        $this->assertSame('organization', $row['detail']['kind']);
        $this->assertSame('/enforcement?tab=organization', $row['detail']['link']);
    }
}
