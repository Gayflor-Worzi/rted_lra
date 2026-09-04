<?php

namespace App\Services;

use App\Models\EnforcementVisit;
use App\Models\PaymentVerification;
use App\Models\PropertyBill;
use App\Models\PropertyDiscovery;
use App\Models\StaffTarget;
use App\Models\Task;
use App\Models\Valuation;
use Illuminate\Database\Eloquent\Builder;

/**
 * Cross-checks every stored dashboard figure against the authoritative
 * transactional records it derives from (single source of truth). Surfaces
 * drift as discrete findings so it can be repaired and re-verified.
 */
class DataIntegrityService
{
    public const MAX_FINDINGS_PER_CHECK = 50;

    /** @return array{status: string, checks: array} */
    public static function run(): array
    {
        $checks = [
            self::billTotalMismatch(),
            self::outstandingExceedsTotal(),
            self::unpaidWithZeroBalance(),
            self::paidWithBalance(),
            self::outstandingMismatch(),
            self::incompleteCompletedTask(),
            self::deliveredWithoutVisit(),
            self::verifiedWithoutEvidence(),
            self::approvedValuationWithoutApproval(),
            self::completedDiscoveryWithoutProcessing(),
            self::staffTargetDrift(),
        ];

        $failed = array_filter($checks, fn ($check) => count($check['findings']) > 0);

        return [
            'status' => count($failed) === 0 ? 'healthy' : 'degraded',
            'checks' => $checks,
            'at' => date('c'),
        ];
    }

    private static function unpaidWithZeroBalance(): array
    {
        $bills = PropertyBill::query()
            ->where('payment_status', 'Unpaid')
            ->where(fn (Builder $q) => $q->whereNull('outstanding_balance')->orWhere('outstanding_balance', '<=', 0))
            ->limit(self::MAX_FINDINGS_PER_CHECK)
            ->get();

        return self::check(
            'unpaid_with_zero_balance',
            'Bills marked Unpaid but with no outstanding balance',
            $bills,
            fn (PropertyBill $b) => [
                'id' => $b->id,
                'reference' => $b->document_number ?? $b->id,
                'issue' => "payment_status=Unpaid, outstanding={$b->outstanding_balance}",
            ],
        );
    }

    private static function paidWithBalance(): array
    {
        $bills = PropertyBill::query()
            ->where('payment_status', 'Paid')
            ->where('outstanding_balance', '>', 0)
            ->limit(self::MAX_FINDINGS_PER_CHECK)
            ->get();

        return self::check(
            'paid_with_balance',
            'Bills marked Paid but still carrying an outstanding balance',
            $bills,
            fn (PropertyBill $b) => [
                'id' => $b->id,
                'reference' => $b->document_number ?? $b->id,
                'issue' => "payment_status=Paid, outstanding={$b->outstanding_balance}",
            ],
        );
    }

    private static function outstandingMismatch(): array
    {
        $bills = PropertyBill::query()
            ->with('payments:id,bill_id,amount')
            ->orderByDesc('id')
            ->limit(self::MAX_FINDINGS_PER_CHECK)
            ->get();

        $findings = [];
        foreach ($bills as $bill) {
            $paid = (float) $bill->payments->sum('amount');
            $expected = max(0, (float) $bill->total_tax_due - $paid);
            $stored = (float) ($bill->outstanding_balance ?: 0);

            if (abs($expected - $stored) > 0.01) {
                $findings[] = [
                    'id' => $bill->id,
                    'reference' => $bill->document_number ?? $bill->id,
                    'issue' => "outstanding={$stored}, expected={$expected} (total={$bill->total_tax_due}, paid={$paid})",
                ];
            }

            if (count($findings) >= self::MAX_FINDINGS_PER_CHECK) {
                break;
            }
        }

        return self::check(
            'outstanding_mismatch',
            'Bills whose stored outstanding balance does not match total_tax_due minus payments',
            $findings,
        );
    }

    private static function incompleteCompletedTask(): array
    {
        $tasks = Task::query()
            ->whereIn('status', Task::COMPLETED_STATUSES)
            ->whereNull('completed_at')
            ->limit(self::MAX_FINDINGS_PER_CHECK)
            ->get();

        return self::check(
            'incomplete_completed_task',
            'Tasks in a completed status without a completion timestamp',
            $tasks,
            fn (Task $t) => [
                'id' => $t->id,
                'reference' => $t->task_reference ?? $t->id,
                'issue' => "status={$t->status}, completed_at=null",
            ],
        );
    }

    private static function deliveredWithoutVisit(): array
    {
        $bills = PropertyBill::query()
            ->where('delivery_status', EnforcementVisit::DELIVERY_DELIVERED)
            ->with('visits:id,bill_id,delivery_status')
            ->limit(self::MAX_FINDINGS_PER_CHECK)
            ->get();

        $findings = [];
        foreach ($bills as $bill) {
            $delivered = $bill->visits->contains(fn (EnforcementVisit $v) => $v->delivery_status === EnforcementVisit::DELIVERY_DELIVERED);
            if (! $delivered) {
                $findings[] = [
                    'id' => $bill->id,
                    'reference' => $bill->document_number ?? $bill->id,
                    'issue' => 'delivery_status=Delivered but no Delivered visit recorded',
                ];
            }

            if (count($findings) >= self::MAX_FINDINGS_PER_CHECK) {
                break;
            }
        }

        return self::check(
            'delivered_without_visit',
            'Bills marked Delivered without a matching Delivered visit record',
            $findings,
        );
    }

