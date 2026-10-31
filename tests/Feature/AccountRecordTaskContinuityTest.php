<?php

namespace Tests\Feature;

use App\Models\PropertyDiscovery;
use App\Models\Task;
use App\Models\User;
use App\Models\Valuation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Account & Record Manager's own review task has to retire when their decision is
 * made, on both routes a property can arrive by.
 *
 * Left open, the retired stage kept counting as Active work beside the task raised for
 * the officer who picked the record up next. A property that had been processed through
 * to completion still sat in the manager's Active tab, and their Assigned counter never
 * moved — the exact symptom reported on the A&R task list.
 *
 * These tests pin both directions: the finished stage closes on every decision the
 * manager can take, and a stage that is genuinely still owed is never touched.
 */
class AccountRecordTaskContinuityTest extends TestCase
{
    use RefreshDatabase;

    /** Task types this desk owns, i.e. what its Active/Completed tabs are built from. */
    private const DESK_TYPES = [
        Task::TYPE_DISCOVERY_REVIEW,
        Task::TYPE_ACCOUNT_MANAGER_REVIEW,
        Task::TYPE_ACCOUNT_PROCESSING,
    ];

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

    private function task(string $type, string $refType, int $refId): Task
    {
        return Task::where('task_type', $type)
            ->where('reference_type', $refType)
            ->where('reference_id', $refId)
            ->latest('id')
            ->firstOrFail();
    }

    /** Open tasks on the A&R Manager's own desk — what the Active tab lists. */
    private function openDeskTasks(string $email): Collection
    {
        return Task::where('assigned_to', $this->user($email)->id)
            ->whereIn('task_type', self::DESK_TYPES)
            ->whereNotIn('status', Task::COMPLETED_STATUSES)
            ->get();
    }

    private function submitDiscovery(string $classification): PropertyDiscovery
    {
        $this->act('account.officer@test.lra');
        $id = $this->postJson('/api/v1/discoveries', [
            'owner_name' => 'Owner '.$classification,
            'owner_contact' => '0770-100-110',
            'tin' => 'TIN-ARC-'.substr(md5($classification), 0, 5),
            'property_address' => $classification.' Way, Monrovia',
            'county' => 'Montserrado',
            'district' => 'Greater Monrovia',
            'property_classification' => $classification,
            'property_type' => 'House',
            'occupancy_use' => $classification,
            'discovery_date' => now()->toDateString(),
            'gps_coordinate' => '6.3130,-10.8074',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/discoveries/{$id}/submit")->assertOk();

        return PropertyDiscovery::findOrFail($id);
    }

    /** Drives a commercial discovery all the way to the A&R Manager's own review. */
    private function valuationAwaitingAccountReview(): Valuation
    {
        $discovery = $this->submitDiscovery('Commercial');

        $this->act('val.manager@test.lra');
        $this->postJson("/api/v1/discoveries/{$discovery->id}/val-review", [
            'decision' => 'assign',
            'officer_id' => $this->user('val.officer@test.lra')->id,
        ])->assertOk();

        // Routing to the officer is what raises the assessment.
        $valuation = Valuation::findOrFail($discovery->fresh()->valuation_id);
        $this->assertSame(Valuation::STATUS_ASSIGNED, $valuation->status);

        $this->act('val.officer@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/descriptions", [
            'descriptions' => [[
                'description' => 'Land',
                'level' => 'Land',
                'tar' => 5000.00,
                'quantity' => 1,
            ], [
                'description' => 'Commercial floor.',
                'level' => 'Ground Floor',
                'area_sqft' => 4000,
                'tar' => 25.0,
                'quantity' => 1,
                'amount' => 100000.00,
                'depreciation_pct' => 0,
            ]],
        ])->assertOk();

        $this->putJson("/api/v1/valuations/{$valuation->id}", [
            'photos' => 'proof://ARC-VAL',
            'owner_contact' => '0770-100-110',
        ])->assertOk();

        $this->postJson("/api/v1/valuations/{$valuation->id}/submit", [
            'assessed_value' => 900000.00,
            'annual_tax' => 31500.00,
            'applicable_tax_rate' => 3.5,
        ])->assertOk();

        $this->act('val.manager@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/review", [
            'decision' => 'confirm_ac',
            'remarks' => 'Assessment correct.',
        ])->assertOk();

        $this->act('ac@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/decide", [
            'decision' => 'approve',
            'remarks' => 'Approved.',
        ])->assertOk();

