<?php

namespace Tests\Feature;

use App\Models\EnforcementVisit;
use App\Models\PropertyBill;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
