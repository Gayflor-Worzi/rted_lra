<?php

namespace App\Services;

use App\Models\PaymentVerification;

/**
 * Receipt duplicate detection for payment claims.
 *
 * A receipt is fingerprinted from its normalized receipt number + amount +
 * payment period. The fingerprint is stored on every claim and reserved in the
 * `receipt_fingerprints` uniqueness table so a receipt that is already claimed
 * or approved for one property cannot be re-claimed for another.
 *
 * The service searches the complete receipt dataset internally but only
 * exposes the minimum reference information (property id + claim status) an
 * actor needs to understand a conflict — never the other taxpayer's records.
 */
class ReceiptDuplicationService
{
    /** Claims that still "hold" a receipt: pending, approved, or in exception. */
    public const ACTIVE_STATUSES = [
        PaymentVerification::STATUS_PENDING,
        PaymentVerification::STATUS_CONFIRMED,
        PaymentVerification::STATUS_EXCEPTION,
    ];

    public static function normalizeReceiptNumber(?string $value): string
    {
        return (string) preg_replace('/\s+/', ' ', mb_strtoupper(trim((string) ($value ?? ''))));
    }

    public static function normalizePeriod(?string $value): string
    {
        return (string) preg_replace('/\s+/', ' ', mb_strtoupper(trim((string) ($value ?? ''))));
    }

    public static function normalizeAmount(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /**
     * Deterministic SHA-256 fingerprint for a receipt claim. Two officers must
     * enter the same receipt identically (up to casing/whitespace) to collide.
     */
    public static function fingerprint(?string $receiptNumber, mixed $amount, ?string $period): string
    {
        return hash('sha256', implode('|', [
            self::normalizeReceiptNumber($receiptNumber),
            self::normalizeAmount($amount),
            self::normalizePeriod($period),
        ]));
    }

    /**
     * Assess a proposed claim against existing claims.
     *
     * @param  array{receipt_number: string, amount: mixed, period?: string|null, bill_id?: int|null, property_id?: string|null}  $input
     * @param  int|null  $excludeClaimId  claim to ignore (self) when re-checking
     * @return array{
     *     fingerprint: string,
     *     normalized_receipt_number: string,
     *     hard_block: bool,
     *     block_reason: string|null,
     *     warnings: array<int, string>,
     *     conflicts: array<int, array{claim_id: int, status: string, same_bill: bool, property_id: string|null}>
     * }
     */
    public function assess(array $input, ?int $excludeClaimId = null): array
    {
        $receipt = self::normalizeReceiptNumber($input['receipt_number'] ?? null);
        $period = self::normalizePeriod($input['period'] ?? null);
        $fingerprint = self::fingerprint($receipt, $input['amount'] ?? 0, $period);

        $proposedBillId = $input['bill_id'] ?? null;
        $proposedPropertyId = $input['property_id'] ?? null;

        $active = self::ACTIVE_STATUSES;

        // Strong match: the exact same receipt (number + amount + period).
        $strong = PaymentVerification::query()
            ->where('receipt_fingerprint', $fingerprint)
            ->whereIn('verification_status', $active)
            ->when($excludeClaimId, fn ($q) => $q->where('id', '!=', $excludeClaimId))
            ->orderBy('id')
            ->get(['id', 'bill_id', 'property_id', 'verification_status']);

        // Weak match: same receipt number but different fingerprint (amount /
        // period differs) — a possible typo or a genuinely different payment.
        $weakQuery = PaymentVerification::query()
            ->where('receipt_number', $receipt)
            ->where('receipt_fingerprint', '!=', $fingerprint)
            ->whereIn('verification_status', $active)
            ->when($excludeClaimId, fn ($q) => $q->where('id', '!=', $excludeClaimId));

        if ($proposedBillId !== null) {
            $weakQuery->where('bill_id', '!=', $proposedBillId);
        }

        $weak = $weakQuery->limit(20)
            ->get(['id', 'bill_id', 'property_id', 'verification_status']);

        $conflicts = [];
        $warnings = [];
        $blockReason = null;

        foreach ($strong as $claim) {
            $sameBill = $claim->bill_id === $proposedBillId;
            $sameProperty = $proposedPropertyId !== null && $claim->property_id !== null && strcasecmp((string) $claim->property_id, (string) $proposedPropertyId) === 0;

            $conflicts[] = [
                'claim_id' => (int) $claim->id,
                'status' => $claim->verification_status,
                'same_bill' => $sameBill,
                'property_id' => $claim->property_id,
            ];

            if ($blockReason !== null) {
                continue;
            }

            if ($sameBill || $sameProperty) {
                $blockReason = $claim->verification_status === PaymentVerification::STATUS_CONFIRMED
                    ? 'This receipt has already been approved for this property.'
                    : 'This receipt has already been submitted for this property and is awaiting verification.';
            } else {
                $blockReason = $claim->verification_status === PaymentVerification::STATUS_CONFIRMED
                    ? sprintf('This receipt has already been approved for Property ID %s and cannot be used for another payment claim.', $claim->property_id ?? 'unknown')
                    : sprintf('This receipt is already under verification for Property ID %s.', $claim->property_id ?? 'unknown');
            }
        }

        // Same receipt number on another property is a conflict to investigate,
        // not a hard block — the amounts/periods differ, so it may be a typo or
        // a genuinely separate payment. Non-deterministic; flagged, not banned.
        foreach ($weak as $claim) {
            $warnings[] = $claim->property_id !== null
                ? sprintf('Receipt number %s already appears on Property ID %s (%s).', $receipt, $claim->property_id, $claim->verification_status)
                : sprintf('Receipt number %s already appears on another claim (%s).', $receipt, $claim->verification_status);
        }

        return [
            'fingerprint' => $fingerprint,
            'normalized_receipt_number' => $receipt,
            'hard_block' => $blockReason !== null,
            'block_reason' => $blockReason,
            'warnings' => array_values(array_unique($warnings)),
            'conflicts' => $conflicts,
        ];
    }
}
