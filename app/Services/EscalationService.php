<?php

namespace App\Services;

use App\Models\PropertyBill;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Enforcement escalation engine.
 *
 * BILL DELIVERED → 30 DAYS → 30-DAY REMINDER → 72-HOUR DEMAND → FINAL
 * ENFORCEMENT → CLOSURE → LEGAL RECOVERY.
 *
 * Eligibility is derived from dates + payment/verification state — an officer
 * never manually advances 30 → 72; the state machine and this engine decide.
 */
class EscalationService
{
    /**
     * Evaluate the bill's next eligible escalation step.
     *
     * @return array{stage: string, eligible_action: ?string, reason: ?string}
     */
    public function evaluate(PropertyBill $bill): array
    {
        $outstanding = (float) $bill->outstanding_balance;

        // Paid / nothing left -> no escalation.
        if ($outstanding <= 0 || in_array($bill->payment_status, ['Paid'], true)) {
            return ['stage' => $bill->escalation_stage ?? $bill->case_status, 'eligible_action' => null, 'reason' => 'No outstanding balance.'];
        }

        // Hold while a payment claim is pending verification.
        if ($bill->case_status === 'Under Verification'
            || $bill->verifications()->where('verification_status', 'Pending')->exists()) {
            return ['stage' => $bill->escalation_stage ?? $bill->case_status, 'eligible_action' => null, 'reason' => 'Payment claim pending verification.'];
        }

        if (! $bill->delivery_date) {
            return ['stage' => $bill->escalation_stage ?? $bill->case_status, 'eligible_action' => null, 'reason' => 'Bill not yet delivered.'];
        }

        $daysSinceDelivery = Carbon::parse($bill->delivery_date)->startOfDay()->diffInDays(now()->startOfDay());

        // 30-DAY REMINDER: 30 days after delivery.
        if (! $bill->thirty_day_notice_date && $daysSinceDelivery >= 30) {
            return ['stage' => '30-Day Warning', 'eligible_action' => 'issue_thirty_day', 'reason' => "30 days elapsed since delivery ({$daysSinceDelivery}d)."];
        }

        $notice = $bill->thirty_day_notice_date ? Carbon::parse($bill->thirty_day_notice_date)->startOfDay() : null;
        $daysSinceNotice = $notice?->diffInDays(now()->startOfDay()) ?? 0;

        // 72-HOUR DEMAND: 30 days after the 30-day notice.
        if ($bill->thirty_day_notice_date && ! $bill->final_notice_date && $daysSinceNotice >= 30) {
            return ['stage' => '72-Hour Warning', 'eligible_action' => 'issue_seventy_two_hour', 'reason' => "30 days elapsed since 30-day notice ({$daysSinceNotice}d)."];
        }

        $final = $bill->final_notice_date ? Carbon::parse($bill->final_notice_date)->startOfDay() : null;
        $daysSinceFinal = $final?->diffInDays(now()->startOfDay()) ?? 0;

        // FINAL ENFORCEMENT: 3 days after the 72-hour demand.
        if ($bill->final_notice_date && $bill->escalation_stage !== 'Escalated' && $daysSinceFinal >= 3) {
            return ['stage' => 'Escalated', 'eligible_action' => 'final_enforcement', 'reason' => "72-hour demand window elapsed ({$daysSinceFinal}d)."];
        }

        // CLOSURE / LEGAL RECOVERY: prolonged escalation (> 21 days).
        if ($bill->escalation_stage === 'Escalated' && $bill->final_notice_date && $daysSinceFinal >= 21) {
            return ['stage' => 'Closure', 'eligible_action' => 'closure', 'reason' => 'Escalated for more than 21 days.'];
        }

        return ['stage' => $bill->escalation_stage ?? $bill->case_status, 'eligible_action' => null, 'reason' => 'Not yet eligible.'];
    }

    /**
     * Advance the bill (and its task) to the eligible escalation step.
     * Returns the new escalation stage, or null when not eligible.
     */
    public function autoAdvance(PropertyBill $bill, ?User $actor = null): ?string
    {
        $result = $this->evaluate($bill);

        if (! $result['eligible_action'] || in_array($bill->case_status, ['Under Verification', 'Resolved', 'Closed'], true)) {
            return null;
        }

        $task = $bill->tasks()->orderByDesc('id')->first();

        if (! $task || in_array($task->status, ['Resolved', 'Closed', 'Paid'], true)) {
            return null;
        }

        $actor ??= auth()->user();

        try {
            switch ($result['eligible_action']) {
                case 'issue_thirty_day':
                    // Flow the task to Payment Follow-up first if it is a fresh delivery.
                    if ($task->status === 'Delivered') {
                        $task->transitionTo('Payment Follow-up', 'auto', $actor, 'Auto: bill due for collection.');
                    }
                    $task->transitionTo('30-Day Warning', 'auto', $actor, "Auto: {$result['reason']}");
                    $bill->update([
                        'thirty_day_notice_date' => now()->toDateString(),
                        'escalation_stage' => '30-Day Warning',
                    ]);

                    return '30-Day Warning';

                case 'issue_seventy_two_hour':
                    if ($task->status === '30-Day Warning' || $task->status === 'Payment Follow-up') {
                        $task->transitionTo('72-Hour Warning', 'auto', $actor, "Auto: {$result['reason']}");
                    }
                    $bill->update([
                        'final_notice_date' => now()->toDateString(),
                        'escalation_stage' => '72-Hour Warning',
                    ]);

                    return '72-Hour Warning';

                case 'final_enforcement':
                    if (in_array($task->status, ['30-Day Warning', '72-Hour Warning', 'Payment Follow-up'], true)) {
                        $task->transitionTo('Escalated', 'auto', $actor, "Auto: {$result['reason']}");
                    }
                    $bill->update(['escalation_stage' => 'Escalated']);

                    return 'Escalated';

                case 'closure':
                    $task->transitionTo('Closed', 'auto', $actor, "Auto: {$result['reason']}");
                    $bill->update(['escalation_stage' => 'Closure']);

                    return 'Closure';
            }
        } catch (\InvalidArgumentException) {
            return null;
        }

        return null;
    }
}
