<?php

namespace Tests\Feature;

use App\Models\Appeal;
use App\Models\PaymentVerification;
use App\Models\PropertyBill;
use App\Models\PropertyDiscovery;
use App\Models\Task;
use App\Models\User;
use App\Models\Valuation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\ApprovedEnforcementAssignments;
use Tests\TestCase;

/**
 * Gate 1 — Business process (end-to-end workflows) driven through the API as
 * real role users. Each workflow asserts creator/owner/viewer/approver hand-offs,
 * soD guards, notifications following the task, source-vs-internal identifiers,
 * and consistent terminal closures.
 *
 * Workflow A: self-declared property  (Discovery → A&R Manager → A&R Officer →
 *             bill → enforcement → payment verification → completion)
 * Workflow B: commercial/valuation property (Discovery → Valuation → AC →
 *             A&R → registration → completion)
 * Workflow C: existing-property reassessment (bill-referenced valuation, no discovery)
 */
class Gate1WorkflowsTest extends TestCase
{
    use RefreshDatabase;
    use ApprovedEnforcementAssignments;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function act(string $email): void
    {
        Sanctum::actingAs($this->user($email));
    }

    private function discoveryPayload(string $classification): array
    {
        return [
            'owner_name' => 'Owner '.$classification,
            'owner_contact' => '0770-100-110',
            'tin' => 'TIN-G1-'.strtoupper(substr($classification, 0, 4)),
            'property_address' => 'Address '.$classification.', Monrovia',
            'county' => 'Montserrado',
            'district' => 'Greater Monrovia',
            'property_classification' => $classification,
            'property_type' => 'House',
            'occupancy_use' => $classification,
            'discovery_date' => now()->toDateString(),
            'gps_coordinate' => '6.3130,-10.8074',
        ];
    }

    private function latestTask(string $type, string $refType, int $refId): Task
    {
        return Task::where('task_type', $type)
            ->where('reference_type', $refType)
            ->where('reference_id', $refId)
            ->latest('id')
            ->firstOrFail();
    }

