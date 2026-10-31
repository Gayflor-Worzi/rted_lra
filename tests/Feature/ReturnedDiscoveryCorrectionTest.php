<?php

namespace Tests\Feature;

use App\Models\PropertyDiscovery;
use App\Models\Task;
use App\Models\User;
use App\Models\Valuation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Returned for correction.
 *
 * A discovery that a reviewer sends back is the one state where the officer who
 * recorded it is the only person who can fix it, and the only one who can put it
 * back on the queue. Both halves used to be unreachable:
 *
 *  - the AC returned the valuation through ValuationsController::decide(), a path
 *    that only broadcast to the Valuation section, so the recording officer was
 *    never told their record had come back;
 *  - the discovery was editable over the API (discovery.create + recorder +
 *    Draft/Returned) but no screen offered the edit, and the only stable identity
 *    on the payload was discovered_by, which is a name on one desk and an id on
 *    another, so the client could not decide who was allowed to correct.
 *
 * These tests pin the three things the flow now depends on: the recorder is
 * notified, the recorder is the only one who can correct, and a return of the
 * *valuation* to its own officer is not mistaken for a return of the *discovery*
 * to the recording officer.
 */
class ReturnedDiscoveryCorrectionTest extends TestCase
{
    use RefreshDatabase;

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

    private function payload(string $suffix): array
    {
        return [
            'owner_name' => 'Owner '.$suffix,
            'owner_contact' => '0770-100-110',
            'tin' => 'TIN-RC-'.$suffix,
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
     * Drive a commercial discovery up to the valuation manager's desk and hand back
     * the discovery, the valuation, the recording officer and the valuation officer.
     *
     * @return array{0: PropertyDiscovery, 1: Valuation, 2: User, 3: User}
     */
    private function discoveryAtManagerDesk(string $suffix): array
    {
        $this->act('account.officer@test.lra');
        $discoveryId = $this->postJson('/api/v1/discoveries', $this->payload($suffix))
            ->assertCreated()->json('data.id');
        $this->postJson("/api/v1/discoveries/{$discoveryId}/submit")->assertOk();

        $this->act('val.manager@test.lra');
        $valOfficer = $this->user('val.officer@test.lra');
        $this->postJson("/api/v1/discoveries/{$discoveryId}/val-review", [
            'decision' => 'assign',
            'officer_id' => $valOfficer->id,
        ])->assertOk();

        $this->act('val.officer@test.lra');
        $valuationId = Valuation::where('discovery_id', $discoveryId)->value('id');
        $this->postJson("/api/v1/valuations/{$valuationId}/descriptions", [
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
        $this->putJson("/api/v1/valuations/{$valuationId}", [
            'photos' => 'proof://VAL-RC-'.$suffix,
            'owner_contact' => '0770-100-110',
        ])->assertOk();
        $this->postJson("/api/v1/valuations/{$valuationId}/submit", [
            'assessed_value' => 1200000.00,
            'annual_tax' => 42000.00,
            'applicable_tax_rate' => 3.5,
        ])->assertOk();

        return [
            PropertyDiscovery::findOrFail($discoveryId),
            Valuation::findOrFail($valuationId),
            $this->user('account.officer@test.lra'),
            $valOfficer,
        ];
    }

    /**
     * The same, forwarded to the Assistant Commissioner.
     *
     * @return array{0: PropertyDiscovery, 1: Valuation, 2: User}
     */
    private function discoveryAwaitingAc(string $suffix): array
    {
        [$discovery, $valuation, $recorder] = $this->discoveryAtManagerDesk($suffix);

        $this->act('val.manager@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/review", [
            'decision' => 'confirm_ac',
            'remarks' => 'Assessment complete.',
        ])->assertOk();

        return [$discovery->fresh(), $valuation->fresh(), $recorder];
    }

    private function notificationTo(int $userId, string $needle): bool
    {
        return DB::table('notifications')
            ->where('user_id', $userId)
            ->where('title', 'like', '%'.$needle.'%')
            ->exists();
    }

    public function test_ac_return_notifies_the_recording_officer_and_returns_the_discovery(): void
    {
        [$discovery, $valuation, $recorder] = $this->discoveryAwaitingAc('RET1');

        $this->assertSame(Valuation::STATUS_SUBMITTED_TO_AC, $valuation->status);
        $this->assertFalse($this->notificationTo($recorder->id, 'returned for correction'));

        $this->act('ac@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/decide", [
            'decision' => 'return',
            'remarks' => 'Re-check the floor area before this goes any further.',
        ])->assertOk();

        $valuation->refresh();
        $this->assertSame(Valuation::STATUS_AC_RETURNED, $valuation->status);

        $discovery->refresh();
        $this->assertSame(PropertyDiscovery::STATUS_RETURNED, $discovery->status);

        // The point of the change: the officer who recorded it is told, by name.
        $this->assertTrue(
            $this->notificationTo($recorder->id, 'returned for correction'),
            'The recording officer was not told that their discovery came back for correction.'
        );
    }

    public function test_ac_reject_also_notifies_the_recording_officer(): void
    {
        [$discovery, $valuation, $recorder] = $this->discoveryAwaitingAc('REJ1');

        $this->act('ac@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/decide", [
            'decision' => 'reject',
            'remarks' => 'This assessment is not supportable.',
        ])->assertOk();

        $this->assertTrue(
            $this->notificationTo($recorder->id, 'rejected'),
            'A rejected discovery must also tell the officer who recorded it.'
        );
    }

    public function test_presenter_exposes_a_stable_recorder_id(): void
    {
        [$discovery, , $recorder] = $this->discoveryAwaitingAc('ID01');

        $this->act('account.officer@test.lra');
        $data = $this->getJson("/api/v1/discoveries/{$discovery->id}")->assertOk()->json('data');

        // discovered_by is a name on one desk and an id on another, so it can never
        // be used to decide who may correct. The id is the stable field.
        $this->assertSame($recorder->id, $data['discovered_by_user_id']);
        $this->assertIsInt($data['discovered_by_user_id']);
    }

    public function test_recorder_can_correct_a_returned_discovery_and_resubmit_it(): void
    {
        [$discovery, $valuation, $recorder] = $this->discoveryAwaitingAc('COR1');

        $this->act('ac@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/decide", [
            'decision' => 'return',
            'remarks' => 'Correct the owner name.',
        ])->assertOk();

        // Another officer with the same permission must not be able to correct it.
        $this->act('enf.officer@test.lra');
        $this->putJson("/api/v1/discoveries/{$discovery->id}", [
            'owner_name' => 'Somebody Else Entirely',
        ])->assertForbidden();

        // The recorder can.
        $this->act('account.officer@test.lra');
        $this->putJson("/api/v1/discoveries/{$discovery->id}", [
            'owner_name' => 'Corrected Owner Name',
            'property_address' => '12 corrected Street, Monrovia',
        ])->assertOk();

        $discovery->refresh();
        $this->assertSame('Corrected Owner Name', $discovery->owner_name);
        $this->assertSame(PropertyDiscovery::STATUS_RETURNED, $discovery->status, 'Correcting must not move the record on by itself.');

        // And can put it back on the queue.
        $this->postJson("/api/v1/discoveries/{$discovery->id}/resubmit")->assertOk();
        $discovery->refresh();
        $this->assertNotSame(PropertyDiscovery::STATUS_RETURNED, $discovery->status);
    }

