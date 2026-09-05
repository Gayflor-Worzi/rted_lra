<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EnforcementVisit;
use App\Models\Payment;
use App\Models\PaymentVerification;
use App\Models\PropertyBill;
use App\Models\PropertyDiscovery;
use App\Models\StaffTarget;
use App\Models\Task;
use App\Models\User;
use App\Models\Valuation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Consolidated dashboard analytics engine.
 *
 * Dashboard rule: every dashboard gets at most ONE interactive bar chart and
 * ONE interactive pie chart, both driven by the same filtering engine. This
 * endpoint serves the filtered KPI band, bar distribution, pie distribution
 * and the drill-down record table from a single request.
 *
 * RBAC: each metric is gated by permission and every query is scoped to the
 * caller's data scope (own / team / section / division / system).
 */
class AnalyticsController extends Controller
{
    private const TABLE = [
        'tasks' => 'tasks',
        'bills' => 'property_bills',
        'collections' => 'payments',
        'payments' => 'payment_verifications',
        'discoveries' => 'property_discoveries',
        'valuations' => 'valuations',
        'visits' => 'enforcement_visits',
        'targets' => 'staff_targets',
    ];

    private const METRICS = [
        'tasks' => ['label' => 'Tasks', 'perms' => ['tasks.view_division', 'tasks.view_section', 'tasks.view_own'], 'scope' => 'assigned_to', 'date' => 'created_at'],
        'bills' => ['label' => 'Bills', 'perms' => ['bills.view'], 'scope' => 'account_staff_id', 'date' => 'date_logged'],
        'collections' => ['label' => 'Collections', 'perms' => ['reports.view', 'bills.view'], 'scope' => 'verified_by', 'date' => 'verified_at', 'sum' => 'amount'],
        'payments' => ['label' => 'Payment Verification', 'perms' => ['payments.view_queue', 'payments.view_history'], 'scope' => 'verified_by', 'date' => 'created_at'],
        'discoveries' => ['label' => 'Discoveries', 'perms' => ['discovery.view', 'discovery.create'], 'scope' => 'discovered_by', 'date' => 'discovery_date'],
        'valuations' => ['label' => 'Valuations', 'perms' => ['valuation.view_history', 'valuation.review', 'valuation.create'], 'scope' => 'valuation_officer_id', 'date' => 'created_at'],
        'visits' => ['label' => 'Visits', 'perms' => ['enforcement.view_assignments', 'enforcement.record_visit'], 'scope' => 'officer_id', 'date' => 'visit_date'],
        'targets' => ['label' => 'Target vs Actual', 'perms' => ['targets.view'], 'scope' => 'user_id', 'date' => 'start_date'],
    ];

    private const GROUP_OPTIONS = [
        'tasks' => ['staff'=>'Staff', 'section'=>'Section', 'month'=>'Month', 'quarter'=>'Quarter', 'year'=>'Year', 'task_type'=>'Task type', 'task_status'=>'Status', 'enforcement_stage'=>'Enforcement stage', 'completion_status'=>'Completion', 'payment_status'=>'Bill payment status', 'property_classification'=>'Property classification'],
        'bills' => ['staff'=>'Staff', 'section'=>'Section', 'month'=>'Month', 'quarter'=>'Quarter', 'year'=>'Year', 'property_classification'=>'Property classification', 'property_type'=>'Property type', 'payment_status'=>'Payment status', 'bill_status'=>'Case status', 'completion_status'=>'Completion'],
        'collections' => ['staff'=>'Staff', 'section'=>'Section', 'month'=>'Month', 'quarter'=>'Quarter', 'year'=>'Year', 'property_classification'=>'Property classification', 'property_type'=>'Property type'],
        'payments' => ['staff'=>'Staff', 'month'=>'Month', 'quarter'=>'Quarter', 'year'=>'Year', 'payment_status'=>'Verification status', 'match_status'=>'Match status'],
        'discoveries' => ['staff'=>'Staff', 'section'=>'Section', 'month'=>'Month', 'quarter'=>'Quarter', 'year'=>'Year', 'property_classification'=>'Property classification', 'property_type'=>'Property type', 'discovery_status'=>'Discovery status', 'decision_path'=>'Decision path'],
        'valuations' => ['staff'=>'Staff', 'section'=>'Section', 'month'=>'Month', 'quarter'=>'Quarter', 'year'=>'Year', 'property_classification'=>'Property classification', 'property_type'=>'Type', 'valuation_status'=>'Valuation status', 'completion_status'=>'Completion'],
        'visits' => ['staff'=>'Staff', 'section'=>'Section', 'month'=>'Month', 'quarter'=>'Quarter', 'year'=>'Year', 'visit_status'=>'Visit status', 'delivery_status'=>'Delivery status'],
        'targets' => ['staff'=>'Staff', 'section'=>'Section', 'month'=>'Month', 'quarter'=>'Quarter', 'year'=>'Year', 'metric'=>'Target metric', 'completion_status'=>'Target health'],
    ];

    private const PIE_OPTIONS = [
        'tasks' => ['task_status'=>'Task status', 'enforcement_stage'=>'Enforcement stage', 'completion_status'=>'Completion', 'staff'=>'Staff', 'section'=>'Section', 'payment_status'=>'Bill payment status'],
        'bills' => ['payment_status'=>'Payment status', 'bill_status'=>'Case status', 'property_classification'=>'Property classification', 'property_type'=>'Property type'],
        'collections' => ['property_classification'=>'Property classification', 'property_type'=>'Property type'],
        'payments' => ['payment_status'=>'Verification status', 'match_status'=>'Match status'],
        'discoveries' => ['discovery_status'=>'Discovery status', 'decision_path'=>'Decision path', 'property_classification'=>'Property classification', 'property_type'=>'Property type'],
        'valuations' => ['valuation_status'=>'Valuation status', 'completion_status'=>'Completion', 'property_classification'=>'Property classification'],
        'visits' => ['visit_status'=>'Visit status', 'delivery_status'=>'Delivery status', 'staff'=>'Staff', 'section'=>'Section'],
        'targets' => ['metric'=>'Target metric', 'completion_status'=>'Target health'],
    ];

    private const PIE_DEFAULT = [
        'tasks' => 'task_status', 'bills' => 'payment_status', 'collections' => 'property_classification',
        'payments' => 'payment_status', 'discoveries' => 'discovery_status', 'valuations' => 'valuation_status',
        'visits' => 'visit_status', 'targets' => 'metric',
    ];

