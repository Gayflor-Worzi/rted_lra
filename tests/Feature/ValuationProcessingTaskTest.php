<?php

namespace Tests\Feature;

use App\Models\PropertyBill;
use App\Models\Task;
use App\Models\User;
use App\Models\Valuation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Recording a standalone valuation's registration finishes the processing task.
 *
 * The discovery-side processing step closes the Account & Record Processing task
 * it completes. The valuation-side step recorded the same engagement but left the
 * task open, so a registered property kept sitting in the officer's Active list
 * as work they still owed.
 */
class ValuationProcessingTaskTest extends TestCase
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

    /** An approved standalone reassessment, assigned to the account officer for processing. */
    private function valuationAwaitingProcessing(): Valuation
    {
        $this->act('account.officer@test.lra');
        $this->postJson('/api/v1/property-bills', [
            'document_number' => '2026/PRC-1',
            'property_id' => 'PRC-P-1',
            'taxpayer_name' => 'Processing Taxpayer',
            'tin' => 'TIN-PRC-1',
            'property_address' => '7 Processing Road, Monrovia',
            'tax_amount' => 5000.00,
            'tax_period' => '2026',
        ])->assertCreated();

        $bill = PropertyBill::where('document_number', '2026/PRC-1')->firstOrFail();

        $this->act('val.officer@test.lra');
        $valuationId = $this->postJson('/api/v1/valuations', [
            'valuation_type' => 'reassessment',
            'bill_id' => $bill->id,
            'property_id' => $bill->property_id,
            'document_number' => $bill->document_number,
            'owner_name' => 'Processing Taxpayer',
            'owner_contact' => '0770-100-140',
            'tin' => $bill->tin,
            'property_classification' => 'Commercial',
            'property_address' => $bill->property_address,
            'assessment_date' => now()->toDateString(),
            'gps_coordinate' => '6.3130,-10.8074',
            'photos' => 'proof://VAL-PRC-1',
            'descriptions' => [[
                'description' => 'Land',
                'level' => 'Land',
                'tar' => 5000.00,
                'quantity' => 1,
            ]],
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/valuations/{$valuationId}/submit", [
            'assessed_value' => 1200000.00,
            'annual_tax' => 42000.00,
            'applicable_tax_rate' => 3.5,
        ])->assertOk();

        $this->act('val.manager@test.lra');
        $this->postJson("/api/v1/valuations/{$valuationId}/review", ['decision' => 'confirm_ac'])->assertOk();

        $this->act('ac@test.lra');
        $this->postJson("/api/v1/valuations/{$valuationId}/decide", ['decision' => 'approve'])->assertOk();

        $this->act('account.manager@test.lra');
        $this->postJson("/api/v1/valuations/{$valuationId}/account-review", [
            'decision' => 'approve',
            'officer_id' => $this->user('account.officer@test.lra')->id,
        ])->assertOk();

        return Valuation::findOrFail($valuationId);
    }

    public function test_recording_the_registration_closes_the_processing_task(): void
    {
        $valuation = $this->valuationAwaitingProcessing();
        $officer = $this->user('account.officer@test.lra');

        $task = Task::where('task_type', Task::TYPE_ACCOUNT_PROCESSING)
            ->where('reference_type', 'valuation')
            ->where('reference_id', $valuation->id)
            ->firstOrFail();

        $this->assertSame($officer->id, $task->assigned_to);
        $this->assertNotContains($task->status, Task::COMPLETED_STATUSES, 'Processing is still open work until it is recorded.');

        $this->act('account.officer@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/processing", [
            'property_id' => 'PRC-P-1',
            'document_number' => '2026/PRC-1',
        ])->assertOk();

        $task->refresh();

        $this->assertSame('Closed', $task->status);
        $this->assertSame($officer->id, $task->completed_by);
        $this->assertNotNull($task->completed_at);
        $this->assertSame(1, $task->engagements()->where('outcome', 'registration_completed')->count());
        $this->assertSame('Closed', $task->history()->latest('id')->first()->to_status);

        // No processing work remains in the Active list for this valuation.
        $this->assertSame(0, Task::where('task_type', Task::TYPE_ACCOUNT_PROCESSING)
            ->where('reference_type', 'valuation')
            ->where('reference_id', $valuation->id)
            ->whereNotIn('status', Task::COMPLETED_STATUSES)
            ->count());
    }
}
