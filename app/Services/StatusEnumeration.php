<?php

namespace App\Services;

use App\Models\PropertyDiscovery;

/**
 * Single source of truth for all RETD enums:
 * - Statuses (task, bill, payment, discovery, etc.)
 * - Transitions (state machines)
 * - Types (task types, permission groups)
 *
 * Both the backend API and the frontends (web React + mobile Expo)
 * MUST consume this service at boot rather than hard-coding enums.
 *
 * This eliminates the vocabulary drift that previously existed across:
 *   Task.STATUSES vs PropertyBill.CASE_STATUSES vs PaymentStatuses
 *   'Payment Rejected' vs 'Payment Mismatch'
 *   'Paid' vs 'Tax Cleared' vs 'Under Verification'
 */
class StatusEnumeration
{
    // ------------------------------------------------------------------
    // Task statuses — single source of truth
    // ------------------------------------------------------------------
    public const TASK_STATUSES = [
        'Logged',
        'Awaiting Assignment',
        'Assigned',
        'Out for Delivery',
        'Delivered',
        'Payment Follow-up',
        'Payment Claimed',
        'Verification Pending',
        'Payment Verification',
        'Payment Rejected',
        '30-Day Warning',
        '72-Hour Warning',
        'Escalated',
        'Paid',
        'Partially Paid',
        'Outstanding',
        'Resolved',
        'Closed',
    ];

    // ------------------------------------------------------------------
    // Task completed statuses (sub-set used by dashboards/analytics)
    // ------------------------------------------------------------------
    public const TASK_COMPLETED_STATUSES = ['Resolved', 'Closed', 'Paid', 'Partially Paid'];

    // ------------------------------------------------------------------
    // Task transitions — single source of truth
    // ------------------------------------------------------------------
    public const TASK_TRANSITIONS = [
        'Logged' => ['Awaiting Assignment', 'Assigned', 'Closed'],
        'Awaiting Assignment' => ['Assigned', 'Closed'],
        'Assigned' => ['Out for Delivery', 'Delivered', 'Payment Follow-up', 'Escalated'],
        'Out for Delivery' => ['Delivered', 'Assigned', 'Escalated'],
        'Delivered' => ['Payment Follow-up', 'Escalated', 'Closed', 'Payment Rejected'],
        'Payment Follow-up' => ['Payment Claimed', 'Verification Pending', '30-Day Warning', '72-Hour Warning', 'Escalated', 'Closed', 'Payment Rejected'],
        'Payment Claimed' => ['Verification Pending', 'Payment Follow-up', 'Payment Rejected'],
        'Verification Pending' => ['Payment Verification', 'Paid', 'Partially Paid', 'Outstanding', 'Payment Follow-up', 'Payment Rejected'],
        'Payment Verification' => ['Paid', 'Partially Paid', 'Outstanding', 'Payment Follow-up', 'Payment Rejected'],
        '30-Day Warning' => ['72-Hour Warning', 'Escalated', 'Payment Follow-up'],
        '72-Hour Warning' => ['Escalated', 'Payment Follow-up'],
        'Escalated' => ['Payment Follow-up', 'Closed'],
        'Paid' => ['Resolved', 'Closed'],
        'Partially Paid' => ['Payment Follow-up', 'Closed'],
        'Outstanding' => ['30-Day Warning', 'Escalated'],
        'Payment Rejected' => ['Payment Follow-up', 'Escalated'],
        'Resolved' => ['Closed'],
        'Closed' => [],
    ];

    // ------------------------------------------------------------------
    // Bill delivery stages
    // ------------------------------------------------------------------
    public const BILL_DELIVERY_STAGES = [
        'Logged',
        'Out for Delivery',
        'Delivered',
        'Returned',
        'Undelivered',
        'Not Mailable',
        'Filed',
    ];

    // ------------------------------------------------------------------
    // Bill case statuses — operational case status (broken out from payment status)
    // ------------------------------------------------------------------
    public const BILL_CASE_STATUSES = [
        'Logged',
        'Awaiting Assignment',
        'Assigned',
        'Out for Delivery',
        'Delivered',
        'Payment Follow-up',
        '30-Day Warning',
        '72-Hour Warning',
        'Escalated',
        'Under Verification',
        'Resolved',
        'Closed',
    ];

