<?php

namespace App\Services;

use App\Models\BillAmendment;
use App\Models\DataQualityFlag;
use App\Models\EnforcementVisit;
use App\Models\Payment;
use App\Models\PaymentVerification;
use App\Models\PropertyBill;
use App\Models\PropertyDiscovery;
use App\Models\Task;
use App\Models\TaskHistory;
use App\Models\User;
use App\Models\Valuation;
use App\Support\BusinessClock;
use App\Support\ReportingPeriod;

/**
 * Single source of truth for the three Manager dashboards.
 *
 * Each method builds the complete KPIs + pipeline + workload + activity +
 * alerts + quick-actions payload for the role-specific dashboard. All figures
 * are computed from the authenticated user's authorized data scope - nothing is
 * hardcoded, no duplicates are created, and no source-system data is fabricated.
 *
 * System boundary: the source system is the authoritative external system for
 * property registration, assessment, and bill generation. RETD manages the
 * internal workflow around those records. Dashboard figures are derived from
 * RETD's own transactional records.
 */
class ManagerDashboardService
{
    /* =====================================================================
     *  PUBLIC - Account & Record Manager
     * =================================================================== */

    public function forAccount(User $user): array
    {
        $billIds = BillScopeService::visibleBillIds($user);
        $officerIds = $this->sectionOfficerIds($user, 'Account & Records Officer');
        $acctTypes = [Task::TYPE_ACCOUNT_PROCESSING, Task::TYPE_ACCOUNT_MANAGER_REVIEW, Task::TYPE_DISCOVERY_REVIEW];

        $accountTeamIds = array_values(array_unique([$user->id, ...$officerIds]));

        // ---------------- Base queries ------------------------------------------------------------
        $billBase = $billIds !== null
            ? PropertyBill::whereIn('property_bills.id', $billIds)
            : PropertyBill::query();

        $acctTaskBase = Task::query()
            ->whereIn('task_type', $acctTypes)
            ->whereIn('assigned_to', $accountTeamIds);

        $unassignedAcctTaskBase = Task::query()
            ->whereIn('task_type', $acctTypes)
            ->whereNotIn('status', Task::COMPLETED_STATUSES)
            ->where(function ($q) use ($accountTeamIds) {
                $q->whereNull('assigned_to')->orWhereNotIn('assigned_to', $accountTeamIds);
            });

        // ---------------- KPIs ------------------------------------------------------------
        $totalBills = (clone $billBase)->count();
        $pendingAssignment = (clone $unassignedAcctTaskBase)->count();
        $assignedTasks = (clone $acctTaskBase)->whereNotIn('status', Task::COMPLETED_STATUSES)->count();

        $regPending = Task::query()
            ->whereIn('task_type', [Task::TYPE_ACCOUNT_PROCESSING])
            ->whereNotIn('status', Task::COMPLETED_STATUSES)
            ->whereIn('assigned_to', $accountTeamIds)
            ->count()
            + PropertyDiscovery::where('decision_path', 'account')
                ->whereIn('status', [
                    PropertyDiscovery::STATUS_AWAITING_ACCT_REVIEW,
                    PropertyDiscovery::STATUS_ASSIGNED_ACCT,
                ])
                ->count();

        $paymentVerificationPending = $billIds !== null
            ? PaymentVerification::whereIn('bill_id', $billIds)->where('verification_status', PaymentVerification::STATUS_PENDING)->count()
            : PaymentVerification::where('verification_status', PaymentVerification::STATUS_PENDING)->count();

        $amendmentsPending = $billIds !== null
            ? BillAmendment::whereIn('bill_id', $billIds)->count()
            : BillAmendment::query()->count();

        $dataQualityIssues = $billIds !== null
            ? DataQualityFlag::whereIn('bill_id', $billIds)->where('status', '!=', DataQualityFlag::STATUSES[2])->count()
            : DataQualityFlag::where('status', '!=', DataQualityFlag::STATUSES[2])->count();

        $overdue = Task::query()
            ->whereIn('task_type', $acctTypes)
            ->whereIn('assigned_to', $accountTeamIds)
            ->overdue()
            ->count();

        $kpis = [
            ['key' => 'pending_assignment', 'kind' => 'current', 'kpi_code' => 'AR_PENDING_ASSIGNMENT', 'label' => 'Pending Assignment', 'value' => $pendingAssignment, 'tone' => 'amber', 'icon' => '📥', 'link' => '/account/my-tasks'],
            ['key' => 'assigned_tasks', 'kind' => 'current', 'kpi_code' => 'AR_ASSIGNED_TASKS', 'label' => 'Assigned Tasks', 'value' => $assignedTasks, 'tone' => 'navy', 'icon' => '📋', 'link' => '/account/my-tasks'],
            ['key' => 'registration_pending', 'kind' => 'current', 'kpi_code' => 'AR_REGISTRATION_PENDING', 'label' => 'Registration / Update Pending', 'value' => $regPending, 'tone' => 'blue', 'icon' => '⏳', 'link' => '/account/my-tasks'],
            ['key' => 'bills_logged', 'kind' => 'current', 'kpi_code' => 'AR_BILLS_LOGGED', 'label' => 'Bills Logged in RETD', 'value' => $totalBills, 'tone' => 'blue', 'icon' => '🧾', 'link' => '/bills'],
            ['key' => 'payment_verification', 'kind' => 'current', 'kpi_code' => 'AR_PAYMENT_VERIFICATION_PENDING', 'label' => 'Payment Verification', 'value' => $paymentVerificationPending, 'tone' => 'amber', 'icon' => '💳', 'link' => '/payments'],
            ['key' => 'amendments', 'kind' => 'current', 'kpi_code' => 'AR_AMENDMENTS_PENDING', 'label' => 'Amendments Recorded', 'value' => $amendmentsPending, 'tone' => 'brand', 'icon' => '✏️', 'link' => '/amendments'],
            ['key' => 'data_quality_issues', 'kind' => 'current', 'kpi_code' => 'AR_DATA_QUALITY_OPEN', 'label' => 'Data Quality Issues', 'value' => $dataQualityIssues, 'tone' => 'red', 'icon' => '🔎', 'link' => '/drill/flags'],
            ['key' => 'overdue', 'kind' => 'current', 'kpi_code' => 'AR_OVERDUE_TASKS', 'label' => 'Overdue Tasks', 'value' => $overdue, 'tone' => 'red', 'icon' => '⏰', 'link' => '/account/my-tasks'],
        ];

        // ---------------- Financial summary ------------------------------------------------------------
        $totalTaxDue = (clone $billBase)->sum('total_tax_due');
        $minLogged = substr((string) (clone $billBase)->min('date_logged'), 0, 10) ?: '—';
        $verifiedPayments = $billIds !== null
            ? Payment::whereIn('bill_id', $billIds)->sum('amount')
            : Payment::query()->sum('amount');
        $outstandingBalance = (clone $billBase)->sum('outstanding_balance');
        $partialPayments = (clone $billBase)->where('payment_status', 'Partially Paid')->sum('outstanding_balance');
        $fullyPaidBills = (clone $billBase)->where('payment_status', 'Paid')->sum('total_tax_due');
        $unverifiedPayments = $billIds !== null
            ? PaymentVerification::whereIn('bill_id', $billIds)->where('verification_status', PaymentVerification::STATUS_PENDING)->sum('amount_claimed')
            : PaymentVerification::where('verification_status', PaymentVerification::STATUS_PENDING)->sum('amount_claimed');

        $financial = [
            ['label' => 'Total Tax Due', 'value' => round((float) $totalTaxDue, 2), 'money' => true],
            ['label' => 'Verified Payments', 'value' => round((float) $verifiedPayments, 2), 'money' => true],
            ['label' => 'Outstanding Balance', 'value' => round((float) $outstandingBalance, 2), 'money' => true],
            ['label' => 'Partial Payments', 'value' => round((float) $partialPayments, 2), 'money' => true],
            ['label' => 'Fully Paid Bills', 'value' => round((float) $fullyPaidBills, 2), 'money' => true],
            ['label' => 'Unverified Payments', 'value' => round((float) $unverifiedPayments, 2), 'money' => true],
        ];

        // - Period activity (This Month) - event-date based (-7, -20.4, -66) -
        [$monthStart, $monthEnd, $monthLabel] = $this->monthlyRange();
        $verifiedBase = $billIds !== null
            ? PaymentVerification::whereIn('bill_id', $billIds)
            : PaymentVerification::query();
        $amendBase = $billIds !== null
            ? BillAmendment::whereIn('bill_id', $billIds)
            : BillAmendment::query();

        $monthly = [
            ['key' => 'bills_logged_period', 'kind' => 'period', 'kpi_code' => 'AR_BILLS_LOGGED_PERIOD', 'label' => 'Bills Logged in RETD', 'value' => (clone $billBase)->where('date_logged', '>=', $monthStart->toDateString())->where('date_logged', '<', $monthEnd->toDateString())->count(), 'event' => 'Bill Logged', 'event_field' => 'date_logged', 'period' => $monthLabel],
            ['key' => 'payments_verified_period', 'kind' => 'period', 'kpi_code' => 'AR_PAYMENTS_VERIFIED_PERIOD', 'label' => 'Payments Verified', 'value' => (clone $verifiedBase)->where('verification_status', PaymentVerification::STATUS_CONFIRMED)->where('verified_at', '>=', $monthStart)->where('verified_at', '<', $monthEnd)->count(), 'event' => 'Verified', 'event_field' => 'verified_at', 'period' => $monthLabel],
            ['key' => 'amendments_completed_period', 'kind' => 'period', 'kpi_code' => 'AR_AMENDMENTS_COMPLETED_PERIOD', 'label' => 'Amendments Completed', 'value' => (clone $amendBase)->where('amended_at', '>=', $monthStart)->where('amended_at', '<', $monthEnd)->count(), 'event' => 'Amendment Recorded', 'event_field' => 'amended_at', 'period' => $monthLabel],
            ['key' => 'tasks_completed_period', 'kind' => 'period', 'kpi_code' => 'AR_TASKS_COMPLETED_PERIOD', 'label' => 'Tasks Completed', 'value' => (clone $acctTaskBase)->whereIn('status', Task::COMPLETED_STATUSES)->where('completed_at', '>=', $monthStart)->where('completed_at', '<', $monthEnd)->count(), 'event' => 'Completed', 'event_field' => 'completed_at', 'period' => $monthLabel],
        ];

        // ---------------- Payment verification status ------------------------------------------------------------
        $verStats = $billIds !== null
            ? PaymentVerification::whereIn('bill_id', $billIds)->selectRaw('verification_status, COUNT(*) as c')->groupBy('verification_status')->pluck('c', 'verification_status')->all()
            : PaymentVerification::query()->selectRaw('verification_status, COUNT(*) as c')->groupBy('verification_status')->pluck('c', 'verification_status')->all();

        $paymentDonut = [
            'title' => 'Payment Verification Status',
            'center' => array_sum($verStats),
            'data' => [
                ['label' => 'Verified', 'value' => $verStats[PaymentVerification::STATUS_CONFIRMED] ?? 0, 'tone' => 'green'],
                ['label' => 'Pending', 'value' => $verStats[PaymentVerification::STATUS_PENDING] ?? 0, 'tone' => 'amber'],
                ['label' => 'Rejected', 'value' => $verStats[PaymentVerification::STATUS_REJECTED] ?? 0, 'tone' => 'red'],
                ['label' => 'Exception', 'value' => $verStats[PaymentVerification::STATUS_EXCEPTION] ?? 0, 'tone' => 'blue'],
            ],
        ];

        // ---------------- Property workflow ------------------------------------------------------------
        $wfCounts = [
            'Awaiting A&R Manager Review' => Task::query()->whereIn('task_type', [Task::TYPE_ACCOUNT_MANAGER_REVIEW, Task::TYPE_DISCOVERY_REVIEW])->whereNotIn('status', Task::COMPLETED_STATUSES)->where(fn ($q) => $q->whereNull('assigned_to')->orWhereNotIn('assigned_to', $accountTeamIds))->count()
                + PropertyDiscovery::where('decision_path', 'account')->whereIn('status', [PropertyDiscovery::STATUS_AWAITING_ACCT_REVIEW])->count(),
            'Approved / Awaiting Assignment' => Task::query()->whereIn('task_type', [Task::TYPE_ACCOUNT_PROCESSING, Task::TYPE_ACCOUNT_MANAGER_REVIEW, Task::TYPE_DISCOVERY_REVIEW])->whereNotIn('status', Task::COMPLETED_STATUSES)->where(fn ($q) => $q->whereNull('assigned_to')->orWhereNotIn('assigned_to', $accountTeamIds))->count(),
            'Assigned' => (clone $acctTaskBase)->where('status', 'Assigned')->count(),
            'Officer Processing (assigned)' => Task::query()->whereIn('task_type', [Task::TYPE_ACCOUNT_PROCESSING])->whereNotIn('status', Task::COMPLETED_STATUSES)->whereIn('assigned_to', $officerIds)->count()
                + PropertyDiscovery::whereIn('status', [PropertyDiscovery::STATUS_ASSIGNED_ACCT])->count(),
            'Bill Record Updated' => Task::query()->whereIn('task_type', [Task::TYPE_ACCOUNT_PROCESSING])->whereIn('status', Task::COMPLETED_STATUSES)->whereIn('assigned_to', $officerIds)->count(),
            'Bill Logged in RETD' => $totalBills,
            'Completed' => (clone $acctTaskBase)->whereIn('status', Task::COMPLETED_STATUSES)->count(),
        ];

        $pipeline = [
            'title' => 'Property Workflow',
            'stages' => collect($wfCounts)->map(fn ($c, $l) => ['label' => $l, 'count' => $c])->values()->all(),
        ];

        // ---------------- Officer workload ------------------------------------------------------------
        $workload = $this->buildAccountWorkload($officerIds, $acctTypes);

        // ---------------- Payment verification trend (last 6 months) ------------------------------------------------------------
        $trend = $this->buildPaymentVerificationTrend($billIds);

        // ---------------- Charts ------------------------------------------------------------
        $charts = [
            ['title' => 'Account & Record Workload by Status', 'data' => $this->taskStatusBreakdown($acctTypes, $officerIds)],
            ['title' => 'Payment Verification Trend', 'data' => $trend],
        ];

        // ---------------- Priority queue ------------------------------------------------------------
        $priority = $this->buildAccountPriorityQueue($user, $officerIds, $acctTypes, $billIds);

        // ---------------- Recent activity ------------------------------------------------------------
        $activity = $this->recentActivity($user, $billIds, $acctTypes, $officerIds);

        // ---------------- Alerts ------------------------------------------------------------
        $alerts = [
            ['label' => 'Overdue tasks', 'count' => $overdue, 'tone' => 'red', 'link' => '/account/my-tasks'],
            ['label' => 'Unassigned approved records', 'count' => $pendingAssignment, 'tone' => 'amber', 'link' => '/account/my-tasks'],
            ['label' => 'Potential duplicate receipt claims', 'count' => $this->duplicateClaimsCount($billIds), 'tone' => 'amber', 'link' => '/payments'],
            ['label' => 'Data quality failures', 'count' => $dataQualityIssues, 'tone' => 'red', 'link' => '/drill/flags'],
            ['label' => 'Pending amendments', 'count' => $amendmentsPending, 'tone' => 'brand', 'link' => '/amendments'],
            ['label' => 'Payment verifications overdue', 'count' => $this->pendingVerificationsOverdue($billIds), 'tone' => 'red', 'link' => '/payments'],
        ];

        // ---------------- Quick actions (permission-gated) ------------------------------------------------------------
        $quickActions = $this->accountQuickActions($user);

        return [
            'meta' => [
                'type' => 'account',
                'label' => 'Account & Record Manager Dashboard',
                'role' => $user->role?->name,
                'section' => $user->section?->name,
                'section_code' => $user->section?->code,
                'as_of' => BusinessClock::now()->toIso8601String(),
                'timezone' => BusinessClock::TIMEZONE,
                'range' => ['start' => $minLogged, 'end' => BusinessClock::today()->toDateString()],
                'reporting' => ReportingPeriod::meta(),
                'report_id' => 'RETD-DASH-'.strtoupper(substr(md5($user->id.'account-manager'.BusinessClock::now()->toDateTimeString()), 0, 10)),
            ],
            'kpis' => $kpis,
            'monthly' => $monthly,
            'financial' => $financial,
            'donuts' => [$paymentDonut],
            'pipeline' => $pipeline,
            'workload' => $workload,
            'charts' => $charts,
            'priority' => $priority,
            'activity' => $activity,
            'alerts' => $alerts,
            'quick_actions' => $quickActions,
        ];
    }