    private function auditHas(string $auditableType, int $auditableId, string $action): void
    {
        $this->assertTrue(
            DB::table('audit_logs')
                ->where('auditable_type', $auditableType)
                ->where('auditable_id', $auditableId)
                ->where('action', $action)
                ->exists(),
            "Expected audit action {$action} on {$auditableType}#{$auditableId}."
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Workflow A — self-declared property */
    /* ------------------------------------------------------------------ */

    public function test_workflow_a_self_declared_property(): void
    {
        // 1/2. Discoverer records + submits the self-declared discovery.
        $this->act('account.officer@test.lra');
        $discoveryId = $this->postJson('/api/v1/discoveries', $this->discoveryPayload('Residential'))
            ->assertCreated()->json('data.id');
        $this->postJson("/api/v1/discoveries/{$discoveryId}/submit")->assertOk();

        $discovery = PropertyDiscovery::findOrFail($discoveryId);

        // Self-declared NEVER bypasses the A&R Manager: routed to ACCT desk.
        $this->assertSame(PropertyDiscovery::STATUS_AWAITING_ACCT_REVIEW, $discovery->status);
        $this->assertSame('account', $discovery->decision_path);
        $this->assertFalse(
            DB::table('tasks')
                ->where('task_type', Task::TYPE_VALUATION_MANAGER_REVIEW)
                ->where('reference_type', 'property_discovery')
                ->where('reference_id', $discoveryId)
                ->exists()
        );
        $reviewTask = $this->latestTask(Task::TYPE_DISCOVERY_REVIEW, 'property_discovery', $discoveryId);
        $acctManager = $this->user('account.manager@test.lra');
        $this->assertSame($acctManager->id, $reviewTask->assigned_to);

        // Only the A&R desk may review — a Valuation Manager and an officer get 403.
        $this->act('val.manager@test.lra');
        $this->postJson("/api/v1/discoveries/{$discoveryId}/acct-review", ['decision' => 'approve'])
            ->assertForbidden();
        $this->act('enf.officer@test.lra');
        $this->postJson("/api/v1/discoveries/{$discoveryId}/acct-review", ['decision' => 'approve'])
            ->assertForbidden();

        // 3. A&R Manager approves and assigns the Account & Records Officer.
        $this->act('account.manager@test.lra');
        $accountOfficer = $this->user('account.officer@test.lra');
        $this->postJson("/api/v1/discoveries/{$discoveryId}/acct-review", [
            'decision' => 'approve',
            'officer_id' => $accountOfficer->id,
            'remarks' => 'Self-declared, proceed with registration.',
        ])->assertOk();

        $discovery->refresh();
        $this->assertSame(PropertyDiscovery::STATUS_ASSIGNED_ACCT, $discovery->status);
        $this->assertSame($accountOfficer->id, $discovery->current_owner_id);

        // Ownership + notifications followed the record to the new owner.
        $registrationTask = $this->latestTask(Task::TYPE_ACCOUNT_PROCESSING, 'property_discovery', $discoveryId);
        $this->assertSame($accountOfficer->id, $registrationTask->assigned_to);
        $this->assertTrue(DB::table('notifications')->where('user_id', $accountOfficer->id)->exists());
        $this->auditHas(PropertyDiscovery::class, $discoveryId, 'discovery.approved');

        // 4. Account & Records Officer records the registration + bills the property.
        $this->act('account.officer@test.lra');
        $this->postJson("/api/v1/discoveries/{$discoveryId}/account-processing", [
            'property_id' => 'G1A-P-1',
            'document_number' => '2026/G1A-1',
            'property_tax_amount' => 5000.00,
            'tax_period' => '2026',
            'taxpayer_name' => 'Taxpayer G1A',
            'remarks' => 'Registered from self-declared discovery.',
        ])->assertOk();

        $discovery->refresh();
        $this->assertSame(PropertyDiscovery::STATUS_REGISTERED, $discovery->status);

        // The original discoverer stays identifiable through the chain (never overwritten).
        $discovererId = $discovery->discovered_by;
        $this->assertSame($accountOfficer->id, $discovererId);

        // The logged bill carries ONLY source-system identifiers — no RETD-generated ones.
        $bill = PropertyBill::where('document_number', '2026/G1A-1')->firstOrFail();
        $this->assertSame('G1A-P-1', $bill->property_id);
        $this->assertNull($bill->snapshot_case_status); // no internal reference column on bills
        $this->assertSame('Awaiting Assignment', $bill->case_status);
        $this->auditHas(PropertyDiscovery::class, $discoveryId, 'discovery.registration_recorded');

        // 5. Enforcement: the responsible supervisor assigns the approved team member.
        $enforcementOfficer = $this->user('enf.officer@test.lra');
        [, $enforcementSupervisor] = $this->approveEnforcementAssignment($enforcementOfficer);
        Sanctum::actingAs($enforcementSupervisor);
        $this->postJson("/api/v1/property-bills/{$bill->id}/assign", ['officer_id' => $enforcementOfficer->id])
            ->assertOk();
        $bill->refresh();
        $this->assertSame('Assigned', $bill->case_status);

        $deliveryTask = $this->latestTask(Task::TYPE_BILL_DELIVERY, 'property_bill', $bill->id);
        $this->assertSame($enforcementOfficer->id, $deliveryTask->assigned_to);

        $this->act('enf.officer@test.lra');
        $this->postJson('/api/v1/enforcement-visits', [
            'assignment_id' => $deliveryTask->id,
            'status' => 'delivered',
            'delivery_status' => 'Delivered',
            'gps_lat' => 6.31,
            'gps_lng' => -10.80,
            'recipient_name' => 'Taxpayer G1A',
            'notes' => 'Bill handed over in person.',
        ])->assertCreated();

        $deliveryTask->refresh();
        $this->assertSame('Delivered', $deliveryTask->status);
        $bill->refresh();
        $this->assertSame('Delivered', $bill->case_status);
        $this->assertTrue(DB::table('enforcement_visits')->where('bill_id', $bill->id)->exists());

        $this->postJson('/api/v1/enforcement/submit-receipt', [
            'billing_number' => '2026/G1A-1',
            'amount' => 5000.00,
            'payment_period' => '2026',
            'receipt_number' => 'RCPT-G1A-0001',
            'receipt_date' => now()->toDateString(),
        ])->assertCreated();

        $bill->refresh();
        $this->assertSame('Under Verification', $bill->case_status);
        $this->assertSame('Payment Claimed', $bill->payment_status);

        $verification = PaymentVerification::where('bill_id', $bill->id)->latest('id')->firstOrFail();
        $this->assertSame(PaymentVerification::STATUS_PENDING, $verification->verification_status);

        // 6. Payment verification closes the case consistently (soD: verifier ≠ claimant).
        $this->act('account.manager@test.lra'); // does NOT equal claimed_by (enf.officer) and is bill-scoped
        $this->postJson("/api/v1/payments/verifications/{$verification->id}/confirm", [
            'verified_amount' => 5000.00,
            'remarks' => 'Receipt matches.',
        ])->assertOk();

        $bill->refresh();
        $this->assertSame('Resolved', $bill->case_status);
        $this->assertSame('Paid', $bill->payment_status);
        $this->assertSame('0.00', (string) $bill->outstanding_balance);
        // The payment claim is linked to the bill-delivery task, which advances
        // from delivery through verification to its settled state.
        $deliveryTask->refresh();
        $this->assertSame('Paid', $deliveryTask->status);
        $this->assertTrue(DB::table('payments')->where('bill_id', $bill->id)->where('amount', 5000.00)->exists());
        $this->assertTrue(
            DB::table('notifications')->where('type', 'payments')
                ->where('title', 'Payment verified')
                ->exists()
        );

        // Discovery closes consistently alongside the settled bill.
        $this->act('account.manager@test.lra');
        $this->postJson("/api/v1/discoveries/{$discoveryId}/complete")->assertOk();
        $discovery->refresh();
        $this->assertSame(PropertyDiscovery::STATUS_COMPLETED, $discovery->status);

        // The discoverer identity survived to the very end.
        $this->assertSame($accountOfficer->id, $discovery->discovered_by);
        $this->auditHas(PropertyDiscovery::class, $discoveryId, 'discovery.completed');
        $this->assertSame(1, PropertyDiscovery::count());
        $this->assertSame(1, PropertyBill::count());
    }

    /* ------------------------------------------------------------------ */
    /*  Workflow B — commercial / valuation property */
    /* ------------------------------------------------------------------ */

    public function test_workflow_b_commercial_valuation_property(): void
    {
        // 1. Discovery recorded + submitted. Commercial → valuation route.
        $this->act('account.officer@test.lra');
        $discoveryId = $this->postJson('/api/v1/discoveries', $this->discoveryPayload('Commercial'))
            ->assertCreated()->json('data.id');
        $this->postJson("/api/v1/discoveries/{$discoveryId}/submit")->assertOk();

        $discovery = PropertyDiscovery::findOrFail($discoveryId);
        $this->assertSame(PropertyDiscovery::STATUS_AWAITING_VALUATION, $discovery->status);
        $this->assertSame('valuation', $discovery->decision_path);

        // Commercial cannot be diverted to the A&R account desk.
        $this->assertFalse(
            DB::table('tasks')->where('task_type', Task::TYPE_DISCOVERY_REVIEW)
                ->where('reference_type', 'property_discovery')->where('reference_id', $discoveryId)->exists()
        );
        $valManagerReview = $this->latestTask(Task::TYPE_VALUATION_MANAGER_REVIEW, 'property_discovery', $discoveryId);
        $valManager = $this->user('val.manager@test.lra');
        $this->assertSame($valManager->id, $valManagerReview->assigned_to);

        // 2. Valuation Manager routes to a Valuation Officer.
        $this->act('val.manager@test.lra');
        $valOfficer = $this->user('val.officer@test.lra');
        $this->postJson("/api/v1/discoveries/{$discoveryId}/val-review", [
            'decision' => 'assign',
            'officer_id' => $valOfficer->id,
        ])->assertOk();

        $discovery->refresh();
        $this->assertSame(PropertyDiscovery::STATUS_UNDER_VALUATION, $discovery->status);
        $valuation = Valuation::findOrFail($discovery->valuation_id);
        $this->assertSame(Valuation::STATUS_ASSIGNED, $valuation->status);
        $this->assertSame($valOfficer->id, $valuation->valuation_officer_id);
        $valTask = $this->latestTask(Task::TYPE_VALUATION, 'valuation', $valuation->id);
        $this->assertSame($valOfficer->id, $valTask->assigned_to);
        $this->assertTrue(DB::table('notifications')->where('user_id', $valOfficer->id)->exists());

        // 3. Valuation Officer completes the assessment.
        $this->act('val.officer@test.lra');

        // Cannot submit an incomplete assessment (missing description + photo data).
        $this->postJson("/api/v1/valuations/{$valuation->id}/submit", [
            'assessed_value' => 1200000.00,
            'annual_tax' => 42000.00,
        ])->assertStatus(422);

        $this->postJson("/api/v1/valuations/{$valuation->id}/descriptions", [
            'descriptions' => [[
                'description' => 'Land',
                'level' => 'Land',
                'tar' => 5000.00,
                'quantity' => 1,
            ], [
                'description' => 'Ground-floor commercial floor.',
                'level' => 'Ground Floor',
                'area_sqft' => 4500,
                'tar' => 28.0,
                'quantity' => 1,
                'amount' => 126000.00,
                'depreciation_pct' => 0,
            ]],
        ])->assertOk();
        $this->putJson("/api/v1/valuations/{$valuation->id}", [
            'photos' => 'proof://VAL-G1B-0001',
            'owner_contact' => '0770-100-110',
        ])->assertOk();

        $this->postJson("/api/v1/valuations/{$valuation->id}/submit", [
            'assessed_value' => 1200000.00,
            'annual_tax' => 42000.00,
            'applicable_tax_rate' => 3.5,
            'remarks' => 'Commercial block assessed.',
        ])->assertOk();

        $valuation->refresh();
        $this->assertSame(Valuation::STATUS_SUBMITTED_TO_MANAGER, $valuation->status);
        $this->auditHas(Valuation::class, $valuation->id, 'valuation.submitted');

        // 4. Valuation Manager forwards to the Assistant Commissioner.
        $reviewTask = $this->latestTask(Task::TYPE_VALUATION_REVIEW, 'valuation', $valuation->id);
        $this->assertSame($valManager->id, $reviewTask->assigned_to);

        $this->act('val.manager@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/review", [
            'decision' => 'confirm_ac',
            'remarks' => 'Assessment complete and correct.',
        ])->assertOk();
        $valuation->refresh();
        $this->assertSame(Valuation::STATUS_SUBMITTED_TO_AC, $valuation->status);
        $acTask = $this->latestTask(Task::TYPE_AC_APPROVAL, 'valuation', $valuation->id);
        $this->assertSame($this->user('ac@test.lra')->id, $acTask->assigned_to);

        // The Valuation Officer cannot review or approve their own assessment (soD).
        $this->act('val.officer@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/review", ['decision' => 'confirm_ac'])->assertForbidden();
        $this->postJson("/api/v1/valuations/{$valuation->id}/decide", ['decision' => 'approve'])->assertForbidden();

        // AC approval cannot be bypassed: A&R desk is out of scope before AC decides → 403.
        $this->act('account.manager@test.lra');
        $accountOfficer = $this->user('account.officer@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/account-review", [
            'decision' => 'approve',
            'officer_id' => $accountOfficer->id,
        ])->assertForbidden();

        // Nor can an officer register the assessment before AC approval (status guard).
        $this->act('account.officer@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/processing", [
            'property_id' => 'G1B-P-1',
        ])->assertStatus(422);

        // 5. AC approval.
        $ac = $this->user('ac@test.lra');
        $this->assertNotSame($valuation->valuation_officer_id, $ac->id); // never self-decide
        $this->act('ac@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/decide", [
            'decision' => 'approve',
            'remarks' => 'Valuation approved.',
        ])->assertOk();