    // ------------------------------------------------------------------
    // Bill payment statuses — claims vs verified are distinct
    // ------------------------------------------------------------------
    public const BILL_PAYMENT_STATUSES = [
        'Unpaid',
        'Payment Claimed',
        'Verification Pending',
        'Partially Paid',
        'Paid',
        'Payment Rejected',
        'Payment Mismatch',
    ];

    // ------------------------------------------------------------------
    // Discovery statuses
    // ------------------------------------------------------------------
    // The model's constants ARE the discovery vocabulary. This used to be a
    // second, uppercase list (DISCOVERED, SENT_TO_ACCOUNT, PENDING_AC_APPROVAL,
    // …) that the workflow never wrote, so clients building filters or legends
    // from this endpoint produced options that matched no record. Delegating to
    // the model keeps the published list and the stored statuses identical.
    public const DISCOVERY_STATUSES = PropertyDiscovery::STATUSES;

    // ------------------------------------------------------------------
    // Discovery decision paths
    // ------------------------------------------------------------------
    public const DISCOVERY_DECISION_PATHS = ['account', 'valuation'];

    // ------------------------------------------------------------------
    // Payment verification statuses
    // ------------------------------------------------------------------
    public const PAYMENT_VERIFICATION_STATUSES = [
        'Pending',
        'Confirmed',
        'Rejected',
        'Exception',
    ];

    // ------------------------------------------------------------------
    // Payment verification match statuses
    // ------------------------------------------------------------------
    public const PAYMENT_MATCH_STATUSES = [
        'PENDING',
        'MATCH',
        'MISMATCH',
    ];

    // --------------------------------------------------------------------
    // Helper: get all enum values keyed by category
    // --------------------------------------------------------------------
    public static function all(): array
    {
        return [
            'task_statuses' => self::TASK_STATUSES,
            'task_completed' => self::TASK_COMPLETED_STATUSES,
            'task_transitions' => self::TASK_TRANSITIONS,
            'bill_delivery_stages' => self::BILL_DELIVERY_STAGES,
            'visit_delivery_outcomes' => \App\Models\EnforcementVisit::DELIVERY_STATUSES,
            'bill_case_statuses' => self::BILL_CASE_STATUSES,
            'bill_payment_statuses' => self::BILL_PAYMENT_STATUSES,
            'discovery_statuses' => self::DISCOVERY_STATUSES,
            'discovery_paths' => self::DISCOVERY_DECISION_PATHS,
            'payment_verification' => self::PAYMENT_VERIFICATION_STATUSES,
            'payment_match' => self::PAYMENT_MATCH_STATUSES,
        ];
    }

    // ------------------------------------------------------------------
    // Convenience: check if a task status is a "completed/settled" state
    // ------------------------------------------------------------------
    public static function isTaskCompleted(string $status): bool
    {
        return in_array($status, self::TASK_COMPLETED_STATUSES, true);
    }

    // ------------------------------------------------------------------
    // Convenience: check if a bill case status is "settled" (zero balance)
    // ------------------------------------------------------------------
    public static function isBillSettled(string $caseStatus): bool
    {
        return in_array($caseStatus, ['Resolved', 'Closed'], true);
    }

    // ------------------------------------------------------------------
    // Convenience: check if a bill payment status means "paid"
    // ------------------------------------------------------------------
    public static function isBillPaymentPaid(string $paymentStatus): bool
    {
        return $paymentStatus === 'Paid';
    }

    // ------------------------------------------------------------------
    // Convenience: map legacy enum names to current single source values
    // ------------------------------------------------------------------
    public static function mapLegacy(string $category, string $legacyValue): ?string
    {
        $map = [
            'task_status' => [
                'Paid' => 'Paid',
                'Tax Cleared' => 'Paid',
                'Settled' => 'Paid',
                'Partially Paid' => 'Partially Paid',
                'Unpaid' => 'Unpaid',
                'Outstanding' => 'Outstanding',
                'Payment Rejected' => 'Payment Rejected',
                'Payment Mismatch' => 'Payment Mismatch',
                'Verification Pending' => 'Verification Pending',
                'Payment Verification' => 'Payment Verification',
            ],
            'bill_case_status' => [
                'Tax Cleared' => 'Resolved',
                'Settled' => 'Resolved',
                'Under Verification' => 'Under Verification',
                'Payment Follow-up' => 'Payment Follow-up',
            ],
            'bill_payment_status' => [
                'Tax Cleared' => 'Paid',
                'Settled' => 'Paid',
            ],
        ];

        $categoryMap = $map[$category] ?? [];

        return $categoryMap[$legacyValue] ?? null;
    }
}