    /* =====================================================================
     *  PUBLIC - Valuation Manager
     * =================================================================== */

    public function forValuation(User $user): array
    {
        $officerIds = $this->sectionOfficerIds($user, 'Valuation Officer');

        $valuationBase = Valuation::query()->whereIn('valuation_officer_id', $officerIds);

        // ---------------- KPIs ------------------------------------------------------------
        $newTasks = Task::query()->whereIn('task_type', [Task::TYPE_VALUATION, Task::TYPE_VALUATION_REVIEW, Task::TYPE_VALUATION_MANAGER_REVIEW, Task::TYPE_AC_APPROVAL])
            ->whereIn('assigned_to', $officerIds)
            ->whereIn('status', ['Logged', 'Awaiting Assignment'])
            ->count();

        $assigned = (clone $valuationBase)->where('status', Valuation::STATUS_ASSIGNED)->count();

        $inProgress = (clone $valuationBase)->where('status', Valuation::STATUS_ASSESSMENT_IN_PROGRESS)->count();

        // Every valuation counter below is the size of a stage from the model's
        // authoritative map, so the manager's figures, the register tab a card drills to
        // and the records behind that tab are one population. `completed` in particular
        // counts only the terminal stage: a valuation still sitting in Account & Record
        // is outstanding work, not a finished assessment.
        $managerReview = (clone $valuationBase)->whereIn('status', Valuation::statusesForStage('Awaiting Review'))->count();

        $returned = (clone $valuationBase)->whereIn('status', Valuation::statusesForStage('Returned for Correction'))->count();

        $awaitingAC = (clone $valuationBase)->whereIn('status', Valuation::statusesForStage('Awaiting AC'))->count();

        $overdue = Task::query()->whereIn('task_type', [Task::TYPE_VALUATION, Task::TYPE_VALUATION_REVIEW])
            ->whereIn('assigned_to', $officerIds)
            ->overdue()
            ->count();

        $completed = (clone $valuationBase)->whereIn('status', Valuation::TERMINAL_STATUSES)->count();

        // Each valuation-stage card opens the register already filtered to that stage, so
        // the number on the card and the rows behind it are the same records.
        $stageLink = fn (string $stage) => '/valuations?status='.rawurlencode($stage);

        $kpis = [
            ['key' => 'new_valuation_tasks', 'kind' => 'current', 'kpi_code' => 'VAL_NEW_TASKS', 'label' => 'New Valuation Tasks', 'value' => $newTasks, 'tone' => 'navy', 'icon' => '🆕'],
            ['key' => 'assigned_valuations', 'kind' => 'current', 'kpi_code' => 'VAL_ASSIGNED', 'label' => 'Assigned Valuations', 'value' => $assigned, 'tone' => 'blue', 'icon' => '📋', 'link' => $stageLink('Assigned')],
            ['key' => 'in_progress', 'kind' => 'current', 'kpi_code' => 'VAL_IN_PROGRESS', 'label' => 'In Progress', 'value' => $inProgress, 'tone' => 'blue', 'icon' => '⏳', 'link' => $stageLink('Assessment in Progress')],
            ['key' => 'manager_review', 'kind' => 'current', 'kpi_code' => 'VAL_MANAGER_REVIEW', 'label' => 'Manager Review', 'value' => $managerReview, 'tone' => 'amber', 'icon' => '🔍', 'link' => $stageLink('Awaiting Review')],
            ['key' => 'returned', 'kind' => 'current', 'kpi_code' => 'VAL_RETURNED', 'label' => 'Returned', 'value' => $returned, 'tone' => 'brand', 'icon' => '↩', 'link' => $stageLink('Returned for Correction')],
            ['key' => 'awaiting_ac_approval', 'kind' => 'current', 'kpi_code' => 'VAL_AC_APPROVAL', 'label' => 'Awaiting AC Approval', 'value' => $awaitingAC, 'tone' => 'amber', 'icon' => '🕐', 'link' => $stageLink('Awaiting AC')],
            ['key' => 'overdue', 'kind' => 'current', 'kpi_code' => 'VAL_OVERDUE', 'label' => 'Overdue', 'value' => $overdue, 'tone' => 'red', 'icon' => '⏰'],
            ['key' => 'completed', 'kind' => 'current', 'kpi_code' => 'VAL_COMPLETED', 'label' => 'Completed', 'value' => $completed, 'tone' => 'green', 'icon' => '✅', 'link' => $stageLink('Completed')],
        ];

        // ---------------- Discovery pipeline ------------------------------------------------------------
        // Every stage below names a state the workflow actually writes. `Submitted`
        // and `Classified` belong to the old discovery vocabulary: submit() classifies
        // and routes in one step, so neither status is ever stored and a counter built
        // on them could only ever read zero. A new, unrouted discovery is a Draft.
        // The valuation-derived stages read the model's stage map — the same mapping
        // the KPI cards and the register tabs use — so a stage cannot under-count the
        // statuses that belong to it (AC Returned / AC Rejected are returns too).
        $dpCounts = [
            'New Discoveries' => PropertyDiscovery::where('status', PropertyDiscovery::STATUS_DRAFT)->count(),
            'Requiring Valuation' => PropertyDiscovery::whereIn('status', [PropertyDiscovery::STATUS_AWAITING_VALUATION, PropertyDiscovery::STATUS_REFERRED_VALUATION])->count(),
            'Under Valuation' => PropertyDiscovery::where('status', PropertyDiscovery::STATUS_UNDER_VALUATION)->count(),
            'Manager Review' => (clone $valuationBase)->whereIn('status', Valuation::statusesForStage('Awaiting Review'))->count(),
            'Awaiting AC Approval' => (clone $valuationBase)->whereIn('status', Valuation::statusesForStage('Awaiting AC'))->count(),
            'Returned' => (clone $valuationBase)->whereIn('status', Valuation::statusesForStage('Returned for Correction'))->count()
                + PropertyDiscovery::where('status', PropertyDiscovery::STATUS_RETURNED)->count(),
            'Approved' => (clone $valuationBase)->whereIn('status', [Valuation::STATUS_AC_APPROVED, Valuation::STATUS_REGISTERED, Valuation::STATUS_COMPLETED])->count()
                + PropertyDiscovery::whereIn('status', [PropertyDiscovery::STATUS_APPROVED, PropertyDiscovery::STATUS_REGISTERED, PropertyDiscovery::STATUS_COMPLETED])->count(),
        ];

        $pipeline = [
            'title' => 'Valuation Discovery Pipeline',
            'stages' => collect($dpCounts)->map(fn ($c, $l) => ['label' => $l, 'count' => $c])->values()->all(),
        ];

        // ---------------- Workload by status (donut) ------------------------------------------------------------
        $statusCounts = (clone $valuationBase)
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();

        $donuts = [
            [
                'title' => 'Valuation Workload by Status',
                'center' => array_sum($statusCounts),
                'data' => collect(Valuation::STATUSES)->map(fn ($s) => [
                    'label' => $s,
                    'value' => $statusCounts[$s] ?? 0,
                ])->filter(fn ($d) => $d['value'] > 0)->values()->all(),
            ],
        ];

        // ---------------- Officer workload ------------------------------------------------------------
        $workload = $this->buildValuationWorkload($officerIds);

        // ---------------- Review queue ------------------------------------------------------------
        $reviewQueue = Valuation::query()
            ->with('valuationOfficer:id,full_name')
            ->whereIn('valuation_officer_id', $officerIds)
            ->whereIn('status', [Valuation::STATUS_SUBMITTED_TO_MANAGER, Valuation::STATUS_UNDER_MANAGER_REVIEW])
            ->orderByDesc('submitted_at')
            ->limit(10)
            ->get()
            ->map(fn (Valuation $v) => [
                'id' => $v->id,
                'property_id' => $v->property_id ?? '—',
                'property_classification' => $v->property_classification ?? '—',
                'officer' => $v->valuationOfficer?->full_name ?? '—',
                'submitted' => $v->submitted_at?->toDateString() ?? '—',
                'due' => '—',
                'status' => $v->status,
            ])->values()->all();

        // ---------------- Quality ------------------------------------------------------------
        // Active = every stage that is not terminal. A valuation awaiting the account
        // officer's processing or registration is still outstanding work and is judged
        // on the same completeness grounds as one still with the officer.
        $activeValuations = (clone $valuationBase)->whereNotIn('status', Valuation::TERMINAL_STATUSES)->get();

        // A property is photographed on the discovery visit, so a valuation raised from a
        // discovery inherits those photographs. Reading only the valuation's own photo
        // rows reported "Missing Photos" against properties that have three on file.
        $hasPhotos = fn (Valuation $v) => $v->evidencePhotos()->count() > 0
            || $v->discoveryPropertyPhotographs()->isNotEmpty();

        $quality = [
            ['label' => 'Returned Valuations', 'count' => $returned],
            ['label' => 'Missing GPS', 'count' => $activeValuations->where('gps_coordinate', null)->count()],
            ['label' => 'Missing Photos', 'count' => $activeValuations->reject($hasPhotos)->count()],
            ['label' => 'Incomplete Specifications', 'count' => $activeValuations->filter(fn ($v) => blank($v->land_dimensions) || blank($v->building_specs))->count()],
            ['label' => 'Pending Clarification', 'count' => (clone $valuationBase)->where('status', Valuation::STATUS_AC_RETURNED)->count()],
        ];

        // ---------------- GPS & Evidence ------------------------------------------------------------
        $gpsCaptured = $activeValuations->where('gps_coordinate', '!=', null)->count();
        $gpsMissing = $activeValuations->where('gps_coordinate', null)->count();
        $photosComplete = $activeValuations->filter($hasPhotos)->count();
        $photosMissing = $activeValuations->reject($hasPhotos)->count();
        $pendingGpsVerification = $activeValuations->where('gps_verified', '===', null)->count();

        $gpsEvidence = [
            ['label' => 'GPS Captured', 'count' => $gpsCaptured, 'tone' => 'green'],
            ['label' => 'GPS Missing', 'count' => $gpsMissing, 'tone' => 'red'],
            ['label' => 'Photos Complete', 'count' => $photosComplete, 'tone' => 'green'],
            ['label' => 'Photos Missing', 'count' => $photosMissing, 'tone' => 'red'],
            ['label' => 'Pending GPS Verification', 'count' => $pendingGpsVerification, 'tone' => 'amber'],
        ];

        // ---------------- AC Approval ------------------------------------------------------------
        $acApproval = [
            ['label' => 'Awaiting AC Review', 'count' => (clone $valuationBase)->whereIn('status', [Valuation::STATUS_SUBMITTED_TO_AC, Valuation::STATUS_AWAITING_AC_APPROVAL])->count()],
            ['label' => 'Approved', 'count' => (clone $valuationBase)->where('status', Valuation::STATUS_AC_APPROVED)->count()],
            ['label' => 'Returned', 'count' => (clone $valuationBase)->where('status', Valuation::STATUS_AC_RETURNED)->count()],
            ['label' => 'Correction Required', 'count' => (clone $valuationBase)->where('status', Valuation::STATUS_AC_REJECTED)->count()],
        ];

        // ---------------- Property type breakdown ------------------------------------------------------------
        $propertyTypes = (clone $valuationBase)
            ->selectRaw('COALESCE(property_classification, \'Unspecified\') as type, COUNT(*) as c')
            ->groupBy('type')
            ->orderByDesc('c')
            ->pluck('c', 'type')
            ->map(fn ($c, $t) => ['label' => $t, 'value' => $c])
            ->values()
            ->all();

        // ---------------- Task source ------------------------------------------------------------
        $taskSource = [
            ['label' => 'Property Discovery', 'count' => (clone $valuationBase)->whereNotNull('discovery_id')->count()],
            ['label' => 'Existing Property Reassessment', 'count' => (clone $valuationBase)->whereNull('discovery_id')->whereNotNull('bill_id')->count()],
            ['label' => 'Other', 'count' => (clone $valuationBase)->whereNull('discovery_id')->whereNull('bill_id')->count()],
        ];

        // - Period activity (This Month) - event-date based (-27.8, -28, -66) -
        [$monthStart, $monthEnd, $monthLabel] = $this->monthlyRange();

        $monthly = [
            ['key' => 'valuations_completed_period', 'kind' => 'period', 'kpi_code' => 'VAL_COMPLETED_PERIOD', 'label' => 'Valuations Completed', 'value' => (clone $valuationBase)->whereIn('status', [Valuation::STATUS_REGISTERED, Valuation::STATUS_COMPLETED, Valuation::STATUS_SENT_TO_ACCT])->whereNotNull('registration_completed_at')->where('registration_completed_at', '>=', $monthStart)->where('registration_completed_at', '<', $monthEnd)->count(), 'event' => 'Registration Completed', 'event_field' => 'registration_completed_at', 'period' => $monthLabel],
            ['key' => 'valuations_submitted_period', 'kind' => 'period', 'kpi_code' => 'VAL_SUBMITTED_PERIOD', 'label' => 'Valuations Submitted', 'value' => (clone $valuationBase)->whereNotNull('submitted_at')->where('submitted_at', '>=', $monthStart)->where('submitted_at', '<', $monthEnd)->count(), 'event' => 'Submitted', 'event_field' => 'submitted_at', 'period' => $monthLabel],
            ['key' => 'valuations_approved_period', 'kind' => 'period', 'kpi_code' => 'VAL_APPROVED_PERIOD', 'label' => 'Valuations Approved', 'value' => (clone $valuationBase)->whereNotNull('ac_reviewed_at')->where('ac_reviewed_at', '>=', $monthStart)->where('ac_reviewed_at', '<', $monthEnd)->count(), 'event' => 'AC Approved', 'event_field' => 'ac_reviewed_at', 'period' => $monthLabel],
            ['key' => 'discoveries_requiring_valuation_period', 'kind' => 'period', 'kpi_code' => 'VAL_DISCOVERY_PERIOD', 'label' => 'Discoveries Requiring Valuation', 'value' => PropertyDiscovery::whereNotNull('discovery_date')->where('discovery_date', '>=', $monthStart->toDateString())->where('discovery_date', '<', $monthEnd->toDateString())->count(), 'event' => 'Discovered', 'event_field' => 'discovery_date', 'period' => $monthLabel],
        ];

        // ---------------- Charts ------------------------------------------------------------
        $charts = [
            ['title' => 'Valuation Tasks by Property Type', 'data' => $propertyTypes],
            ['title' => 'Valuation Tasks by Source', 'data' => $taskSource],
        ];

        // ---------------- Priority queue ------------------------------------------------------------
        $priority = $this->buildValuationPriorityQueue($user, $officerIds);

        // ---------------- Recent activity ------------------------------------------------------------
        $activity = $this->valuationRecentActivity($user, $officerIds);

        // ---------------- Alerts ------------------------------------------------------------
        // An alert that names a workflow stage opens the register filtered to that stage,
        // so the figure and the rows it opens are the same records. The task-level
        // figures (overdue, missing photos) have no stage to filter on and are left
        // unlinked rather than pointed at an unfiltered list.
        $alerts = [
            ['label' => 'Overdue valuations', 'count' => $overdue, 'tone' => 'red'],
            ['label' => 'Returned valuations', 'count' => $returned, 'tone' => 'amber', 'link' => $stageLink('Returned for Correction')],
            ['label' => 'Missing GPS', 'count' => $gpsMissing, 'tone' => 'red', 'link' => '/gps'],
            ['label' => 'Missing photos', 'count' => $photosMissing, 'tone' => 'red'],
            ['label' => 'Valuations awaiting Manager review', 'count' => $managerReview, 'tone' => 'amber', 'link' => $stageLink('Awaiting Review')],
            ['label' => 'Valuations awaiting AC approval', 'count' => $awaitingAC, 'tone' => 'amber', 'link' => $stageLink('Awaiting AC')],
        ];

        $quickActions = $this->valuationQuickActions($user);

        $return = [
            'meta' => [
                'type' => 'valuation',
                'label' => 'Valuation Manager Dashboard',
                'role' => $user->role?->name,
                'section' => $user->section?->name,
                'section_code' => $user->section?->code,
                'as_of' => BusinessClock::now()->toISOString(),
                'timezone' => BusinessClock::TIMEZONE,
                'reporting' => ReportingPeriod::meta(),
                'report_id' => 'RETD-DASH-'.strtoupper(substr(md5($user->id.'valuation-manager'.BusinessClock::now()->toDateTimeString()), 0, 10)),
            ],
            'kpis' => $kpis,
            'monthly' => $monthly,
            'pipeline' => $pipeline,
            'donuts' => $donuts,
            'workload' => $workload,
            'review_queue' => $reviewQueue,
            'quality' => $quality,
            'gps_evidence' => $gpsEvidence,
            'ac_approval' => $acApproval,
            'charts' => $charts,
            'priority' => $priority,
            'activity' => $activity,
            'alerts' => $alerts,
            'quick_actions' => $quickActions,
        ];

        return $return;
    }

