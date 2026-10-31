<?php

namespace Tests\Feature;

use App\Models\PropertyDiscovery;
use App\Models\User;
use App\Models\Valuation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * "Conduct personally" is offered only to users who can actually conduct it.
 *
 * The mobile Valuation Manager panel offered the self option to anyone holding
 * discovery.route_to_valuation. begin() and submit() both require
 * valuation.submit, which the Valuation Manager role does not hold, so choosing
 * it handed the manager an assessment they could never start or finish.
 *
 * The server now rejects it and the client stops offering it; the web panel
 * already used the same eligibility rule.
 */
class ValuationAssignmentEligibilityTest extends TestCase
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

    /** A commercial discovery submitted and waiting for the Valuation Manager. */
    private function discoveryAwaitingValuation(string $suffix = 'VAE'): PropertyDiscovery
    {
        $this->act('account.officer@test.lra');

        $id = $this->postJson('/api/v1/discoveries', [
            'owner_name' => 'Owner '.$suffix,
            'owner_contact' => '0770-100-160',
            'tin' => 'TIN-'.$suffix,
            'property_address' => 'Address '.$suffix.', Monrovia',
            'county' => 'Montserrado',
            'district' => 'Greater Monrovia',
            'property_classification' => 'Commercial',
            'property_type' => 'House',
            'occupancy_use' => 'Commercial',
            'discovery_date' => now()->toDateString(),
            'gps_coordinate' => '6.3130,-10.8074',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/discoveries/{$id}/submit")->assertOk();

        return PropertyDiscovery::findOrFail($id);
    }

    public function test_a_routing_manager_without_valuation_submit_cannot_conduct_personally(): void
    {
        $discovery = $this->discoveryAwaitingValuation();

        $manager = $this->user('val.manager@test.lra');
        $this->assertTrue($manager->canPermission('discovery.route_to_valuation'));
        $this->assertFalse($manager->canPermission('valuation.submit'));

        $this->act('val.manager@test.lra');
        $this->postJson("/api/v1/discoveries/{$discovery->id}/val-review", [
            'decision' => 'self',
        ])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'valuation.submit'));

        // Nothing was assigned, so no unfinishable assessment exists.
        $this->assertSame(0, Valuation::count());
        $this->assertSame(PropertyDiscovery::STATUS_AWAITING_VALUATION, $discovery->fresh()->status);
        $this->assertNull($discovery->fresh()->valuation_id);
    }

    public function test_a_manager_who_can_submit_may_conduct_personally_and_begin_the_work(): void
    {
        $discovery = $this->discoveryAwaitingValuation('VAE2');

        $role = $this->user('val.manager@test.lra')->role;
        $role->grant('valuation.submit');
        $manager = $this->user('val.manager@test.lra')->fresh();

        $this->act('val.manager@test.lra');
        $this->postJson("/api/v1/discoveries/{$discovery->id}/val-review", [
            'decision' => 'self',
        ])->assertOk();

        $valuation = Valuation::where('discovery_id', $discovery->id)->firstOrFail();
        $this->assertSame($manager->id, $valuation->valuation_officer_id);

        // The assignment is truthful: the officer can start the assessment.
        $this->postJson("/api/v1/valuations/{$valuation->id}/begin")->assertOk();
        $this->assertSame(Valuation::STATUS_ASSESSMENT_IN_PROGRESS, $valuation->fresh()->status);
    }

    public function test_assigning_an_eligible_officer_is_unchanged(): void
    {
        $discovery = $this->discoveryAwaitingValuation('VAE3');

        $this->act('val.manager@test.lra');
        $this->postJson("/api/v1/discoveries/{$discovery->id}/val-review", [
            'decision' => 'assign',
            'officer_id' => $this->user('val.officer@test.lra')->id,
        ])->assertOk();

        $valuation = Valuation::where('discovery_id', $discovery->id)->firstOrFail();
        $this->assertSame($this->user('val.officer@test.lra')->id, $valuation->valuation_officer_id);
        $this->assertSame(Valuation::STATUS_ASSIGNED, $valuation->status);
    }
}
