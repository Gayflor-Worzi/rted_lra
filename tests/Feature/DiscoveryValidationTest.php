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
 * A discovery is validated for the fields routing actually depends on.
 *
 * Owner Name and Property Classification are consumed downstream: the owner is
 * the taxpayer on the valuation and the bill, and the classification chooses the
 * desk. Both were nullable all the way through submission, so an incomplete
 * record could reach Valuation and produce records nobody could match to a
 * taxpayer.
 *
 * The TIN is deliberately NOT required: the source does not always provide one,
 * and inventing it would corrupt the bill register. Its absence is recorded as an
 * explicit gap instead.
 */
class DiscoveryValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function officer(): User
    {
        return User::where('email', 'account.officer@test.lra')->firstOrFail();
    }

    private function draft(array $overrides = []): PropertyDiscovery
    {
        Sanctum::actingAs($this->officer());

        $id = $this->postJson('/api/v1/discoveries', array_merge([
            'owner_name' => 'Address Owner',
            'property_address' => '12 Located Road, Monrovia',
            'property_classification' => 'Residential',
            'property_type' => 'House',
            'occupancy_use' => 'Residential',
            'county' => 'Montserrado',
            'district' => 'Greater Monrovia',
            'tin' => 'TIN-DV-1',
            'discovery_date' => now()->toDateString(),
        ], $overrides))->assertCreated()->json('data.id');

        return PropertyDiscovery::findOrFail($id);
    }

    private function auditActions(PropertyDiscovery $discovery): array
    {
        return DB::table('audit_logs')
            ->where('auditable_type', $discovery->getMorphClass())
            ->where('auditable_id', $discovery->id)
            ->pluck('action')
            ->all();
    }

    public function test_submission_requires_an_owner_name(): void
    {
        $discovery = $this->draft(['owner_name' => null]);

        $this->postJson("/api/v1/discoveries/{$discovery->id}/submit")
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Owner Name'));

        // The failed submission leaves no queue work behind: still a draft, no task.
        $this->assertSame(PropertyDiscovery::STATUS_DRAFT, $discovery->fresh()->status);
        $this->assertSame(0, Task::where('reference_type', 'property_discovery')->where('reference_id', $discovery->id)->count());
    }

    public function test_submission_requires_a_property_classification(): void
    {
        $discovery = $this->draft(['property_classification' => null]);

        $this->postJson("/api/v1/discoveries/{$discovery->id}/submit")
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Property Classification'));

        $this->assertSame(PropertyDiscovery::STATUS_DRAFT, $discovery->fresh()->status);
        $this->assertSame(0, Valuation::count());
    }

    public function test_an_absent_tin_does_not_block_routing_but_is_flagged(): void
    {
        $discovery = $this->draft(['tin' => null]);

        $this->postJson("/api/v1/discoveries/{$discovery->id}/submit")->assertOk();

        $fresh = $discovery->fresh();
        $this->assertSame(PropertyDiscovery::STATUS_AWAITING_ACCT_REVIEW, $fresh->status);
        $this->assertNull($fresh->tin, 'A missing TIN must never be replaced with an invented value.');

        $this->assertContains(
            'discovery.missing_information_flagged',
            $this->auditActions($fresh),
            'The missing TIN must be an explicit, traceable gap.'
        );
    }

    public function test_a_complete_discovery_routes_normally(): void
    {
        $discovery = $this->draft();

        $this->postJson("/api/v1/discoveries/{$discovery->id}/submit")->assertOk();

        $fresh = $discovery->fresh();
        $this->assertSame(PropertyDiscovery::STATUS_AWAITING_ACCT_REVIEW, $fresh->status);
        $this->assertSame(1, Task::where('reference_type', 'property_discovery')
            ->where('reference_id', $discovery->id)
            ->whereNotIn('status', Task::COMPLETED_STATUSES)
            ->count());
        $this->assertNotContains('discovery.missing_information_flagged', $this->auditActions($fresh));
    }

    public function test_a_returned_discovery_cannot_be_resubmitted_without_an_owner(): void
    {
        $discovery = $this->draft();

        // A returned record must still identify its owner before going back on the
        // queue (the correction form can clear a field it should not).
        $discovery->update([
            'status' => PropertyDiscovery::STATUS_RETURNED,
            'owner_name' => null,
        ]);

        $this->postJson("/api/v1/discoveries/{$discovery->id}/resubmit")
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Owner Name'));

        $this->assertSame(PropertyDiscovery::STATUS_RETURNED, $discovery->fresh()->status);
    }
}