    /* =====================================================================
     *  PUBLIC - Enforcement Manager
     * =================================================================== */

    public function forEnforcement(User $user): array
    {
        $billIds = BillScopeService::visibleBillIds($user);
        $officerIds = $this->sectionOfficerIds($user, 'Enforcement Officer');
        $today = BusinessClock::now()->toDateString();

        $billBase = $billIds !== null
            ? PropertyBill::whereIn('property_bills.id', $billIds)
            : PropertyBill::query();

        // ---------------- KPIs (12 cards) ------------------------------------------------------------
        $billsReceived = (clone $billBase)->count();

        $unassigned = $this->enforcementUnassignedQuery($billIds)->count();

        $assigned = (clone $billBase)->where('case_status', 'Assigned')->count();

        $outForDelivery = (clone $billBase)->where('case_status', 'Out for Delivery')->count();

        $delivered = (clone $billBase)->where('case_status', 'Delivered')->count();

        $paymentFollowup = (clone $billBase)->whereIn('case_status', ['Payment Follow-up', 'Under Verification'])->count();

        $overdueTasks = Task::query()
            ->whereIn('assigned_to', $officerIds)
            ->overdue()
            ->count();

        $escalated = (clone $billBase)->where('case_status', 'Escalated')->count();

        $reminders30 = (clone $billBase)->where('case_status', '30-Day Warning')->count();

        $demands72 = (clone $billBase)->where('case_status', '72-Hour Warning')->count();

        $finalNotices = (clone $billBase)->whereNotNull('final_notice_date')
            ->whereNotIn('case_status', ['Resolved', 'Closed'])
            ->count();

        $paidCompleted = (clone $billBase)->where(function ($q) {
            $q->whereIn('case_status', ['Resolved', 'Closed'])
                ->orWhere('payment_status', 'Paid');
        })->count();

        $kpis = [
            ['key' => 'bills_received', 'kind' => 'current', 'kpi_code' => 'ENF_BILLS_RECEIVED', 'label' => 'Bills Received', 'value' => $billsReceived, 'tone' => 'navy', 'icon' => '🧾'],
            ['key' => 'unassigned', 'kind' => 'current', 'kpi_code' => 'ENF_UNASSIGNED', 'label' => 'Unassigned', 'value' => $unassigned, 'tone' => 'amber', 'icon' => '📥'],
            ['key' => 'assigned', 'kind' => 'current', 'kpi_code' => 'ENF_ASSIGNED', 'label' => 'Assigned', 'value' => $assigned, 'tone' => 'blue', 'icon' => '👷'],
            ['key' => 'out_for_delivery', 'kind' => 'current', 'kpi_code' => 'ENF_OUT_FOR_DELIVERY', 'label' => 'Out for Delivery', 'value' => $outForDelivery, 'tone' => 'blue', 'icon' => '🚚'],
            ['key' => 'delivered', 'kind' => 'current', 'kpi_code' => 'ENF_DELIVERED_CURRENT', 'label' => 'Delivered', 'value' => $delivered, 'tone' => 'green', 'icon' => '📬'],
            ['key' => 'payment_followup', 'kind' => 'current', 'kpi_code' => 'ENF_PAYMENT_FOLLOWUP', 'label' => 'Payment Follow-up', 'value' => $paymentFollowup, 'tone' => 'amber', 'icon' => '💰'],
            ['key' => 'overdue', 'kind' => 'current', 'kpi_code' => 'ENF_OVERDUE', 'label' => 'Overdue', 'value' => $overdueTasks, 'tone' => 'red', 'icon' => '⏰'],
            ['key' => 'escalated', 'kind' => 'current', 'kpi_code' => 'ENF_ESCALATED', 'label' => 'Escalated', 'value' => $escalated, 'tone' => 'red', 'icon' => '🚨'],
            ['key' => 'reminders_30', 'kind' => 'current', 'kpi_code' => 'ENF_30DAY_REMINDER', 'label' => '30-Day Reminders', 'value' => $reminders30, 'tone' => 'amber', 'icon' => '🔔'],
            ['key' => 'demands_72', 'kind' => 'current', 'kpi_code' => 'ENF_72_HOUR_DEMAND', 'label' => '72-Hour Demands', 'value' => $demands72, 'tone' => 'red', 'icon' => '⚡'],
            ['key' => 'final_notices', 'kind' => 'current', 'kpi_code' => 'ENF_FINAL_NOTICE', 'label' => 'Final Notices', 'value' => $finalNotices, 'tone' => 'red', 'icon' => '📄'],
            ['key' => 'paid_completed', 'kind' => 'current', 'kpi_code' => 'ENF_PAID_COMPLETED', 'label' => 'Paid / Completed', 'value' => $paidCompleted, 'tone' => 'green', 'icon' => '✅'],
        ];

        // ---------------- Bill lifecycle pipeline ------------------------------------------------------------
        $pipelineStages = [
            'Received' => $billsReceived,
            'Assigned' => $assigned,
            'Out for Delivery' => $outForDelivery,
            'Delivered' => $delivered,
            'Payment Follow-up' => $paymentFollowup,
            '30-Day Reminder' => $reminders30,
            '72-Hour Demand' => $demands72,
            'Escalated' => $escalated,
            'Paid / Completed' => $paidCompleted,
        ];

        $pipeline = [
            'title' => 'Bill Lifecycle Pipeline',
            'stages' => collect($pipelineStages)->map(fn ($c, $l) => ['label' => $l, 'count' => $c])->values()->all(),
        ];

        // ---------------- Delivery performance ------------------------------------------------------------
        $deliveryVisitBase = EnforcementVisit::query()
            ->when($billIds !== null, fn ($q) => $q->whereIn('bill_id', $billIds))
            ->whereIn('delivery_status', [...EnforcementVisit::DELIVERY_STATUSES, 'Returned to Office']);
        $deliveredCount = (clone $deliveryVisitBase)->where('delivery_status', EnforcementVisit::DELIVERY_DELIVERED)->count();
        $pendingVisits = (clone $deliveryVisitBase)->where('delivery_status', EnforcementVisit::DELIVERY_OUT_FOR_DELIVERY)->count();
        $returnedVisits = (clone $deliveryVisitBase)->whereIn('delivery_status', [EnforcementVisit::DELIVERY_RETURNED, 'Returned to Office'])->count();
        $undeliveredVisits = (clone $deliveryVisitBase)->where('delivery_status', EnforcementVisit::DELIVERY_UNDELIVERED)->count();
        $notMailableVisits = (clone $deliveryVisitBase)->where('delivery_status', EnforcementVisit::DELIVERY_NOT_MAILABLE)->count();
        $failedVisits = $returnedVisits + $undeliveredVisits + $notMailableVisits;

        $donuts = [
            [
                'title' => 'Delivery Performance',
                'center' => $deliveryVisitBase->count(),
                'data' => [
                    ['label' => 'Delivered', 'value' => $deliveredCount, 'tone' => 'green'],
                    ['label' => 'Out for Delivery', 'value' => $pendingVisits, 'tone' => 'amber'],
                    ['label' => 'Returned', 'value' => $returnedVisits, 'tone' => 'red'],
                    ['label' => 'Undelivered', 'value' => $undeliveredVisits, 'tone' => 'red'],
                    ['label' => 'Not Mailable', 'value' => $notMailableVisits, 'tone' => 'slate'],
                ],
            ],
        ];

        // ---------------- Officer workload ------------------------------------------------------------
        $workload = $this->buildEnforcementWorkload($officerIds);

        // ---------------- Field activity today ------------------------------------------------------------
        $todayVisits = EnforcementVisit::query()
            ->when($billIds !== null, fn ($q) => $q->whereIn('bill_id', $billIds))
            ->whereDate('visit_date', $today);

        $fieldActivity = [
            ['label' => 'Visits Today', 'count' => (clone $todayVisits)->count(), 'tone' => 'blue'],
            ['label' => 'Delivered', 'count' => (clone $todayVisits)->where('delivery_status', EnforcementVisit::DELIVERY_DELIVERED)->count(), 'tone' => 'green'],
            ['label' => 'Delivery Attempts', 'count' => (clone $todayVisits)->whereNotNull('visit_status')->where('delivery_status', '!=', EnforcementVisit::DELIVERY_DELIVERED)->count(), 'tone' => 'amber'],
            ['label' => 'Scheduled / Pending', 'count' => (clone $todayVisits)->whereNull('visit_status')->count(), 'tone' => 'amber'],
            ['label' => 'GPS Captured', 'count' => (clone $todayVisits)->whereNotNull('gps_coordinate')->count(), 'tone' => 'green'],
            ['label' => 'Evidence Submitted', 'count' => (clone $todayVisits)->whereNotNull('visit_photo')->count(), 'tone' => 'green'],
        ];

        // ---------------- Payment follow-up ------------------------------------------------------------
        $payFollowup = [
            ['label' => 'Pending Follow-up', 'count' => $paymentFollowup, 'tone' => 'amber'],
            ['label' => 'Partial Payment', 'count' => (clone $billBase)->where('payment_status', 'Partially Paid')->count(), 'tone' => 'blue'],
            ['label' => 'Full Payment', 'count' => (clone $billBase)->where('payment_status', 'Paid')->count(), 'tone' => 'green'],
            ['label' => 'No Payment', 'count' => (clone $billBase)->where('payment_status', 'Unpaid')->count(), 'tone' => 'red'],
            ['label' => 'Overdue', 'count' => $overdueTasks, 'tone' => 'red'],
        ];

        // ---------------- Notice & escalation ------------------------------------------------------------
        $noticeEscalation = [
            ['label' => '30-Day Reminder', 'count' => $reminders30, 'tone' => 'amber'],
            ['label' => '72-Hour Demand', 'count' => $demands72, 'tone' => 'red'],
            ['label' => 'Final Notice', 'count' => $finalNotices, 'tone' => 'red'],
            ['label' => 'Escalated', 'count' => $escalated, 'tone' => 'red'],
        ];

        // ---------------- Payment security ------------------------------------------------------------
        $pendingVerifications = PaymentVerification::query()
            ->when($billIds !== null, fn ($q) => $q->whereIn('bill_id', $billIds))
            ->where('verification_status', PaymentVerification::STATUS_PENDING)
            ->count();
        $duplicateClaims = $this->duplicateClaimsCount($billIds);
        $rejectedReceipts = PaymentVerification::query()
            ->when($billIds !== null, fn ($q) => $q->whereIn('bill_id', $billIds))
            ->where('verification_status', PaymentVerification::STATUS_REJECTED)
            ->count();

        $paymentSecurity = [
            ['label' => 'Pending Verification', 'count' => $pendingVerifications, 'tone' => 'amber'],
            ['label' => 'Potential Duplicate Claims', 'count' => $duplicateClaims, 'tone' => 'red'],
            ['label' => 'Rejected Receipts', 'count' => $rejectedReceipts, 'tone' => 'red'],
        ];

        // ---------------- Priority queue ------------------------------------------------------------
        $priority = $this->buildEnforcementPriorityQueue($user, $officerIds, $billIds);

        // ---------------- Recent activity ------------------------------------------------------------
        $activity = $this->enforcementRecentActivity($user, $billIds, $officerIds);

        // ---------------- Charts ------------------------------------------------------------
        $charts = [
            ['title' => 'Enforcement Workload by Stage', 'data' => collect($pipelineStages)->map(fn ($c, $l) => ['label' => $l, 'value' => $c])->values()->all()],
        ];

        $quickActions = $this->enforcementQuickActions($user);

        // - Period activity (This Month) - event-date based (-31, -33, -35, -38) -
        [$monthStart, $monthEnd, $monthLabel] = $this->monthlyRange();
        $visitsBase = EnforcementVisit::query()->when($billIds !== null, fn ($q) => $q->whereIn('bill_id', $billIds));

        $monthly = [
            ['key' => 'bills_logged_period', 'kind' => 'period', 'kpi_code' => 'AR_BILLS_LOGGED_PERIOD', 'label' => 'Bills Logged in RETD', 'value' => (clone $billBase)->where('date_logged', '>=', $monthStart->toDateString())->where('date_logged', '<', $monthEnd->toDateString())->count(), 'event' => 'Bill Logged', 'event_field' => 'date_logged', 'period' => $monthLabel],
            ['key' => 'bills_delivered_period', 'kind' => 'period', 'kpi_code' => 'ENF_DELIVERED_PERIOD', 'label' => 'Bills Delivered', 'value' => (clone $billBase)->whereNotNull('delivery_date')->where('delivery_date', '>=', $monthStart->toDateString())->where('delivery_date', '<', $monthEnd->toDateString())->count(), 'event' => 'Delivered', 'event_field' => 'delivery_date', 'period' => $monthLabel],
            ['key' => 'delivery_attempts_period', 'kind' => 'period', 'kpi_code' => 'ENF_DELIVERY_ATTEMPTS_PERIOD', 'label' => 'Delivery Attempts', 'value' => (clone $visitsBase)->whereNotNull('visit_date')->where('visit_date', '>=', $monthStart->toDateString())->where('visit_date', '<', $monthEnd->toDateString())->count(), 'event' => 'Field Visit', 'event_field' => 'visit_date', 'period' => $monthLabel],
            ['key' => 'reminders_30_period', 'kind' => 'period', 'kpi_code' => 'ENF_30DAY_REMINDER_PERIOD', 'label' => '30-Day Reminders Issued', 'value' => (clone $billBase)->whereNotNull('thirty_day_notice_date')->where('thirty_day_notice_date', '>=', $monthStart->toDateString())->where('thirty_day_notice_date', '<', $monthEnd->toDateString())->count(), 'event' => 'Reminder Issued', 'event_field' => 'thirty_day_notice_date', 'period' => $monthLabel],
            ['key' => 'final_notices_period', 'kind' => 'period', 'kpi_code' => 'ENF_FINAL_NOTICE_PERIOD', 'label' => 'Final Notices Issued', 'value' => (clone $billBase)->whereNotNull('final_notice_date')->where('final_notice_date', '>=', $monthStart->toDateString())->where('final_notice_date', '<', $monthEnd->toDateString())->count(), 'event' => 'Notice Issued', 'event_field' => 'final_notice_date', 'period' => $monthLabel],
            ['key' => 'paid_completed_period', 'kind' => 'period', 'kpi_code' => 'ENF_PAID_COMPLETED_PERIOD', 'label' => 'Cases Paid / Completed', 'value' => Task::query()->whereIn('task_type', [Task::TYPE_BILL_DELIVERY, Task::TYPE_PAYMENT_FOLLOWUP, Task::TYPE_ENFORCEMENT_VISIT])->whereIn('assigned_to', $officerIds)->whereIn('status', Task::COMPLETED_STATUSES)->whereNotNull('completed_at')->where('completed_at', '>=', $monthStart)->where('completed_at', '<', $monthEnd)->count(), 'event' => 'Completed', 'event_field' => 'completed_at', 'period' => $monthLabel],
        ];

        return [
            'meta' => [
                'type' => 'enforcement',
                'label' => ($user->role?->name ?? 'Enforcement').' Dashboard',
                'role' => $user->role?->name,
                'section' => $user->section?->name,
                'section_code' => $user->section?->code,
                'as_of' => BusinessClock::now()->toISOString(),
                'timezone' => BusinessClock::TIMEZONE,
                'reporting' => ReportingPeriod::meta(),
                'report_id' => 'RETD-DASH-'.strtoupper(substr(md5($user->id.'enforcement-manager'.BusinessClock::now()->toDateTimeString()), 0, 10)),
            ],
            'kpis' => $kpis,
            'monthly' => $monthly,
            'pipeline' => $pipeline,
            'donuts' => $donuts,
            'workload' => $workload,
            'field_activity' => $fieldActivity,
            'payment_followup' => $payFollowup,
            'notice_escalation' => $noticeEscalation,
            'payment_security' => $paymentSecurity,
            'charts' => $charts,
            'priority' => $priority,
            'activity' => $activity,
            'alerts' => $this->enforcementAlerts($unassigned, $overdueTasks, $demands72, $duplicateClaims, $pendingVerifications, $escalated, $failedVisits),
            'quick_actions' => $quickActions,
        ];
    }

