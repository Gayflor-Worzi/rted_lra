<?php

namespace Tests\Feature;

use App\Models\EvidencePhoto;
use App\Models\GpsLocationHistory;
use App\Models\PropertyBill;
use App\Models\PropertyDiscovery;
use App\Models\SyncTransaction;
use App\Models\Task;
use App\Models\User;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Guards for the two queued write-hardening items:
 *   - offline entity-create + sync-ledger commit must be ONE transaction, so a
 *     ledger failure cannot leave an orphaned entity (lost idempotency);
 *   - task transitions commit with an optimistic status guard so two concurrent
 *     transitions from the same state cannot both write transition history.
 */
class AtomicWriteGuardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function officer(): User
    {
        return User::where('email', 'enf.officer@test.lra')->firstOrFail();
    }

    private function makeBill(User $officer, string $doc, string $propertyId): PropertyBill
    {
        $bill = PropertyBill::create([
            'document_number' => $doc,
            'property_id' => $propertyId,
            'taxpayer_name' => 'Atomic Taxpayer',
            'property_classification' => 'Residential',
            'property_address' => 'Atomic Ave',
            'tax_amount' => 600,
            'interest_charged' => 0,
            'penalty_charged' => 0,
            'total_tax_due' => 600,
            'tax_period' => '2026',
            'payment_status' => 'Unpaid',
            'delivery_status' => PropertyBill::DELIVERY_STAGES[0],
            'case_status' => PropertyBill::CASE_STATUSES[0],
            'date_logged' => now()->toDateString(),
            'assigned_enforcement_officer_id' => $officer->id,
        ]);

        Task::create([
            'task_type' => Task::TYPE_BILL_DELIVERY,
            'section' => 'Enforcement',
            'reference_type' => 'property_bill',
            'reference_id' => $bill->id,
            'assigned_to' => $officer->id,
            'status' => 'Assigned',
        ]);

        return $bill;
    }

    private function ledgerSimulatedAsDown(): void
    {
        $this->app->instance(SyncService::class, new class extends SyncService
        {
            public function record(int $userId, string $localId, string $entityType, int $entityId, array $responseData): void
            {
                throw new \RuntimeException('simulated ledger outage');
            }
        });
    }

    public function test_evidence_rolls_back_when_ledger_commit_fails(): void
    {
        $officer = $this->officer();
        $bill = $this->makeBill($officer, '2026/ATOM-1', 'REG-ATOM-1');
        $this->ledgerSimulatedAsDown();

        Sanctum::actingAs($officer);

        $this->postJson('/api/v1/evidence/photos', [
            'photo_type' => 'PREMISES',
            'path' => 'device/photo.jpg',
            'task_id' => $bill->tasks()->first()->id,
            'local_transaction_id' => 'MOB-ATOM-EVIDENCE-1',
        ])->assertStatus(500);

        $this->assertSame(0, EvidencePhoto::count(), 'A failed ledger commit must not leave an orphaned photo.');
        $this->assertSame(0, SyncTransaction::count());
    }

    public function test_discovery_rolls_back_when_ledger_commit_fails(): void
    {
        $officer = $this->officer();
        $this->ledgerSimulatedAsDown();

        Sanctum::actingAs($officer);

        $this->postJson('/api/v1/discoveries', [
            'property_address' => '12 Atomic Road',
            'local_transaction_id' => 'MOB-ATOM-DISCOVERY-1',
        ])->assertStatus(500);

        $this->assertSame(0, PropertyDiscovery::count(), 'A failed ledger commit must not leave an orphaned discovery.');
        $this->assertSame(0, SyncTransaction::count());
    }

    public function test_gps_certify_rolls_back_when_ledger_commit_fails(): void
    {
        $officer = $this->officer();
        $bill = $this->makeBill($officer, '2026/ATOM-2', 'REG-ATOM-2');
        $this->ledgerSimulatedAsDown();

        Sanctum::actingAs($officer);

        $this->postJson('/api/v1/gps/certify', [
            'property_id' => 'REG-ATOM-2',
            'task_id' => $bill->tasks()->first()->id,
            'latitude' => 6.31,
            'longitude' => -10.8,
            'accuracy' => 12,
            'source' => 'enforcement_visit',
            'certification_decision' => 'no',
            'remarks' => 'Capture is not the property.',
            'local_transaction_id' => 'MOB-ATOM-GPS-1',
        ])->assertStatus(500);

        $this->assertSame(0, GpsLocationHistory::count(), 'A failed ledger commit must not leave an orphaned GPS history row.');
        $this->assertSame(0, SyncTransaction::count());
    }

    public function test_concurrent_transition_is_rejected_by_optimistic_guard(): void
    {
        $officer = $this->officer();
        $bill = $this->makeBill($officer, '2026/ATOM-3', 'REG-ATOM-3');
        $task = $bill->tasks()->first();

        // Two actors loaded the same task while it was still Assigned...
        $alpha = Task::find($task->id);
        $bravo = Task::find($task->id);

        // Alpha completes the first hop.
        $alpha->transitionTo('Out for Delivery', 'visit', $officer);
        $this->assertSame('Out for Delivery', $alpha->status);

        // Bravo still sees the stale "Assigned" copy and must be rejected.
        try {
            $bravo->transitionTo('Delivered', 'delivered', $officer);
            $this->fail('A stale concurrent transition must be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('concurrently', $e->getMessage());
        }

        $task->refresh();
        $this->assertSame('Out for Delivery', $task->status, 'The committed transition must win.');
        $this->assertSame(
            1,
            $task->history()->count(),
            'Exactly one transition history row must exist for the winning hop.',
        );
    }
}