        $valuation->refresh();
        $this->assertSame(Valuation::STATUS_AC_APPROVED, $valuation->status);
        $discovery->refresh();
        $this->assertSame(PropertyDiscovery::STATUS_APPROVED, $discovery->status);
        $this->auditHas(Valuation::class, $valuation->id, 'valuation.decided');

        // 6. Account & Record Manager assigns the officer; officer registers.
        $this->act('account.manager@test.lra');
        $acctReviewTask = $this->latestTask(Task::TYPE_ACCOUNT_MANAGER_REVIEW, 'valuation', $valuation->id);
        $this->assertSame($acctManager = $this->user('account.manager@test.lra')->id, $acctReviewTask->assigned_to);

        $this->postJson("/api/v1/valuations/{$valuation->id}/account-review", [
            'decision' => 'approve',
            'officer_id' => $accountOfficer->id,
            'remarks' => 'Register in the source system.',
        ])->assertOk();
        $valuation->refresh();
        $this->assertSame(Valuation::STATUS_SENT_TO_ACCT, $valuation->status);

        $regTask = $this->latestTask(Task::TYPE_ACCOUNT_PROCESSING, 'property_discovery', $discoveryId);
        $this->assertSame($accountOfficer->id, $regTask->assigned_to);
        $discovery->refresh();
        $this->assertSame(PropertyDiscovery::STATUS_ASSIGNED_ACCT, $discovery->status);
        $this->assertSame($accountOfficer->id, $discovery->current_owner_id);