    /* =====================================================================
     *  PUBLIC - Assistant Commissioner (division-wide leadership view)
     * =================================================================== */

    /**
     * Division-wide overview for the Assistant Commissioner. Aggregates every
     * section (Account & Record, Valuation, Enforcement) - the AC holds the
     * `division` scope and answers to the whole RETD workload, not one team.
     * Same payload contract as the manager dashboards (KPIs, financial, donuts,
     * pipeline, workload, charts, priority, activity, alerts, quick_actions) so
     * web + mobile render it through the same surfaces.
     */
    public function forAssistantCommissioner(User $user): array
    {
        $officerIds = $this->divisionOfficerIds();

        // ---------------- KPIs (division-wide) ------------------------------------------------------------
        $totalBills = PropertyBill::query()->count();
        $pendingAssignment = Task::query()->whereNotIn('status', Task::COMPLETED_STATUSES)->whereNull('assigned_to')->count();
        $activeTasks = Task::query()->whereNotIn('status', Task::COMPLETED_STATUSES)->count();
        $overdue = Task::query()->overdue()->count();
        $valuationsActive = Valuation::query()->whereNotIn('status', [
            Valuation::STATUS_COMPLETED, Valuation::STATUS_REGISTERED, Valuation::STATUS_SENT_TO_ACCT,
        ])->count();
        $paymentVerificationPending = PaymentVerification::where('verification_status', PaymentVerification::STATUS_PENDING)->count();
        $enforcementActive = Task::query()
            ->whereIn('task_type', [Task::TYPE_BILL_DELIVERY, Task::TYPE_PAYMENT_FOLLOWUP, Task::TYPE_ENFORCEMENT_VISIT])
            ->whereNotIn('status', Task::COMPLETED_STATUSES)
            ->count();
        $amendmentsPending = BillAmendment::query()->count();

        $kpis = [
            ['key' => 'bills_logged', 'kind' => 'current', 'kpi_code' => 'AC_BILLS_LOGGED', 'label' => 'Bills Logged in RETD', 'value' => $totalBills, 'tone' => 'blue', 'icon' => '🧾', 'link' => '/bills'],
            ['key' => 'pending_assignment', 'kind' => 'current', 'kpi_code' => 'AR_PENDING_ASSIGNMENT', 'label' => 'Pending Assignment', 'value' => $pendingAssignment, 'tone' => 'amber', 'icon' => '📥', 'link' => '/enforcement?tab=tasks&group=unassigned'],
            ['key' => 'active_tasks', 'kind' => 'current', 'kpi_code' => 'AR_ASSIGNED_TASKS', 'label' => 'Active Tasks (Division)', 'value' => $activeTasks, 'tone' => 'navy', 'icon' => '📋', 'link' => '/enforcement?tab=tasks'],
            ['key' => 'overdue', 'kind' => 'current', 'kpi_code' => 'ENF_OVERDUE', 'label' => 'Overdue Tasks', 'value' => $overdue, 'tone' => 'red', 'icon' => '⏰', 'link' => '/enforcement?tab=tasks'],
            ['key' => 'valuations_active', 'kind' => 'current', 'kpi_code' => 'VAL_IN_PROGRESS', 'label' => 'Valuations in Progress', 'value' => $valuationsActive, 'tone' => 'blue', 'icon' => '📐', 'link' => '/valuations'],
            ['key' => 'payment_verification', 'kind' => 'current', 'kpi_code' => 'AR_PAYMENT_VERIFICATION_PENDING', 'label' => 'Payment Verification', 'value' => $paymentVerificationPending, 'tone' => 'amber', 'icon' => '💳', 'link' => '/payments'],
            ['key' => 'enforcement_active', 'kind' => 'current', 'kpi_code' => 'ENF_ASSIGNED', 'label' => 'Enforcement Cases', 'value' => $enforcementActive, 'tone' => 'brand', 'icon' => '🚚', 'link' => '/enforcement'],
            ['key' => 'amendments', 'kind' => 'current', 'kpi_code' => 'AR_AMENDMENTS_PENDING', 'label' => 'Amendments Recorded', 'value' => $amendmentsPending, 'tone' => 'navy', 'icon' => '✏️', 'link' => '/amendments'],
        ];

        // ---------------- Financial summary (division-wide) ------------------------------------------------------------
        $financial = [
            ['label' => 'Total Tax Due', 'value' => round((float) PropertyBill::sum('total_tax_due'), 2), 'money' => true],
            ['label' => 'Verified Payments', 'value' => round((float) Payment::sum('amount'), 2), 'money' => true],
            ['label' => 'Outstanding Balance', 'value' => round((float) PropertyBill::sum('outstanding_balance'), 2), 'money' => true],
            ['label' => 'Partial Payments', 'value' => round((float) PropertyBill::where('payment_status', 'Partially Paid')->sum('outstanding_balance'), 2), 'money' => true],
            ['label' => 'Fully Paid Bills', 'value' => round((float) PropertyBill::where('payment_status', 'Paid')->sum('total_tax_due'), 2), 'money' => true],
            ['label' => 'Unverified Payments', 'value' => round((float) PaymentVerification::where('verification_status', PaymentVerification::STATUS_PENDING)->sum('amount_claimed'), 2), 'money' => true],
        ];

        // ---------------- Payment verification status donut ------------------------------------------------------------
        $verStats = PaymentVerification::query()->selectRaw('verification_status, COUNT(*) as c')->groupBy('verification_status')->pluck('c', 'verification_status')->all();

        $donuts = [[
            'title' => 'Payment Verification Status',
            'center' => array_sum($verStats),
            'data' => [
                ['label' => 'Verified', 'value' => $verStats[PaymentVerification::STATUS_CONFIRMED] ?? 0, 'tone' => 'green'],
                ['label' => 'Pending', 'value' => $verStats[PaymentVerification::STATUS_PENDING] ?? 0, 'tone' => 'amber'],
                ['label' => 'Rejected', 'value' => $verStats[PaymentVerification::STATUS_REJECTED] ?? 0, 'tone' => 'red'],
                ['label' => 'Exception', 'value' => $verStats[PaymentVerification::STATUS_EXCEPTION] ?? 0, 'tone' => 'blue'],
            ],
        ]];

        // ---------------- Division workflow pipeline ------------------------------------------------------------
        $pipeline = [
            'title' => 'Division Workflow',
            'stages' => [
                ['label' => 'Bills Logged', 'count' => $totalBills],
                ['label' => 'Pending Assignment', 'count' => $pendingAssignment],
                ['label' => 'Assigned & Active', 'count' => max($activeTasks - $pendingAssignment, 0)],
                ['label' => 'Completed Tasks', 'count' => Task::whereIn('status', Task::COMPLETED_STATUSES)->count()],
            ],
        ];

        // ---------------- Officer workload (all sections) ------------------------------------------------------------
        $workload = $this->buildDivisionWorkload($officerIds);

        // ---------------- Charts ------------------------------------------------------------
        $charts = [
            ['title' => 'Task Status — Division-wide', 'data' => $this->divisionTaskStatusBreakdown()],
            ['title' => 'Payment Verification Trend', 'data' => $this->buildPaymentVerificationTrend(null)],
        ];

        // ---------------- Priority queue (division-wide) ------------------------------------------------------------
        // - Period activity (This Month) - division-wide, event-date based (-31.1) -
        [$monthStart, $monthEnd, $monthLabel] = $this->monthlyRange();

        $monthly = [
            ['key' => 'bills_logged_period', 'kind' => 'period', 'kpi_code' => 'AC_BILLS_LOGGED_PERIOD', 'label' => 'Bills Logged in RETD', 'value' => PropertyBill::where('date_logged', '>=', $monthStart->toDateString())->where('date_logged', '<', $monthEnd->toDateString())->count(), 'event' => 'Bill Logged', 'event_field' => 'date_logged', 'period' => $monthLabel],
            ['key' => 'payments_verified_period', 'kind' => 'period', 'kpi_code' => 'AR_PAYMENTS_VERIFIED_PERIOD', 'label' => 'Payments Verified', 'value' => PaymentVerification::where('verification_status', PaymentVerification::STATUS_CONFIRMED)->where('verified_at', '>=', $monthStart)->where('verified_at', '<', $monthEnd)->count(), 'event' => 'Verified', 'event_field' => 'verified_at', 'period' => $monthLabel],
            ['key' => 'valuations_completed_period', 'kind' => 'period', 'kpi_code' => 'VAL_COMPLETED_PERIOD', 'label' => 'Valuations Completed', 'value' => Valuation::whereIn('status', [Valuation::STATUS_REGISTERED, Valuation::STATUS_COMPLETED, Valuation::STATUS_SENT_TO_ACCT])->whereNotNull('registration_completed_at')->where('registration_completed_at', '>=', $monthStart)->where('registration_completed_at', '<', $monthEnd)->count(), 'event' => 'Registration Completed', 'event_field' => 'registration_completed_at', 'period' => $monthLabel],
            ['key' => 'bills_delivered_period', 'kind' => 'period', 'kpi_code' => 'ENF_DELIVERED_PERIOD', 'label' => 'Bills Delivered', 'value' => PropertyBill::whereNotNull('delivery_date')->where('delivery_date', '>=', $monthStart->toDateString())->where('delivery_date', '<', $monthEnd->toDateString())->count(), 'event' => 'Delivered', 'event_field' => 'delivery_date', 'period' => $monthLabel],
            ['key' => 'tasks_completed_period', 'kind' => 'period', 'kpi_code' => 'ENF_PAID_COMPLETED_PERIOD', 'label' => 'Tasks Completed', 'value' => Task::whereIn('status', Task::COMPLETED_STATUSES)->whereNotNull('completed_at')->where('completed_at', '>=', $monthStart)->where('completed_at', '<', $monthEnd)->count(), 'event' => 'Completed', 'event_field' => 'completed_at', 'period' => $monthLabel],
        ];

        $priority = $this->buildDivisionPriority();

        // ---------------- Recent activity ------------------------------------------------------------
        $activity = $this->recentActivity($user, null, $this->allTaskTypes(), $officerIds);

        // ---------------- Alerts ------------------------------------------------------------
        $alerts = [
            ['label' => 'Overdue tasks', 'count' => $overdue, 'tone' => 'red', 'link' => '/enforcement?tab=tasks'],
            ['label' => 'Unassigned records', 'count' => $pendingAssignment, 'tone' => 'amber', 'link' => '/enforcement?tab=tasks&group=unassigned'],
            ['label' => 'Payment verifications pending', 'count' => $paymentVerificationPending, 'tone' => 'amber', 'link' => '/payments'],
            ['label' => 'Valuations awaiting manager review', 'count' => Valuation::whereIn('status', [Valuation::STATUS_SUBMITTED_TO_MANAGER, Valuation::STATUS_UNDER_MANAGER_REVIEW])->count(), 'tone' => 'amber', 'link' => '/valuations'],
            ['label' => 'Data quality issues', 'count' => DataQualityFlag::where('status', '!=', DataQualityFlag::STATUSES[2])->count(), 'tone' => 'red', 'link' => '/drill/flags'],
            ['label' => 'Potential duplicate receipt claims', 'count' => $this->duplicateClaimsCount(null), 'tone' => 'amber', 'link' => '/payments'],
        ];

        // ---------------- Quick actions (permission-gated) ------------------------------------------------------------
        $quickActions = $this->leadershipQuickActions($user);

        return [
            'meta' => [
                'type' => 'ac',
                'label' => 'Assistant Commissioner Dashboard',
                'role' => $user->role?->name,
                'section' => $user->section?->name,
                'section_code' => $user->section?->code,
                'as_of' => BusinessClock::now()->toISOString(),
                'timezone' => BusinessClock::TIMEZONE,
                'range' => ['start' => substr((string) PropertyBill::min('date_logged'), 0, 10) ?: '—', 'end' => BusinessClock::today()->toDateString()],
                'reporting' => ReportingPeriod::meta(),
                'report_id' => 'RETD-DASH-'.strtoupper(substr(md5($user->id.'assistant-commissioner'.BusinessClock::now()->toDateTimeString()), 0, 10)),
            ],
            'kpis' => $kpis,
            'monthly' => $monthly,
            'financial' => $financial,
            'donuts' => $donuts,
            'pipeline' => $pipeline,
            'workload' => $workload,
            'charts' => $charts,
            'priority' => $priority,
            'activity' => $activity,
            'alerts' => $alerts,
            'quick_actions' => $quickActions,
        ];
    }

