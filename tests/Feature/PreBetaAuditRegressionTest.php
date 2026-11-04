<?php

namespace Tests\Feature;

use App\Models\EnforcementVisit;
use App\Models\GpsLocationHistory;
use App\Models\PropertyBill;
use App\Models\Task;
use App\Models\User;
use App\Models\Valuation;
use App\Services\SyncService;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\ApprovedEnforcementAssignments;
use Tests\Feature\Concerns\LocalDemoUsers;
use Tests\TestCase;

/**
 * Regression tests for the pre-Beta audit/hardening pass (wave 1 + wave 2).
 *
 * Each test maps 1:1 to a finding that was fixed:
 *  - Account deactivation must bite immediately (EnsureActiveUser + token purge).
 *  - Bill search must respect the caller's data scope, not the national register.
 *  - Financial statuses must be unreachable by a bare `tasks.complete` holder.
 *  - Bill amounts are only editable through the Amendment path, never update().
 *  - Discovery-origin valuations require the discovery to be on the valuation path.
 *  - Sync ledger tracks receipt + GPS submissions.
 *  - GPS certification guards (reported accuracy preservation + separation of duties).
 */
class PreBetaAuditRegressionTest extends TestCase
{
    use LocalDemoUsers;
    use ApprovedEnforcementAssignments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    private function accountOfficer(): User
    {
        return User::where('email', 'account.officer@test.lra')->firstOrFail();
    }

    private function accountManager(): User
    {
        return User::where('email', 'account.manager@test.lra')->firstOrFail();
    }

    private function enforcementOfficer(): User
    {
        return User::where('email', 'enf.officer@test.lra')->firstOrFail();
    }

    private function enforcementSupervisor(): User
    {
        return $this->localEnforcementSupervisor();
    }

    private function valuationOfficer(): User
    {
        return User::where('email', 'val.officer@test.lra')->firstOrFail();
    }

    private function systemAdministrator(): User
    {
        return $this->localRoleUser('system.admin@test.lra', 'System Administrator', 'SYS');
    }

    private function logBill(string $doc, string $property): array
    {
        Sanctum::actingAs($this->accountOfficer());

        return $this->postJson('/api/v1/property-bills', [
            'document_number' => $doc,
            'property_id' => $property,
            'taxpayer_name' => "Taxpayer {$property}",
            'tin' => 'TIN-PBA',
            'property_address' => "Address {$property}",
            'tax_amount' => 5000.00,
            'interest_charged' => 100.00,
            'penalty_charged' => 50.00,
            'tax_period' => '2025',
        ])->assertStatus(201)->json('data');
    }

    /* ------------------------------------------------------------------ */
    /*  Account deactivation is immediate */
    /* ------------------------------------------------------------------ */

    public function test_deactivated_account_is_blocked_from_every_endpoint_except_logout(): void
    {
        $officer = $this->enforcementOfficer();
        $officer->update(['is_active' => false]);

        Sanctum::actingAs($officer->fresh());

        $this->getJson('/api/v1/auth/me')->assertStatus(403)
            ->assertJsonPath('message', 'Your account is deactivated. Contact the system administrator.');
        $this->getJson('/api/v1/tasks/my')->assertStatus(403);
        $this->postJson('/api/v1/auth/logout')->assertOk();
    }

