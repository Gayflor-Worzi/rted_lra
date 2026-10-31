<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Valuation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\LocalDemoUsers;
use Tests\TestCase;

/**
 * One owner per step.
 *
 * The Valuation Manager and the Valuation Officer are two seats in ONE workflow, not
 * two processes. Assigning an assessment hands the officer step to that officer, and
 * the desk that assigned it keeps read access plus its own verbs (reassign, review) —
 * it must not keep the conduct step too.
 *
 * That separation was missing on the API: update(), descriptions(), begin() and
 * submit() all checked only `valuation.edit` / `valuation.submit` plus the data scope,
 * never *who the record is assigned to*. The Valuation Supervisor role is granted
 * valuation.edit, valuation.submit AND valuation.review together, and its team scope
 * can see the whole section's valuations, so a supervisor could open an assessment that
 * had been assigned to a colleague and conduct, edit and submit it. The dashboard
 * offered the same options, because every button was gated on the permission alone.
 *
 * These tests pin the rule: the officer steps belong to the assigned officer.
 */
class ValuationOfficerOwnershipTest extends TestCase
{
    use LocalDemoUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function act(string $email): void
    {
        Sanctum::actingAs($this->user($email));
    }

    /** A Valuation Supervisor is not a seeded account; it is the role that leaked. */
    private function supervisor(): User
    {
        return $this->localRoleUser(
            'val.supervisor@test.lra',
            'Valuation Supervisor',
            'VAL',
            ['val.officer@test.lra'],
        );
    }

    private function payload(string $suffix): array
    {
        return [
            'owner_name' => 'Owner '.$suffix,
            'owner_contact' => '0770-100-110',
            'tin' => 'TIN-OWN-'.$suffix,
            'property_address' => 'Address '.$suffix.', Monrovia',
            'county' => 'Montserrado',
            'district' => 'Greater Monrovia',
            'property_classification' => 'Commercial',
            'property_type' => 'House',
            'occupancy_use' => 'Commercial',
            'discovery_date' => now()->toDateString(),
            'gps_coordinate' => '6.3130,-10.8074',
        ];
    }

    /**
     * A commercial discovery routed by the manager to the Valuation Officer, left
     * sitting at Assigned — i.e. the officer step is open and owned.
     */
    private function assignedToOfficer(string $suffix): Valuation
    {
        $this->act('account.officer@test.lra');
        $discoveryId = $this->postJson('/api/v1/discoveries', $this->payload($suffix))
            ->assertCreated()->json('data.id');
        $this->postJson("/api/v1/discoveries/{$discoveryId}/submit")->assertOk();

        $this->act('val.manager@test.lra');
        $this->postJson("/api/v1/discoveries/{$discoveryId}/val-review", [
            'decision' => 'assign',
            'officer_id' => $this->user('val.officer@test.lra')->id,
        ])->assertOk();

        return Valuation::findOrFail(Valuation::where('discovery_id', $discoveryId)->value('id'));
    }

    /**
     * Everything assertSubmittable() demands of an officer: a property photo, the
     * repeatable descriptions, then the assessment figures.
     */
    private function workIt(Valuation $valuation, string $suffix): void
    {
        $this->putJson("/api/v1/valuations/{$valuation->id}", [
            'photos' => 'proof://VAL-'.$suffix,
        ])->assertOk();
        $this->postJson("/api/v1/valuations/{$valuation->id}/descriptions", [
            'descriptions' => [[
                'description' => 'Land',
                'level' => 'Land',
                'tar' => 5000.00,
                'quantity' => 1,
            ], [
                'description' => 'Ground-floor commercial floor.',
                'level' => 'Ground Floor',
                'area_sqft' => 4500,
                'tar' => 28.0,
                'quantity' => 1,
                'amount' => 126000.00,
                'depreciation_pct' => 0,
            ]],
        ])->assertOk();
        $this->postJson("/api/v1/valuations/{$valuation->id}/submit", [
            'assessed_value' => 1200000.00,
            'annual_tax' => 42000.00,
            'applicable_tax_rate' => 3.5,
        ])->assertOk();
    }