    /* =====================================================================
     *  SHARED - Officer workload builders
     * =================================================================== */

    private function buildAccountWorkload(array $officerIds, array $acctTypes): array
    {
        if ($officerIds === []) {
            return ['title' => 'Officer Workload', 'columns' => ['Officer', 'Assigned', 'In Progress', 'Returned', 'Payment Verification', 'Overdue', 'Completed'], 'rows' => []];
        }

        $users = User::whereIn('id', $officerIds)->get(['id', 'full_name']);
        $rows = [];

        foreach ($users as $u) {
            $base = Task::query()->where('assigned_to', $u->id)->whereIn('task_type', $acctTypes);
            $active = (clone $base)->whereNotIn('status', Task::COMPLETED_STATUSES);

            $rows[] = [
                'officer_id' => $u->id,
                'officer' => $u->full_name,
                'cols' => [
                    'assigned' => $active->count(),
                    'in_progress' => (clone $active)->whereNotIn('status', ['Logged', 'Awaiting Assignment', 'Payment Rejected'])->count(),
                    'returned' => (clone $active)->where('status', 'Payment Rejected')->count(),
                    'payment_verification' => Task::query()->where('assigned_to', $u->id)->where('task_type', Task::TYPE_PAYMENT_VERIFICATION)->whereNotIn('status', Task::COMPLETED_STATUSES)->count(),
                    'overdue' => (clone $active)->overdue()->count(),
                    'completed' => (clone $base)->whereIn('status', Task::COMPLETED_STATUSES)->count(),
                ],
            ];
        }

        return ['title' => 'Officer Workload', 'columns' => ['Officer', 'Assigned', 'In Progress', 'Returned', 'Payment Verification', 'Overdue', 'Completed'], 'rows' => $rows];
    }

