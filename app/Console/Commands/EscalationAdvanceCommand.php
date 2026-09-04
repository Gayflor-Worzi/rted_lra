<?php

namespace App\Console\Commands;

use App\Models\PropertyBill;
use App\Services\EscalationService;
use Illuminate\Console\Command;

/**
 * Advances every delivered, unpaid bill through the statutory escalation ladder
 * (30-Day Reminder → 72-Hour Demand → Final Enforcement → Closure). Schedule on
 * cron; also callable by hand: php artisan enforcement:escalate.
 */
class EscalationAdvanceCommand extends Command
{
    protected $signature = 'enforcement:escalate {--dry-run : Report eligibility without mutating}';

    protected $description = 'Auto-advance unpaid bills through the escalation ladder';

    public function handle(EscalationService $escalation): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $bills = PropertyBill::query()
            ->whereNotNull('delivery_date')
            ->whereNotIn('case_status', ['Under Verification', 'Resolved', 'Closed'])
            ->where('payment_status', '!=', 'Paid')
            ->orderByDesc('id')
            ->get();

        $moved = 0;
        foreach ($bills as $bill) {
            $result = $escalation->evaluate($bill);

            if (! $result['eligible_action']) {
                continue;
            }

            if ($dryRun) {
                $this->line("  [DRY-RUN] Bill {$bill->document_number}: eligible for {$result['eligible_action']} ({$result['reason']})");

                continue;
            }

            $stage = $escalation->autoAdvance($bill, null);
            if ($stage) {
                $moved++;
                $this->line("  Advanced bill {$bill->document_number} → {$stage}");
            }
        }

        $this->info($dryRun ? "Dry-run complete. {$bills->count()} bills evaluated." : "Escalation pass complete — {$moved} bills advanced.");

        return self::SUCCESS;
    }
}
