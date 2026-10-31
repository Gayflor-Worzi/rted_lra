<?php

namespace Tests\Feature;

use App\Models\EvidencePhoto;
use App\Models\PaymentVerification;
use App\Models\PropertyBill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Security guards for the file-streaming endpoints: a stored path may only be
 * served when it lives under the server-owned `evidence/` namespace.
 * Device "path reference only" values and any crafted traversal strings must
 * never reach the download/response call.
 */
class FileTraversalGuardTest extends TestCase
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

    private function makeBill(User $officer, string $doc, string $propertyId): PropertyBill
    {
        return PropertyBill::create([
            'document_number' => $doc,
            'property_id' => $propertyId,
            'taxpayer_name' => 'Traversal Taxpayer',
            'property_classification' => 'Residential',
            'property_address' => 'Guard Ave',
            'tax_amount' => 400,
            'interest_charged' => 0,
            'penalty_charged' => 0,
            'total_tax_due' => 400,
            'tax_period' => '2026',
            'payment_status' => 'Unpaid',
            'delivery_status' => PropertyBill::DELIVERY_STAGES[0],
            'case_status' => PropertyBill::CASE_STATUSES[0],
            'date_logged' => now()->toDateString(),
            'assigned_enforcement_officer_id' => $officer->id,
        ]);
    }

    public function test_evidence_download_rejects_traversal_path_reference(): void
    {
        $officer = $this->enforcementOfficer();
        $bill = $this->makeBill($officer, '2026/TRV-1', 'REG-P-TRV1');

        Sanctum::actingAs($officer);

        // The attacker posts a raw device "path" (reference-only, echoed verbatim).
        $created = $this->postJson('/api/v1/evidence/photos', [
            'photo_type' => 'PREMISES',
            'path' => '../../.env',
            'bill_id' => $bill->id,
        ])->assertStatus(201);

        $photo = EvidencePhoto::findOrFail($created->json('data.id'));
        $this->assertSame('../../.env', $photo->file_path);

        // The traversal reference must not be servable — 404, never file bytes.
        $this->getJson('/api/v1/evidence/photos/'.$photo->id.'/download')->assertStatus(404);
    }

    public function test_evidence_download_streams_only_server_owned_photos(): void
    {
        $officer = $this->enforcementOfficer();
        $bill = $this->makeBill($officer, '2026/TRV-2', 'REG-P-TRV2');
        $bytes = 'photo-bytes-'.bin2hex(random_bytes(6));

        Sanctum::actingAs($officer);

        $created = $this->postJson('/api/v1/evidence/photos', [
            'photo_type' => 'PREMISES',
            'data_uri' => 'data:image/png;base64,'.base64_encode($bytes),
            'bill_id' => $bill->id,
        ])->assertStatus(201);

        $photo = EvidencePhoto::findOrFail($created->json('data.id'));

        try {
            $resp = $this->getJson('/api/v1/evidence/photos/'.$photo->id.'/download')->assertStatus(200);

            ob_start();
            $resp->sendContent();
            $this->assertSame($bytes, ob_get_clean(), 'The streamed bytes must be the uploaded photo.');
        } finally {
            if ($photo->file_path) {
                Storage::disk('local')->delete($photo->file_path);
            }
        }
    }

    public function test_receipt_file_rejects_traversal_stored_path(): void
    {
        $officer = $this->enforcementOfficer();
        $manager = $this->manager();
        $bill = $this->makeBill($officer, '2026/TRV-3', 'REG-P-TRV3');

        $verification = PaymentVerification::create([
            'bill_id' => $bill->id,
            'claimed_by' => $officer->id,
            'property_id' => 'REG-P-TRV3',
            'document_number' => '2026/TRV-3',
            'receipt_number' => 'RCPT-TRV-3',
            'receipt_bill_number' => '2026/TRV-3',
            'amount_claimed' => 400,
            'payment_period' => '2026',
            'receipt_date' => now()->toDateString(),
            'receipt_attachment' => '../../.env',
            'match_status' => 'Pending',
            'verification_status' => 'Pending',
        ]);

        Sanctum::actingAs($manager);

        $this->getJson('/api/v1/payments/verifications/'.$verification->id.'/receipt-file')->assertStatus(404);
    }
}