    private function buildValuationWorkload(array $officerIds): array
    {
        if ($officerIds === []) {
            return ['title' => 'Officer Workload', 'columns' => ['Officer', 'Assigned', 'In Progress', 'Review', 'Returned', 'Overdue', 'Completed'], 'rows' => []];
        }

        $users = User::whereIn('id', $officerIds)->get(['id', 'full_name']);
        $rows = [];

        foreach ($users as $u) {
            $vals = Valuation::query()->where('valuation_officer_id', $u->id);
            $tasks = Task::query()->where('assigned_to', $u->id)->whereIn('task_type', [Task::TYPE_VALUATION, Task::TYPE_VALUATION_REVIEW]);
            $activeTasks = (clone $tasks)->whereNotIn('status', Task::COMPLETED_STATUSES);

            $rows[] = [
                'officer_id' => $u->id,
                'officer' => $u->full_name,
                'cols' => [
                    'assigned' => (clone $vals)->where('status', Valuation::STATUS_ASSIGNED)->count(),
                    'in_progress' => (clone $vals)->where('status', Valuation::STATUS_ASSESSMENT_IN_PROGRESS)->count(),
                    'review' => (clone $vals)->whereIn('status', [Valuation::STATUS_SUBMITTED_TO_MANAGER, Valuation::STATUS_UNDER_MANAGER_REVIEW])->count(),
                    'returned' => (clone $vals)->where('status', Valuation::STATUS_RETURNED)->count(),
                    'overdue' => (clone $activeTasks)->overdue()->count(),
                    'completed' => (clone $vals)->whereIn('status', [Valuation::STATUS_COMPLETED, Valuation::STATUS_REGISTERED, Valuation::STATUS_SENT_TO_ACCT])->count(),
                ],
            ];
        }

        return ['title' => 'Officer Workload', 'columns' => ['Officer', 'Assigned', 'In Progress', 'Review', 'Returned', 'Overdue', 'Completed'], 'rows' => $rows];
    }

    private function buildEnforcementWorkload(array $officerIds): array
    {
        if ($officerIds === []) {
            return ['title' => 'Officer Workload', 'mode' => 'exclusive', 'columns' => ['Officer', 'Active', 'Completed'], 'rows' => []];
        }

        $users = User::whereIn('id', $officerIds)->get(['id', 'full_name']);
        $rows = [];

        foreach ($users as $u) {
            $tasks = Task::query()->where('assigned_to', $u->id)->whereIn('task_type', [Task::TYPE_BILL_DELIVERY, Task::TYPE_PAYMENT_FOLLOWUP, Task::TYPE_ENFORCEMENT_VISIT]);
            $active = (clone $tasks)->whereNotIn('status', Task::COMPLETED_STATUSES);

            $rows[] = [
                'officer_id' => $u->id,
                'officer' => $u->full_name,
                'cols' => [
                    'active' => (clone $active)->count(),
                    'completed' => (clone $tasks)->whereIn('status', Task::COMPLETED_STATUSES)->count(),
                ],
                'operational' => [
                    'delivery' => (clone $active)->where('task_type', Task::TYPE_BILL_DELIVERY)->count(),
                    'followup' => (clone $active)->where('task_type', Task::TYPE_PAYMENT_FOLLOWUP)->count(),
                    'notices' => (clone $active)->whereIn('status', ['30-Day Warning', '72-Hour Warning'])->count(),
                    'overdue' => (clone $active)->overdue()->count(),
                ],
            ];
        }

        return [
            'title' => 'Officer Workload',
            'mode' => 'exclusive',
            'columns' => ['Officer', 'Active', 'Completed'],
            'operational_metrics' => [
                ['key' => 'delivery', 'label' => 'Active deliveries'],
                ['key' => 'followup', 'label' => 'Active follow-ups'],
                ['key' => 'notices', 'label' => 'Active notices'],
                ['key' => 'overdue', 'label' => 'Overdue active tasks'],
            ],
            'rows' => $rows,
        ];
    }

    /* =====================================================================
     *  SHARED - Charts, activity, priority, quick actions
     * =================================================================== */

    private function taskStatusBreakdown(array $types, array $officerIds): array
    {
        $rows = Task::query()
            ->whereIn('task_type', $types)
            ->whereIn('assigned_to', $officerIds)
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->orderByDesc('c')
            ->pluck('c', 'status')
            ->map(fn ($c, $s) => ['label' => $s, 'value' => $c])
            ->values()
            ->all();

        return $rows;
    }

    private function buildPaymentVerificationTrend(?array $billIds): array
    {
        $query = PaymentVerification::query();
        if ($billIds !== null) {
            $query->whereIn('bill_id', $billIds);
        }

        $dates = $query->where('verified_at', '>=', BusinessClock::now()->subMonths(6)->startOfMonth())
            ->where('verification_status', PaymentVerification::STATUS_CONFIRMED)
            ->pluck('verified_at');

        $counts = [];
        foreach ($dates as $d) {
            $key = $d instanceof \DateTimeInterface ? $d->format('Y-m') : substr((string) $d, 0, 7);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $out = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = BusinessClock::now()->subMonths($i);
            $out[] = ['label' => $month->format('M Y'), 'value' => $counts[$month->format('Y-m')] ?? 0];
        }

        return $out;
    }

