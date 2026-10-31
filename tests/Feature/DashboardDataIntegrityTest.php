<?php

namespace Tests\Feature;

use App\Models\PaymentVerification;
use App\Models\PropertyBill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardDataIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function bill(string $document, User $staff): PropertyBill
    {
        return PropertyBill::create([
            'document_number' => $document,
            'property_id' => 'P-'.substr(md5($document), 0, 8),
            'taxpayer_name' => 'Dashboard Test Taxpayer',
            'tin' => 'TIN-'.substr(md5($document), 0, 8),
            'property_address' => 'Dashboard Test Address',
            'date_logged' => now()->toDateString(),
            'account_staff_id' => $staff->id,
            'total_tax_due' => 100,
            'outstanding_balance' => 100,
            'tax_amount' => 100,
            'payment_status' => 'Unpaid',
            'case_status' => 'Logged',
        ]);
    }

    private function pendingVerification(PropertyBill $bill): void
    {
        PaymentVerification::create([
            'bill_id' => $bill->id,
            'receipt_number' => 'RC-'.substr(md5((string) $bill->id), 0, 8),
            'receipt_bill_number' => $bill->document_number,
            'amount_claimed' => 25,
            'payment_period' => (string) now()->year,
            'verification_status' => PaymentVerification::STATUS_PENDING,
        ]);
    }

    public function test_my_dashboard_pending_verification_priority_count_respects_bill_scope(): void
    {
        $officer = User::where('email', 'account.officer@test.lra')->firstOrFail();
        $otherStaff = User::where('email', 'val.officer@test.lra')->firstOrFail();

        $this->pendingVerification($this->bill('DASH-OWN', $officer));
        $this->pendingVerification($this->bill('DASH-OTHER', $otherStaff));

        Sanctum::actingAs($officer);
        $data = $this->getJson('/api/v1/dashboard/my?fresh=1')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $data['payment_verifications']['pending']);
        $priority = collect($data['priority_actions'])->firstWhere('key', 'verify');
        $this->assertNotNull($priority);
        $this->assertSame(1, $priority['count']);
    }
}