    public function test_deactivation_purges_all_live_sessions(): void
    {
        $officer = $this->enforcementOfficer();
        $officer->createToken('device-A');
        $officer->createToken('device-B');

        $this->assertSame(2, DB::table('personal_access_tokens')->where('tokenable_id', $officer->id)->count());

        Sanctum::actingAs($this->systemAdministrator());
        $this->patchJson("/api/v1/users/{$officer->id}/active", ['is_active' => false])->assertOk();

        $this->assertFalse($officer->fresh()->is_active);
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $officer->id)->count());
    }

    public function test_staff_management_is_limited_to_the_actors_own_scope(): void
    {
        // An Account Manager (ACCT section) must not edit a Valuation Officer
        // (VAL section) — the account staff page is section-scoped.
        Sanctum::actingAs($this->accountManager());
        $this->patchJson('/api/v1/users/'.$this->valuationOfficer()->id.'/active', ['is_active' => false])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Staff member outside your data scope.');
        $this->putJson('/api/v1/users/'.$this->valuationOfficer()->id, ['full_name' => 'Hacked'])
            ->assertStatus(403);
        $this->assertTrue($this->valuationOfficer()->fresh()->is_active);
    }

    /* ------------------------------------------------------------------ */
    /*  Task transition permission map (financial statuses gate) */
    /* ------------------------------------------------------------------ */

    public function test_tasks_complete_holder_cannot_drive_a_task_to_financial_statuses(): void
    {
        $this->approveEnforcementAssignment($this->enforcementOfficer());
        $bill = $this->logBill('2026/3001', 'P-TRANS-1');
        $task = app(TaskService::class)->createTaskFromBill(PropertyBill::findOrFail($bill['id']), $this->enforcementOfficer());

        Sanctum::actingAs($this->enforcementOfficer());

        // Financial/settlement statuses are owned by the payment pipeline.
        foreach (['Paid', 'Partially Paid', 'Outstanding', 'Payment Verification', 'Payment Rejected'] as $status) {
            $this->postJson("/api/v1/tasks/{$task->id}/transition", [
                'to_status' => $status,
                'action' => 'complete',
                'remarks' => 'attempted from a bare tasks.complete holder',
            ])->assertStatus(403)
                ->assertJsonPath('message', 'Missing permission for this transition.');
        }

        $this->assertNotContains($task->fresh()->status, ['Paid', 'Partially Paid', 'Outstanding', 'Payment Verification', 'Payment Rejected']);
    }

    public function test_officer_can_still_perform_regular_transitions(): void
    {
        $this->approveEnforcementAssignment($this->enforcementOfficer());
        $bill = $this->logBill('2026/3002', 'P-TRANS-2');
        $task = app(TaskService::class)->createTaskFromBill(PropertyBill::findOrFail($bill['id']), $this->enforcementOfficer());

        Sanctum::actingAs($this->enforcementOfficer());
        $this->postJson("/api/v1/tasks/{$task->id}/transition", [
            'to_status' => 'Delivered',
            'action' => 'delivery_confirmed',
            'remarks' => 'handed over in person',
        ])->assertOk()->assertJsonPath('data.task.status', 'Delivered');
    }

    public function test_assignment_to_an_ineligible_assignee_is_rejected_even_for_open_tasks(): void
    {
        [, $enforcementSupervisor] = $this->approveEnforcementAssignment($this->enforcementOfficer());
        $bill = $this->logBill('2026/3003', 'P-TRANS-3');
        $task = app(TaskService::class)->createTaskFromBill(PropertyBill::findOrFail($bill['id']), $this->enforcementOfficer());

        // The Enforcement Manager can see the task, but an Account & Records
        // Officer cannot execute a Bill Delivery task.
        Sanctum::actingAs($enforcementSupervisor);
        $this->postJson("/api/v1/tasks/{$task->id}/assign", ['assigned_to' => $this->accountOfficer()->id])
            ->assertStatus(422);

        $this->assertSame($this->enforcementOfficer()->id, $task->fresh()->assigned_to);
    }

    /* ------------------------------------------------------------------ */
    /*  PII minimisation on staff lookups (G0.2) */
    /* ------------------------------------------------------------------ */

    public function test_enforcement_officer_lookup_exposes_no_personal_contact(): void
    {
        Sanctum::actingAs($this->accountOfficer());
        $data = $this->getJson('/api/v1/lookup/enforcement-officers')->assertOk()->json('data');

        $this->assertNotEmpty($data);
        foreach ($data as $row) {
            $this->assertArrayNotHasKey('phone', $row);
            $this->assertArrayNotHasKey('email', $row);
            $this->assertArrayHasKey('full_name', $row);
            $this->assertArrayHasKey('staff_id', $row);
        }

        // A staff.view holder still receives the email needed for directory duties.
        Sanctum::actingAs($this->accountManager());
        $rows = $this->getJson('/api/v1/lookup/enforcement-officers')->assertOk()->json('data');
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('phone', $row);
        }
        $this->assertArrayHasKey('email', $rows[0]);
    }

    /* ------------------------------------------------------------------ */
    /*  Internal reference generation is id-based and race-free (G0.4) */
    /* ------------------------------------------------------------------ */

    public function test_internal_references_are_unique_and_id_based(): void
    {
        $officer = $this->enforcementOfficer();

        $tasks = collect();
        for ($i = 0; $i < 3; $i++) {
            $tasks->push(Task::create([
                'task_type' => Task::TYPE_BILL_DELIVERY,
                'section' => 'Enforcement',
                'status' => 'Logged',
            ]));
        }

        $visits = collect();
        for ($i = 0; $i < 3; $i++) {
            $visits->push(EnforcementVisit::create([
                'officer_id' => $officer->id,
                'visit_date' => now()->toDateString(),
                'visit_status' => 'Planned',
            ]));
        }

        $year = now()->format('Y');

        $tasks->each(function (Task $task) use ($year) {
            $this->assertMatchesRegularExpression('/^TASK-\d{4}-\d{5}$/', $task->task_reference);
            $this->assertSame(sprintf('TASK-%s-%05d', $year, $task->id), $task->task_reference);
            // canonical value persisted — no placeholder residue in the DB
            $this->assertSame($task->task_reference, DB::table('tasks')->where('id', $task->id)->value('task_reference'));
        });

        $visits->each(function (EnforcementVisit $visit) use ($year) {
            $this->assertMatchesRegularExpression('/^VIS-\d{4}-\d{5}$/', $visit->visit_reference);
            $this->assertSame(sprintf('VIS-%s-%05d', $year, $visit->id), $visit->visit_reference);
            $this->assertSame($visit->visit_reference, DB::table('enforcement_visits')->where('id', $visit->id)->value('visit_reference'));
        });

        $this->assertSame(
            3,
            DB::table('tasks')->where('task_reference', 'like', "TASK-{$year}-%")->distinct()->count('task_reference')
        );
        $this->assertSame(
            3,
            DB::table('enforcement_visits')->where('visit_reference', 'like', "VIS-{$year}-%")->distinct()->count('visit_reference')
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Search respects data scope */
    /* ------------------------------------------------------------------ */

    public function test_global_search_never_leaks_bills_outside_the_callers_scope(): void
    {
        $this->approveEnforcementAssignment($this->enforcementOfficer());
        // Assigned to the enforcement officer → inside the supervisor's team scope.
        $scoped = $this->logBill('2026/3101', 'P-SCOPE-1');
        app(TaskService::class)->createTaskFromBill(PropertyBill::findOrFail($scoped['id']), $this->enforcementOfficer());

        // Logged by the account officer, then closed without assignment → outside ENF scope.
        $outOfScope = $this->logBill('2026/3102', 'P-SCOPE-2');
        PropertyBill::findOrFail($outOfScope['id'])->update(['case_status' => 'Closed']);
        Task::where('reference_type', 'property_bill')
            ->where('reference_id', $outOfScope['id'])
            ->update(['status' => 'Closed']);

        Sanctum::actingAs($this->enforcementSupervisor());
        $this->getJson('/api/v1/search?q=2026/3101')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/search?q=2026/3102')->assertOk()->assertJsonCount(0, 'data');

        // The Account Manager is the register custodian and may still see it.
        Sanctum::actingAs($this->accountManager());
        $this->getJson('/api/v1/search?q=2026/3102')->assertOk()->assertJsonCount(1, 'data');

        // The officer who logged it sees their own record, nothing structural breaks.
        Sanctum::actingAs($this->accountOfficer());
        $this->assertCount(2, $this->getJson('/api/v1/search?q=P-SCOPE')->assertOk()->json('data'));
    }

    /* ------------------------------------------------------------------ */
    /*  Reports respect the caller's record scope */
    /* ------------------------------------------------------------------ */

    public function test_reports_never_include_bills_outside_the_callers_scope(): void
    {
        // Logged by the account officer, never assigned to ENF staff.
        $bill = $this->logBill('2026/3150', 'P-REP-1');
        PropertyBill::findOrFail($bill['id'])->update(['case_status' => 'Closed']);
        Task::where('reference_type', 'property_bill')
            ->where('reference_id', $bill['id'])
            ->update(['status' => 'Closed']);

        // Enforcement Supervisor (reports.view, team scope) must not see it.
        Sanctum::actingAs($this->enforcementSupervisor());
        $rows = $this->getJson('/api/v1/reports/bills?per_page=50')->assertOk()->json('data.rows.data');
        $this->assertNotContains($bill['id'], array_column($rows, 'id'));
        $csv = $this->get('/api/v1/reports/bills/export?format=csv')->assertOk()->getContent();
        $this->assertStringNotContainsString('P-REP-1', $csv);

        // The Account Manager is the register custodian and may still see it.
        Sanctum::actingAs($this->accountManager());
        $rows = $this->getJson('/api/v1/reports/bills?per_page=50')->assertOk()->json('data.rows.data');
        $this->assertContains($bill['id'], array_column($rows, 'id'));
    }

    /* ------------------------------------------------------------------ */
    /*  Financial amounts are amend-only */
    /* ------------------------------------------------------------------ */

    public function test_update_cannot_alter_tax_interest_or_penalty_amounts(): void
    {
        $bill = $this->logBill('2026/3201', 'P-AMT-1');

        Sanctum::actingAs($this->accountOfficer());
        $this->putJson("/api/v1/property-bills/{$bill['id']}", [
            'taxpayer_name' => 'Renamed taxpayer',
            'tax_amount' => 1.00,
            'interest_charged' => 1.00,
            'penalty_charged' => 1.00,
        ])->assertOk();

        $fresh = PropertyBill::findOrFail($bill['id']);
        $this->assertSame('Renamed taxpayer', $fresh->taxpayer_name);
        $this->assertSame('5000.00', (string) $fresh->tax_amount);
        $this->assertSame('100.00', (string) $fresh->interest_charged);
        $this->assertSame('50.00', (string) $fresh->penalty_charged);
        $this->assertSame('5150.00', (string) $fresh->total_tax_due);
    }

    /* ------------------------------------------------------------------ */
    /*  Discovery-origin valuations require the valuation decision path */
    /* ------------------------------------------------------------------ */

    public function test_non_valuation_discovery_cannot_be_pulled_into_a_standalone_valuation(): void
    {
        // Account-route discovery: created and submitted by the account officer.
        Sanctum::actingAs($this->accountOfficer());
        $discovery = $this->postJson('/api/v1/discoveries', [
            'owner_name' => 'Owner Valuation',
            'property_address' => 'Address P-VAL-1',
            'property_classification' => 'Residential',
            'property_type' => 'House',
            'occupancy_use' => 'Residential',
            'county' => 'Montserrado',
            'district' => 'Greater Monrovia',
            'tin' => 'TIN-PVAL',
            'discovery_date' => now()->toDateString(),
        ])->assertStatus(201)->json('data');
        $this->postJson("/api/v1/discoveries/{$discovery['id']}/submit")->assertStatus(200);

        // A Valuation Officer must NOT be able to pull it into an assessment.
        Sanctum::actingAs($this->valuationOfficer());
        $this->postJson('/api/v1/valuations', [
            'valuation_type' => 'new_property',
            'discovery_id' => $discovery['id'],
            'owner_name' => 'Owner Valuation',
            'property_address' => 'Address P-VAL-1',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'This discovery is not on the valuation path and may not be pulled into a standalone assessment.');

        $this->assertDatabaseMissing('valuations', ['discovery_id' => $discovery['id']]);
    }

    /* ------------------------------------------------------------------ */
    /*  Sync ledger covers receipt + GPS entity types */
    /* ------------------------------------------------------------------ */

    public function test_sync_entity_tables_cover_receipt_and_gps_submissions(): void
    {
        $tables = SyncService::ENTITY_TABLES;

        $this->assertSame('payment_verifications', $tables['receipt']);
        $this->assertSame('gps_location_history', $tables['gps']);
    }

    /* ------------------------------------------------------------------ */
    /*  GPS certification quality + separation of duties */
    /* ------------------------------------------------------------------ */

    public function test_gps_certify_preserves_reported_accuracy_above_100_metres(): void
    {
        $officer = $this->enforcementOfficer();
        $bill = PropertyBill::create([
            'document_number' => 'GPS-ACCURACY-1',
            'property_id' => 'P-GPS-1',
            'taxpayer_name' => 'GPS Accuracy Taxpayer',
            'property_classification' => 'Residential',
            'property_address' => 'GPS Accuracy Road',
            'tax_amount' => 100,
            'interest_charged' => 0,
            'penalty_charged' => 0,
            'total_tax_due' => 100,
            'tax_period' => '2026',
            'payment_status' => 'Unpaid',
            'delivery_status' => PropertyBill::DELIVERY_STAGES[0],
            'case_status' => 'Assigned',
            'date_logged' => now()->toDateString(),
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

        Sanctum::actingAs($officer);

        $this->postJson('/api/v1/gps/certify', [
            'property_id' => 'P-GPS-1',
            'latitude' => 6.3000,
            'longitude' => -10.8000,
            'accuracy' => 150,
            'certification_decision' => 'yes',
            'source' => 'other',
            'task_id' => $task->id,
        ])->assertCreated()
            ->assertJsonPath('data.verification_status', 'pending_review');

        $this->assertDatabaseHas('gps_location_history', ['property_id' => 'P-GPS-1', 'accuracy' => 150]);
    }

    public function test_gps_certify_rejects_negative_reported_accuracy(): void
    {
        $officer = $this->enforcementOfficer();
        $bill = PropertyBill::create([
            'document_number' => 'GPS-ACCURACY-2',
            'property_id' => 'P-GPS-2',
            'taxpayer_name' => 'GPS Accuracy Taxpayer',
            'property_classification' => 'Residential',
            'property_address' => 'GPS Accuracy Road',
            'tax_amount' => 100,
            'interest_charged' => 0,
            'penalty_charged' => 0,
            'total_tax_due' => 100,
            'tax_period' => '2026',
            'payment_status' => 'Unpaid',
            'delivery_status' => PropertyBill::DELIVERY_STAGES[0],
            'case_status' => 'Assigned',
            'date_logged' => now()->toDateString(),
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

        Sanctum::actingAs($officer);

        $this->postJson('/api/v1/gps/certify', [
            'property_id' => 'P-GPS-2',
            'latitude' => 6.3000,
            'longitude' => -10.8000,
            'accuracy' => -1,
            'certification_decision' => 'yes',
            'source' => 'other',
            'task_id' => $task->id,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('gps_location_history', ['property_id' => 'P-GPS-2']);
    }

    public function test_gps_approver_cannot_approve_a_certification_they_performed(): void
    {
        $supervisor = $this->enforcementSupervisor();

        $history = GpsLocationHistory::create([
            'property_id' => 'P-GPS-2',
            'new_latitude' => 6.3000,
            'new_longitude' => -10.8000,
            'source' => 'enforcement_visit',
            'verification_status' => 'pending_review',
            'captured_by' => $supervisor->id,
            'certified_by' => $supervisor->id,
            'captured_at' => now(),
            'certified_at' => now(),
        ]);

        Sanctum::actingAs($supervisor);
        $this->postJson("/api/v1/gps/reviews/{$history->id}/decision", [
            'decision' => 'approve',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Separation of duties: you may not approve a certification you performed. Have a different approving officer review it.');

        $this->assertSame('pending_review', $history->fresh()->verification_status);
    }
}
