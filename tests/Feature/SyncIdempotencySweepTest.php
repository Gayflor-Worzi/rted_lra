<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PropertyDiscovery;
use App\Models\SyncTransaction;
use App\Models\Task;
use App\Models\User;
use App\Models\Valuation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Gate 3 — field-client sync reliability & data resilience.
 *
 * The offline-first field app retries every local transaction until the server
 * confirms receipt. Every mobile-facing create endpoint must therefore treat a
 * resubmitted (user, local_transaction_id) as a replay and never duplicate
 * server records. This sweep proves the pattern holds beyond the evidence
 * endpoint (covered in HardeningRegressionTest) and exercises ledger hygiene:
 * distinct local ids create distinct rows, and web-style creates that carry no
 * local id are neither deduped nor pollute the ledger.
 */
class SyncIdempotencySweepTest extends TestCase
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

    private function discoveryPayload(string $address): array
    {
        return [
            'owner_name' => 'Field Taxpayer',
            'property_address' => $address,
            'property_classification' => 'Residential',
            'property_type' => 'House',
            'occupancy_use' => 'Residential',
            'county' => 'Montserrado',
            'district' => 'Greater Monrovia',
            'tin' => 'TIN-SYNC',
            'discovery_date' => now()->toDateString(),
        ];
    }

    public function test_discovery_offline_replay_is_idempotent(): void
    {
        $officer = $this->officer();
        Sanctum::actingAs($officer);

        $payload = $this->discoveryPayload('Sync sweep one') + ['local_transaction_id' => 'MOB-20260922-SWEEP-D1'];

        $first = $this->postJson('/api/v1/discoveries', $payload)->assertStatus(201);
        $replay = $this->postJson('/api/v1/discoveries', $payload)->assertStatus(200);

        $this->assertTrue($replay->json('data.replayed'));
        $this->assertSame($first->json('data.id'), $replay->json('data.id'));
        $this->assertSame(1, PropertyDiscovery::where('discovered_by', $officer->id)->count(), 'A replayed offline discovery must never duplicate.');
        $this->assertSame(1, SyncTransaction::where('user_id', $officer->id)
            ->where('local_transaction_id', 'MOB-20260922-SWEEP-D1')
            ->count());
    }

    public function test_valuation_offline_replay_is_idempotent(): void
    {
        $officer = User::where('email', 'val.officer@test.lra')->firstOrFail();
        Sanctum::actingAs($officer);

        $payload = [
            'valuation_type' => 'new_property',
            'owner_name' => 'Offline Valuation Owner',
            'property_address' => 'Offline Valuation Way',
            'local_transaction_id' => 'MOB-20260922-VALUATION-REPLAY',
            'descriptions' => [
                ['description' => 'Land', 'level' => 'Land', 'tar' => 1500, 'quantity' => 1],
            ],
        ];

        $first = $this->postJson('/api/v1/valuations', $payload)->assertStatus(201);
        $replay = $this->postJson('/api/v1/valuations', $payload)
            ->assertOk()
            ->assertJsonPath('data.replayed', true);

        $valuationId = $first->json('data.id');
        $this->assertSame($valuationId, $replay->json('data.id'));
        $this->assertSame(1, Valuation::where('owner_name', 'Offline Valuation Owner')->count());
        $this->assertSame(1, Task::where('reference_type', 'valuation')->where('reference_id', $valuationId)->count());
        $this->assertSame(1, SyncTransaction::where('user_id', $officer->id)
            ->where('local_transaction_id', 'MOB-20260922-VALUATION-REPLAY')
            ->where('entity_type', 'valuation')
            ->count());
        $this->assertSame(1, AuditLog::where('action', 'valuation.created')
            ->where('auditable_type', Valuation::class)
            ->where('auditable_id', $valuationId)
            ->count());
        $this->getJson('/api/v1/sync/transactions')
            ->assertOk()
            ->assertJsonPath('data.counts.valuation', 1);
    }

    public function test_task_engagement_offline_replay_is_idempotent(): void
    {
        $officer = $this->officer();
        $task = Task::create([
            'task_type' => Task::TYPE_BILL_DELIVERY,
            'section' => 'Enforcement',
            'assigned_to' => $officer->id,
            'assigned_by' => $officer->id,
            'priority' => 'Normal',
            'status' => 'Assigned',
        ]);
        Sanctum::actingAs($officer);

        $payload = [
            'engagement_type' => 'follow_up',
            'outcome' => 'no_contact',
            'notes' => 'Attempt recorded offline.',
            'occurred_at' => now()->toISOString(),
            'local_transaction_id' => 'MOB-20261103-ENGAGEMENT-REPLAY',
        ];

        $first = $this->postJson("/api/v1/tasks/{$task->id}/engagements", $payload)->assertCreated();
        $replay = $this->postJson("/api/v1/tasks/{$task->id}/engagements", $payload)
            ->assertOk()
            ->assertJsonPath('data.replayed', true);

        $this->assertSame($first->json('data.id'), $replay->json('data.id'));
        $this->assertSame(1, $task->engagements()->count());
        $this->assertSame(1, SyncTransaction::where('user_id', $officer->id)
            ->where('local_transaction_id', 'MOB-20261103-ENGAGEMENT-REPLAY')
            ->where('entity_type', 'engagement')
            ->count());
    }

    public function test_distinct_local_ids_create_distinct_rows(): void
    {
        $officer = $this->officer();
        Sanctum::actingAs($officer);

        $this->postJson('/api/v1/discoveries', $this->discoveryPayload('Sync sweep two')
            + ['local_transaction_id' => 'MOB-20260922-SWEEP-D2'])
            ->assertStatus(201);

        $this->postJson('/api/v1/discoveries', $this->discoveryPayload('Sync sweep three')
            + ['local_transaction_id' => 'MOB-20260922-SWEEP-D3'])
            ->assertStatus(201);

        $this->assertSame(2, PropertyDiscovery::where('discovered_by', $officer->id)->count());
        $this->assertSame(2, SyncTransaction::where('user_id', $officer->id)->count());
    }

    public function test_web_style_create_without_local_id_is_neither_deduped_nor_ledgered(): void
    {
        $officer = $this->officer();
        Sanctum::actingAs($officer);

        $this->postJson('/api/v1/discoveries', $this->discoveryPayload('Sync sweep web'))->assertStatus(201);
        $this->postJson('/api/v1/discoveries', $this->discoveryPayload('Sync sweep web'))->assertStatus(201);

        // Two identical web submissions are two records (no device id to dedupe on)...
        $this->assertSame(2, PropertyDiscovery::where('discovered_by', $officer->id)->count());

        // ...and they must NOT have written ledger rows keyed on an empty id.
        $this->assertSame(0, SyncTransaction::where('user_id', $officer->id)
            ->where('local_transaction_id', '')
            ->count());
    }

    public function test_same_local_id_is_isolated_by_authenticated_account(): void
    {
        $officerA = $this->officer();
        $officerB = $officerA->replicate();
        $officerB->email = 'enf.officer.second@test.lra';
        $officerB->staff_id = 'SYNC-B';
        $officerB->save();
        $localId = 'MOB-20260922-ACCOUNT-ISOLATION';

        Sanctum::actingAs($officerA);
        $aResponse = $this->postJson('/api/v1/discoveries', $this->discoveryPayload('Account A property')
            + ['local_transaction_id' => $localId])
            ->assertStatus(201);
        $aDiscoveryId = $aResponse->json('data.id');

        Sanctum::actingAs($officerB);
        $this->getJson('/api/v1/sync/transactions')
            ->assertOk()
            ->assertJsonPath('data.counts.discovery', 0)
            ->assertJsonPath('data.transactions', []);

        $bResponse = $this->postJson('/api/v1/discoveries', $this->discoveryPayload('Account B property')
            + ['local_transaction_id' => $localId, 'user_id' => $officerA->id])
            ->assertStatus(201);
        $bDiscoveryId = $bResponse->json('data.id');

        $this->assertNotSame($aDiscoveryId, $bDiscoveryId);
        $this->assertSame($officerA->id, PropertyDiscovery::findOrFail($aDiscoveryId)->discovered_by);
        $this->assertSame($officerB->id, PropertyDiscovery::findOrFail($bDiscoveryId)->discovered_by);
        $this->assertSame(1, SyncTransaction::where('user_id', $officerA->id)
            ->where('local_transaction_id', $localId)->count());
        $this->assertSame(1, SyncTransaction::where('user_id', $officerB->id)
            ->where('local_transaction_id', $localId)->count());

        Sanctum::actingAs($officerA);
        $this->postJson('/api/v1/discoveries', $this->discoveryPayload('Account A retry')
            + ['local_transaction_id' => $localId])
            ->assertOk()
            ->assertJsonPath('data.replayed', true)
            ->assertJsonPath('data.id', $aDiscoveryId);
        $this->getJson('/api/v1/sync/transactions')
            ->assertOk()
            ->assertJsonPath('data.counts.discovery', 1)
            ->assertJsonCount(1, 'data.transactions')
            ->assertJsonPath('data.transactions.0.entity_id', $aDiscoveryId);
    }

    public function test_unauthenticated_client_cannot_submit_or_read_sync_transactions(): void
    {
        $this->postJson('/api/v1/discoveries', $this->discoveryPayload('Unauthenticated property')
            + ['local_transaction_id' => 'MOB-UNAUTHENTICATED'])
            ->assertUnauthorized();

        $this->getJson('/api/v1/sync/transactions')->assertUnauthorized();

        $this->assertSame(0, PropertyDiscovery::where('property_address', 'Unauthenticated property')->count());
        $this->assertSame(0, SyncTransaction::where('local_transaction_id', 'MOB-UNAUTHENTICATED')->count());
    }
}