    private function recentActivity(User $user, ?array $billIds, array $acctTypes, array $officerIds): array
    {
        $events = collect();

        // Task history (account-type tasks)
        $taskHist = TaskHistory::query()
            ->with('task:id,task_reference,reference_type,reference_id')
            ->whereHas('task', fn ($q) => $q->whereIn('task_type', $acctTypes)->whereIn('assigned_to', $officerIds))
            ->orderByDesc('task_histories.created_at')
            ->limit(20)
            ->get();

        foreach ($taskHist as $h) {
            $ref = $h->task?->task_reference ?? "Task #{$h->task_id}";
            $action = $h->action ?? "{$h->from_status} → {$h->to_status}";
            $events[] = [
                'event' => ucfirst(str_replace('_', ' ', $action)),
                'reference' => $ref,
                'user' => '—',
                'time' => $h->created_at?->toISOString(),
            ];
        }

        // Recent amendments
        $amendQuery = BillAmendment::query()->orderByDesc('amended_at')->limit(5);
        if ($billIds !== null) {
            $amendQuery->whereIn('bill_id', $billIds);
        }
        foreach ($amendQuery->get() as $a) {
            $events[] = [
                'event' => 'Amendment applied',
                'reference' => $a->document_number ?? "Bill #{$a->bill_id}",
                'user' => '—',
                'time' => $a->amended_at?->toISOString(),
            ];
        }

        // Recent verifications
        $verQuery = PaymentVerification::query()->orderByDesc('verified_at')->limit(5);
        if ($billIds !== null) {
            $verQuery->whereIn('bill_id', $billIds);
        }
        foreach ($verQuery->get() as $v) {
            $events[] = [
                'event' => "Payment {$v->verification_status}",
                'reference' => $v->document_number ?? "Bill #{$v->bill_id}",
                'user' => '—',
                'time' => $v->verified_at?->toISOString(),
            ];
        }

        return $events->sortByDesc('time')->take(12)->values()->all();
    }

    private function valuationRecentActivity(User $user, array $officerIds): array
    {
        $events = collect();

        $taskHist = TaskHistory::query()
            ->with('task:id,task_reference')
            ->whereHas('task', fn ($q) => $q->whereIn('task_type', [Task::TYPE_VALUATION, Task::TYPE_VALUATION_REVIEW, Task::TYPE_VALUATION_MANAGER_REVIEW, Task::TYPE_AC_APPROVAL])->whereIn('assigned_to', $officerIds))
            ->orderByDesc('task_histories.created_at')
            ->limit(20)
            ->get();

        foreach ($taskHist as $h) {
            $events[] = [
                'event' => $h->action ?? "{$h->from_status} → {$h->to_status}",
                'reference' => $h->task?->task_reference ?? "Task #{$h->task_id}",
                'user' => '—',
                'time' => $h->created_at?->toISOString(),
            ];
        }

        return $events->sortByDesc('time')->take(12)->values()->all();
    }

    private function enforcementRecentActivity(User $user, ?array $billIds, array $officerIds): array
    {
        $events = collect();

        $taskHist = TaskHistory::query()
            ->with('task:id,task_reference')
            ->whereHas('task', fn ($q) => $q->whereIn('task_type', [Task::TYPE_BILL_DELIVERY, Task::TYPE_PAYMENT_FOLLOWUP, Task::TYPE_ENFORCEMENT_VISIT])->whereIn('assigned_to', $officerIds))
            ->orderByDesc('task_histories.created_at')
            ->limit(20)
            ->get();

        foreach ($taskHist as $h) {
            $events[] = [
                'event' => $h->action ?? "{$h->from_status} → {$h->to_status}",
                'reference' => $h->task?->task_reference ?? "Task #{$h->task_id}",
                'user' => '—',
                'time' => $h->created_at?->toISOString(),
            ];
        }

        $visitQuery = EnforcementVisit::query()->orderByDesc('visit_date')->limit(5);
        if ($billIds !== null) {
            $visitQuery->whereIn('bill_id', $billIds);
        }
        foreach ($visitQuery->get() as $v) {
            $events[] = [
                'event' => 'Field visit '.($v->visit_status ?? 'recorded'),
                'reference' => $v->visit_reference,
                'user' => '—',
                'time' => $v->visit_date?->toISOString(),
            ];
        }

        return $events->sortByDesc('time')->take(12)->values()->all();
    }

    /* =====================================================================
     *  SHARED - Priority queues
     * =================================================================== */

    private function buildAccountPriorityQueue(User $user, array $officerIds, array $acctTypes, ?array $billIds): array
    {
        $rows = [];

        // Overdue tasks
        $overdueTasks = Task::query()
            ->with('assignedTo:id,full_name')
            ->whereIn('task_type', $acctTypes)
            ->whereIn('assigned_to', $officerIds)
            ->overdue()
            ->orderBy('due_date')
            ->limit(4)
            ->get();

        foreach ($overdueTasks as $t) {
            $rows[] = [
                'priority' => 'Critical',
                'reference' => $t->task_reference,
                'label' => $t->task_type,
                'staff' => $t->assignedTo?->full_name ?? '—',
                'status' => $t->status,
                'due' => $t->due_date?->toDateString() ?? '—',
                'action' => 'Open task',
            ];
        }

        // Unassigned approved records
        $unassigned = Task::query()
            ->whereIn('task_type', $acctTypes)
            ->whereIn('assigned_to', $officerIds)
            ->where('status', 'Awaiting Assignment')
            ->limit(4 - count($rows))
            ->get();

        foreach ($unassigned as $t) {
            $rows[] = [
                'priority' => 'High',
                'reference' => $t->task_reference,
                'label' => $t->task_type,
                'staff' => 'Unassigned',
                'status' => $t->status,
                'due' => $t->due_date?->toDateString() ?? '—',
                'action' => 'Assign',
            ];
        }

        // Pending payment verifications
        if ($billIds !== null) {
            $pendingVer = PaymentVerification::query()
                ->whereIn('bill_id', $billIds)
                ->where('verification_status', PaymentVerification::STATUS_PENDING)
                ->orderByDesc('created_at')
                ->limit(max(0, 4 - count($rows)))
                ->get();

            foreach ($pendingVer as $v) {
                $rows[] = [
                    'priority' => 'Medium',
                    'reference' => $v->document_number ?? "Bill #{$v->bill_id}",
                    'label' => 'Payment Verification',
                    'staff' => '—',
                    'status' => 'Pending',
                    'due' => '—',
                    'action' => 'Review',
                ];
            }
        }

        // Pending amendments
        $amendQuery = BillAmendment::query()->orderByDesc('created_at')->limit(max(0, 4 - count($rows)));
        if ($billIds !== null) {
            $amendQuery->whereIn('bill_id', $billIds);
        }
        foreach ($amendQuery->get() as $a) {
            $rows[] = [
                'priority' => 'Medium',
                'reference' => $a->document_number ?? "Bill #{$a->bill_id}",
                'label' => 'Recent Amendment',
                'staff' => '—',
                'status' => 'Applied',
                'due' => '—',
                'action' => 'Review',
            ];
        }

        return ['title' => 'Manager Attention / Priority Queue', 'rows' => array_slice($rows, 0, 8)];
    }

    private function buildValuationPriorityQueue(User $user, array $officerIds): array
    {
        $rows = [];

        $overdue = Task::query()
            ->with('assignedTo:id,full_name')
            ->whereIn('task_type', [Task::TYPE_VALUATION, Task::TYPE_VALUATION_REVIEW])
            ->whereIn('assigned_to', $officerIds)
            ->overdue()
            ->orderBy('due_date')
            ->limit(4)
            ->get();

        foreach ($overdue as $t) {
            $rows[] = [
                'priority' => 'Critical',
                'reference' => $t->task_reference,
                'label' => $t->task_type,
                'staff' => $t->assignedTo?->full_name ?? '—',
                'status' => $t->status,
                'due' => $t->due_date?->toDateString() ?? '—',
                'action' => 'Open task',
            ];
        }

        $returned = Valuation::query()
            ->with('valuationOfficer:id,full_name')
            ->whereIn('valuation_officer_id', $officerIds)
            ->where('status', Valuation::STATUS_RETURNED)
            ->orderByDesc('manager_reviewed_at')
            ->limit(max(0, 4 - count($rows)))
            ->get();

        foreach ($returned as $v) {
            $rows[] = [
                'priority' => 'High',
                'reference' => $v->valuation_reference,
                'label' => 'Returned for Correction',
                'staff' => $v->valuationOfficer?->full_name ?? '—',
                'status' => $v->status,
                'due' => '—',
                'action' => 'Review',
            ];
        }

        return ['title' => 'Manager Attention', 'rows' => array_slice($rows, 0, 8)];
    }

    /**
     * Bills awaiting enforcement assignment — the open "Awaiting Assignment"
     * queue. This queue belongs to the enforcement register, not the caller's
     * personal bill scope: a team-scoped supervisor does not yet "own" an
     * unassigned bill, yet must still see the queue it exists to work from
     * (TaskService::createTaskFromBill queues walk-in bills here). Counting the
     * bill scope alone under-reports it to that role.
     */
    private function enforcementUnassignedQuery(?array $billIds)
    {
        return PropertyBill::query()
            ->whereIn('case_status', ['Logged', 'Awaiting Assignment'])
            ->when($billIds !== null, function ($q) use ($billIds) {
                $q->where(function ($sub) use ($billIds) {
                    $sub->whereIn('property_bills.id', $billIds)
                        ->orWhereHas('tasks', fn ($t) => $t
                            ->where('task_type', Task::TYPE_BILL_DELIVERY)
                            ->whereNull('assigned_to')
                            ->whereNotIn('status', Task::COMPLETED_STATUSES));
                });
            });
    }

    private function buildEnforcementPriorityQueue(User $user, array $officerIds, ?array $billIds): array
    {
        $rows = [];

        $overdue = Task::query()
            ->with('assignedTo:id,full_name')
            ->whereIn('task_type', [Task::TYPE_BILL_DELIVERY, Task::TYPE_PAYMENT_FOLLOWUP, Task::TYPE_ENFORCEMENT_VISIT])
            ->whereIn('assigned_to', $officerIds)
            ->overdue()
            ->orderBy('due_date')
            ->limit(4)
            ->get();

        foreach ($overdue as $t) {
            $rows[] = [
                'priority' => 'Critical',
                'reference' => $t->task_reference,
                'label' => $t->task_type,
                'staff' => $t->assignedTo?->full_name ?? '—',
                'status' => $t->status,
                'due' => $t->due_date?->toDateString() ?? '—',
                'action' => 'Open task',
            ];
        }

        $unassigned = $this->enforcementUnassignedQuery($billIds)
            ->orderBy('date_logged')
            ->limit(max(0, 4 - count($rows)))
            ->get();

        foreach ($unassigned as $b) {
            $rows[] = [
                'priority' => 'High',
                'reference' => $b->document_number ?? '—',
                'label' => 'Unassigned bill',
                'staff' => 'Unassigned',
                'status' => $b->case_status,
                'due' => '—',
                'action' => 'Assign',
            ];
        }

        return ['title' => 'Manager Attention Required', 'rows' => array_slice($rows, 0, 8)];
    }

    /* =====================================================================
     *  SHARED - Alerts
     * =================================================================== */

    private function enforcementAlerts(int $unassigned, int $overdue, int $demands72, int $duplicateClaims, int $pendingVerifications, int $escalated, int $failedVisits): array
    {
        return [
            ['label' => 'Overdue deliveries', 'count' => $overdue, 'tone' => 'red', 'link' => '/enforcement?tab=tasks'],
            ['label' => 'Unassigned bills', 'count' => $unassigned, 'tone' => 'amber', 'link' => '/enforcement?tab=tasks'],
            ['label' => 'Failed delivery attempts', 'count' => $failedVisits, 'tone' => 'red', 'link' => '/enforcement?tab=followups'],
            ['label' => '72-Hour notices due', 'count' => $demands72, 'tone' => 'red', 'link' => '/enforcement?tab=tasks'],
            ['label' => 'Potential duplicate receipt claims', 'count' => $duplicateClaims, 'tone' => 'amber', 'link' => '/payments'],
            ['label' => 'Payment verifications pending', 'count' => $pendingVerifications, 'tone' => 'amber', 'link' => '/payments'],
            ['label' => 'Escalated cases', 'count' => $escalated, 'tone' => 'red', 'link' => '/enforcement?tab=tasks'],
        ];
    }