    public function test_the_assigned_officer_owns_the_conduct_step(): void
    {
        $valuation = $this->assignedToOfficer('OWN1');

        $this->act('val.officer@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/begin")->assertOk();
        $this->putJson("/api/v1/valuations/{$valuation->id}", ['owner_contact' => '0770-100-999'])
            ->assertOk();
        $this->workIt($valuation, 'OWN1');

        $this->assertSame(Valuation::STATUS_SUBMITTED_TO_MANAGER, $valuation->fresh()->status);
    }

    public function test_a_supervisor_sees_their_staff_but_only_read_only(): void
    {
        $theirs = $this->assignedToOfficer('OWN2');
        $this->supervisor();

        // View-only across the staff they supervise: the assessment is on their desk
        // so they can follow the work.
        $this->act('val.supervisor@test.lra');
        $this->getJson("/api/v1/valuations/{$theirs->id}")->assertOk();

        $ids = collect($this->getJson('/api/v1/valuations')->assertOk()->json('data.data'))
            ->pluck('id');
        $this->assertTrue(
            $ids->contains($theirs->id),
            'A supervisor must see the valuations of the staff they supervise.'
        );

        // But seeing it is not owning it. The officer step is refused on all four
        // write paths, with the ownership message rather than a scope error, because
        // the supervisor genuinely can see the record.
        $message = 'This assessment is assigned to another Valuation Officer.';

        $this->postJson("/api/v1/valuations/{$theirs->id}/begin")
            ->assertStatus(403)->assertJsonPath('message', $message);
        $this->putJson("/api/v1/valuations/{$theirs->id}", ['owner_contact' => '0770-000-000'])
            ->assertStatus(403)->assertJsonPath('message', $message);
        $this->postJson("/api/v1/valuations/{$theirs->id}/descriptions", [
            'descriptions' => [['description' => 'Unauthorised edit.', 'amount' => 1]],
        ])->assertStatus(403)->assertJsonPath('message', $message);
        $this->postJson("/api/v1/valuations/{$theirs->id}/submit", [
            'assessed_value' => 1200000.00,
            'annual_tax' => 42000.00,
        ])->assertStatus(403)->assertJsonPath('message', $message);

        // Nor may they route it to somebody else.
        $this->postJson("/api/v1/valuations/{$theirs->id}/assign", [
            'officer_id' => $this->user('val.officer@test.lra')->id,
        ])->assertStatus(403)->assertJsonPath(
            'message',
            'You have view-only access to your staff member\'s assessment.'
        );

        $theirs->refresh();
        $this->assertSame(Valuation::STATUS_ASSIGNED, $theirs->status);
        $this->assertNull($theirs->assessed_value);
    }

    public function test_a_view_only_supervisor_may_not_review_their_staffs_assessment(): void
    {
        $theirs = $this->assignedToOfficer('OWN7');
        $this->supervisor();

        $this->act('val.officer@test.lra');
        $this->postJson("/api/v1/valuations/{$theirs->id}/begin")->assertOk();
        $this->workIt($theirs, 'OWN7');

        // The supervisor holds valuation.review and can see the submitted assessment.
        // View-only has to refuse it, or following the work would silently turn into
        // approving it.
        $this->act('val.supervisor@test.lra');
        $this->getJson("/api/v1/valuations/{$theirs->id}")->assertOk();
        $this->postJson("/api/v1/valuations/{$theirs->id}/review", [
            'decision' => 'confirm_ac',
        ])->assertStatus(403)->assertJsonPath(
            'message',
            'You have view-only access to your staff member\'s assessment.'
        );

        $this->assertSame(Valuation::STATUS_SUBMITTED_TO_MANAGER, $theirs->fresh()->status);

        // The manager still reviews it.
        $this->act('val.manager@test.lra');
        $this->postJson("/api/v1/valuations/{$theirs->id}/review", [
            'decision' => 'confirm_ac',
        ])->assertOk();

        $this->assertSame(Valuation::STATUS_SUBMITTED_TO_AC, $theirs->fresh()->status);
    }

    public function test_a_manager_carrying_the_officer_permission_still_cannot_conduct_someone_elses_assessment(): void
    {
        $valuation = $this->assignedToOfficer('OWN2');

        // The manager already holds section scope, so it can see every valuation in the
        // section. Granting it the officer permission models a manager who also covers
        // the desk — visibility and permission both pass, and ownership is the only
        // thing left that can protect the officer's assessment.
        $role = $this->user('val.manager@test.lra')->role;
        $role->grant('valuation.submit');
        $role->grant('valuation.edit');
        $this->user('val.manager@test.lra')->refresh();

        $this->act('val.manager@test.lra');
        $this->getJson("/api/v1/valuations/{$valuation->id}")->assertOk();

        $message = 'This assessment is assigned to another Valuation Officer.';

        $this->postJson("/api/v1/valuations/{$valuation->id}/begin")
            ->assertStatus(403)->assertJsonPath('message', $message);

        $this->putJson("/api/v1/valuations/{$valuation->id}", ['owner_contact' => '0770-000-000'])
            ->assertStatus(403)->assertJsonPath('message', $message);

        $this->postJson("/api/v1/valuations/{$valuation->id}/descriptions", [
            'descriptions' => [['description' => 'Unauthorised edit.', 'amount' => 1]],
        ])->assertStatus(403)->assertJsonPath('message', $message);

        $this->postJson("/api/v1/valuations/{$valuation->id}/submit", [
            'assessed_value' => 1200000.00,
            'annual_tax' => 42000.00,
        ])->assertStatus(403)->assertJsonPath('message', $message);

        // Nothing moved, and the officer's record is untouched.
        $valuation->refresh();
        $this->assertSame(Valuation::STATUS_ASSIGNED, $valuation->status);
        $this->assertNull($valuation->assessed_value, 'nobody but the officer may assess the property');
        $this->assertNull($valuation->annual_tax);
        $this->assertNull($valuation->photos, 'the property photo must not have been added for them');
        $this->assertSame(0, $valuation->descriptions()->count(), 'property descriptions must not have been written for them');
    }

    public function test_the_assigning_manager_keeps_routing_and_review_but_not_the_conduct_step(): void
    {
        $valuation = $this->assignedToOfficer('OWN3');

        // The manager's own verbs still work on a record they routed.
        $this->act('val.manager@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/assign", [
            'officer_id' => $this->user('val.officer@test.lra')->id,
        ])->assertOk();

        // The manager cannot conduct it.
        $this->postJson("/api/v1/valuations/{$valuation->id}/begin")->assertStatus(403);
        $this->postJson("/api/v1/valuations/{$valuation->id}/submit", [
            'assessed_value' => 1200000.00,
            'annual_tax' => 42000.00,
        ])->assertStatus(403);
        $this->assertSame(Valuation::STATUS_ASSIGNED, $valuation->fresh()->status);

        // The officer works it, and only then does the manager's review open.
        $this->act('val.officer@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/begin")->assertOk();
        $this->workIt($valuation, 'OWN3');

        $this->act('val.manager@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/review", [
            'decision' => 'confirm_ac',
            'remarks' => 'Assessment complete.',
        ])->assertOk();

        $this->assertSame(Valuation::STATUS_SUBMITTED_TO_AC, $valuation->fresh()->status);
    }

