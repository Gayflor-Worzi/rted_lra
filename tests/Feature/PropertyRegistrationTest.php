<?php

namespace Tests\Feature;

use App\Models\PropertyBill;
use App\Models\PropertyDiscovery;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Completing property registration (spec §14) must:
 *   - automatically turn the assigned registration task into a completed task, and
 *   - log the registered property as a bill — reflecting it for the staff
 *     member who completed the registration — using the remaining required
 *     Bill-Log information (document #, property id, tax period).
 *
 * System boundary: RETD records the registration outcome and the source-system
 * identifiers; it never performs the registration itself.
 */
class PropertyRegistrationTest extends TestCase
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

    private function manager(): User
    {
        return User::where('email', 'account.manager@test.lra')->firstOrFail();
    }

    /** Create + submit + approve a discovery and assign property registration to the account officer. */
    private function referToOfficer(string $suffix = ''): array
    {
        Sanctum::actingAs($this->officer());

        $discovery = $this->postJson('/api/v1/discoveries', [
            'owner_name' => 'Owner Registration'.$suffix,
            'property_address' => 'Address Registration'.$suffix,
            'property_classification' => 'Residential',
            'property_type' => 'House',
            'occupancy_use' => 'Residential',
            'county' => 'Montserrado',
            'district' => 'Greater Monrovia',
            'tin' => 'TIN-REG',
            'discovery_date' => now()->toDateString(),
        ])->assertStatus(201)->json('data');

        $this->postJson("/api/v1/discoveries/{$discovery['id']}/submit")->assertStatus(200);

        Sanctum::actingAs($this->manager());

        return $this->postJson("/api/v1/discoveries/{$discovery['id']}/acct-review", [
            'decision' => 'approve',
            'officer_id' => $this->officer()->id,
            'remarks' => 'Approved for property registration.',
        ])->assertStatus(200)->json('data');
    }

    private function completeRegistration(int $discoveryId, array $overrides = []): TestResponse
    {
        Sanctum::actingAs($this->officer());

        return $this->postJson("/api/v1/discoveries/{$discoveryId}/account-processing", array_merge([
            'property_id' => 'REG-P-1',
            'document_number' => '2026/REG-1',
            'property_tax_amount' => 1450.5,
            'tax_period' => '2026',
            'remarks' => 'Property registered.',
        ], $overrides));
    }

    public function test_completing_registration_logs_a_bill_for_the_staff_and_completes_the_task(): void
    {
        $approved = $this->referToOfficer();
        $discoveryId = $approved['id'];

        $response = $this->completeRegistration($discoveryId)->assertStatus(200);

        // The task is moved to a completed state and its completion recorded.
        $task = Task::where('task_type', Task::TYPE_ACCOUNT_PROCESSING)
            ->where('reference_type', 'property_discovery')
            ->where('reference_id', $discoveryId)
            ->firstOrFail();

        $this->assertTrue(in_array($task->status, Task::COMPLETED_STATUSES, true), "Task should be completed, got {$task->status}");
        $this->assertSame($this->officer()->id, $task->completed_by);
        $this->assertNotNull($task->completed_at);
        $this->assertSame(1, $task->engagements()->where('outcome', 'registration_completed')->count());
        $this->assertSame('Closed', $task->history()->latest('id')->first()->to_status);

        // A bill was logged and credited to the staff member who completed the registration.
        $bill = PropertyBill::where('document_number', '2026/REG-1')->firstOrFail();
        $this->assertSame($this->officer()->id, $bill->account_staff_id);
        $this->assertSame('REG-P-1', $bill->property_id);
        $this->assertSame('TIN-REG', $bill->tin);
        $this->assertSame('2026', $bill->tax_period);
        $this->assertSame(1450.5, (float) $bill->total_tax_due);
        $this->assertSame(1450.5, (float) $bill->outstanding_balance);
        $this->assertSame('Logged', $bill->delivery_status);
        $this->assertSame('Unpaid', $bill->payment_status);

        // The logged bill lands in the enforcement delivery queue (one source of truth).
        $this->assertSame('Awaiting Assignment', $bill->case_status);
        $this->assertSame(PropertyBill::RECIPIENT_WALK_IN, $bill->recipient_type);
        $delivery = Task::where('task_type', Task::TYPE_BILL_DELIVERY)
            ->where('reference_type', 'property_bill')
            ->where('reference_id', $bill->id)
            ->firstOrFail();
        $this->assertSame('Awaiting Assignment', $delivery->status);
        $this->assertSame('Enforcement', $delivery->section);

        // The discovery itself records the source-system identifiers.
        $fresh = PropertyDiscovery::find($discoveryId);
        $this->assertSame(PropertyDiscovery::STATUS_REGISTERED, $fresh->status);
        $this->assertSame('REG-P-1', $fresh->property_id);
        $this->assertSame('2026/REG-1', $fresh->document_number);

        // Response exposes the logged bill confirmation.
        $this->assertSame('2026/REG-1', $response->json('data.bill.document_number'));
    }

    public function test_registration_requires_the_bill_log_information(): void
    {
        $approved = $this->referToOfficer();

        $this->completeRegistration($approved['id'], ['tax_period' => null])->assertStatus(422);
        $this->completeRegistration($approved['id'], ['document_number' => null])->assertStatus(422);
        $this->completeRegistration($approved['id'], ['property_tax_amount' => null])->assertStatus(422);

        $this->assertSame(
            0,
            PropertyBill::where('property_id', 'REG-P-1')->count(),
            'No bill may be logged while the Bill-Log fields are incomplete.'
        );
    }

    public function test_an_already_logged_assessment_is_updated_not_duplicated(): void
    {
        $first = $this->referToOfficer(' A');
        $this->completeRegistration($first['id'], [
            'property_id' => 'REG-DUP',
            'document_number' => '2026/DUP-1',
        ])->assertStatus(200);

        $second = $this->referToOfficer(' B');

        $response = $this->completeRegistration($second['id'], [
            'property_id' => 'REG-DUP',
            'document_number' => '2026/DUP-2',
            'property_tax_amount' => 1999.25,
        ]);

        $response->assertStatus(200);
        $this->assertSame('updated', $response->json('data.bill_action'));

        $this->assertSame(
            1,
            PropertyBill::where('property_id', 'REG-DUP')->count(),
            'The one-assessment rule must prevent duplicate logging for the same tax period.'
        );

        $bill = PropertyBill::where('property_id', 'REG-DUP')->firstOrFail();
        $this->assertSame('2026/DUP-2', $bill->document_number, 'The existing bill record is updated in place.');
        $this->assertSame('1999.25', number_format((float) $bill->total_tax_due, 2, '.', ''));
    }

    public function test_a_discovery_cannot_be_processed_twice(): void
    {
        $approved = $this->referToOfficer();

        $this->completeRegistration($approved['id'])->assertStatus(200);

        $repeat = $this->completeRegistration($approved['id'], [
            'document_number' => '2026/REG-REPEAT',
        ]);

        $repeat->assertStatus(422);
        $this->assertStringContainsString('Already processed', $repeat->json('message'));
    }
}
