<?php

namespace App\Services;

/**
 * Central KPI definition registry — single source of truth.
 *
 * §78  KPI Definition Registry.
 * §79  Recommended KPI Codes.
 * §90  NON-NEGOTIABLE: ONE DEFINITION → ONE QUERY → ONE COUNTING RULE → ONE DATE RULE
 *       → ONE RBAC SCOPE → USED EVERYWHERE.
 *
 * Dashboard = Report = Chart = Drill-down = Export must all reconcile.
 *
 * Every KPI listed here specifies:
 *   population, status_condition, date_field, counting_method,
 *   rbac_scope, reporting_period, exclusions, drill-down route.
 */
final class KpiRegistry
{
    /**
     * The full definition registry. Consumers may reference codes in docs,
     * logging, or future validation layers. Each code maps to one KPI that
     * must be computed through a single shared service, never ad-hoc SQL.
     */
    public const DEFINITIONS = [

        /* ================================================================
         *  ACCOUNT & RECORD  (§20, §79)
         * ================================================================ */

        'AR_PENDING_ASSIGNMENT' => [
            'code' => 'AR_PENDING_ASSIGNMENT',
            'name' => 'Pending Assignment',
            'module' => 'account',
            'kind' => 'current',
            'population' => 'Active Account & Records tasks ready for assignment',
            'condition' => 'status NOT IN terminal AND assigned_to IS NULL',
            'date_field' => null,
            'counting' => 'COUNT(DISTINCT tasks.id)',
            'scope' => 'Manager section scope',
            'exclusions' => 'Completed, Closed, Paid, Partially Paid',
            'drilldown' => '/enforcement?tab=tasks&group=unassigned',
        ],
        'AR_ASSIGNED_TASKS' => [
            'code' => 'AR_ASSIGNED_TASKS',
            'name' => 'Assigned Tasks',
            'module' => 'account',
            'kind' => 'current',
            'population' => 'Active Account & Records tasks currently assigned to an A&R officer',
            'condition' => 'status NOT IN terminal AND assigned_to IS NOT NULL',
            'date_field' => null,
            'counting' => 'COUNT(DISTINCT tasks.id)',
            'scope' => 'Manager section scope',
            'exclusions' => 'Completed, Closed, Paid, Partially Paid',
            'drilldown' => '/enforcement?tab=tasks',
        ],
        'AR_REGISTRATION_PENDING' => [
            'code' => 'AR_REGISTRATION_PENDING',
            'name' => 'Registration / Update Pending',
            'module' => 'account',
            'kind' => 'current',
            'population' => 'Active tasks requiring property registration or record update',
            'condition' => 'task_type IN (Account & Record Processing, Account & Record Manager Review, Discovery Review) AND status NOT IN terminal',
            'date_field' => null,
            'counting' => 'COUNT(DISTINCT tasks.id)',
            'scope' => 'Manager section scope',
            'exclusions' => 'Completed, Closed, Paid, Partially Paid',
            'drilldown' => '/enforcement?tab=tasks',
        ],
        'AR_BILLS_LOGGED' => [
            'code' => 'AR_BILLS_LOGGED',
            'name' => 'Bills Logged in RETD',
            'module' => 'account',
            'kind' => 'current',
            'population' => 'All source-system-generated bills formally logged into RETD within scope',
            'condition' => null,
            'date_field' => 'date_logged',
            'counting' => 'COUNT(DISTINCT property_bills.id)',
            'scope' => 'Manager section scope',
            'exclusions' => 'None (includes all logged bills)',
            'drilldown' => '/bills',
        ],
        'AR_BILLS_LOGGED_PERIOD' => [
            'code' => 'AR_BILLS_LOGGED_PERIOD',
            'name' => 'Bills Logged in RETD — This Month',
            'module' => 'account',
            'kind' => 'period',
            'population' => 'Bills whose bill_logged_at falls within the selected reporting period',
            'condition' => 'date_logged >= period_start AND date_logged < period_end',
            'date_field' => 'date_logged',
            'counting' => 'COUNT(DISTINCT property_bills.id)',
            'scope' => 'Manager section scope',
            'exclusions' => 'None',
            'drilldown' => '/bills',
        ],
        'AR_PAYMENT_VERIFICATION_PENDING' => [
            'code' => 'AR_PAYMENT_VERIFICATION_PENDING',
            'name' => 'Payment Verification Pending',
            'module' => 'account',
            'kind' => 'current',
            'population' => 'Unique payment verification submissions awaiting A&R action',
            'condition' => 'verification_status = Pending',
            'date_field' => null,
            'counting' => 'COUNT(DISTINCT payment_verifications.id)',
            'scope' => 'Manager section scope',
            'exclusions' => 'Verified, Rejected, Cancelled, Completed',
            'drilldown' => '/payments',
        ],
        'AR_AMENDMENTS_PENDING' => [
            'code' => 'AR_AMENDMENTS_PENDING',
            'name' => 'Amendments Pending',
            'module' => 'account',
            'kind' => 'current',
            'population' => 'Unique amendment requests currently awaiting action',
            'condition' => 'All amendments (current model has no pending status)',
            'date_field' => null,
            'counting' => 'COUNT(DISTINCT bill_amendments.id)',
            'scope' => 'Manager section scope',
            'exclusions' => 'None (model has no terminal status yet)',
            'drilldown' => '/amendments',
        ],
        'AR_AMENDMENTS_COMPLETED_PERIOD' => [
            'code' => 'AR_AMENDMENTS_COMPLETED_PERIOD',
            'name' => 'Amendments Completed — This Month',
            'module' => 'account',
            'kind' => 'period',
            'population' => 'Amendments whose amended_at falls within the reporting period',
            'condition' => 'amended_at >= period_start AND amended_at < period_end',
            'date_field' => 'amended_at',
            'counting' => 'COUNT(DISTINCT bill_amendments.id)',
            'scope' => 'Manager section scope',
            'exclusions' => 'None',
            'drilldown' => '/amendments',
        ],
        'AR_DATA_QUALITY_OPEN' => [
            'code' => 'AR_DATA_QUALITY_OPEN',
            'name' => 'Data Quality Issues',
            'module' => 'account',
            'kind' => 'current',
            'population' => 'Unique currently open data-quality flags',
            'condition' => 'status != resolved/closed',
            'date_field' => null,
            'counting' => 'COUNT(DISTINCT data_quality_flags.id)',
            'scope' => 'Manager section scope',
            'exclusions' => 'Closed / resolved flags',
            'drilldown' => '/drill/flags',
        ],
        'AR_OVERDUE_TASKS' => [
            'code' => 'AR_OVERDUE_TASKS',
            'name' => 'Overdue Tasks',
            'module' => 'account',
            'kind' => 'current',
            'population' => 'Active A&R tasks whose due_at is before the current Liberia date',
            'condition' => 'due_date < today (Africa/Monrovia) AND status NOT IN terminal',
            'date_field' => 'due_date',
            'counting' => 'COUNT(DISTINCT tasks.id)',
            'scope' => 'Manager section scope',
            'exclusions' => 'Completed, Closed, Paid, Partially Paid',
            'drilldown' => '/enforcement?tab=tasks',
        ],
        'AR_PAYMENTS_VERIFIED_PERIOD' => [
            'code' => 'AR_PAYMENTS_VERIFIED_PERIOD',
            'name' => 'Payments Verified — This Month',
            'module' => 'account',
            'kind' => 'period',
            'population' => 'Payment verifications whose verified_at falls within the reporting period',
            'condition' => 'verification_status = Confirmed AND verified_at >= period_start AND verified_at < period_end',
            'date_field' => 'verified_at',
            'counting' => 'COUNT(DISTINCT payment_verifications.id)',
            'scope' => 'Manager section scope',
            'exclusions' => 'Pending, Rejected, Exception',
            'drilldown' => '/payments',
        ],

        /* ================================================================
         *  FINANCIAL  (§21-§26, §79)
         * ================================================================ */

        'AR_TAX_DUE' => [
            'code' => 'AR_TAX_DUE',
            'name' => 'Total Tax Due',
            'module' => 'account',
            'kind' => 'current',
            'population' => 'Unique in-scope bills',
            'condition' => null,
            'date_field' => null,
            'counting' => 'SUM(total_tax_due)',
            'scope' => 'Manager section scope',
            'exclusions' => 'None',
            'drilldown' => '/bills',
        ],
        'AR_VERIFIED_PAYMENT_VALUE' => [
            'code' => 'AR_VERIFIED_PAYMENT_VALUE',
            'name' => 'Verified Payment Value',
            'module' => 'account',
            'kind' => 'current',
            'population' => 'Payments whose verification status is Confirmed',
            'condition' => 'verification_status = Confirmed',
            'date_field' => null,
            'counting' => 'SUM(amount)',
            'scope' => 'Manager section scope',
            'exclusions' => 'Pending, Rejected, Exception',
            'drilldown' => '/payments',
        ],
        'AR_OUTSTANDING_BALANCE' => [
            'code' => 'AR_OUTSTANDING_BALANCE',
            'name' => 'Outstanding Balance',
            'module' => 'account',
            'kind' => 'current',
            'population' => 'Unique in-scope bills (authoritative source-system outstanding balance)',
            'condition' => null,
            'date_field' => null,
            'counting' => 'SUM(outstanding_balance)',
            'scope' => 'Manager section scope',
            'exclusions' => 'None',
            'drilldown' => '/bills',
        ],

        /* ================================================================
         *  VALUATION  (§27-§30, §79)
         * ================================================================ */

        'VAL_NEW_TASKS' => [
            'code' => 'VAL_NEW_TASKS',
            'name' => 'New Valuation Tasks',
            'module' => 'valuation',
            'kind' => 'current',
            'population' => 'Active valuation tasks awaiting assignment/action',
            'condition' => 'status NOT IN terminal AND assigned_to IS NULL',
            'date_field' => null,
            'counting' => 'COUNT(DISTINCT valuations.id)',
            'scope' => 'Valuation Manager section scope',
            'exclusions' => 'Completed, Registered, Sent to Account & Record',
            'drilldown' => '/valuations',
        ],
        'VAL_ASSIGNED' => [
            'code' => 'VAL_ASSIGNED',
            'name' => 'Assigned Valuations',
            'module' => 'valuation',
            'kind' => 'current',
            'population' => 'Active valuations currently assigned to Valuation Officers',
            'condition' => 'status = Assigned AND assigned_to IS NOT NULL',
            'date_field' => null,
            'counting' => 'COUNT(DISTINCT valuations.id)',
            'scope' => 'Valuation Manager section scope',
            'exclusions' => 'Completed, Registered, Sent to Account & Record',
            'drilldown' => '/valuations',
        ],
        'VAL_IN_PROGRESS' => [
            'code' => 'VAL_IN_PROGRESS',
            'name' => 'In Progress',
            'module' => 'valuation',
            'kind' => 'current',
            'population' => 'Active valuations whose current status is Assessment in Progress',
            'condition' => 'status = Assessment in Progress',
            'date_field' => null,
            'counting' => 'COUNT(DISTINCT valuations.id)',
            'scope' => 'Valuation Manager section scope',
            'exclusions' => 'Completed, Registered, Sent to Account & Record',
            'drilldown' => '/valuations',
        ],
        'VAL_MANAGER_REVIEW' => [
            'code' => 'VAL_MANAGER_REVIEW',
            'name' => 'Manager Review',
            'module' => 'valuation',
            'kind' => 'current',
            'population' => 'Valuations submitted to Valuation Manager awaiting review',
            'condition' => 'status IN (Submitted to Manager, Under Manager Review)',
            'date_field' => null,
            'counting' => 'COUNT(DISTINCT valuations.id)',
            'scope' => 'Valuation Manager section scope',
            'exclusions' => 'Returned, Completed, Registered',
            'drilldown' => '/valuations',
        ],
        'VAL_RETURNED' => [
            'code' => 'VAL_RETURNED',
            'name' => 'Returned',
            'module' => 'valuation',
            'kind' => 'current',
            'population' => 'Valuations currently in Returned for Correction',
            'condition' => 'status = Returned for Correction',
            'date_field' => null,
            'counting' => 'COUNT(DISTINCT valuations.id)',
            'scope' => 'Valuation Manager section scope',
            'exclusions' => 'Resubmitted and moved on',
            'drilldown' => '/valuations',
        ],
        'VAL_AC_APPROVAL' => [
            'code' => 'VAL_AC_APPROVAL',
            'name' => 'Awaiting AC Approval',
            'module' => 'valuation',
            'kind' => 'current',
            'population' => 'Valuations currently submitted to AC and awaiting decision',
            'condition' => 'status IN (Submitted to AC, Awaiting AC Approval)',
            'date_field' => null,
            'counting' => 'COUNT(DISTINCT valuations.id)',
            'scope' => 'Valuation Manager section scope',
            'exclusions' => 'AC Approved, AC Returned, AC Rejected',
            'drilldown' => '/valuations',
        ],
        'VAL_OVERDUE' => [
            'code' => 'VAL_OVERDUE',
            'name' => 'Overdue Valuations',
            'module' => 'valuation',
            'kind' => 'current',
            'population' => 'Active valuations whose due_at is before the current Liberia date',
            'condition' => 'due_date < today AND status NOT IN terminal',
            'date_field' => 'due_date',
            'counting' => 'COUNT(DISTINCT valuations.id)',
            'scope' => 'Valuation Manager section scope',
            'exclusions' => 'Completed, Registered, Sent to Account & Record',
            'drilldown' => '/valuations',
        ],
        'VAL_COMPLETED_PERIOD' => [
            'code' => 'VAL_COMPLETED_PERIOD',
            'name' => 'Completed Valuations — This Month',
            'module' => 'valuation',
            'kind' => 'period',
            'population' => 'Valuations whose completion timestamp falls within the reporting period',
            'condition' => 'status IN terminal AND (registration_completed_at OR ac_reviewed_at) >= period_start',
            'date_field' => 'registration_completed_at',
            'counting' => 'COUNT(DISTINCT valuations.id)',
            'scope' => 'Valuation Manager section scope',
            'exclusions' => 'Non-terminal',
            'drilldown' => '/valuations',
        ],
        'VAL_GPS_MISSING' => [
            'code' => 'VAL_GPS_MISSING',
            'name' => 'Missing GPS',
            'module' => 'valuation',
            'kind' => 'current',
            'population' => 'Active valuations with gps_verified IS NULL or = no',
            'condition' => "gps_verified IS NULL OR gps_verified = 'no'",
            'date_field' => null,
            'counting' => 'COUNT(DISTINCT valuations.id)',
            'scope' => 'Valuation Manager section scope',
            'exclusions' => 'Completed, Registered',
            'drilldown' => '/valuations',
        ],

        /* ================================================================
         *  ENFORCEMENT  (§31-§39, §79)
         * ================================================================ */

        'ENF_BILLS_RECEIVED' => [
            'code' => 'ENF_BILLS_RECEIVED',
            'name' => 'Bills Received',
            'module' => 'enforcement',
            'kind' => 'current',
            'population' => 'Active enforcement bills available to enforcement within scope',
            'condition' => 'task_type IN (Bill Delivery, Payment Follow-up, Enforcement Visit)',
            'date_field' => 'date_logged',
            'counting' => 'COUNT(DISTINCT tasks.id)',
            'scope' => 'Enforcement Manager section scope',
            'exclusions' => 'Completed, Closed, Paid, Partially Paid',
            'drilldown' => '/enforcement?tab=tasks',
        ],
        'ENF_UNASSIGNED' => [
            'code' => 'ENF_UNASSIGNED',
            'name' => 'Unassigned Bills',
            'module' => 'enforcement',
            'kind' => 'current',
            'population' => 'Active enforcement bills eligible for assignment but with no officer',
            'condition' => 'assigned_to IS NULL AND status NOT IN terminal',
            'date_field' => null,
            'counting' => 'COUNT(DISTINCT tasks.id)',
            'scope' => 'Enforcement Manager section scope',
            'exclusions' => 'Completed, Closed, Paid, Partially Paid',
            'drilldown' => '/enforcement?tab=tasks',
        ],
        'ENF_ASSIGNED' => [
            'code' => 'ENF_ASSIGNED',
            'name' => 'Assigned Bills',
            'module' => 'enforcement',
            'kind' => 'current',
            'population' => 'Active enforcement bills currently assigned to an Enforcement Officer',
            'condition' => 'assigned_to IS NOT NULL AND status NOT IN terminal',
            'date_field' => null,
            'counting' => 'COUNT(DISTINCT tasks.id)',
            'scope' => 'Enforcement Manager section scope',
            'exclusions' => 'Completed, Closed, Paid, Partially Paid',
            'drilldown' => '/enforcement?tab=tasks',
        ],
        'ENF_OUT_FOR_DELIVERY' => [
            'code' => 'ENF_OUT_FOR_DELIVERY',
            'name' => 'Out for Delivery',
            'module' => 'enforcement',
            'kind' => 'current',
            'population' => 'Active enforcement tasks whose current status is Out for Delivery',
            'condition' => "task_status = 'Out for Delivery'",
            'date_field' => null,
            'counting' => 'COUNT(DISTINCT tasks.id)',
            'scope' => 'Enforcement Manager section scope',
            'exclusions' => 'Completed, Closed, Paid',
            'drilldown' => '/enforcement?tab=tasks',
        ],
        'ENF_DELIVERED_CURRENT' => [
            'code' => 'ENF_DELIVERED_CURRENT',
            'name' => 'Delivered — Current',
            'module' => 'enforcement',
            'kind' => 'current',
            'population' => 'Bills whose current bill delivery status is Delivered',
            'condition' => "case_status = 'Delivered'",
            'date_field' => 'delivery_date',
            'counting' => 'COUNT(DISTINCT property_bills.id)',
            'scope' => 'Enforcement Manager section scope',
            'exclusions' => 'Non-delivered',
            'drilldown' => '/bills',
        ],
        'ENF_DELIVERED_PERIOD' => [
            'code' => 'ENF_DELIVERED_PERIOD',
            'name' => 'Bills Delivered — This Month',
            'module' => 'enforcement',
            'kind' => 'period',
            'population' => 'Unique bills whose delivery_date falls within the reporting period',
            'condition' => 'delivery_date >= period_start AND delivery_date < period_end',
            'date_field' => 'delivery_date',
            'counting' => 'COUNT(DISTINCT property_bills.id)',
            'scope' => 'Enforcement Manager section scope',
            'exclusions' => 'Future-dated deliveries excluded until actual delivery date',
            'drilldown' => '/bills',
        ],
        'ENF_PAYMENT_FOLLOWUP' => [
            'code' => 'ENF_PAYMENT_FOLLOWUP',
            'name' => 'Payment Follow-up',
            'module' => 'enforcement',
            'kind' => 'current',
            'population' => 'Unique active enforcement bills currently requiring payment follow-up',
            'condition' => "task_status = 'Payment Follow-up'",
            'date_field' => null,
            'counting' => 'COUNT(DISTINCT tasks.id)',
            'scope' => 'Enforcement Manager section scope',
            'exclusions' => 'Completed, Closed, Paid',
            'drilldown' => '/enforcement?tab=followups',
        ],
        'ENF_30DAY_REMINDER_PERIOD' => [
            'code' => 'ENF_30DAY_REMINDER_PERIOD',
            'name' => '30-Day Reminders Issued — This Month',
            'module' => 'enforcement',
            'kind' => 'period',
            'population' => 'Unique bills with a thirty_day_notice_date within the reporting period',
            'condition' => 'thirty_day_notice_date >= period_start AND thirty_day_notice_date < period_end',
            'date_field' => 'thirty_day_notice_date',
            'counting' => 'COUNT(DISTINCT property_bills.id)',
            'scope' => 'Enforcement Manager section scope',
            'exclusions' => 'None',
            'drilldown' => '/enforcement?tab=tasks',
        ],
        'ENF_FINAL_NOTICE_PERIOD' => [
            'code' => 'ENF_FINAL_NOTICE_PERIOD',
            'name' => 'Final Notices Issued — This Month',
            'module' => 'enforcement',
            'kind' => 'period',
            'population' => 'Unique bills with a final_notice_date within the reporting period',
            'condition' => 'final_notice_date >= period_start AND final_notice_date < period_end',
            'date_field' => 'final_notice_date',
            'counting' => 'COUNT(DISTINCT property_bills.id)',
            'scope' => 'Enforcement Manager section scope',
            'exclusions' => 'None',
            'drilldown' => '/enforcement?tab=tasks',
        ],
        'ENF_OVERDUE' => [
            'code' => 'ENF_OVERDUE',
            'name' => 'Overdue',
            'module' => 'enforcement',
            'kind' => 'current',
            'population' => 'Active enforcement tasks whose due_at is before the current Liberia date',
            'condition' => 'due_date < today AND status NOT IN terminal',
            'date_field' => 'due_date',
            'counting' => 'COUNT(DISTINCT tasks.id)',
            'scope' => 'Enforcement Manager section scope',
            'exclusions' => 'Completed, Closed, Paid, Partially Paid',
            'drilldown' => '/enforcement?tab=tasks',
        ],
        'ENF_ESCALATED' => [
            'code' => 'ENF_ESCALATED',
            'name' => 'Escalated',
            'module' => 'enforcement',
            'kind' => 'current',
            'population' => 'Active enforcement cases currently escalated',
            'condition' => "task_status = 'Escalated'",
            'date_field' => null,
            'counting' => 'COUNT(DISTINCT tasks.id)',
            'scope' => 'Enforcement Manager section scope',
            'exclusions' => 'Completed, Closed',
            'drilldown' => '/enforcement?tab=tasks',
        ],
        'ENF_PAID_COMPLETED_PERIOD' => [
            'code' => 'ENF_PAID_COMPLETED_PERIOD',
            'name' => 'Bills Paid / Completed — This Month',
            'module' => 'enforcement',
            'kind' => 'period',
            'population' => 'Tasks that entered a terminal paid/completed state during the period',
            'condition' => 'status IN terminal AND completed_at >= period_start AND completed_at < period_end',
            'date_field' => 'completed_at',
            'counting' => 'COUNT(DISTINCT tasks.id)',
            'scope' => 'Enforcement Manager section scope',
            'exclusions' => 'Non-terminal',
            'drilldown' => '/enforcement?tab=tasks',
        ],

        /* ================================================================
         *  ASSISTANT COMMISSIONER / DIVISION  (§79 — aggregated)
         * ================================================================ */

        'AC_BILLS_LOGGED' => [
            'code' => 'AC_BILLS_LOGGED',
            'name' => 'Bills Logged in RETD (Division)',
            'module' => 'ac',
            'kind' => 'current',
            'population' => 'All source-system-generated bills logged into RETD across all sections',
            'condition' => null,
            'date_field' => 'date_logged',
            'counting' => 'COUNT(DISTINCT property_bills.id)',
            'scope' => 'Division scope (all sections)',
            'exclusions' => 'None',
            'drilldown' => '/bills',
        ],
        'AC_BILLS_LOGGED_PERIOD' => [
            'code' => 'AC_BILLS_LOGGED_PERIOD',
            'name' => 'Bills Logged in RETD — This Month',
            'module' => 'ac',
            'kind' => 'period',
            'population' => 'Bills whose date_logged falls within the reporting period across all sections',
            'condition' => 'date_logged >= period_start AND date_logged < period_end',
            'date_field' => 'date_logged',
            'counting' => 'COUNT(DISTINCT property_bills.id)',
            'scope' => 'Division scope',
            'exclusions' => 'None',
            'drilldown' => '/bills',
        ],

        /* ================================================================
         *  SHARED  (officer dashboards §44)
         * ================================================================ */

        'OFFICER_MY_ASSIGNED' => [
            'code' => 'OFFICER_MY_ASSIGNED',
            'name' => 'My Assigned Tasks',
            'module' => 'officer',
            'kind' => 'current',
            'population' => 'Active tasks currently assigned to the authenticated Officer',
            'condition' => 'assigned_to = auth_user AND status NOT IN terminal',
            'date_field' => null,
            'counting' => 'COUNT(DISTINCT tasks.id)',
            'scope' => 'Officer (current_assignee = authenticated_user)',
            'exclusions' => 'Completed, Closed, Paid, Partially Paid',
            'drilldown' => '/enforcement?tab=tasks',
        ],
    ];

    /**
     * Retrieve a single KPI definition by code.
     */
    public static function definition(string $code): ?array
    {
        return self::DEFINITIONS[$code] ?? null;
    }

    /**
     * Return all definitions, optionally filtered by module.
     */
    public static function all(?string $module = null): array
    {
        if ($module === null) {
            return self::DEFINITIONS;
        }

        return array_filter(self::DEFINITIONS, fn (array $def) => $def['module'] === $module);
    }

    /**
     * Return just the codes for a given module.
     */
    public static function codes(string $module): array
    {
        return array_keys(self::all($module));
    }
}
