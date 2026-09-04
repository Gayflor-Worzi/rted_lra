<?php

namespace App\Console\Commands;

use App\Models\PropertyBill;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class StatutoryOverdueCommand extends Command
{
    protected $signature = 'lra:statutory-overdue';

    protected $description = 'Flag all unpaid property bills as Statutorily Overdue (runs after the July 1 statutory deadline)';

    public function handle(): int
    {
        $bills = PropertyBill::where(function ($q) {
            $q->whereNull('payment_status')->orWhere('payment_status', '!=', 'Paid');
        })
            ->where('case_status', '!=', 'Statutorily Overdue')
            ->get();

        $flagged = 0;

        DB::transaction(function () use ($bills, &$flagged) {
            foreach ($bills as $bill) {
                $bill->forceFill(['case_status' => 'Statutorily Overdue'])->save();
                $flagged++;
            }
        });

        if ($flagged > 0) {
            NotificationService::sendToRole(
                'Enforcement Manager',
                'Statutory overdue sweep executed',
                "{$flagged} unpaid property bill(s) flagged 'Statutorily Overdue' after the July 1 deadline and escalated to your dashboard.",
                'statutory_overdue',
                null,
                null,
                'high'
            );

            NotificationService::sendToRole(
                'M&E Officer',
                'Statutory overdue sweep executed',
                "{$flagged} property bill(s) are now 'Statutorily Overdue'.",
                'statutory_overdue'
            );
        }

        $this->info("Statutory overdue sweep complete: {$flagged} bill(s) flagged.");

        return self::SUCCESS;
    }
}