    public function test_returning_the_valuation_to_its_officer_is_not_a_discovery_correction(): void
    {
        [$discovery, $valuation, $recorder] = $this->discoveryAtManagerDesk('INT1');

        // The manager sends the assessment back to the valuation officer. That is a
        // correction of the valuation, so the discovery stays on the valuation desk.
        $this->act('val.manager@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/review", [
            'decision' => 'return',
            'remarks' => 'Re-verify the rate applied.',
        ])->assertOk();

        $valuation->refresh();
        $this->assertSame(Valuation::STATUS_RETURNED, $valuation->status);

        $discovery->refresh();
        $this->assertSame(
            PropertyDiscovery::STATUS_UNDER_VALUATION,
            $discovery->status,
            'A valuation-level return must not put the discovery back in the recorder\'s correction queue.'
        );
        $this->assertFalse(
            $this->notificationTo($recorder->id, 'returned for correction'),
            'The recording officer has nothing to correct when only the valuation came back.'
        );
    }

    public function test_a_returned_valuation_is_not_offered_to_the_manager_desk(): void
    {
        [$discovery, $valuation] = $this->discoveryAwaitingAc('TSK1');

        $this->act('ac@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/decide", [
            'decision' => 'return',
            'remarks' => 'Return to the recording officer.',
        ])->assertOk();

        // review() only accepts the two manager-review statuses, so once the AC has
        // returned the valuation the record belongs to the recording officer's
        // correction queue. The manager desk must not be able to pick it up again -
        // a task offering them that action is a task nobody can ever complete.
        $this->act('val.manager@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/review", [
            'decision' => 'return',
        ])->assertStatus(422);
    }
}