        $valuation->refresh();
        $this->assertSame(Valuation::STATUS_AC_APPROVED, $valuation->status);

        return $valuation;
    }

    private function assertRetired(Task $task, string $action): void
    {
        $task->refresh();
        $this->assertContains(
            $task->status,
            Task::COMPLETED_STATUSES,
            "{$task->task_reference} should have retired once the A&R review was decided."
        );
        $this->assertNotNull($task->completed_at);
        $this->assertTrue(
            $task->history()->where('action', $action)->exists(),
            "{$task->task_reference} should record a '{$action}' history entry."
        );
        $this->assertTrue(Task::whereKey($task->id)->exists(), 'A retired stage is closed, never deleted.');
    }

    /* ------------------------------------------------------------------ */
    /*  Route 1 — property arrived directly from Discovery */
    /* ------------------------------------------------------------------ */

    public function test_the_discovery_review_task_retires_when_the_manager_approves(): void
    {
        $discovery = $this->submitDiscovery('Residential');
        $review = $this->task(Task::TYPE_DISCOVERY_REVIEW, 'property_discovery', $discovery->id);
        $this->assertSame('Assigned', $review->status);

        $this->act('account.manager@test.lra');
        $this->postJson("/api/v1/discoveries/{$discovery->id}/acct-review", [
            'decision' => 'approve',
            'officer_id' => $this->user('account.officer@test.lra')->id,
            'remarks' => 'Proceed with registration.',
        ])->assertOk();

        $this->assertRetired($review, 'approved_for_processing');

        // The only work the manager still holds is what is genuinely still owed.
        $this->assertCount(0, $this->openDeskTasks('account.manager@test.lra'));
    }

    public function test_the_discovery_review_task_retires_when_the_manager_returns_it(): void
    {
        $discovery = $this->submitDiscovery('Residential');
        $review = $this->task(Task::TYPE_DISCOVERY_REVIEW, 'property_discovery', $discovery->id);

        $this->act('account.manager@test.lra');
        $this->postJson("/api/v1/discoveries/{$discovery->id}/acct-review", [
            'decision' => 'return',
            'remarks' => 'Owner details unclear.',
        ])->assertOk();

        $this->assertRetired($review, 'returned_for_correction');
        $this->assertCount(0, $this->openDeskTasks('account.manager@test.lra'));
    }

    public function test_the_discovery_review_task_retires_when_the_record_is_referred_to_valuation(): void
    {
        $discovery = $this->submitDiscovery('Residential');
        $review = $this->task(Task::TYPE_DISCOVERY_REVIEW, 'property_discovery', $discovery->id);

        $this->act('account.manager@test.lra');
        $this->postJson("/api/v1/discoveries/{$discovery->id}/acct-review", [
            'decision' => 'refer_to_valuation',
            'remarks' => 'Formal assessment required.',
        ])->assertOk();

        $this->assertRetired($review, 'referred_to_valuation');
        $this->assertCount(0, $this->openDeskTasks('account.manager@test.lra'));
    }

    public function test_the_discovery_review_task_retires_when_the_record_is_rejected(): void
    {
        $discovery = $this->submitDiscovery('Residential');
        $review = $this->task(Task::TYPE_DISCOVERY_REVIEW, 'property_discovery', $discovery->id);

        $this->act('account.manager@test.lra');
        $this->postJson("/api/v1/discoveries/{$discovery->id}/acct-review", [
            'decision' => 'reject',
            'remarks' => 'Outside the municipal boundary.',
        ])->assertOk();

        $this->assertRetired($review, 'rejected');
        $this->assertCount(0, $this->openDeskTasks('account.manager@test.lra'));
    }

    public function test_a_decision_that_is_rejected_for_an_ineligible_officer_leaves_the_review_task_open(): void
    {
        $discovery = $this->submitDiscovery('Residential');
        $review = $this->task(Task::TYPE_DISCOVERY_REVIEW, 'property_discovery', $discovery->id);

        $this->act('account.manager@test.lra');
        $this->postJson("/api/v1/discoveries/{$discovery->id}/acct-review", [
            'decision' => 'approve',
            // A Valuation Officer may not process a property registration.
            'officer_id' => $this->user('val.officer@test.lra')->id,
        ])->assertStatus(422);

        // Nothing was decided, so nothing may be retired.
        $this->assertSame('Assigned', $review->fresh()->status);
        $this->assertCount(1, $this->openDeskTasks('account.manager@test.lra'));
    }

    public function test_a_review_still_awaiting_the_manager_is_never_closed(): void
    {
        $discovery = $this->submitDiscovery('Residential');

        $this->assertSame(PropertyDiscovery::STATUS_AWAITING_ACCT_REVIEW, $discovery->status);
        $this->assertCount(1, $this->openDeskTasks('account.manager@test.lra'));

        $this->artisan('lra:close-completed-stage-tasks --execute')->assertSuccessful();

        $review = $this->task(Task::TYPE_DISCOVERY_REVIEW, 'property_discovery', $discovery->id);
        $this->assertSame('Assigned', $review->status, 'A pending review must survive reconciliation.');
    }

    /* ------------------------------------------------------------------ */
    /*  Route 2 — property arrived through the Valuation workflow */
    /* ------------------------------------------------------------------ */

    public function test_the_account_manager_review_task_retires_when_the_manager_approves(): void
    {
        $valuation = $this->valuationAwaitingAccountReview();
        $review = $this->task(Task::TYPE_ACCOUNT_MANAGER_REVIEW, 'valuation', $valuation->id);
        $this->assertSame('Assigned', $review->status);
        $this->assertCount(1, $this->openDeskTasks('account.manager@test.lra'));

        $this->act('account.manager@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/account-review", [
            'decision' => 'approve',
            'officer_id' => $this->user('account.officer@test.lra')->id,
        ])->assertOk();

        $this->assertRetired($review, 'approved_for_processing');
        $this->assertCount(0, $this->openDeskTasks('account.manager@test.lra'));
    }

    public function test_the_account_manager_review_task_retires_when_the_manager_refers_it_back(): void
    {
        $valuation = $this->valuationAwaitingAccountReview();
        $review = $this->task(Task::TYPE_ACCOUNT_MANAGER_REVIEW, 'valuation', $valuation->id);

        $this->act('account.manager@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/account-review", [
            'decision' => 'return',
            'remarks' => 'Declared value inconsistent with the assessment.',
        ])->assertOk();

        $this->assertRetired($review, 'returned_to_valuation_section');
        $this->assertCount(0, $this->openDeskTasks('account.manager@test.lra'));
    }

    public function test_a_valuation_awaiting_the_manager_is_never_closed_by_reconciliation(): void
    {
        $valuation = $this->valuationAwaitingAccountReview();

        $this->artisan('lra:close-completed-stage-tasks --execute')->assertSuccessful();

        $review = $this->task(Task::TYPE_ACCOUNT_MANAGER_REVIEW, 'valuation', $valuation->id);
        $this->assertSame(
            'Assigned',
            $review->status,
            'An AC-approved valuation is still owed an A&R review and must stay open.'
        );
        $this->assertCount(1, $this->openDeskTasks('account.manager@test.lra'));
    }

    public function test_reconciliation_retires_a_completed_discovery_review_but_leaves_the_pending_one(): void
    {
        // A property the A&R desk already processed through to completion.
        $done = $this->submitDiscovery('Residential');
        $this->act('account.manager@test.lra');
        $this->postJson("/api/v1/discoveries/{$done->id}/acct-review", [
            'decision' => 'approve',
            'officer_id' => $this->user('account.officer@test.lra')->id,
        ])->assertOk();
        $this->act('account.officer@test.lra');
        $this->postJson("/api/v1/discoveries/{$done->id}/account-processing", [
            'property_id' => 'ARC-P-1',
            'document_number' => '2026/ARC-1',
            'property_tax_amount' => 5000.00,
            'tax_period' => '2026',
            'taxpayer_name' => 'Taxpayer ARC',
        ])->assertOk();
        $this->postJson("/api/v1/discoveries/{$done->id}/complete")->assertOk();
        $done->refresh();
        $this->assertSame(PropertyDiscovery::STATUS_COMPLETED, $done->status);

        // Pretend the stage predates the fix: the completed record keeps an open review.
        $stale = $this->task(Task::TYPE_DISCOVERY_REVIEW, 'property_discovery', $done->id);
        $stale->update(['status' => 'Assigned', 'completed_at' => null]);

        // A second property the manager has not looked at yet.
        $pending = $this->submitDiscovery('Residential');
        $pendingReview = $this->task(Task::TYPE_DISCOVERY_REVIEW, 'property_discovery', $pending->id);

        $this->artisan('lra:close-completed-stage-tasks --execute')->assertSuccessful();

        $this->assertContains($stale->fresh()->status, Task::COMPLETED_STATUSES);
        $this->assertSame(
            'Assigned',
            $pendingReview->fresh()->status,
            'Reconciliation must not touch work that is still owed.'
        );
    }

    /* ------------------------------------------------------------------ */
    /*  The desks upstream of Account & Record must retire too, otherwise */
    /*  a property still looks like pending work at the Valuation Manager */
    /*  and the Assistant Commissioner after both have already decided. */
    /* ------------------------------------------------------------------ */

    public function test_the_manager_review_and_ac_approval_tasks_retire_on_approval(): void
    {
        $valuation = $this->valuationAwaitingAccountReview();

        $managerReview = $this->task(Task::TYPE_VALUATION_REVIEW, 'valuation', $valuation->id);
        $acApproval = $this->task(Task::TYPE_AC_APPROVAL, 'valuation', $valuation->id);

        // Both decisions have been recorded, so neither desk still owes anything.
        $this->assertRetired($managerReview, 'forwarded_to_ac');
        $this->assertRetired($acApproval, 'ac_approve');

        // The A&R review is the only work still genuinely open.
        $this->assertCount(0, $this->openDeskTasks('val.manager@test.lra'));
        $this->assertCount(0, Task::where('assigned_to', $this->user('ac@test.lra')->id)
            ->whereNotIn('status', Task::COMPLETED_STATUSES)->get());
        $this->assertCount(1, $this->openDeskTasks('account.manager@test.lra'));
    }

    public function test_the_manager_review_task_retires_when_the_manager_returns_the_assessment(): void
    {
        $discovery = $this->submitDiscovery('Commercial');

        $this->act('val.manager@test.lra');
        $this->postJson("/api/v1/discoveries/{$discovery->id}/val-review", [
            'decision' => 'assign',
            'officer_id' => $this->user('val.officer@test.lra')->id,
        ])->assertOk();
        $valuation = Valuation::findOrFail($discovery->fresh()->valuation_id);

        $this->act('val.officer@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/descriptions", [
            'descriptions' => [[
                'description' => 'Land',
                'level' => 'Land',
                'tar' => 5000.00,
                'quantity' => 1,
            ], [
                'description' => 'Shop.',
                'level' => 'Ground Floor',
                'area_sqft' => 900,
                'tar' => 20.0,
                'quantity' => 1,
                'amount' => 18000.00,
                'depreciation_pct' => 0,
            ]],
        ])->assertOk();
        $this->putJson("/api/v1/valuations/{$valuation->id}", ['photos' => 'proof://ARC-VAL2'])->assertOk();
        $this->postJson("/api/v1/valuations/{$valuation->id}/submit", [
            'assessed_value' => 90000.00,
            'annual_tax' => 3150.00,
            'applicable_tax_rate' => 3.5,
        ])->assertOk();

        $this->act('val.manager@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/review", [
            'decision' => 'return',
            'remarks' => 'Overstated.',
        ])->assertOk();

        $this->assertRetired(
            $this->task(Task::TYPE_VALUATION_REVIEW, 'valuation', $valuation->id),
            'returned_to_officer'
        );
    }

    public function test_the_ac_approval_task_retires_on_an_ac_rejection(): void
    {
        $discovery = $this->submitDiscovery('Commercial');

        $this->act('val.manager@test.lra');
        $this->postJson("/api/v1/discoveries/{$discovery->id}/val-review", [
            'decision' => 'assign',
            'officer_id' => $this->user('val.officer@test.lra')->id,
        ])->assertOk();
        $valuation = Valuation::findOrFail($discovery->fresh()->valuation_id);

        $this->act('val.officer@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/descriptions", [
            'descriptions' => [[
                'description' => 'Land',
                'level' => 'Land',
                'tar' => 5000.00,
                'quantity' => 1,
            ], [
                'description' => 'Depot.',
                'level' => 'Ground Floor',
                'area_sqft' => 1500,
                'tar' => 20.0,
                'quantity' => 1,
                'amount' => 30000.00,
                'depreciation_pct' => 0,
            ]],
        ])->assertOk();
        $this->putJson("/api/v1/valuations/{$valuation->id}", ['photos' => 'proof://ARC-VAL3'])->assertOk();
        $this->postJson("/api/v1/valuations/{$valuation->id}/submit", [
            'assessed_value' => 150000.00,
            'annual_tax' => 5250.00,
            'applicable_tax_rate' => 3.5,
        ])->assertOk();

        $this->act('val.manager@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/review", [
            'decision' => 'confirm_ac',
        ])->assertOk();

        $this->act('ac@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/decide", [
            'decision' => 'reject',
            'remarks' => 'Outside the taxable boundary.',
        ])->assertOk();

        $this->assertRetired(
            $this->task(Task::TYPE_AC_APPROVAL, 'valuation', $valuation->id),
            'ac_reject'
        );
    }

    public function test_an_ac_approval_still_awaiting_the_decision_is_never_closed(): void
    {
        $discovery = $this->submitDiscovery('Commercial');

        $this->act('val.manager@test.lra');
        $this->postJson("/api/v1/discoveries/{$discovery->id}/val-review", [
            'decision' => 'assign',
            'officer_id' => $this->user('val.officer@test.lra')->id,
        ])->assertOk();
        $valuation = Valuation::findOrFail($discovery->fresh()->valuation_id);

        $this->act('val.officer@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/descriptions", [
            'descriptions' => [[
                'description' => 'Land',
                'level' => 'Land',
                'tar' => 5000.00,
                'quantity' => 1,
            ], [
                'description' => 'Store.',
                'level' => 'Ground Floor',
                'area_sqft' => 1200,
                'tar' => 20.0,
                'quantity' => 1,
                'amount' => 24000.00,
                'depreciation_pct' => 0,
            ]],
        ])->assertOk();
        $this->putJson("/api/v1/valuations/{$valuation->id}", ['photos' => 'proof://ARC-VAL4'])->assertOk();
        $this->postJson("/api/v1/valuations/{$valuation->id}/submit", [
            'assessed_value' => 120000.00,
            'annual_tax' => 4200.00,
            'applicable_tax_rate' => 3.5,
        ])->assertOk();

        $this->act('val.manager@test.lra');
        $this->postJson("/api/v1/valuations/{$valuation->id}/review", [
            'decision' => 'confirm_ac',
        ])->assertOk();

        $valuation->refresh();
        $this->assertContains($valuation->status, [
            Valuation::STATUS_SUBMITTED_TO_AC,
            Valuation::STATUS_AWAITING_AC_APPROVAL,
        ]);

        $this->artisan('lra:close-completed-stage-tasks --execute')->assertSuccessful();

        $this->assertSame(
            'Assigned',
            $this->task(Task::TYPE_AC_APPROVAL, 'valuation', $valuation->id)->status,
            'A decision the AC has not made yet must survive reconciliation.'
        );
    }
}