    private const TARGET_METRIC_LABELS = [
        'bills_logged' => 'Bills logged', 'bills_processed' => 'Bills processed', 'bills_delivered' => 'Bills delivered',
        'payment_verifications' => 'Payment verifications', 'records_amended' => 'Records amended', 'data_quality_completed' => 'Data-quality items',
        'visits' => 'Visits', 'payment_followups' => 'Payment follow-ups', 'reminder_notices' => 'Reminder notices',
        'hour_72_demands' => '72-hour demands', 'enforcement_cases' => 'Enforcement cases', 'completed_tasks' => 'Tasks completed',
        'valuations' => 'Valuations', 'reassessments' => 'Reassessments', 'valuation_corrections' => 'Valuation corrections',
        'approved_valuations' => 'Approved valuations', 'reports_completed' => 'Reports completed', 'tasks_reviewed' => 'Tasks reviewed',
        'monitoring_activities' => 'Monitoring activities', 'data_quality_checks' => 'Data-quality checks',
        'performance_reports' => 'Performance reports', 'walkin_assignments' => 'Walk-in assignments',
        'collections_amount' => 'Collections (amount)', 'custom' => 'Custom',
    ];

    private const ENF_STAGE_TASKS = "(CASE WHEN status IN ('Logged','Awaiting Assignment','Assigned','Out for Delivery','Delivered') THEN 'Field / Delivery' WHEN status IN ('Payment Follow-up','Payment Claimed','Verification Pending','Payment Verification') THEN 'Payment Follow-up' WHEN status = '30-Day Warning' THEN '30-Day Reminder' WHEN status = '72-Hour Warning' THEN '72-Hour Demand' WHEN status IN ('Escalated','Outstanding','Payment Rejected') THEN 'Final / Escalated' WHEN status IN ('Resolved','Closed','Paid','Partially Paid') THEN 'Completed' ELSE status END)";

    private const ENF_STAGE_BILLS = "(CASE WHEN case_status IN ('Logged','Awaiting Assignment','Assigned','Out for Delivery','Delivered') THEN 'Field / Delivery' WHEN case_status IN ('Payment Follow-up','Under Verification') THEN 'Payment Follow-up' WHEN case_status = '30-Day Warning' THEN '30-Day Reminder' WHEN case_status IN ('72-Hour Warning','Escalated') THEN '72-Hour Demand / Escalated' WHEN case_status IN ('Resolved','Closed') THEN 'Completed' ELSE case_status END)";

    public function summary(Request $request)
    {
        $user = $request->user();

        $metric = (string) $request->query('metric', 'tasks');
        $meta = self::METRICS[$metric] ?? null;
        abort_unless($meta, 422, 'Unknown analytics metric.');
        abort_unless($user->hasAnyPermission($meta['perms']), 403, 'Missing permission to view this metric.');

        return response()->json(['data' => $this->buildSummary($request, $metric, $user)]);
    }

    /**
     * Report export for the dashboard. Produces the exact dataset currently
     * displayed (same filters, same range, same RBAC scope) plus the applied
     * filter, generation time, generating user, KPI summary, detailed records,
     * calculation notes and page numbering — section 16 of the dashboard
     * integrity spec.
     */
    public function export(Request $request)
    {
        $user = $request->user();
        $metric = (string) $request->query('metric', 'tasks');
        $meta = self::METRICS[$metric] ?? null;
        abort_unless($meta, 422, 'Unknown analytics metric.');
        abort_unless($user->hasAnyPermission($meta['perms']), 403, 'Missing permission to view this metric.');

        $format = $request->query('format', 'csv');
        abort_unless(in_array($format, ['csv', 'pdf'], true), 422, 'format must be csv or pdf.');

        $summary = $this->buildSummary($request, $metric, $user);
        $label = 'RETD '.$summary['meta']['label'].' Dashboard';

        $filename = 'retd_dashboard_'.$metric.'_'.date('Ymd_His').'.'.$format;

        $filterContext = $this->exportFilterContext($request, $metric);

        if ($format === 'csv') {
            $csv = $this->toExportCsv($label, $summary, $user, $filterContext, $request);

            return response($csv)
                ->header('Content-Type', 'text/csv; charset=UTF-8')
                ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
        }

        $html = $this->toExportPdfHtml($label, $summary, $user, $filterContext, $request);

        return \Barryvdh\DomPDF\Facade\Pdf::setOption('isPhpEnabled', true)
            ->loadHTML($html)
            ->setPaper('a4', 'landscape')
            ->download($filename);
    }