    private static function verifiedWithoutEvidence(): array
    {
        $items = PaymentVerification::query()
            ->where('verification_status', PaymentVerification::STATUS_CONFIRMED)
            ->whereNull('receipt_attachment')
            ->limit(self::MAX_FINDINGS_PER_CHECK)
            ->get();

        return self::check(
            'verified_without_evidence',
            'Confirmed payment verifications with no receipt attachment',
            $items,
            fn (PaymentVerification $v) => [
                'id' => $v->id,
                'reference' => $v->receipt_number ?? $v->document_number ?? $v->id,
                'issue' => 'verification_status=Confirmed, receipt_attachment=null',
            ],
        );
    }

    private static function approvedValuationWithoutApproval(): array
    {
        $valuations = Valuation::query()
            ->where('status', 'Approved')
            ->whereNull('ac_reviewed_at')
            ->limit(self::MAX_FINDINGS_PER_CHECK)
            ->get();

        return self::check(
            'approved_valuation_without_approval',
            'Approved valuations missing the AC approval decision record',
            $valuations,
            fn (Valuation $v) => [
                'id' => $v->id,
                'reference' => $v->valuation_reference ?? $v->id,
                'issue' => 'status=Approved, ac_reviewed_at=null',
            ],
        );
    }

    private static function completedDiscoveryWithoutProcessing(): array
    {
        $discoveries = PropertyDiscovery::query()
            ->where('status', PropertyDiscovery::STATUS_COMPLETED)
            ->whereNull('processed_at')
            ->limit(self::MAX_FINDINGS_PER_CHECK)
            ->get();

        return self::check(
            'completed_discovery_without_processing',
            'Completed discoveries missing the LITAS processing confirmation',
            $discoveries,
            fn (PropertyDiscovery $d) => [
                'id' => $d->id,
                'reference' => $d->discovery_reference ?? $d->id,
                'issue' => 'status=COMPLETED, processed_at=null',
            ],
        );
    }

    private static function billTotalMismatch(): array
    {
        $bills = PropertyBill::query()
            ->whereRaw('ABS(total_tax_due - (tax_amount + interest_charged + penalty_charged)) > 0.01')
            ->limit(self::MAX_FINDINGS_PER_CHECK)
            ->get();

        return self::check(
            'bill_total_mismatch',
            'Bills whose total_tax_due does not equal tax_amount + interest_charged + penalty_charged',
            $bills,
            fn (PropertyBill $b) => [
                'id' => $b->id,
                'reference' => $b->document_number ?? $b->id,
                'issue' => "total_tax_due={$b->total_tax_due}, sum=".round((float) $b->tax_amount + (float) $b->interest_charged + (float) $b->penalty_charged, 2),
            ],
        );
    }

    private static function outstandingExceedsTotal(): array
    {
        $bills = PropertyBill::query()
            ->where('outstanding_balance', '>', 0)
            ->whereRaw('outstanding_balance > total_tax_due + 0.01')
            ->limit(self::MAX_FINDINGS_PER_CHECK)
            ->get();

        return self::check(
            'outstanding_exceeds_total',
            'Bills whose outstanding balance exceeds the total tax due',
            $bills,
            fn (PropertyBill $b) => [
                'id' => $b->id,
                'reference' => $b->document_number ?? $b->id,
                'issue' => "outstanding={$b->outstanding_balance}, total_tax_due={$b->total_tax_due}",
            ],
        );
    }

    private static function staffTargetDrift(): array
    {
        $targets = StaffTarget::where('status', 'Approved')
            ->with('user:id,full_name')
            ->limit(self::MAX_FINDINGS_PER_CHECK)
            ->get();

        $findings = [];
        foreach ($targets as $target) {
            $liveAchieved = round($target->computeAchievedValue(), 2);
            $storedAchieved = round((float) $target->achieved_value, 2);

            if (abs($liveAchieved - $storedAchieved) > 0.5) {
                $findings[] = [
                    'id' => $target->id,
                    'reference' => $target->metric.' ('.($target->user?->full_name ?? 'User #'.$target->user_id).')',
                    'issue' => "stored_achieved={$storedAchieved}, live_achieved={$liveAchieved}, target={$target->target_value}",
                ];
            }

            if (count($findings) >= self::MAX_FINDINGS_PER_CHECK) {
                break;
            }
        }

        return self::check(
            'staff_target_drift',
            'Staff targets whose stored achieved value differs from the live-computed value',
            $findings,
        );
    }

    /**
     * Normalise a check result. `$source` may be an Eloquent collection (a
     * $formatter is then required) or a pre-built array of finding maps.
     */
    private static function check(string $key, string $label, iterable $source, callable $formatter = null): array
    {
        $findings = [];

        if ($formatter !== null) {
            foreach ($source as $row) {
                $findings[] = $formatter($row);
            }
        } else {
            $findings = $source;
        }

        return [
            'key' => $key,
            'label' => $label,
            'count' => count($findings),
            'truncated' => count($findings) >= self::MAX_FINDINGS_PER_CHECK,
            'findings' => array_slice($findings, 0, self::MAX_FINDINGS_PER_CHECK),
        ];
    }
}