    public function test_the_supervisor_role_is_view_only_across_its_staff(): void
    {
        $supervisor = $this->supervisor();
        $this->assertSame(
            User::SCOPE_TEAM_VIEW,
            $supervisor->role->default_scope,
            'A supervisor sees the staff they supervise, read-only.'
        );
    }

    public function test_a_supervisor_sees_and_works_the_discoveries_routed_to_them(): void
    {
        $supervisor = $this->supervisor();

        $this->act('account.officer@test.lra');
        $discoveryId = $this->postJson('/api/v1/discoveries', $this->payload('OWN5'))
            ->assertCreated()->json('data.id');
        $this->postJson("/api/v1/discoveries/{$discoveryId}/submit")->assertOk();

        // The manager routes a discovered property to the supervisor, exactly as it
        // would to an officer.
        $this->act('val.manager@test.lra');
        $this->postJson("/api/v1/discoveries/{$discoveryId}/val-review", [
            'decision' => 'assign',
            'officer_id' => $supervisor->id,
        ])->assertOk();

        $valuation = Valuation::findOrFail(Valuation::where('discovery_id', $discoveryId)->value('id'));
        $this->assertSame($supervisor->id, $valuation->valuation_officer_id);

        $this->act('val.supervisor@test.lra');
        $this->getJson("/api/v1/valuations/{$valuation->id}")->assertOk();
        $this->postJson("/api/v1/valuations/{$valuation->id}/begin")->assertOk();
        $this->workIt($valuation, 'OWN5');

        $this->assertSame(Valuation::STATUS_SUBMITTED_TO_MANAGER, $valuation->fresh()->status);
    }

    public function test_a_supervisor_may_not_review_their_own_assessment(): void
    {
        $supervisor = $this->supervisor();

        $this->act('account.officer@test.lra');
        $discoveryId = $this->postJson('/api/v1/discoveries', $this->payload('OWN6'))
            ->assertCreated()->json('data.id');
        $this->postJson("/api/v1/discoveries/{$discoveryId}/submit")->assertOk();

        $this->act('val.manager@test.lra');
        $this->postJson("/api/v1/discoveries/{$discoveryId}/val-review", [
            'decision' => 'assign',
            'officer_id' => $supervisor->id,
        ])->assertOk();

        $valuation = Valuation::findOrFail(Valuation::where('discovery_id', $discoveryId)->value('id'));

        $this->act('val.supervisor@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/begin")->assertOk();
        $this->workIt($valuation, 'OWN6');

        // Their own scope now makes the submitted assessment visible to them, and they
        // hold valuation.review — so without the separation-of-duties guard they could
        // wave through their own assessment.
        $this->postJson("/api/v1/valuations/{$valuation->id}/review", [
            'decision' => 'confirm_ac',
        ])->assertStatus(422)->assertJsonPath(
            'message',
            'Separation of duties: you may not review an assessment you conducted yourself.'
        );

        // The manager still can.
        $this->act('val.manager@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/review", [
            'decision' => 'confirm_ac',
        ])->assertOk();

        $this->assertSame(Valuation::STATUS_SUBMITTED_TO_AC, $valuation->fresh()->status);
    }
}