    /** The analytics payload — shared by the JSON dashboard and its export. */
    private function buildSummary(Request $request, string $metric, User $user): array
    {
        $meta = self::METRICS[$metric];

        [$start, $end] = $this->rangeBounds($request);

        $query = $this->baseQuery($metric, $user);
        $query->whereBetween($this->dateCol($metric), [$start->startOfDay(), $end->endOfDay()]);

        if ($staffId = $request->query('staff_id')) {
            $this->filterOwner($metric, $query, (int) $staffId);
        }
        if ($section = $request->query('section')) {
            $this->filterSection($metric, $query, $section);
        }
        $this->applyAttributeFilters($metric, $query, $request);

        $groupBy = (string) $request->query('group_by', 'staff');
        abort_unless(isset(self::GROUP_OPTIONS[$metric][$groupBy]), 422, 'group_by not supported for this metric.');

        $pie = (string) $request->query('pie', self::PIE_DEFAULT[$metric]);
        abort_unless(isset(self::PIE_OPTIONS[$metric][$pie]), 422, 'pie dimension not supported for this metric.');

        $bar = $this->buildBar($metric, $groupBy, (clone $query), $meta['date']);
        $pieData = $this->buildPie($metric, $pie, (clone $query), $meta['date']);
        $kpis = $this->buildKpis($metric, (clone $query));
        $records = $this->buildRecords($metric, $request, (clone $query));

        return [
            'meta' => [
                'metric' => $metric,
                'label' => $meta['label'],
                'as_of' => now()->toISOString(),
                'range' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
                'available_metrics' => $this->availableMetrics($user),
                'group_options' => self::GROUP_OPTIONS[$metric],
                'pie_options' => self::PIE_OPTIONS[$metric],
                'group_by' => $groupBy,
                'pie' => $pie,
                'drill' => ['tasks' => '/drill/tasks', 'bills' => '/drill/bills', 'valuations' => '/drill/valuations', 'discoveries' => '/drill/discoveries', 'visits' => '/drill/visits', 'payments' => '/drill/payments', 'collections' => '/drill/payments', 'targets' => '/targets'][$metric],
                'staff_options' => $this->staffOptions($user),
                'section_options' => $this->sectionOptions($user),
            ],
            'kpis' => $kpis,
            'bar' => $bar,
            'pie' => $pieData,
            'records' => $records,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Base scoped queries                                                */
    /* ------------------------------------------------------------------ */

    private function baseQuery(string $metric, User $user): Builder
    {
        return match ($metric) {
            'tasks' => $this->scoped($user, Task::query(), 'assigned_to'),
            'bills' => $this->scoped($user, PropertyBill::query(), 'account_staff_id'),
            'collections' => $this->scoped($user, Payment::query(), 'verified_by'),
            'payments' => $this->scoped($user, PaymentVerification::query(), 'verified_by'),
            'discoveries' => $this->scoped($user, PropertyDiscovery::query(), 'discovered_by'),
            'valuations' => $this->scoped($user, Valuation::query(), 'valuation_officer_id'),
            'visits' => $this->scoped($user, EnforcementVisit::query(), 'officer_id'),
            'targets' => $this->scoped($user, StaffTarget::query(), 'user_id')->where('status', 'Approved'),
        };
    }

    private function scoped(User $user, Builder $query, string $owner): Builder
    {
        $user->applyScope($query, $owner);

        return $query;
    }

    private function ownerColumn(string $metric): string
    {
        return self::TABLE[$metric].'.'.self::METRICS[$metric]['scope'];
    }

    private function dateCol(string $metric): string
    {
        return self::TABLE[$metric].'.'.self::METRICS[$metric]['date'];
    }

    private function filterOwner(string $metric, Builder $query, int $staffId): void
    {
        $query->where(self::ownerColumn($metric), $staffId);
    }

    private function filterSection(string $metric, Builder $query, string $section): void
    {
        if ($metric === 'tasks') {
            $query->where('tasks.section', $section);

            return;
        }

        $owner = self::ownerColumn($metric);
        $query->whereExists(function (Builder $q) use ($owner, $section) {
            $q->selectRaw('1')
                ->from('users as fu')
                ->join('sections as fs', 'fs.id', '=', 'fu.section_id')
                ->whereRaw("fu.id = {$owner}")
                ->where('fs.name', $section);
        });
    }

    /**
     * Apply attribute-level filters shared by every metric so a single filter
     * set drives the whole dashboard (KPIs, bar, pie and records alike). Only
     * filters that map to an actual column on the metric's base record (or its
     * directly joined bill) are applied — never fabricated/passthrough fields.
     */
    private function applyAttributeFilters(string $metric, Builder $query, Request $request): void
    {
        $t = self::TABLE[$metric];

        // Columns that live directly on the metric's own table.
        $directCols = [
            'property_id' => "{$t}.property_id",
            'tin' => "{$t}.tin",
            'document_number' => "{$t}.document_number",
            'property_classification' => "{$t}.property_classification",
            'property_type' => "{$t}.property_type",
            'tax_period' => "{$t}.tax_period",
        ];

        // Status dimensions map per-metric (only metrics with a status column).
        $statusColumns = [
            'tasks' => "{$t}.status",
            'bills' => "{$t}.case_status",
            'payments' => "{$t}.verification_status",
            'discoveries' => "{$t}.status",
            'valuations' => "{$t}.status",
            'visits' => "{$t}.visit_status",
        ];

        if (isset($statusColumns[$metric])) {
            $directCols['task_status'] = $statusColumns[$metric];
            $directCols['case_status'] = $statusColumns[$metric];
            $directCols['verification_status'] = $statusColumns[$metric];
        }

        // Bill-bridged attributes for metrics whose base table has no property_id/tin/etc.
        $billBridged = in_array($metric, ['tasks', 'collections', 'payments'], true);

        foreach ($directCols as $param => $column) {
            $value = $request->query($param);
            if ($value === null || $value === '') {
                continue;
            }

            // Tasks keep bill attributes on the referenced bill, not the task row.
            if ($metric === 'tasks' && in_array($param, ['property_id', 'tin', 'document_number', 'property_classification', 'property_type', 'tax_period'], true)) {
                $query->whereExists(function ($q) use ($param, $value) {
                    $q->selectRaw('1')->from('property_bills as AFB')
                        ->whereColumn('AFB.id', '=', 'tasks.reference_id')
                        ->where('tasks.reference_type', 'property_bill')
                        ->where("AFB.{$param}", $value);
                });

                continue;
            }

            // Payments/collections: most attributes live on the linked bill.
            if ($billBridged && $metric !== 'tasks' && in_array($param, ['property_id', 'tin', 'property_classification', 'property_type', 'tax_period'], true)) {
                $tbl = $t;
                $query->whereExists(function ($q) use ($param, $value, $tbl) {
                    $q->selectRaw('1')->from('property_bills as AFB5')
                        ->whereColumn("AFB5.id", '=', "{$tbl}.bill_id")
                        ->where("AFB5.{$param}", $value);
                });

                continue;
            }

            $query->where($column, $value);
        }

        // Payment status filter bridges through the linked bill for task/bill metrics.
        $paymentStatus = $request->query('payment_status');
        if ($paymentStatus !== null && $paymentStatus !== '') {
            if ($metric === 'bills') {
                $query->where('property_bills.payment_status', $paymentStatus);
            } elseif ($metric === 'tasks') {
                $query->whereExists(function ($q) use ($paymentStatus) {
                    $q->selectRaw('1')->from('property_bills as AFB3')
                        ->whereColumn('AFB3.id', '=', 'tasks.reference_id')
                        ->where('tasks.reference_type', 'property_bill')
                        ->where('AFB3.payment_status', $paymentStatus);
                });
            }
        }

        // Deliverability status lives on the bill for task/bill metrics.
        $deliveryStatus = $request->query('delivery_status');
        if ($deliveryStatus !== null && $deliveryStatus !== '' && in_array($metric, ['tasks', 'bills'], true)) {
            if ($metric === 'tasks') {
                $query->whereExists(function ($q) use ($deliveryStatus) {
                    $q->selectRaw('1')->from('property_bills as AFB4')
                        ->whereColumn('AFB4.id', '=', 'tasks.reference_id')
                        ->where('tasks.reference_type', 'property_bill')
                        ->where('AFB4.delivery_status', $deliveryStatus);
                });
            } else {
                $query->where('property_bills.delivery_status', $deliveryStatus);
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Bar chart                                                          */
    /* ------------------------------------------------------------------ */

    /** Human label for a target's live health band. */
    private function targetHealth(StaffTarget $t): string
    {
        if ((float) $t->target_value <= 0) {
            return 'No target value';
        }

        $pct = (float) $t->progressPercent();

        return $pct >= 100 ? 'On track' : ($pct >= 60 ? 'At risk' : 'Behind');
    }

    /**
     * Hydrate target rows with a live-achieved override so every consumer of
     * the analytics engine reports the same single source of truth as the
     * target records themselves, never the last-refreshed stored snapshot.
     */
    private function targetRows(Builder $query): \Illuminate\Support\Collection
    {
        return $query->with('user:id,full_name')->get()
            ->each(fn (StaffTarget $t) => $t->setAttribute('achieved_value', $t->computeAchievedValue()));
    }

    private function buildBar(string $metric, string $group, Builder $query, string $dateCol): array
    {
        [$expr, $joins, $idExpr] = $this->groupSpec($metric, $group, $dateCol);
        $this->applyJoins($query, $joins);

        if ($metric === 'targets') {
            $rows = $query->selectRaw("{$expr} as label")
                ->selectRaw($idExpr ? "{$idExpr} as owner_id" : 'null as owner_id')
                ->get();

            $buckets = [];
            foreach ($rows as $r) {
                $key = (string) ($r->label ?? '—').'|'.($r->owner_id ?? '');
                $bucket = $buckets[$key] ?? [
                    'label' => $this->polish($metric, $group, $r->label ?? '—'),
                    'achieved' => 0.0,
                    'target' => 0.0,
                    'id' => $r->owner_id ?? null,
                ];
                $bucket['achieved'] += (float) $r->computeAchievedValue();
                $bucket['target'] += (float) $r->target_value;
                $buckets[$key] = $bucket;
            }

            $rows = array_values($buckets);
            usort($rows, fn ($a, $b) => $b['achieved'] - $a['achieved']);

            return [
                'shape' => 'grouped',
                'series' => [
                    ['key' => 'achieved', 'label' => 'Achieved', 'color' => '#10B981'],
                    ['key' => 'target', 'label' => 'Target', 'color' => '#64748B'],
                ],
                'data' => array_map(fn ($d) => [
                    'label' => $d['label'],
                    'achieved' => round($d['achieved'], 2),
                    'target' => round($d['target'], 2),
                    'id' => $d['id'],
                ], array_slice($rows, 0, 30)),
            ];
        }

        $sum = self::METRICS[$metric]['sum'] ?? null;
        $query->selectRaw('COALESCE('.$expr.', \'—\') as label')
            ->selectRaw($sum ? "SUM({$sum}) as value" : 'COUNT(*) as value')
            ->selectRaw($idExpr ? "{$idExpr} as owner_id" : 'null as owner_id')
            ->groupBy(DB::raw($expr))
            ->when($idExpr, fn (Builder $q) => $q->groupBy($idExpr));

        $rows = $query->orderByDesc('value')
            ->limit($group === 'staff' ? 10 : 30)->get();

        return [
            'shape' => 'simple',
            'metric_label' => $sum ? 'Value' : 'Count',
            'data' => $rows->map(fn ($r) => [
                'label' => $this->polish($metric, $group, $r->label ?? '—'),
                'value' => round((float) $r->value, 2),
                'id' => $r->owner_id ?? null,
            ])->values()->toArray(),
        ];
    }

    private function buildPie(string $metric, string $dim, Builder $query, string $dateCol): array
    {
        if ($metric === 'targets') {
            $buckets = [];

            foreach ($this->targetRows($query) as $t) {
                $label = $dim === 'completion_status'
                    ? $this->targetHealth($t)
                    : $this->polish($metric, $dim, $t->metric ?? '—');
                $buckets[$label] = ($buckets[$label] ?? 0) + 1;
            }

            return [
                'dimension' => $dim,
                'data' => array_map(fn ($label) => [
                    'label' => $label,
                    'value' => (int) $buckets[$label],
                    'id' => null,
                ], array_keys($buckets)),
            ];
        }

        [$expr, $joins, $idExpr] = $this->groupSpec($metric, $dim, $dateCol);
        $this->applyJoins($query, $joins);

        $query->selectRaw('COALESCE('.$expr.', \'—\') as label')
            ->selectRaw('COUNT(*) as value')
            ->selectRaw($idExpr ? "{$idExpr} as owner_id" : 'null as owner_id')
            ->groupBy(DB::raw($expr))
            ->when($idExpr, fn (Builder $q) => $q->groupBy($idExpr));

        $rows = $query->orderByDesc('value')->get();

        return [
            'dimension' => $dim,
            'data' => $rows->map(fn ($r) => [
                'label' => $this->polish($metric, $dim, $r->label ?? '—'),
                'value' => (int) $r->value,
                'id' => $r->owner_id ?? null,
            ])->values()->toArray(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Group specifications                                               */
    /* ------------------------------------------------------------------ */

    /**
     * @return array{0: string, 1: array, 2: ?string}  [expr, joins, idExpr]
     */
    private function groupSpec(string $metric, string $dim, string $dateCol): array
    {
        $t = self::TABLE[$metric];
        $owner = $t.'.'.self::METRICS[$metric]['scope'];

        // staff
        if ($dim === 'staff') {
            return ["COALESCE(u.full_name, 'Unassigned')", [
                fn (Builder $q) => $q->leftJoin('users as u', function ($j) use ($owner) {
                    $j->on('u.id', '=', $owner)->where('u.is_active', true);
                }),
            ], 'u.id'];
        }

        // section
        if ($dim === 'section') {
            if ($metric === 'tasks') {
                return ["tasks.section", [], null];
            }

            return ['COALESCE(s.name, \'—\')', [
                fn (Builder $q) => $q->leftJoin('users as u', 'u.id', '=', "{$owner}"),
                fn (Builder $q) => $q->leftJoin('sections as s', 's.id', '=', 'u.section_id'),
            ], null];
        }

        // temporal
        if ($dim === 'month') {
            return [$this->dateGroupExpr('month', "{$t}.{$dateCol}"), [], null];
        }
        if ($dim === 'quarter') {
            return [$this->dateGroupExpr('quarter', "{$t}.{$dateCol}"), [], null];
        }
        if ($dim === 'year') {
            return [$this->dateGroupExpr('year', "{$t}.{$dateCol}"), [], null];
        }

        // bill-shared dimensions need the bill join
        $billJoin = null;
        if (in_array($dim, ['property_classification', 'property_type', 'payment_status'], true) && $metric !== 'bills' && $metric !== 'discoveries' && $metric !== 'valuations') {
            $billJoin = fn (Builder $q) => $q->leftJoin('property_bills as pb', 'pb.id', '=', "{$t}.bill_id");
        }
        if (in_array($dim, ['property_classification', 'property_type', 'payment_status'], true) && $metric === 'tasks') {
            $billJoin = function (Builder $q) {
                $q->leftJoin('property_bills as pb', function ($j) {
                    $j->on('pb.id', '=', 'tasks.reference_id')
                        ->where('tasks.reference_type', 'property_bill');
                });
            };
        }

        return match ("{$metric}.{$dim}") {
            'discoveries.property_classification' => ['COALESCE(property_discoveries.property_classification, \'—\')', [], null],
            'valuations.property_classification' => ['COALESCE(valuations.property_classification, \'—\')', [], null],
            'bills.property_classification' => ['COALESCE(property_bills.property_classification, \'—\')', [], null],
            'tasks.property_classification', 'collections.property_classification', 'payments.property_classification', 'visits.property_classification' => ['COALESCE(pb.property_classification, \'—\')', $billJoin !== null ? [$billJoin] : [], null],
            'discoveries.property_type' => ['COALESCE(property_discoveries.property_type, \'—\')', [], null],
            'valuations.property_type' => ['COALESCE(valuations.valuation_type, \'—\')', [], null],
            'bills.property_type' => ['COALESCE(property_bills.property_type, \'—\')', [], null],
            'tasks.property_type', 'collections.property_type', 'payments.property_type', 'visits.property_type' => ['COALESCE(pb.property_type, \'—\')', $billJoin !== null ? [$billJoin] : [], null],
            'tasks.task_type' => ['tasks.task_type', [], null],
            'tasks.task_status' => ['tasks.status', [], null],
            'payments.match_status' => ['COALESCE(payment_verifications.match_status, \'Pending\')', [], null],
            'bills.bill_status' => ['property_bills.case_status', [], null],
            'tasks.enforcement_stage' => [self::ENF_STAGE_TASKS, [], null],
            'bills.enforcement_stage' => [self::ENF_STAGE_BILLS, [], null],
            'tasks.payment_status' => ['COALESCE(pb.payment_status, \'—\')', $billJoin ? [$billJoin] : [], null],
            'bills.payment_status' => ['property_bills.payment_status', [], null],
            'payments.payment_status' => ['COALESCE(payment_verifications.verification_status, \'Pending\')', [], null],
            'discoveries.discovery_status' => ['property_discoveries.status', [], null],
            'discoveries.decision_path' => ['COALESCE(property_discoveries.decision_path, \'not set\')', [], null],
            'valuations.valuation_status' => ['valuations.status', [], null],
            'visits.visit_status' => ['COALESCE(enforcement_visits.visit_status, \'Scheduled\')', [], null],
            'visits.delivery_status' => ['COALESCE(enforcement_visits.delivery_status, \'—\')', [], null],
            'targets.metric' => ['staff_targets.metric', [], null],
            'targets.completion_status' => ['(CASE WHEN staff_targets.target_value > 0 AND (staff_targets.achieved_value / staff_targets.target_value) >= 1 THEN \'On track\' WHEN staff_targets.target_value > 0 AND (staff_targets.achieved_value / staff_targets.target_value) >= 0.6 THEN \'At risk\' WHEN staff_targets.target_value > 0 THEN \'Behind\' ELSE \'No target value\' END)', [], null],
            'tasks.completion_status', 'valuations.completion_status' => ["(CASE WHEN {$t}.status IN ('Resolved','Closed') THEN 'Completed' ELSE 'Open' END)", [], null],
            'bills.completion_status' => ["(CASE WHEN property_bills.case_status IN ('Resolved','Closed') THEN 'Completed' ELSE 'Open' END)", [], null],
            default => throw new \InvalidArgumentException("Unsupported group dimension: {$dim} for {$metric}"),
        };
    }

    private function applyJoins(Builder $query, array $joins): void
    {
        foreach ($joins as $join) {
            $join($query);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  KPIs                                                               */
    /* ------------------------------------------------------------------ */

    private function buildKpis(string $metric, Builder $query): array
    {
        if ($metric === 'tasks') {
            return [
                ['key' => 'total', 'label' => 'Total (range)', 'value' => (clone $query)->count(), 'tone' => 'navy'],
                ['key' => 'active', 'label' => 'Active', 'value' => (clone $query)->whereNotIn('status', Task::COMPLETED_STATUSES)->count(), 'tone' => 'blue'],
                ['key' => 'overdue', 'label' => 'Overdue', 'value' => (clone $query)->whereNotIn('status', Task::COMPLETED_STATUSES)->whereNotNull('due_date')->whereDate('due_date', '<', now())->count(), 'tone' => 'red'],
                ['key' => 'escalated', 'label' => 'Escalated', 'value' => (clone $query)->where('status', 'Escalated')->count(), 'tone' => 'amber'],
                ['key' => 'completed', 'label' => 'Completed (range)', 'value' => (clone $query)->whereIn('status', Task::COMPLETED_STATUSES)->count(), 'tone' => 'green'],
            ];
        }

        if ($metric === 'bills') {
            return [
                ['key' => 'total', 'label' => 'Bills (range)', 'value' => (clone $query)->count(), 'tone' => 'navy'],
                ['key' => 'total_tax_due', 'label' => 'Total tax due', 'value' => round((float) (clone $query)->sum('total_tax_due'), 2), 'tone' => 'blue', 'money' => true],
                ['key' => 'outstanding_amount', 'label' => 'Outstanding balance', 'value' => round((float) (clone $query)->sum('outstanding_balance'), 2), 'tone' => 'amber', 'money' => true],
                ['key' => 'paid', 'label' => 'Paid', 'value' => (clone $query)->where('payment_status', 'Paid')->count(), 'tone' => 'green'],
                ['key' => 'unpaid', 'label' => 'Unpaid', 'value' => (clone $query)->where('payment_status', 'Unpaid')->count(), 'tone' => 'red'],
            ];
        }

        if ($metric === 'collections') {
            return [
                ['key' => 'count', 'label' => 'Collections (range)', 'value' => (clone $query)->count(), 'tone' => 'navy'],
                ['key' => 'verified_amount', 'label' => 'Verified amount', 'value' => round((float) (clone $query)->sum('amount'), 2), 'tone' => 'green', 'money' => true],
                ['key' => 'avg', 'label' => 'Average payment', 'value' => round((float) (clone $query)->avg('amount'), 2), 'tone' => 'blue', 'money' => true],
            ];
        }

        if ($metric === 'payments') {
            return [
                ['key' => 'pending', 'label' => 'Pending verification', 'value' => (clone $query)->where('verification_status', 'Pending')->count(), 'tone' => 'amber'],
                ['key' => 'confirmed', 'label' => 'Confirmed', 'value' => (clone $query)->where('verification_status', 'Confirmed')->count(), 'tone' => 'green'],
                ['key' => 'rejected', 'label' => 'Rejected', 'value' => (clone $query)->where('verification_status', 'Rejected')->count(), 'tone' => 'red'],
                ['key' => 'total', 'label' => 'Total claims', 'value' => (clone $query)->count(), 'tone' => 'navy'],
            ];
        }

        if ($metric === 'discoveries') {
            return [
                ['key' => 'total', 'label' => 'Discoveries (range)', 'value' => (clone $query)->count(), 'tone' => 'navy'],
                ['key' => 'in_progress', 'label' => 'In progress', 'value' => (clone $query)->whereNotIn('status', ['COMPLETED', 'AC_REJECTED'])->count(), 'tone' => 'blue'],
                ['key' => 'completed', 'label' => 'Completed', 'value' => (clone $query)->where('status', 'COMPLETED')->count(), 'tone' => 'green'],
                ['key' => 'valuation_path', 'label' => 'Valuation path', 'value' => (clone $query)->where('decision_path', 'valuation')->count(), 'tone' => 'amber'],
            ];
        }

        if ($metric === 'valuations') {
            return [
                ['key' => 'total', 'label' => 'Valuations (range)', 'value' => (clone $query)->count(), 'tone' => 'navy'],
                ['key' => 'pending', 'label' => 'In review / pending', 'value' => (clone $query)->whereNotIn('status', ['Approved', 'Rejected'])->count(), 'tone' => 'amber'],
                ['key' => 'approved', 'label' => 'Approved', 'value' => (clone $query)->where('status', 'Approved')->count(), 'tone' => 'green'],
                ['key' => 'tax_payable', 'label' => 'Total tax payable', 'value' => round((float) (clone $query)->sum('total_tax_payable'), 2), 'tone' => 'blue', 'money' => true],
            ];
        }

        if ($metric === 'visits') {
            return [
                ['key' => 'total', 'label' => 'Visits (range)', 'value' => (clone $query)->count(), 'tone' => 'navy'],
                ['key' => 'delivered', 'label' => 'Delivered', 'value' => (clone $query)->where('delivery_status', EnforcementVisit::DELIVERY_DELIVERED)->count(), 'tone' => 'green'],
                ['key' => 'returned', 'label' => 'Returned', 'value' => (clone $query)->where('delivery_status', 'Returned')->count(), 'tone' => 'red'],
                ['key' => 'out_for_delivery', 'label' => 'Out for delivery', 'value' => (clone $query)->where('delivery_status', 'Out for Delivery')->count(), 'tone' => 'amber'],
            ];
        }

        // targets — live-computed achievement (single source of truth)
        $rows = $this->targetRows($query);

        return [
            ['key' => 'total', 'label' => 'Approved targets', 'value' => $rows->count(), 'tone' => 'navy'],
            ['key' => 'on_track', 'label' => 'On track (≥100%)', 'value' => $rows->where(fn (StaffTarget $t) => $this->targetHealth($t) === 'On track')->count(), 'tone' => 'green'],
            ['key' => 'at_risk', 'label' => 'At risk (60–99%)', 'value' => $rows->where(fn (StaffTarget $t) => $this->targetHealth($t) === 'At risk')->count(), 'tone' => 'amber'],
            ['key' => 'behind', 'label' => 'Behind (<60%)', 'value' => $rows->where(fn (StaffTarget $t) => $this->targetHealth($t) === 'Behind')->count(), 'tone' => 'red'],
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Detailed record table                                              */
    /* ------------------------------------------------------------------ */

    private function buildRecords(string $metric, Request $request, Builder $query): array
    {
        $this->applyRecordFilter($metric, $query, $request);

        $perPage = (int) $request->query('per_page', 10);
        $perPage = max(1, min(50, $perPage));

        $query->with($this->recordEagerLoads($metric));

        [$columns, $transform] = $this->recordShape($metric);
        $rows = $query->orderByDesc('id')->paginate($perPage)->withQueryString();

        if ($metric === 'targets') {
            $rows->getCollection()->each(fn (StaffTarget $t) => $t->setAttribute('achieved_value', $t->computeAchievedValue()));
        }

        return [
            'columns' => $columns,
            'rows' => $rows->getCollection()->map(fn ($row) => $transform($row))->values()->toArray(),
            'total' => $rows->total(),
            'per_page' => $rows->perPage(),
            'current_page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
        ];
    }

    private function recordEagerLoads(string $metric): array
    {
        return match ($metric) {
            'tasks' => ['bill', 'assignedTo'],
            'collections' => ['verifier'],
            'valuations' => ['valuationOfficer'],
            'visits' => ['officer', 'bill'],
            'targets' => ['user'],
            default => [],
        };
    }

    private function recordShape(string $metric): array
    {
        return match ($metric) {
            'tasks' => [
                ['Task ref', 'Type', 'Section', 'Status', 'Assignee', 'Due', 'Completed', 'Document #', 'Taxpayer', 'Outstanding', 'Bill status'],
                fn (Task $t) => [
                    $t->task_reference,
                    $t->task_type,
                    $t->section,
                    $t->status,
                    $t->assignedTo?->full_name ?? '—',
                    $t->due_date?->toDateString() ?? '—',
                    $t->completed_at?->toDateString() ?? '—',
                    $t->bill?->document_number ?? ($this->loadBillRef($t)),
                    $t->bill?->taxpayer_name ?? '—',
                    $t->bill ? number_format((float) $t->bill->outstanding_balance, 2) : '—',
                    $t->bill?->payment_status ?? '—',
                ],
            ],
            'bills' => [
                ['Document #', 'Property ID', 'Taxpayer', 'Classification', 'Tax due', 'Outstanding', 'Payment', 'Case status', 'Date logged'],
                fn (PropertyBill $b) => [
                    $b->document_number ?? '—',
                    $b->property_id ?? '—',
                    $b->taxpayer_name,
                    $b->property_classification ?? '—',
                    number_format((float) $b->total_tax_due, 2),
                    number_format((float) $b->outstanding_balance, 2),
                    $b->payment_status,
                    $b->case_status,
                    $b->date_logged?->toDateString() ?? '—',
                ],
            ],
            'collections' => [
                ['Document #', 'Amount', 'Period', 'Receipt #', 'Verified by', 'Verified at'],
                fn (Payment $p) => [
                    $p->document_number ?? '—',
                    number_format((float) $p->amount, 2),
                    $p->payment_period ?? '—',
                    $p->receipt_number ?? '—',
                    $p->relationLoaded('verifier') ? $p->verifier?->full_name ?? '—' : '—',
                    $p->verified_at?->toDateString() ?? '—',
                ],
            ],
            'payments' => [
                ['Document #', 'Receipt #', 'Amount claimed', 'Match', 'Verification status', 'Created'],
                fn (PaymentVerification $v) => [
                    $v->document_number ?? '—',
                    $v->receipt_number ?? '—',
                    number_format((float) $v->amount_claimed, 2),
                    $v->match_status ?? 'Pending',
                    $v->verification_status,
                    $v->created_at?->toDateString() ?? '—',
                ],
            ],
            'discoveries' => [
                ['Ref', 'Owner', 'Classification', 'Type', 'Status', 'Path', 'Discovery date'],
                fn (PropertyDiscovery $d) => [
                    $d->discovery_reference,
                    $d->owner_name ?? '—',
                    $d->property_classification ?? '—',
                    $d->property_type ?? '—',
                    $d->status,
                    $d->decision_path ?? 'not set',
                    $d->discovery_date?->toDateString() ?? '—',
                ],
            ],
            'valuations' => [
                ['Ref', 'Owner', 'Status', 'Assessed value', 'Tax payable', 'Officer', 'Created'],
                fn (Valuation $v) => [
                    $v->valuation_reference,
                    $v->owner_name ?? '—',
                    $v->status,
                    number_format((float) $v->total_property_value, 2),
                    number_format((float) $v->total_tax_payable, 2),
                    $v->relationLoaded('valuationOfficer') ? $v->valuationOfficer?->full_name ?? '—' : '—',
                    $v->created_at?->toDateString() ?? '—',
                ],
            ],
            'visits' => [
                ['Visit ref', 'Document #', 'Visit date', 'Visit status', 'Delivery status', 'Officer'],
                fn (EnforcementVisit $v) => [
                    $v->visit_reference,
                    $v->document_number ?? '—',
                    $v->visit_date?->toDateString() ?? '—',
                    $v->visit_status ?? 'Scheduled',
                    $v->delivery_status ?? '—',
                    $v->relationLoaded('officer') ? $v->officer?->full_name ?? '—' : '—',
                ],
            ],
            'targets' => [
                ['Staff', 'Section', 'Metric', 'Period', 'Frequency', 'Target', 'Achieved', 'Progress'],
                fn (StaffTarget $t) => [
                    $t->relationLoaded('user') ? $t->user?->full_name ?? '—' : '—',
                    $t->section ?? '—',
                    self::TARGET_METRIC_LABELS[$t->metric] ?? $t->metric,
                    $t->period ?? '—',
                    $t->frequency ?? '—',
                    number_format((float) $t->target_value, 2),
                    number_format((float) $t->achieved_value, 2),
                    $t->progressPercent().'%',
                ],
            ],
        };
    }

    private function loadBillRef(Task $t): string
    {
        return isset($t->bill) ? ($t->bill->document_number ?? '—') : '—';
    }

    private function applyRecordFilter(string $metric, Builder $query, Request $request): void
    {
        $dim = $request->query('record_dim');
        $value = $request->query('record_value');

        if (! $dim || $value === null || $value === '') {
            return;
        }

        if ($metric === 'targets' && $dim === 'completion_status') {
            $rows = $this->targetRows($query);
            $ids = $rows->where(fn (StaffTarget $t) => $this->targetHealth($t) === (string) $value)->pluck('id')->values()->all();
            $query->whereIn('staff_targets.id', $ids);

            return;
        }

        $t = self::TABLE[$metric];

        if ($dim === 'staff') {
            $id = (int) $value;
            if ($id > 0) {
                $query->where(self::ownerColumn($metric), $id);

                return;
            }

            // fallback: match by name through the owner relation
            $query->whereExists(function (Builder $q) use ($metric, $value) {
                $q->selectRaw('1')->from('users as U2')
                    ->whereColumn('U2.id', '=', self::ownerColumn($metric))
                    ->where('U2.full_name', (string) $value);
            });

            return;
        }

        if ($dim === 'section') {
            $this->filterSection($metric, $query, (string) $value);

            return;
        }

        if (in_array($dim, ['month', 'quarter', 'year'], true)) {
            $dateCol = self::METRICS[$metric]['date'];
            $expr = $this->dateGroupExpr($dim, "{$t}.{$dateCol}");

            $query->whereRaw("({$expr}) = ?", [(string) $value]);

            return;
        }

        // everything else: equality against the derived expression
        [, $joins, ] = $this->groupSpec($metric, $dim, self::METRICS[$metric]['date']);
        $this->applyJoins($query, $joins);
        $query->whereRaw('COALESCE('.$this->dimExpr($metric, $dim).', \'—\') = ?', [(string) $value]);
    }

    private function dimExpr(string $metric, string $dim): string
    {
        $spec = $this->groupSpec($metric, $dim, self::METRICS[$metric]['date']);

        return $spec[0];
    }

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    /** Driver-portable temporal GROUP BY expression (month/quarter/year). */
    private function dateGroupExpr(string $dim, string $col): string
    {
        if ($this->isPostgres()) {
            return match ($dim) {
                'month' => "to_char($col, 'Mon YYYY')",
                'quarter' => "CONCAT('Q', EXTRACT(QUARTER FROM $col), ' ', EXTRACT(YEAR FROM $col))",
                'year' => "to_char($col, 'YYYY')",
            };
        }

        return match ($dim) {
            'month' => "DATE_FORMAT($col, '%b %Y')",
            'quarter' => "CONCAT('Q', QUARTER($col), ' ', YEAR($col))",
            'year' => "YEAR($col)",
        };
    }

    /* ------------------------------------------------------------------ */
    /*  Shared helpers                                                     */
    /* ------------------------------------------------------------------ */

    /** Human-readable filter context written into dashboard exports. */
    private function exportFilterContext(Request $request, string $metric): string
    {
        $parts = [];
        $labels = [
            'metric' => 'Metric', 'range' => 'Range', 'from' => 'From', 'to' => 'To',
            'staff_id' => 'Staff', 'section' => 'Section',
            'property_id' => 'Property ID', 'tin' => 'TIN', 'document_number' => 'Document #',
            'property_classification' => 'Classification', 'property_type' => 'Type',
            'tax_period' => 'Tax period', 'task_status' => 'Task status',
            'case_status' => 'Case status', 'payment_status' => 'Payment status',
            'verification_status' => 'Verification status', 'delivery_status' => 'Delivery status',
            'group_by' => 'Bar group', 'pie' => 'Pie dimension',
        ];

        foreach ($labels as $key => $label) {
            if ($request->filled($key)) {
                $parts[] = $label.'='.$request->query($key);
            }
        }

        return $parts ? implode(' | ', $parts) : 'All records (no filters)';
    }

    /** CSV export of the exact dashboard dataset + KPI summary block. */
    private function toExportCsv(string $label, array $summary, User $user, string $filterContext, Request $request): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [$label]);
        fputcsv($out, ['Generated '.now('Africa/Monrovia')->format('Y-m-d H:i').' by '.$user->full_name]);
        fputcsv($out, ['Scope', $summary['meta']['range']['start'].' → '.$summary['meta']['range']['end']]);
        fputcsv($out, ['Filters', $filterContext]);
        fputcsv($out, []);
        fputcsv($out, ['KPI', 'Value']);
        foreach ($summary['kpis'] as $k) {
            $value = isset($k['money']) && $k['money'] ? number_format((float) $k['value'], 2) : $k['value'];
            fputcsv($out, [$k['label'], $value]);
        }
        fputcsv($out, []);
        fputcsv($out, ['Distribution — '.($summary['meta']['group_options'][$summary['meta']['group_by']] ?? $summary['meta']['group_by'])]);
        fputcsv($out, ['Label', 'Value']);
        foreach ($summary['bar']['data'] as $row) {
            $v = isset($summary['bar']['metric_label']) && $summary['bar']['metric_label'] === 'Value'
                ? number_format((float) $row['value'], 2) : $row['value'];
            fputcsv($out, [$row['label'], $v]);
        }
        fputcsv($out, []);
        fputcsv($out, ['Detail records']);
        fputcsv($out, $summary['records']['columns']);
        foreach ($summary['records']['rows'] as $row) {
            fputcsv($out, array_map(fn ($v) => is_array($v) ? json_encode($v) : $v, $row));
        }
        fputcsv($out, []);
        fputcsv($out, ['Total records', $summary['records']['total']]);
        rewind($out);

        return stream_get_contents($out);
    }

    /** PDF export — same dataset with a title block, KPI band and page numbers. */
    private function toExportPdfHtml(string $label, array $summary, User $user, string $filterContext, Request $request): string
    {
        $range = $summary['meta']['range'];
        $now = now('Africa/Monrovia')->format('Y-m-d H:i');

        $kpiRow = collect($summary['kpis'])->map(function ($k) {
            $value = isset($k['money']) && $k['money'] ? '$'.number_format((float) $k['value'], 2) : number_format((float) $k['value']);
            $tone = $k['tone'] ?? 'navy';
            $bg = ['red' => '#fef2f2', 'green' => '#f0fdf4', 'amber' => '#fffbeb', 'blue' => '#eff6ff', 'navy' => '#f8fafc'][$tone] ?? '#f8fafc';

            return '<div style="background:'.$bg.';border:1px solid #e2e8f0;border-radius:8px;padding:8px 10px;min-width:130px"><div style="font-size:10px;color:#475569">'.e($k['label']).'</div><div style="font-size:16px;font-weight:800;color:#0f172a">'.$value.'</div></div>';
        })->implode('');

        $head = '<tr>'.collect($summary['records']['columns'])->map(fn ($h) => '<th>'.e($h).'</th>')->join('').'</tr>';
        $body = collect($summary['records']['rows'])->map(fn ($row) => '<tr>'.collect($row)->map(fn ($v) => '<td>'.e((string) (is_array($v) ? json_encode($v) : ($v ?? ''))).'</td>')->join('').'</tr>')->join('');

        $barRows = collect($summary['bar']['data'])->map(fn ($r) => '<tr><td>'.e($r['label']).'</td><td style="text-align:right">'.number_format((float) $r['value'], 2).'</td></tr>')->join('');

        $html = <<<'HTML'
        <!DOCTYPE html>
        <html><head><meta charset="utf-8"><style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; margin: 0; }
        h1 { font-size: 16px; margin: 0 0 2px 0; }
        .meta { font-size: 9px; color: #6b7280; margin-bottom: 12px; }
        .kpis { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        th { background: #111827; color: #fff; padding: 5px 6px; text-align: left; font-size: 9px; }
        td { padding: 4px 6px; border-bottom: 1px solid #e5e7eb; font-size: 9px; }
        tr:nth-child(even) td { background: #f9fafb; }
        .section-title { font-size: 11px; font-weight: 800; color: #0f172a; margin: 10px 0 4px 0; }
        </style></head>
        <body>
            <h1>__TITLE__</h1>
            <div class="meta">__META__</div>
            <div class="section-title">KPI Summary</div>
            <div class="kpis">__KPIS__</div>
            <div class="section-title">Detail Records (__COUNT__ total)</div>
            <table>__HEAD____BODY__</table>
            <div class="section-title">Distribution — __GROUPLABEL__</div>
            <table><tr><th>Label</th><th>Value</th></tr>__BARDATA__</table>
            <div class="meta">Pie dimension: __PIELABEL__</div>
            <script type="text/php">
                if (isset($pdf)) {
                    $font = $fontMetrics->get_font("DejaVu Sans", "normal");
                    $pdf->page_text(320, $pdf->get_height() - 16, "Page {PAGE_NUM} of {PAGE_COUNT}", $font, 8, array(0.35, 0.41, 0.51));
                    $pdf->page_text(30, $pdf->get_height() - 16, "RETD Dashboard Report", $font, 8, array(0.35, 0.41, 0.51));
                }
            </script>
        </body></html>
        HTML;

        return strtr($html, [
            '__TITLE__' => $this->escape($label),
            '__META__' => 'Generated '.$now.' by '.$this->escape($user->full_name ?? 'System').'<br>Scope: '.$range['start'].' → '.$range['end'].' · Filters: '.$this->escape($filterContext),
            '__KPIS__' => $kpiRow,
            '__COUNT__' => $summary['records']['total'],
            '__HEAD__' => $head,
            '__BODY__' => $body,
            '__GROUPLABEL__' => $this->escape($summary['meta']['group_options'][$summary['meta']['group_by']] ?? $summary['meta']['group_by']),
            '__BARDATA__' => $barRows,
            '__PIELABEL__' => $this->escape($summary['meta']['pie_options'][$summary['meta']['pie']] ?? $summary['meta']['pie']),
        ]);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function rangeBounds(Request $request): array
    {
        $range = (string) $request->query('range', 'month');
        $start = match ($range) {
            'today' => today(),
            'yesterday' => today()->subDay(),
            'week' => now()->startOfWeek(),
            'quarter' => now()->startOfQuarter(),
            'year' => now()->startOfYear(),
            'custom' => $request->query('from') ? now()->parse($request->query('from')) : now()->startOfMonth(),
            default => now()->startOfMonth(),
        };
        if ($range === 'yesterday') {
            $end = today()->subDay()->endOfDay();
        } else {
            $end = $request->query('to') ? now()->parse($request->query('to')) : now();
        }

        return [$start, $end];
    }

    private function availableMetrics(User $user): array
    {
        $out = [];
        foreach (self::METRICS as $key => $meta) {
            if ($user->hasAnyPermission($meta['perms'])) {
                $out[$key] = $meta['label'];
            }
        }

        return $out;
    }

    /** Staff picker options scoped like the dashboard. */
    private function staffOptions(User $user): array
    {
        $query = User::query()->where('users.is_active', true)->with('role:id,name');

        $scope = $user->scopeLevel();
        if ($scope === 'own') {
            $query->where('users.id', $user->id);
        } elseif ($scope === 'team') {
            $query->where(function (Builder $q) use ($user) {
                $q->where('users.id', $user->id)
                    ->orWhere('users.supervisor_id', $user->id);
            });
        } elseif ($scope === 'section') {
            $query->join('sections', 'sections.id', '=', 'users.section_id')
                ->where('sections.id', $user->section_id);
        }

        return $query->orderBy('full_name')
            ->get(['users.id', 'users.full_name', 'users.section_id'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->full_name,
                'role' => $u->role?->name,
            ])
            ->values()
            ->toArray();
    }

    /**
     * Section picker options. Section-scoped users only see their own section;
     * division/system-wide users see every section they report across.
     */
    private function sectionOptions(User $user): array
    {
        $scope = $user->scopeLevel();

        if (in_array($scope, ['own', 'team'], true)) {
            return $user->section_id
                ? [['id' => $user->section_id, 'name' => $user->section?->name]]
                : [];
        }

        if ($scope === 'section') {
            return $user->section_id
                ? [['id' => $user->section_id, 'name' => $user->section?->name]]
                : [];
        }

        return \Illuminate\Support\Facades\DB::table('sections')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->all();
    }

    private function polish(string $metric, string $dim, string $label): string
    {
        if ($metric === 'targets' && $dim === 'metric') {
            return self::TARGET_METRIC_LABELS[$label] ?? $label;
        }
        if ($dim === 'decision_path') {
            return match ($label) {
                'account' => 'Account (Path A)',
                'valuation' => 'Valuation (Path B)',
                default => $label,
            };
        }

        return $label;
    }
}