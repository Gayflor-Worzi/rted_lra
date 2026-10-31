<?php

namespace Tests\Feature;

use App\Models\PropertyDiscovery;
use App\Models\Task;
use App\Models\User;
use App\Models\Valuation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * One property, one assessment, one approval path.
 *
 * The high-severity defects these tests pin were all the same mistake in three
 * places: a step that continues existing work treated the record as if it had
 * never existed.
 *
 *  1. Re-routing a discovery that came back from the Assistant Commissioner
 *     created a SECOND valuation for the one discovery, orphaning the first.
 *  2. An AC-returned assessment could be assigned to an officer who then could
 *     not submit it — the task offered work the API refused.
 *  3. An AC-approved discovery could be approved again at the Account & Record
 *     desk, raising a competing processing task and skipping the Account &
 *     Record Manager's review of the valuation, which is the authoritative one.
 *
 * AC Rejected stays terminal: a rejected property is not silently revived by a
 * resubmission.
 */
class ValuationWorkflowContinuityTest extends TestCase
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

    /**
     * A commercial discovery submitted and assessed, sitting at the manager's desk.
     *
     * @return array{0: PropertyDiscovery, 1: Valuation}
     */
    private function discoveryAtManagerDesk(string $suffix): array
    {
        $this->act('account.officer@test.lra');
        $discoveryId = $this->postJson('/api/v1/discoveries', [
            'owner_name' => 'Owner '.$suffix,
            'owner_contact' => '0770-100-110',
            'tin' => 'TIN-VC-'.$suffix,
            'property_address' => 'Address '.$suffix.', Monrovia',
            'county' => 'Montserrado',
            'district' => 'Greater Monrovia',
            'property_classification' => 'Commercial',
            'property_type' => 'House',
            'occupancy_use' => 'Commercial',
            'discovery_date' => now()->toDateString(),
            'gps_coordinate' => '6.3130,-10.8074',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/discoveries/{$discoveryId}/submit")->assertOk();

        $this->act('val.manager@test.lra');
        $this->postJson("/api/v1/discoveries/{$discoveryId}/val-review", [
            'decision' => 'assign',
            'officer_id' => $this->user('val.officer@test.lra')->id,
        ])->assertOk();

        $valuation = Valuation::where('discovery_id', $discoveryId)->firstOrFail();

        $this->act('val.officer@test.lra');
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

        $this->putJson("/api/v1/valuations/{$valuation->id}", [
            'photos' => 'proof://VAL-VC-'.$suffix,
            'owner_contact' => '0770-100-110',
        ])->assertOk();

        $this->postJson("/api/v1/valuations/{$valuation->id}/submit", [
            'assessed_value' => 1200000.00,
            'annual_tax' => 42000.00,
            'applicable_tax_rate' => 3.5,
        ])->assertOk();

        return [PropertyDiscovery::findOrFail($discoveryId), $valuation->fresh()];
    }

    /** @return array{0: PropertyDiscovery, 1: Valuation} */
    private function discoveryAwaitingAc(string $suffix): array
    {
        [$discovery, $valuation] = $this->discoveryAtManagerDesk($suffix);

        $this->act('val.manager@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/review", [
            'decision' => 'confirm_ac',
            'remarks' => 'Assessment complete.',
        ])->assertOk();

        return [$discovery->fresh(), $valuation->fresh()];
    }

    /* ------------------------------------------------------------------ */
    /* 3.1  Re-routing continues the assessment, it does not duplicate it */
    /* ------------------------------------------------------------------ */

    public function test_rerouting_a_returned_discovery_reuses_its_assessment(): void
    {
        [$discovery, $valuation] = $this->discoveryAwaitingAc('RTN1');

        $this->act('ac@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/decide", [
            'decision' => 'return',
            'remarks' => 'Re-check the floor area.',
        ])->assertOk();

        $this->assertSame(PropertyDiscovery::STATUS_RETURNED, $discovery->fresh()->status);

        // The recorder corrects and puts the discovery back on the queue.
        $this->act('account.officer@test.lra');
        $this->postJson("/api/v1/discoveries/{$discovery->id}/resubmit")->assertOk();

        // The Valuation Manager routes it again.
        $this->act('val.manager@test.lra');
        $this->postJson("/api/v1/discoveries/{$discovery->id}/val-review", [
            'decision' => 'assign',
            'officer_id' => $this->user('val.officer@test.lra')->id,
        ])->assertOk()->assertJsonPath('data.valuation.valuation_reference', $valuation->valuation_reference);

        $this->assertSame(
            1,
            Valuation::where('discovery_id', $discovery->id)->count(),
            'One discovery must never end up with two assessments.'
        );

        $reopened = Valuation::findOrFail($valuation->id);
        $this->assertSame(Valuation::STATUS_ASSIGNED, $reopened->status, 'The returned assessment is reopened for the officer.');
        $this->assertSame($this->user('val.officer@test.lra')->id, $reopened->valuation_officer_id);

        $discovery->refresh();
        $this->assertSame($valuation->id, $discovery->valuation_id, 'The discovery keeps pointing at the same assessment.');
        $this->assertSame(PropertyDiscovery::STATUS_UNDER_VALUATION, $discovery->status);

        // One officer task for the assessment, not one per routing round.
        $this->assertSame(
            1,
            Task::where('task_type', Task::TYPE_VALUATION)
                ->where('reference_type', 'valuation')
                ->where('reference_id', $valuation->id)
                ->whereNull('completed_at')
                ->count()
        );
    }

    /* ------------------------------------------------------------------ */
    /* 3.2  An AC-returned assessment is correctable, not a dead end */
    /* ------------------------------------------------------------------ */

    public function test_assigning_an_ac_returned_assessment_reopens_it_for_correction(): void
    {
        [$discovery, $valuation] = $this->discoveryAwaitingAc('ACR1');

        $this->act('ac@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/decide", [
            'decision' => 'return',
            'remarks' => 'Correct the rate.',
        ])->assertOk();

        $this->assertSame(Valuation::STATUS_AC_RETURNED, $valuation->fresh()->status);

        // Reassigning it must hand the officer work they can actually do.
        $this->act('val.manager@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/assign", [
            'officer_id' => $this->user('val.officer@test.lra')->id,
        ])->assertOk();

        $this->assertSame(Valuation::STATUS_ASSIGNED, $valuation->fresh()->status);

        $this->act('val.officer@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/submit", [
            'assessed_value' => 1250000.00,
            'annual_tax' => 43750.00,
            'applicable_tax_rate' => 3.5,
        ])->assertOk();

        $this->assertSame(Valuation::STATUS_SUBMITTED_TO_MANAGER, $valuation->fresh()->status);
        $this->assertSame($valuation->valuation_reference, $valuation->fresh()->valuation_reference, 'Correcting keeps the same Valuation ID and reference.');
        $this->assertSame(1, Valuation::where('discovery_id', $discovery->id)->count());
    }

    public function test_ac_rejected_property_remains_terminal(): void
    {
        [$discovery, $valuation] = $this->discoveryAwaitingAc('REJ9');

        $this->act('ac@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/decide", [
            'decision' => 'reject',
            'remarks' => 'Not supportable.',
        ])->assertOk();

        $this->assertSame(Valuation::STATUS_AC_REJECTED, $valuation->fresh()->status);
        $this->assertSame(PropertyDiscovery::STATUS_REJECTED, $discovery->fresh()->status);

        // A rejected property is not quietly revived by resubmission or re-routing.
        $this->act('account.officer@test.lra');
        $this->postJson("/api/v1/discoveries/{$discovery->id}/resubmit")->assertStatus(422);

        $this->act('val.manager@test.lra');
        $this->postJson("/api/v1/discoveries/{$discovery->id}/val-review", [
            'decision' => 'assign',
            'officer_id' => $this->user('val.officer@test.lra')->id,
        ])->assertStatus(422);

        $this->assertSame(1, Valuation::where('discovery_id', $discovery->id)->count());
    }

    /* ------------------------------------------------------------------ */
    /* 3.3  One Account & Record approval path */
    /* ------------------------------------------------------------------ */

    public function test_an_ac_approved_discovery_cannot_be_approved_again_at_the_account_desk(): void
    {
        [$discovery, $valuation] = $this->discoveryAwaitingAc('APR1');

        $this->act('ac@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/decide", [
            'decision' => 'approve',
            'remarks' => 'Approved.',
        ])->assertOk();

        $this->assertSame(PropertyDiscovery::STATUS_APPROVED, $discovery->fresh()->status);

        $officer = $this->user('account.officer@test.lra');

        // The discovery desk must not hand out a second processing assignment: the
        // Account & Record Manager's review of the valuation owns that decision.
        $this->act('account.manager@test.lra');
        $this->postJson("/api/v1/discoveries/{$discovery->id}/acct-review", [
            'decision' => 'approve',
            'officer_id' => $officer->id,
        ])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'managed on the valuation record'));

        $this->assertSame(PropertyDiscovery::STATUS_APPROVED, $discovery->fresh()->status);
        $this->assertSame(
            0,
            Task::where('task_type', Task::TYPE_ACCOUNT_PROCESSING)
                ->where('reference_type', 'property_discovery')
                ->where('reference_id', $discovery->id)
                ->count(),
            'The blocked approval must not leave a processing task behind.'
        );

        // The authoritative path is still open, and it is the one that assigns.
        $this->postJson("/api/v1/valuations/{$valuation->id}/account-review", [
            'decision' => 'approve',
            'officer_id' => $officer->id,
        ])->assertOk();

        $this->assertSame(Valuation::STATUS_SENT_TO_ACCT, $valuation->fresh()->status);
        $this->assertSame(1, Task::where('task_type', Task::TYPE_ACCOUNT_PROCESSING)
            ->where('reference_type', 'property_discovery')
            ->where('reference_id', $discovery->id)
            ->whereNull('completed_at')
            ->count());
    }
}