        // The processing task belongs to the discovery, so the valuation endpoint
        // refuses to double-process it — the bill is written once, in one place.
        $this->act('account.officer@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/processing", [
            'property_id' => 'G1B-P-1',
            'document_number' => '2026/G1B-1',
        ])->assertStatus(422);

        $this->act('account.officer@test.lra');
        $processed = $this->postJson("/api/v1/discoveries/{$discoveryId}/account-processing", [
            'property_id' => 'G1B-P-1',
            'document_number' => '2026/G1B-1',
            'property_tax_amount' => 42000.00,
            'tax_period' => '2026',
            'remarks' => 'Registration completed in the source system.',
        ])->assertOk()->json('data');

        $this->assertSame('created', $processed['bill_action']);

        $valuation->refresh();
        $this->assertSame(Valuation::STATUS_REGISTERED, $valuation->status);
        $this->assertSame(Valuation::REGISTRATION_COMPLETED, $valuation->registration_status);
        $this->assertSame('G1B-P-1', $valuation->property_id);
        $discovery->refresh();
        $this->assertSame(PropertyDiscovery::STATUS_REGISTERED, $discovery->status);
        $this->assertSame(1, PropertyBill::where('property_id', 'G1B-P-1')->count());
        $this->auditHas(PropertyDiscovery::class, $discoveryId, 'discovery.registration_recorded');

        // 7. Completion.
        $this->act('account.manager@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/complete")->assertOk();
        $valuation->refresh();
        $discovery->refresh();
        $this->assertSame(Valuation::STATUS_COMPLETED, $valuation->status);
        $this->assertSame(PropertyDiscovery::STATUS_COMPLETED, $discovery->status);
        $this->auditHas(Valuation::class, $valuation->id, 'valuation.completed');

        // Original discoverer remains identifiable at the end.
        $this->assertSame($accountOfficer->id, $discovery->discovered_by);
        $this->assertSame($discoveryId, $valuation->discovery_id);

        // Every nexus transition above must already be present in the audit chain.
        foreach ([
            'discovery.submitted', 'discovery.classified', 'discovery.routed_to_valuation',
            'valuation.submitted', 'valuation.reviewed', 'valuation.decided',
            'valuation.account_approved', 'valuation.completed',
        ] as $action) {
            $this->assertTrue(
                DB::table('audit_logs')
                    ->where('action', $action)
                    ->where(function ($q) use ($discoveryId, $valuation) {
                        $q->where(fn ($w) => $w->where('auditable_type', PropertyDiscovery::class)->where('auditable_id', $discoveryId))
                            ->orWhere(fn ($w) => $w->where('auditable_type', Valuation::class)->where('auditable_id', $valuation->id));
                    })
                    ->exists(),
                "Expected audit action {$action} somewhere on the discovery/valuation chain."
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Workflow C — existing-property reassessment */
    /* ------------------------------------------------------------------ */

    public function test_workflow_c_existing_property_reassessment(): void
    {
        $this->act('account.officer@test.lra');
        $this->postJson('/api/v1/property-bills', [
            'document_number' => '2026/G1C-1',
            'property_id' => 'G1C-P-1',
            'taxpayer_name' => 'Taxpayer G1C',
            'tin' => 'TIN-G1C',
            'property_address' => 'Capitol By-pass, Monrovia',
            'tax_amount' => 5000.00,
            'tax_period' => '2026',
        ])->assertCreated();

        $bill = PropertyBill::where('document_number', '2026/G1C-1')->firstOrFail();

        // Reassessment is distinct from discovery: no discovery is created.
        $this->assertSame(0, PropertyDiscovery::count());

        // A reassessment MUST reference an existing bill.
        $this->act('val.officer@test.lra');
        $this->postJson('/api/v1/valuations', $this->reassessmentPayload(null, 'G1C-P-1'))
            ->assertStatus(422);

        // And the referenced bill's property must match.
        $this->postJson('/api/v1/valuations', $this->reassessmentPayload($bill->id, 'OTHER-PROPERTY'))
            ->assertStatus(422);

        // Correct reassessment, referenced directly to the existing bill.
        $valuationId = $this->postJson('/api/v1/valuations', $this->reassessmentPayload($bill->id, 'G1C-P-1'))
            ->assertCreated()->json('data.id');

        $this->assertSame(0, PropertyDiscovery::count()); // still no discovery created
        $valuation = Valuation::findOrFail($valuationId);
        $this->assertSame('reassessment', $valuation->valuation_type);
        $this->assertSame($bill->id, $valuation->bill_id);
        $this->assertSame(Valuation::STATUS_ASSIGNED, $valuation->status);
        $this->assertSame($this->user('val.officer@test.lra')->id, $valuation->valuation_officer_id);

        // 2–6. Define propagation: drive the reassessment through the exact chain.
        $this->postJson("/api/v1/valuations/{$valuationId}/submit", [
            'assessed_value' => 1500000.00,
            'annual_tax' => 52000.00,
            'remarks' => 'Reassessment completed.',
        ])->assertOk();
        $valuation->refresh();
        $this->assertSame(Valuation::STATUS_SUBMITTED_TO_MANAGER, $valuation->status);

        $this->act('val.manager@test.lra');
        $this->postJson("/api/v1/valuations/{$valuationId}/review", ['decision' => 'confirm_ac'])->assertOk();
        $valuation->refresh();
        $this->assertSame(Valuation::STATUS_SUBMITTED_TO_AC, $valuation->status);

        $this->act('ac@test.lra');
        $this->postJson("/api/v1/valuations/{$valuationId}/decide", ['decision' => 'approve'])->assertOk();
        $valuation->refresh();
        $this->assertSame(Valuation::STATUS_AC_APPROVED, $valuation->status);

        // The approved assessment result propagates back to the source bill (assessed_value only).
        $bill->refresh();
        $this->assertSame((float) $valuation->assessed_value, (float) $bill->assessed_value);

        $this->act('account.manager@test.lra');
        $this->postJson("/api/v1/valuations/{$valuationId}/account-review", [
            'decision' => 'approve',
            'officer_id' => $this->user('account.officer@test.lra')->id,
        ])->assertOk();
        $valuation->refresh();
        $this->assertSame(Valuation::STATUS_SENT_TO_ACCT, $valuation->status);

        $this->act('account.officer@test.lra');
        $this->postJson("/api/v1/valuations/{$valuationId}/processing", [
            'property_id' => 'G1C-P-1',
            'document_number' => '2026/G1C-1',
        ])->assertOk();
        $valuation->refresh();
        $this->assertSame(Valuation::STATUS_REGISTERED, $valuation->status);

        $this->act('account.manager@test.lra');
        $this->postJson("/api/v1/valuations/{$valuationId}/complete")->assertOk();
        $valuation->refresh();
        $this->assertSame(Valuation::STATUS_COMPLETED, $valuation->status);

        // Terminal state cannot be re-submitted — statuses propagate only forward.
        $this->postJson("/api/v1/valuations/{$valuationId}/complete")->assertStatus(422);

        // No duplicate or orphaned records were produced.
        $this->assertSame(1, PropertyBill::count());
        $this->assertSame(1, Valuation::count());
        $this->assertSame(0, PropertyDiscovery::count());
        $this->assertSame(0, Appeal::count());
        $this->assertSame(
            1,
            DB::table('valuations')->where('bill_id', $bill->id)->count(),
            'Exactly one valuation may reference the bill.'
        );
    }

    /**
     * The applicable tax rate is a percentage, and real rates are not always whole
     * numbers: 1.5, 0.025 and 3.5 are all valid. The column is decimal(8,4) and the
     * field must keep four decimal places of precision rather than rounding a
     * sub-1 rate away to 0.
     */
    public function test_the_applicable_tax_rate_accepts_fractional_percentages(): void
    {
        $this->act('account.officer@test.lra');
        $this->postJson('/api/v1/property-bills', [
            'document_number' => '2026/G1R-1',
            'property_id' => 'G1R-P-1',
            'taxpayer_name' => 'Taxpayer G1R',
            'tin' => 'TIN-G1R',
            'property_address' => 'Allenford Street, Monrovia',
            'tax_amount' => 5000.00,
            'tax_period' => '2026',
        ])->assertCreated();

        $bill = PropertyBill::where('document_number', '2026/G1R-1')->firstOrFail();

        $this->act('val.officer@test.lra');
        $valuationId = $this->postJson('/api/v1/valuations', $this->reassessmentPayload($bill->id, 'G1R-P-1'))
            ->assertCreated()->json('data.id');
        $valuation = Valuation::findOrFail($valuationId);

        // A rate below 1% must survive, not be flattened to zero.
        $this->putJson("/api/v1/valuations/{$valuationId}", [
            'applicable_tax_rate' => 0.025,
        ])->assertOk();
        $this->assertSame('0.0250', $valuation->fresh()->applicable_tax_rate);

        // 1.5 and 3.5 are the everyday case.
        foreach (['1.5' => '1.5000', '3.5' => '3.5000'] as $sent => $stored) {
            $this->putJson("/api/v1/valuations/{$valuationId}", [
                'applicable_tax_rate' => $sent,
            ])->assertOk();
            $this->assertSame($stored, $valuation->fresh()->applicable_tax_rate);
        }

        // A negative rate is still rejected, and leaves the stored value untouched.
        $this->putJson("/api/v1/valuations/{$valuationId}", [
            'applicable_tax_rate' => -1.5,
        ])->assertStatus(422)->assertJsonValidationErrors('applicable_tax_rate');
        $this->assertSame('3.5000', $valuation->fresh()->applicable_tax_rate);

        // Full column precision, carried through submit.
        $this->postJson("/api/v1/valuations/{$valuationId}/submit", [
            'assessed_value' => 1000000.00,
            'annual_tax' => 250.00,
            'applicable_tax_rate' => 0.025,
            'remarks' => 'Fractional rate assessment.',
        ])->assertOk();

        $this->assertSame('0.0250', $valuation->fresh()->applicable_tax_rate);
        $this->assertSame(Valuation::STATUS_SUBMITTED_TO_MANAGER, $valuation->fresh()->status);

        // 0.025% of 1,000,000 is 250.00 — the rate is applied as a real fraction.
        $this->assertEqualsWithDelta(
            250.00,
            (float) $valuation->fresh()->applicable_tax_rate / 100 * 1000000.00,
            0.000001
        );
    }

    private function reassessmentPayload(?int $billId, string $propertyId): array
    {
        return [
            'valuation_type' => 'reassessment',
            'bill_id' => $billId,
            'property_id' => $propertyId,
            'document_number' => '2026/G1C-1',
            'owner_name' => 'Owner G1C',
            'owner_contact' => '0770-100-120',
            'tin' => 'TIN-G1C',
            'property_classification' => 'Commercial',
            'property_address' => 'Capitol By-pass, Monrovia',
            'assessment_date' => now()->toDateString(),
            'gps_coordinate' => '6.3130,-10.8074',
            'photos' => 'proof://VAL-G1C-0001',
            'descriptions' => [[
                'description' => 'Land',
                'level' => 'Land',
                'tar' => 5000.00,
                'quantity' => 1,
            ], [
                'description' => 'Commercial building — ground floor.',
                'level' => 'Ground Floor',
                'area_sqft' => 4000,
                'tar' => 30.0,
                'quantity' => 1,
                'amount' => 120000.00,
            ]],
        ];
    }
}