    /* =====================================================================
     *  SHARED - Quick actions (RBAC-gated)
     * =================================================================== */

    private function accountQuickActions(User $user): array
    {
        $actions = [];
        if ($user->canPermission('tasks.assign')) {
            $actions[] = ['label' => 'Assign Task', 'link' => '/account/my-tasks', 'icon' => '📋'];
        }
        if ($user->canPermission('bills.view')) {
            $actions[] = ['label' => 'Search Property', 'link' => '/bills', 'icon' => '🔎'];
        }
        if ($user->canPermission('payments.verify')) {
            $actions[] = ['label' => 'Review Payment', 'link' => '/payments', 'icon' => '💳'];
        }
        if ($user->canPermission('bills.amend_document') || $user->canPermission('bills.amend_penalty_interest')) {
            $actions[] = ['label' => 'Review Amendment', 'link' => '/amendments', 'icon' => '✏️'];
        }
        if ($user->canPermission('me.flag_data_quality')) {
            $actions[] = ['label' => 'Data Quality', 'link' => '/drill/flags', 'icon' => '🔎'];
        }
        if ($user->canPermission('records.view')) {
            $actions[] = ['label' => 'Records / Register', 'link' => '/drill/bills', 'icon' => '🗂️'];
        }
        if ($user->canPermission('reports.view')) {
            $actions[] = ['label' => 'Reports', 'link' => '/reports', 'icon' => '📊'];
        }

        return $actions;
    }

    private function valuationQuickActions(User $user): array
    {
        $actions = [];
        if ($user->canPermission('valuation.create')) {
            $actions[] = ['label' => 'New Valuation', 'link' => '/valuations', 'icon' => '🆕'];
        }
        if ($user->canPermission('valuation.review')) {
            $actions[] = ['label' => 'Review Submissions', 'link' => '/valuations', 'icon' => '👀'];
        }
        if ($user->canPermission('discovery.view')) {
            $actions[] = ['label' => 'Discovery Queue', 'link' => '/enforcement?tab=discoveries', 'icon' => '📥'];
        }
        if ($user->canPermission('bills.view')) {
            $actions[] = ['label' => 'Search Property', 'link' => '/bills', 'icon' => '🔎'];
        }
        if ($user->canPermission('valuation.view_history')) {
            $actions[] = ['label' => 'Valuation Register', 'link' => '/drill/valuations', 'icon' => '📐'];
        }
        if ($user->canPermission('gps.view')) {
            $actions[] = ['label' => 'GPS / Evidence Review', 'link' => '/gps', 'icon' => '🛰️'];
        }
        if ($user->canPermission('reports.view')) {
            $actions[] = ['label' => 'Reports', 'link' => '/reports', 'icon' => '📊'];
        }

        return $actions;
    }

    private function enforcementQuickActions(User $user): array
    {
        $actions = [];
        if ($user->canPermission('tasks.assign')) {
            $actions[] = ['label' => 'Assign Bill', 'link' => '/enforcement?tab=tasks', 'icon' => '📋'];
        }
        if ($user->canPermission('tasks.reassign')) {
            $actions[] = ['label' => 'Reassign Bill', 'link' => '/enforcement?tab=tasks', 'icon' => '🔄'];
        }
        if ($user->canPermission('bills.view')) {
            $actions[] = ['label' => 'Bill Register', 'link' => '/bills', 'icon' => '🗂️'];
        }
        if ($user->canPermission('enforcement.view_assignments')) {
            $actions[] = ['label' => 'Field Visits', 'link' => '/enforcement?tab=followups', 'icon' => '📍'];
        }
        if ($user->canPermission('enforcement.payment_followup')) {
            $actions[] = ['label' => 'Payment Follow-up', 'link' => '/enforcement?tab=followups', 'icon' => '💰'];
        }
        if ($user->canPermission('enforcement.issue_warning')) {
            $actions[] = ['label' => 'Notices', 'link' => '/enforcement?tab=tasks', 'icon' => '📄'];
        }
        if ($user->canPermission('payments.view_queue')) {
            $actions[] = ['label' => 'Receipt Review', 'link' => '/payments', 'icon' => '🧾'];
        }
        if ($user->canPermission('reports.view')) {
            $actions[] = ['label' => 'Reports', 'link' => '/reports', 'icon' => '📊'];
        }

        return $actions;
    }

    /* =====================================================================
     *  SHARED - Helpers
     * =================================================================== */

    private function sectionOfficerIds(User $user, string $officerRole): array
    {
        return User::where('section_id', $user->section_id)
            ->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->where('name', $officerRole))
            ->pluck('id')
            ->all();
    }

    private function duplicateClaimsCount(?array $billIds): int
    {
        return PaymentVerification::query()
            ->when($billIds !== null, fn ($q) => $q->whereIn('bill_id', $billIds))
            ->whereJsonLength('duplicate_warnings', '>', 0)
            ->count();
    }

    private function pendingVerificationsOverdue(?array $billIds): int
    {
        return PaymentVerification::query()
            ->when($billIds !== null, fn ($q) => $q->whereIn('bill_id', $billIds))
            ->where('verification_status', PaymentVerification::STATUS_PENDING)
            ->where('created_at', '<', BusinessClock::now()->subDays(7))
            ->count();
    }

    /* =====================================================================
         *  SHARED - Assistant Commissioner / division-wide helpers
         * =================================================================== */

    /**
     * Default period used for period/KPI (activity) widgets: the current
     * month in the official Liberia business timezone, as a half-open
     * interval [start, end) plus a display label (-7, -8, -53).
     */
    private function monthlyRange(): array
    {
        $range = BusinessClock::monthRange();

        return [$range['start'], $range['end'], $range['label']];
    }

    private function divisionOfficerIds(): array
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->whereIn('name', ['Account & Records Officer', 'Enforcement Officer', 'Valuation Officer']))
            ->pluck('id')
            ->all();
    }

    private function allTaskTypes(): array
    {
        return [
            Task::TYPE_BILL_DELIVERY,
            Task::TYPE_PAYMENT_FOLLOWUP,
            Task::TYPE_PAYMENT_VERIFICATION,
            Task::TYPE_ENFORCEMENT_VISIT,
            Task::TYPE_VALUATION,
            Task::TYPE_VALUATION_REVIEW,
            Task::TYPE_AC_APPROVAL,
            Task::TYPE_ME_QUERY,
            Task::TYPE_ACCOUNT_PROCESSING,
            Task::TYPE_OTHER,
            Task::TYPE_DISCOVERY_REVIEW,
            Task::TYPE_VALUATION_MANAGER_REVIEW,
            Task::TYPE_ACCOUNT_MANAGER_REVIEW,
        ];
    }

    private function buildDivisionWorkload(array $officerIds): array
    {
        $columns = ['Officer', 'Assigned', 'In Progress', 'Review', 'Overdue', 'Completed'];
        if ($officerIds === []) {
            return ['title' => 'Officer Workload', 'columns' => $columns, 'rows' => []];
        }
        $users = User::whereIn('id', $officerIds)->get(['id', 'full_name']);
        $rows = [];
        foreach ($users as $u) {
            $tasks = Task::query()->where('assigned_to', $u->id);
            $active = (clone $tasks)->whereNotIn('status', Task::COMPLETED_STATUSES);
            $vals = Valuation::query()->where('valuation_officer_id', $u->id);
            $rows[] = [
                'officer_id' => $u->id,
                'officer' => $u->full_name,
                'cols' => [
                    'assigned' => (clone $active)->count()
                        + (clone $vals)->where('status', Valuation::STATUS_ASSIGNED)->count(),
                    'in_progress' => (clone $active)->whereNotIn('status', ['Logged', 'Awaiting Assignment', 'Assigned'])->count()
                        + (clone $vals)->where('status', Valuation::STATUS_ASSESSMENT_IN_PROGRESS)->count(),
                    'review' => (clone $vals)->whereIn('status', [Valuation::STATUS_SUBMITTED_TO_MANAGER, Valuation::STATUS_UNDER_MANAGER_REVIEW])->count()
                        + (clone $vals)->whereIn('status', [Valuation::STATUS_SUBMITTED_TO_AC, Valuation::STATUS_AWAITING_AC_APPROVAL])->count(),
                    'overdue' => (clone $active)->overdue()->count(),
                    'completed' => (clone $tasks)->whereIn('status', Task::COMPLETED_STATUSES)->count()
                        + (clone $vals)->whereIn('status', [Valuation::STATUS_COMPLETED, Valuation::STATUS_REGISTERED, Valuation::STATUS_SENT_TO_ACCT])->count(),
                ],
            ];
        }

        return ['title' => 'Officer Workload', 'columns' => $columns, 'rows' => $rows];
    }

    private function divisionTaskStatusBreakdown(): array
    {
        return Task::query()
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->orderByDesc('c')
            ->get()
            ->map(fn ($t) => ['label' => $t->status, 'value' => (int) $t->c])
            ->values()
            ->all();
    }

    private function buildDivisionPriority(): array
    {
        $rows = [];
        $overdueTasks = Task::query()
            ->with('assignedTo:id,full_name')
            ->overdue()
            ->orderBy('due_date')
            ->limit(3)
            ->get();
        foreach ($overdueTasks as $t) {
            $rows[] = [
                'priority' => 'Critical',
                'reference' => $t->task_reference,
                'label' => $t->task_type,
                'staff' => $t->assignedTo?->full_name ?? '—',
                'status' => $t->status,
                'due' => $t->due_date?->toDateString() ?? '—',
                'action' => 'Open task',
            ];
        }
        $want = max(6 - count($rows), 0);
        $pendingVer = PaymentVerification::where('verification_status', PaymentVerification::STATUS_PENDING)
            ->orderByDesc('created_at')
            ->limit($want)
            ->get();
        foreach ($pendingVer as $v) {
            $rows[] = [
                'priority' => 'High',
                'reference' => $v->document_number ?? "Bill #{$v->bill_id}",
                'label' => 'Payment Verification',
                'staff' => '—',
                'status' => 'Pending',
                'due' => '—',
                'action' => 'Review',
            ];
        }
        $want = max(6 - count($rows), 0);
        foreach (BillAmendment::query()->orderByDesc('created_at')->limit($want)->get() as $a) {
            $rows[] = [
                'priority' => 'Medium',
                'reference' => 'Amendment #'.$a->id,
                'label' => 'Recent Amendment',
                'staff' => '—',
                'status' => 'Recorded',
                'due' => '—',
                'action' => 'Review',
            ];
        }

        return ['title' => 'Division Attention / Priority Queue', 'rows' => $rows];
    }

    private function leadershipQuickActions(User $user): array
    {
        $actions = [];
        if ($user->canPermission('tasks.assign')) {
            $actions[] = ['label' => 'Assign / Review Tasks', 'link' => '/enforcement?tab=tasks', 'icon' => '📋'];
        }
        if ($user->canPermission('bills.view')) {
            $actions[] = ['label' => 'Search Property', 'link' => '/bills', 'icon' => '🔎'];
        }
        if ($user->canPermission('records.view')) {
            $actions[] = ['label' => 'Records / Register', 'link' => '/drill/bills', 'icon' => '🗂️'];
        }
        if ($user->canPermission('valuation.view_history')) {
            $actions[] = ['label' => 'Valuation Register', 'link' => '/drill/valuations', 'icon' => '📐'];
        }
        if ($user->canPermission('payments.verify') || $user->canPermission('payments.view_queue')) {
            $actions[] = ['label' => 'Payment Queue', 'link' => '/payments', 'icon' => '💳'];
        }
        if ($user->canPermission('reports.view')) {
            $actions[] = ['label' => 'Reports', 'link' => '/reports', 'icon' => '📊'];
        }

        return $actions;
    }
}
