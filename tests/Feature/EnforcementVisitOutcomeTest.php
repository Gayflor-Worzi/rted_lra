<?php

namespace Tests\Feature;

use App\Models\EnforcementVisit;
use App\Models\PaymentVerification;
use App\Models\PropertyBill;
use App\Models\SyncTransaction;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EnforcementVisitOutcomeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function fieldTask(string $document): array
    {
        $officer = User::where('email', 'enf.officer@test.lra')->firstOrFail();
        $bill = PropertyBill::create([
            'document_number' => $document,
            'property_id' => 'P-'.$document,
            'taxpayer_name' => 'Visit outcome taxpayer',
            'tin' => 'TIN-'.$document,
            'property_address' => 'Visit outcome address',
            'delivery_status' => 'Logged',
            'case_status' => 'Assigned',
            'payment_status' => 'Unpaid',
            'date_logged' => now()->toDateString(),
            'tax_amount' => 100,
            'total_tax_due' => 100,
            'outstanding_balance' => 100,
            'assigned_enforcement_officer_id' => $officer->id,
        ]);
        $task = Task::create([
            'task_type' => Task::TYPE_BILL_DELIVERY,
            'section' => 'Enforcement',
            'reference_type' => 'property_bill',
            'reference_id' => $bill->id,
            'assigned_to' => $officer->id,
            'status' => 'Assigned',
        ]);

        return [$officer, $bill, $task];
    }

    public function test_unsuccessful_visit_does_not_complete_or_deliver_the_bill_task(): void
    {
        [$officer, $bill, $task] = $this->fieldTask('VISIT-UNDELIVERED');
        Sanctum::actingAs($officer);

        $this->postJson('/api/v1/enforcement-visits', [
            'task_id' => $task->id,
            'bill_id' => $bill->id,
            'status' => 'Premises Locked',
            'delivery_status' => EnforcementVisit::DELIVERY_UNDELIVERED,
        ])->assertCreated();

        $this->assertSame(EnforcementVisit::DELIVERY_UNDELIVERED, $bill->fresh()->delivery_status);
        $this->assertSame('Out for Delivery', $bill->fresh()->case_status);
        $this->assertSame('Out for Delivery', $task->fresh()->status);
        $this->assertDatabaseHas('enforcement_visits', [
            'task_id' => $task->id,
            'delivery_status' => EnforcementVisit::DELIVERY_UNDELIVERED,
        ]);

        $this->postJson("/api/v1/enforcement-assignments/{$task->id}/action", ['action' => 'completed'])
            ->assertStatus(422);
        $this->assertSame('Out for Delivery', $task->fresh()->status);
    }

    public function test_delivered_visit_completes_the_task_and_bill(): void
    {
        [$officer, $bill, $task] = $this->fieldTask('VISIT-DELIVERED');
        Sanctum::actingAs($officer);

        $this->postJson('/api/v1/enforcement-visits', [
            'task_id' => $task->id,
            'bill_id' => $bill->id,
            'status' => 'Visited - Delivered',
            'delivery_status' => EnforcementVisit::DELIVERY_DELIVERED,
        ])->assertCreated();

        $this->assertSame('Delivered', $task->fresh()->status);
        $this->assertSame('Delivered', $bill->fresh()->case_status);
        $this->assertSame('Delivered', $bill->fresh()->delivery_status);
        $this->assertNotNull($bill->fresh()->delivery_date);
    }

    public function test_legacy_returned_to_office_value_is_normalized_to_returned(): void
    {
        [$officer, $bill, $task] = $this->fieldTask('VISIT-LEGACY-RETURN');
        Sanctum::actingAs($officer);

        $this->postJson('/api/v1/enforcement-visits', [
            'task_id' => $task->id,
            'bill_id' => $bill->id,
            'status' => 'Premises Locked',
            'delivery_status' => 'Returned to Office',
        ])->assertCreated();

        $this->assertSame(EnforcementVisit::DELIVERY_RETURNED, $bill->visits()->latest('id')->value('delivery_status'));
        $this->assertSame(EnforcementVisit::DELIVERY_RETURNED, $bill->fresh()->delivery_status);
    }

    public function test_offline_visit_with_payment_claim_replays_without_duplicate_claim_or_payment(): void
    {
        [$officer, $bill, $task] = $this->fieldTask('VISIT-OFFLINE-CLAIM');
        Sanctum::actingAs($officer);
        Storage::fake('local');
        $payload = [
            'assignment_id' => $task->id,
            'property_bill_id' => $bill->id,
            'status' => 'Visited - Payment Follow-up',
            'delivery_status' => EnforcementVisit::DELIVERY_UNDELIVERED,
            'gps_lat' => 6.3001,
            'gps_lng' => -10.8001,
            'claim_receipt_number' => 'REC-VISIT-OFFLINE-1',
            'claim_amount' => 50,
            'claim_receipt_photo' => 'data:image/jpeg;base64,ZmFrZS1yZWNlaXB0',
            'local_transaction_id' => 'MOB-VISIT-OFFLINE-CLAIM-1',
        ];

        $first = $this->postJson('/api/v1/enforcement-visits', $payload)
            ->assertCreated()
            ->assertJsonPath('data.bill_id', $bill->id);
        $replay = $this->postJson('/api/v1/enforcement-visits', $payload)
            ->assertOk()
            ->assertJsonPath('data.replayed', true);

        $this->assertSame($first->json('data.id'), $replay->json('data.id'));
        $this->assertSame(1, EnforcementVisit::where('bill_id', $bill->id)->count());
        $this->assertSame(1, PaymentVerification::where('bill_id', $bill->id)->count());
        $this->assertSame('Pending', PaymentVerification::where('bill_id', $bill->id)->value('verification_status'));
        $this->assertSame(0, $bill->payments()->count());
        $this->assertSame(1, SyncTransaction::where('user_id', $officer->id)
            ->where('local_transaction_id', 'MOB-VISIT-OFFLINE-CLAIM-1')
            ->where('entity_type', 'visit')
            ->count());
    }

    public function test_on_the_spot_payment_claim_requires_receipt_evidence(): void
    {
        [$officer, $bill, $task] = $this->fieldTask('VISIT-CLAIM-NO-PHOTO');
        Sanctum::actingAs($officer);

        $this->postJson('/api/v1/enforcement-visits', [
            'assignment_id' => $task->id,
            'property_bill_id' => $bill->id,
            'status' => 'Visited - Payment Follow-up',
            'claim_receipt_number' => 'REC-VISIT-NO-PHOTO',
            'claim_amount' => 50,
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Receipt evidence is required for an on-the-spot payment claim.');

        $this->assertSame(0, EnforcementVisit::where('bill_id', $bill->id)->count());
        $this->assertSame(0, PaymentVerification::where('bill_id', $bill->id)->count());
    }
}
