<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\EvidencePhoto;
use App\Models\Notification;
use App\Models\PaymentVerification;
use App\Models\PropertyBill;
use App\Models\SyncTransaction;
use App\Models\User;
use App\Services\AuditService;
use App\Services\DataIntegrityService;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression guards for the full-sweep hardening fixes:
 *   - property registration contract: the account-processing endpoint requires
 *     the tax amount the web/mobile UIs now always send (spec §14 Bill-Log info);
 *   - evidence uploads are idempotent across device retries (offline sync replay);
 *   - the audit hash chain is verified by the integrity sweep and tampering is
 *     detectable (beta reviewer §17);
 *   - sendToRole no longer silently no-ops on an off-by-a-space / case role name.
 */
class HardeningRegressionTest extends TestCase
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

    private function enforcementOfficer(): User
    {
        return User::where('email', 'enf.officer@test.lra')->firstOrFail();
    }

    /** Create + submit + approve a discovery, leaving it on the officer's desk for registration. */
    private function referToOfficer(): array
    {
        Sanctum::actingAs($this->officer());

        $discovery = $this->postJson('/api/v1/discoveries', [
            'owner_name' => 'Owner Hardening',
            'property_address' => 'Address Hardening',
            'property_classification' => 'Residential',
            'property_type' => 'House',
            'occupancy_use' => 'Residential',
            'county' => 'Montserrado',
            'district' => 'Greater Monrovia',
            'tin' => 'TIN-HARD',
            'discovery_date' => now()->toDateString(),
        ])->assertStatus(201)->json('data');

        $this->postJson("/api/v1/discoveries/{$discovery['id']}/submit")->assertStatus(200);

        Sanctum::actingAs($this->manager());

        return $this->postJson("/api/v1/discoveries/{$discovery['id']}/acct-review", [
            'decision' => 'approve',
            'officer_id' => $this->officer()->id,
            'remarks' => 'Approved for registration.',
        ])->assertStatus(200)->json('data');
    }

    public function test_account_processing_requires_the_tax_amount(): void
    {
        $approved = $this->referToOfficer();

        Sanctum::actingAs($this->officer());

        // Without the source-system tax amount the Bill-Log cannot be recorded.
        $this->postJson("/api/v1/discoveries/{$approved['id']}/account-processing", [
            'property_id' => 'REG-P-1',
            'document_number' => '2026/HARD-1',
            'tax_period' => '2026',
        ])->assertStatus(422);

        // With it, the bill is logged with the recorded amount.
        $this->postJson("/api/v1/discoveries/{$approved['id']}/account-processing", [
            'property_id' => 'REG-P-1',
            'document_number' => '2026/HARD-1',
            'property_tax_amount' => 990,
            'tax_period' => '2026',
        ])->assertStatus(200);

        $bill = PropertyBill::where('document_number', '2026/HARD-1')->firstOrFail();
        $this->assertSame(990.0, (float) $bill->total_tax_due);
    }

    public function test_evidence_upload_is_idempotent_across_device_replays(): void
    {
        $officer = $this->enforcementOfficer();
        $bill = PropertyBill::create([
            'document_number' => '2026/EV-1',
            'property_id' => 'REG-P-2',
            'taxpayer_name' => 'Evidence Taxpayer',
            'property_classification' => 'Residential',
            'property_address' => 'Evidence Ave',
            'tax_amount' => 500,
            'interest_charged' => 0,
            'penalty_charged' => 0,
            'total_tax_due' => 500,
            'tax_period' => '2026',
            'payment_status' => 'Unpaid',
            'delivery_status' => PropertyBill::DELIVERY_STAGES[0],
            'case_status' => PropertyBill::CASE_STATUSES[0],
            'date_logged' => now()->toDateString(),
            'assigned_enforcement_officer_id' => $officer->id,
        ]);

        Sanctum::actingAs($officer);

        $payload = [
            'photo_type' => 'PREMISES',
            'path' => 'device/evidence/premises-a.jpg',
            'bill_id' => $bill->id,
            'local_transaction_id' => 'MOB-20260921-ABC123-REPLAY',
        ];

        $first = $this->postJson('/api/v1/evidence/photos', $payload)->assertStatus(201);

        // The device resubmits the identical transaction (retry / auto-flush).
        $replay = $this->postJson('/api/v1/evidence/photos', $payload)->assertStatus(200);
        $this->assertTrue($replay->json('data.replayed'));

        $this->assertSame(1, EvidencePhoto::where('bill_id', $bill->id)->count(), 'A replayed upload must never duplicate the photo.');
        $this->assertSame(1, SyncTransaction::where('user_id', $officer->id)
            ->where('local_transaction_id', 'MOB-20260921-ABC123-REPLAY')
            ->count());
        $this->assertSame($first->json('data.id'), $replay->json('data.id'));
    }

    public function test_audit_chain_is_clean_then_tampering_is_detected(): void
    {
        $this->assertSame(0, AuditLog::count());

        $officer = $this->officer();
        $audit = app(AuditService::class);
        $bill = PropertyBill::create([
            'document_number' => '2026/AUD-1',
            'property_id' => 'REG-P-3',
            'taxpayer_name' => 'Audit Taxpayer',
            'property_classification' => 'Commercial',
            'property_address' => 'Audit Way',
            'tax_amount' => 1200,
            'interest_charged' => 0,
            'penalty_charged' => 0,
            'total_tax_due' => 1200,
            'tax_period' => '2026',
            'payment_status' => 'Unpaid',
            'delivery_status' => PropertyBill::DELIVERY_STAGES[0],
            'case_status' => PropertyBill::CASE_STATUSES[0],
            'date_logged' => now()->toDateString(),
        ]);
        $audit->record($bill, 'property.test_event', $officer->id, [], ['note' => 'chain seed']);

        $clean = $this->chainCheck(DataIntegrityService::run());

        $this->assertSame(0, $clean['count'], 'An unmodified chain must verify clean.');

        // Tamper: rewrite the stored hash to a value that never was computed.
        $leak = AuditLog::firstOrFail();
        $leak->update(['hash' => str_repeat('0', 64)]);

        $findings = $this->chainCheck(DataIntegrityService::run())['findings'];
        $this->assertNotEmpty($findings, 'A tampered entry must be flagged by the integrity sweep.');
        $this->assertSame($leak->id, $findings[0]['id']);
    }

    /** Helper: pull the audit chain check out of the sweep result. */
    private function chainCheck(array $result): array
    {
        foreach ($result['checks'] as $check) {
            if ($check['key'] === 'audit_chain_integrity') {
                return $check;
            }
        }

        $this->fail('audit_chain_integrity is missing from the integrity sweep.');
    }

    public function test_send_to_role_is_case_and_whitespace_insensitive(): void
    {
        $role = 'Enforcement Manager';
        $expected = User::where('is_active', true)->get()->filter(fn (User $u) => $u->hasRole($role))->count();

        NotificationService::sendToRole('enforcement  manager', 'Test broadcast', 'Message body', priority: 'normal');

        $this->assertSame(
            $expected,
            Notification::where('title', 'Test broadcast')->count(),
            'A loosely-typed role label must still reach exactly the role holders.',
        );
    }

    public function test_send_to_role_warns_instead_of_silently_vanishing(): void
    {
        Log::spy();

        NotificationService::sendToRole('This Role Does Not Exist', 'Ghost', 'nobody');

        Log::shouldHaveReceived('warning')->once();
        $this->assertSame(0, Notification::where('title', 'Ghost')->count());
    }

    public function test_payment_receipt_attachment_is_streamed_and_scoped(): void
    {
        $managed = $this->manager();
        $officer = $this->officer();
        $bill = PropertyBill::create([
            'document_number' => '2026/RCPT-1',
            'property_id' => 'REG-P-RCPT',
            'taxpayer_name' => 'Receipt Taxpayer',
            'property_classification' => 'Residential',
            'property_address' => 'Receipt Way',
            'tax_amount' => 750,
            'interest_charged' => 0,
            'penalty_charged' => 0,
            'total_tax_due' => 750,
            'tax_period' => '2026',
            'payment_status' => 'Unpaid',
            'delivery_status' => PropertyBill::DELIVERY_STAGES[0],
            'case_status' => PropertyBill::CASE_STATUSES[0],
            'date_logged' => now()->toDateString(),
        ]);

        Storage::disk('local')->put('evidence/r/receipt-test.png', 'receipt-bytes');

        $verification = PaymentVerification::create([
            'bill_id' => $bill->id,
            'claimed_by' => $officer->id,
            'property_id' => 'REG-P-RCPT',
            'document_number' => '2026/RCPT-1',
            'receipt_number' => 'RCPT-2026-0101',
            'receipt_bill_number' => '2026/RCPT-1',
            'amount_claimed' => 750,
            'payment_period' => '2026',
            'receipt_date' => now()->toDateString(),
            'receipt_attachment' => 'evidence/r/receipt-test.png',
            'match_status' => 'Pending',
            'verification_status' => 'Pending',
        ]);

        // The verifier with payment view rights can fetch the photo bytes.
        Sanctum::actingAs($managed);
        $resp = $this->getJson('/api/v1/payments/verifications/'.$verification->id.'/receipt-file')
            ->assertStatus(200)
            ->assertHeader('Content-Type');

        ob_start();
        $resp->sendContent();
        $this->assertSame('receipt-bytes', ob_get_clean());

        // A user outside the payment scope cannot.
        Sanctum::actingAs(User::where('email', 'val.manager@test.lra')->firstOrFail());
        $this->getJson('/api/v1/payments/verifications/'.$verification->id.'/receipt-file')
            ->assertStatus(403);

        // A stored path that no longer exists is reported cleanly.
        Sanctum::actingAs($managed);
        $verification->update(['receipt_attachment' => 'evidence/r/gone.png']);
        $this->getJson('/api/v1/payments/verifications/'.$verification->id.'/receipt-file')
            ->assertStatus(404);
    }
}
