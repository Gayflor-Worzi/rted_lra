<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BillAmendment;
use App\Models\DataQualityFlag;
use App\Models\EnforcementVisit;
use App\Models\Payment;
use App\Models\PaymentVerification;
use App\Models\PropertyBill;
use App\Models\Task;
use App\Models\User;
use App\Services\BillScopeService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Role-specific operational dashboards for the two officer roles:
 *
 *   - Account & Records Officer  → bills they have logged / own (LODGE → PROCESS
 *     → VERIFY → AMEND → CORRECT), with financial value and verification.
 *   - Enforcement Officer        → bills assigned to them (ASSIGNED → DELIVER →
 *     VISIT → FOLLOW-UP → NOTICE → ESCALATE → COMPLETE), with payment position.
 *
 * Both dashboards are served by the SAME endpoint and business logic so the web
 * and mobile clients stay in parity. Every KPI, queue, chart, table and export
 * is computed from the same filtered, authorization-scoped property_bills
 * dataset — never fabricated numbers.
 *
 * RBAC gate: only the two officer roles may use this endpoint. Crow's the
 * caller's data scope (own / team / section / division / system) exactly like
 * the bill register.
 */
class OfficerDashboardController extends Controller
{
    public const ACCT_ROLES = ['Account & Records Officer'];

    public const ENF_ROLES = ['Enforcement Officer'];

    /** Enforcement workflow stages derived from the bill case status. */
    public const ENF_STAGE_BILLS = "(CASE WHEN case_status IN ('Logged','Awaiting Assignment') THEN 'Queued' WHEN case_status IN ('Assigned','Out for Delivery','Delivered') THEN 'Field / Delivery' WHEN case_status IN ('Payment Follow-up','Under Verification') THEN 'Payment Follow-up' WHEN case_status = '30-Day Warning' THEN '30-Day Reminder' WHEN case_status IN ('72-Hour Warning','Escalated') THEN '72-Hour Demand / Escalated' WHEN case_status IN ('Resolved','Closed') THEN 'Completed' ELSE case_status END)";

    public const ENF_STAGE_ORDER = ['Queued', 'Field / Delivery', 'Payment Follow-up', '30-Day Reminder', '72-Hour Demand / Escalated', 'Completed'];

    /* ------------------------------------------------------------------ */
    /*  Endpoints */
    /* ------------------------------------------------------------------ */

    public function summary(Request $request)
    {
        $user = $request->user();
        $type = $this->typeFor($user);
        abort_unless($type, 403, 'This dashboard is only available to Account & Records Officers and Enforcement Officers.');

        return response()->json(['data' => $this->build($request, $user, $type)]);
    }

    public function export(Request $request)
    {
        $user = $request->user();
        $type = $this->typeFor($user);
        abort_unless($type, 403, 'This dashboard is only available to Account & Records Officers and Enforcement Officers.');

        $format = $request->query('format', 'csv');
        abort_unless(in_array($format, ['csv', 'pdf'], true), 422, 'format must be csv or pdf.');

        $summary = $this->build($request, $user, $type);

        $typeLabel = $type === 'enforcement' ? 'Enforcement Officer' : 'Account & Records Officer';
        $label = 'RETD '.$typeLabel.' Dashboard';
        $filename = 'retd_'.$type.'_officer_dashboard_'.date('Ymd_His').'.'.$format;

        $filterContext = $this->filterContext($request);

        if ($format === 'csv') {
            $csv = $this->toCsv($label, $summary, $user, $filterContext);

            return response($csv)
                ->header('Content-Type', 'text/csv; charset=UTF-8')
                ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
        }

        $html = $this->toPdfHtml($label, $summary, $user, $filterContext);

        return Pdf::setOption('isPhpEnabled', true)
            ->loadHTML($html)
            ->setPaper('a4', $summary['meta']['wide'] ? 'landscape' : 'portrait')
            ->download($filename);
    }

    /* ------------------------------------------------------------------ */
    /*  Payload builder */
    /* ------------------------------------------------------------------ */

    private function build(Request $request, User $user, string $type): array
    {
        $query = $this->baseQuery($user);
        $this->applyStatusFilters($request, $type, $query);

        [$from, $to] = $this->rangeBounds($request);

        if ($from !== null && $to !== null) {
            $query->whereBetween('date_logged', [$from->startOfDay(), $to->endOfDay()]);
        }

        $bar = $this->buildBar($type, (clone $query));
        $pie = $this->buildPie((clone $query));
        $kpis = $this->buildKpis($type, (clone $query), $query->pluck('id')->all());
        $queues = $this->buildQueues($type, $user, (clone $query), $query->pluck('id')->all());
        $records = $this->buildRecords($request, $type, (clone $query));

        return [
            'meta' => [
                'type' => $type,
                'label' => $type === 'enforcement' ? 'Enforcement Officer Dashboard' : 'Account & Records Officer Dashboard',
                'as_of' => now()->toISOString(),
                'range' => $this->rangeDisplay($request, $from, $to, $type, $user),
                'filters' => $this->filterOptions($request, $user, $type),
                'active' => [
                    'year' => $request->query('year'),
                    'quarter' => $request->query('quarter'),
                    'month' => $request->query('month'),
                    'from' => $request->query('from'),
                    'to' => $request->query('to'),
                    'status' => $request->query($this->statusParam($type)),
                    'payment_status' => $request->query('payment_status'),
                ],
                'drill' => $request->query('record_dim') && $request->filled('record_value')
                    ? ['dim' => $request->query('record_dim'), 'value' => $request->query('record_value')]
                    : null,
                'wide' => count($records['columns']) > 9,
            ],
            'kpis' => $kpis,
            'queues' => $queues,
            'bar' => $bar,
            'pie' => $pie,
            'records' => $records,
            'my_tasks' => $this->buildMyTasks($user),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Role + scope */
    /* ------------------------------------------------------------------ */

    private function typeFor(User $user): ?string
    {
        $role = $user->role?->name;

        if (in_array($role, self::ACCT_ROLES, true)) {
            return 'account';
        }

        if (in_array($role, self::ENF_ROLES, true)) {
            return 'enforcement';
        }

        return null;
    }

    private function baseQuery(User $user): Builder
    {
        $query = PropertyBill::query()->with(['accountStaff:id,full_name', 'enforcementOfficer:id,full_name']);

        $ids = $this->billIdsInScope($user);

        if ($ids !== null) {
            $query->whereIn('property_bills.id', $ids);
        }

        return $query;
    }

    /** Returns array of allowed ids, or null for unrestricted. */
    private function billIdsInScope(User $user): ?array
    {
        return BillScopeService::visibleBillIds($user);
    }

    private function userSeesAllBills(User $user): bool
    {
        return BillScopeService::userSeesAllBills($user);
    }

    /* ------------------------------------------------------------------ */
    /*  Filters */
    /* ------------------------------------------------------------------ */

    private function statusParam(string $type): string
    {
        return $type === 'enforcement' ? 'enforcement_stage' : 'bill_status';
    }

    private function applyStatusFilters(Request $request, string $type, Builder $query): void
    {
        if ($type === 'enforcement') {
            $stage = $request->query('enforcement_stage');
            if ($stage) {
                $valid = in_array($stage, self::ENF_STAGE_ORDER, true);
                abort_unless($valid, 422, 'Unknown enforcement stage.');
                $query->whereRaw('('.self::ENF_STAGE_BILLS.') = ?', [(string) $stage]);
            }
        } else {
            $status = $request->query('bill_status');
            if ($status) {
                abort_unless(in_array($status, PropertyBill::CASE_STATUSES, true), 422, 'Unknown bill status.');
                $query->where('case_status', $status);
            }
        }

        if ($paymentStatus = $request->query('payment_status')) {
            abort_unless(in_array($paymentStatus, PropertyBill::PAYMENT_STATUSES, true), 422, 'Unknown payment status.');
            $query->where('payment_status', $paymentStatus);
        }
    }

    private function rangeBounds(Request $request): array
    {
        if ($request->query('from') || $request->query('to')) {
            $from = $request->query('from') ? now()->parse($request->query('from')) : now()->startOfYear();
            $to = $request->query('to') ? now()->parse($request->query('to')) : now();

            return [$from, $to];
        }

        $year = (int) $request->query('year');
        $quarter = (int) $request->query('quarter');
        $month = (int) $request->query('month');

        if ($month >= 1 && $month <= 12) {
            return [
                Carbon::create($year, $month, 1)->startOfMonth(),
                Carbon::create($year, $month, 1)->endOfMonth(),
            ];
        }

        if ($quarter >= 1 && $quarter <= 4) {
            $startMonth = (($quarter - 1) * 3) + 1;

            return [
                Carbon::create($year, $startMonth, 1)->startOfMonth(),
                Carbon::create($year, $startMonth + 2, 1)->endOfMonth(),
            ];
        }

        if ($year >= 2000) {
            return [
                Carbon::create($year, 1, 1)->startOfYear(),
                Carbon::create($year, 12, 31)->endOfYear(),
            ];
        }

        // Officer dashboards default to ALL TIME unless a period is explicitly
        // chosen, so the landing mirrors the actual registers (an assignment
        // counted by the year the bill was logged can drift from the live queue).
        return [null, null];
    }

    /**
     * Period label for the header. When no explicit period is chosen the range
     * is the actual first/last logged date inside the user's scope, so the
     * header always reflects the records being reported.
     */
    private function rangeDisplay(Request $request, ?Carbon $from, ?Carbon $to, string $type, User $user): array
    {
        if ($from !== null && $to !== null) {
            return ['start' => $from->toDateString(), 'end' => $to->toDateString()];
        }

        $bounds = $this->baseQuery($user)->selectRaw('MIN(date_logged) as mn, MAX(date_logged) as mx')->first();

        return [
            'start' => $bounds && $bounds->mn ? substr((string) $bounds->mn, 0, 10) : '—',
            'end' => $bounds && $bounds->mx ? substr((string) $bounds->mx, 0, 10) : '—',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Task register snapshot */
    /* ------------------------------------------------------------------ */

    /**
     * The officer's own task register (assigned_to == user) — the same universe
     * the tasks register lists, so the landing's "task" cards always agree with
     * what the officer can open in My Tasks. Independent of the bill date axes.
     */
    private function buildMyTasks(User $user): array
    {
        $base = Task::query()->where('assigned_to', $user->id);
        $today = now()->toDateString();

        return [
            'total' => (clone $base)->count(),
            'active' => (clone $base)->whereNotIn('status', Task::COMPLETED_STATUSES)->count(),
            'overdue' => (clone $base)->whereNotIn('status', Task::COMPLETED_STATUSES)->whereDate('due_date', '<', $today)->count(),
            'due_today' => (clone $base)->whereNotIn('status', Task::COMPLETED_STATUSES)->whereDate('due_date', $today)->count(),
            'escalated' => (clone $base)->where('status', 'Escalated')->count(),
            'completed' => (clone $base)->whereIn('status', Task::COMPLETED_STATUSES)->count(),
            'pending_verification' => Task::where('assigned_to', $user->id)
                ->whereIn('status', ['Payment Claimed', 'Verification Pending', 'Payment Verification'])->count(),
        ];
    }

    /** Cascade filter options (Year → Quarter → Month → statuses), computed from scope. */
    private function filterOptions(Request $request, User $user, string $type): array
    {
        $base = $this->baseQuery($user);
        $statusParam = $this->statusParam($type);

        $years = [];
        $quarters = [];
        $months = [];

        $yearExpr = $this->isPostgres() ? 'EXTRACT(YEAR FROM date_logged)' : 'YEAR(date_logged)';
        $yearRows = (clone $base)->selectRaw("{$yearExpr} as y")->distinct()->orderByDesc('y')->get();

        foreach ($yearRows as $r) {
            $y = (int) $r->y;
            $years[] = ['value' => $y, 'label' => (string) $y];
        }

        if ($request->query('year')) {
            $selYear = (int) $request->query('year');
            $quarterExpr = $this->isPostgres() ? 'EXTRACT(QUARTER FROM date_logged)' : 'QUARTER(date_logged)';
            $monthsExpr = $this->isPostgres() ? 'EXTRACT(MONTH FROM date_logged)' : 'MONTH(date_logged)';

            $qRows = (clone $base)->selectRaw("{$quarterExpr} as q, COUNT(*) as c")
                ->whereYear('date_logged', $selYear)->groupBy(DB::raw('q'))->get();
            foreach ($qRows as $r) {
                $quarters[] = ['value' => (int) $r->q, 'label' => 'Q'.(int) $r->q, 'count' => (int) $r->c];
            }

            $mRows = (clone $base)->selectRaw("{$monthsExpr} as m, COUNT(*) as c")
                ->whereYear('date_logged', $selYear)
                ->when($request->query('quarter'), fn (Builder $b) => $b->whereRaw("{$quarterExpr} = ?", [(int) $request->query('quarter')]))
                ->groupBy(DB::raw('m'))->get();
            foreach ($mRows as $r) {
                $months[] = ['value' => (int) $r->m, 'label' => now()->month((int) $r->m)->format('M'), 'count' => (int) $r->c];
            }
        }

        // Statuses actually present in scope.
        $statusRows = (clone $base)
            ->when($request->query('year'), fn (Builder $b) => $b->whereYear('date_logged', (int) $request->query('year')))
            ->selectRaw($type === 'enforcement' ? '('.self::ENF_STAGE_BILLS.') as label' : 'case_status as label')
            ->distinct()->orderBy('label')->get();

        $statuses = [];
        foreach ($statusRows as $r) {
            $label = (string) $r->label;
            $statuses[] = ['value' => $label, 'label' => $label];
        }

        if ($type === 'enforcement') {
            usort($statuses, fn ($a, $b) => array_search($a['label'], self::ENF_STAGE_ORDER, true) <=> array_search($b['label'], self::ENF_STAGE_ORDER, true));
        }

        $paymentRows = (clone $base)
            ->when($request->query('year'), fn (Builder $b) => $b->whereYear('date_logged', (int) $request->query('year')))
            ->select('payment_status')->distinct()->orderBy('payment_status')->get();
        $payments = $paymentRows->map(fn ($r) => ['value' => (string) $r->payment_status, 'label' => (string) $r->payment_status])->values()->all();

        // Officers with an empty personal scope still see the dropdown filters
        // (matching the web controls) using the register-wide option set. The
        // FILTERED results below remain scope-bound — this only populates the
        // Year / status / Payment status dropdown options.
        if ($years === [] && $statuses === [] && $payments === []) {
            $fallback = PropertyBill::query();

            $years = $fallback->selectRaw("{$yearExpr} as y")->distinct()->orderByDesc('y')->get()
                ->map(fn ($r) => ['value' => (int) $r->y, 'label' => (string) $r->y])->values()->all();

            $fallbackStatuses = PropertyBill::query()
                ->selectRaw($type === 'enforcement' ? '('.self::ENF_STAGE_BILLS.') as label' : 'case_status as label')
                ->distinct()->orderBy('label')->get();
            $statuses = [];
            foreach ($fallbackStatuses as $r) {
                $statuses[] = ['value' => (string) $r->label, 'label' => (string) $r->label];
            }
            if ($type === 'enforcement') {
                usort($statuses, fn ($a, $b) => array_search($a['label'], self::ENF_STAGE_ORDER, true) <=> array_search($b['label'], self::ENF_STAGE_ORDER, true));
            }

            $payments = PropertyBill::query()->select('payment_status')->distinct()->orderBy('payment_status')->get()
                ->map(fn ($r) => ['value' => (string) $r->payment_status, 'label' => (string) $r->payment_status])->values()->all();
        }

        return [
            'status_param' => $statusParam,
            'years' => $years,
            'quarters' => $quarters,
            'months' => $months,
            'statuses' => $statuses,
            'payment_statuses' => $payments,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Charts */
    /* ------------------------------------------------------------------ */

    private function buildBar(string $type, Builder $query): array
    {
        if ($type === 'enforcement') {
            $rows = (clone $query)
                ->selectRaw('('.self::ENF_STAGE_BILLS.') as stage, COUNT(*) as c')
                ->groupBy(DB::raw('stage'))
                ->get();

            $byStage = [];
            foreach ($rows as $r) {
                $byStage[(string) $r->stage] = (int) $r->c;
            }

            $data = [];
            foreach (self::ENF_STAGE_ORDER as $stage) {
                if (isset($byStage[$stage])) {
                    $data[] = ['label' => $stage, 'value' => $byStage[$stage]];
                }
            }

            return ['shape' => 'simple', 'metric_label' => 'Count', 'dimension' => 'enforcement_stage', 'data' => $data];
        }

        $rows = (clone $query)
            ->selectRaw('case_status as stage, COUNT(*) as c')
            ->groupBy('stage')
            ->get();

        $byStatus = [];
        foreach ($rows as $r) {
            $byStatus[(string) $r->stage] = (int) $r->c;
        }

        $data = [];
        foreach (PropertyBill::CASE_STATUSES as $status) {
            if (isset($byStatus[$status])) {
                $data[] = ['label' => $status, 'value' => $byStatus[$status]];
            }
        }

        return ['shape' => 'simple', 'metric_label' => 'Count', 'dimension' => 'bill_status', 'data' => $data];
    }

    private function buildPie(Builder $query): array
    {
        $rows = (clone $query)
            ->selectRaw('payment_status as label, COUNT(*) as c')
            ->groupBy('label')
            ->get();

        $byStatus = [];
        foreach ($rows as $r) {
            $byStatus[(string) $r->label] = (int) $r->c;
        }

        $data = [];
        foreach (PropertyBill::PAYMENT_STATUSES as $status) {
            if (isset($byStatus[$status])) {
                $data[] = ['label' => $status, 'value' => $byStatus[$status]];
            }
        }

        return ['dimension' => 'payment_status', 'data' => $data];
    }

    /* ------------------------------------------------------------------ */
    /*  KPIs */
    /* ------------------------------------------------------------------ */

    private function buildKpis(string $type, Builder $query, array $scopedIds): array
    {
        $total = (clone $query)->count();

        if ($type === 'account') {
            $processed = (clone $query)->whereNotIn('case_status', ['Logged', 'Awaiting Assignment'])->count();
            $pending = $total - $processed;

            return [
                ['key' => 'bills_lodged', 'label' => 'Bills lodged', 'value' => $total, 'tone' => 'navy'],
                ['key' => 'bills_processed', 'label' => 'Bills processed', 'value' => $processed, 'tone' => 'blue'],
                ['key' => 'bills_pending', 'label' => 'Bills pending', 'value' => $pending, 'tone' => 'amber'],
                ['key' => 'total_tax_value', 'label' => 'Total tax value', 'value' => round((float) (clone $query)->sum('total_tax_due'), 2), 'tone' => 'blue', 'money' => true],
                ['key' => 'verified_value', 'label' => 'Paid / verified', 'value' => round((float) $this->paymentsOnScope($scopedIds)->sum('amount'), 2), 'tone' => 'green', 'money' => true],
                ['key' => 'outstanding', 'label' => 'Outstanding', 'value' => round((float) (clone $query)->sum('outstanding_balance'), 2), 'tone' => 'red', 'money' => true],
            ];
        }

        $fieldDelivery = ['Assigned', 'Out for Delivery', 'Delivered'];
        $followups = ['Payment Follow-up', 'Under Verification'];
        $notices = ['30-Day Warning', '72-Hour Warning'];

        return [
            ['key' => 'my_assigned', 'label' => 'My assigned', 'value' => $total, 'tone' => 'navy'],
            ['key' => 'field_delivery', 'label' => 'Field / delivery', 'value' => (clone $query)->whereIn('case_status', $fieldDelivery)->count(), 'tone' => 'blue'],
            ['key' => 'payment_followups', 'label' => 'Payment follow-up', 'value' => (clone $query)->whereIn('case_status', $followups)->count(), 'tone' => 'amber'],
            ['key' => 'notices', 'label' => 'Notices / reminders', 'value' => (clone $query)->whereIn('case_status', $notices)->count(), 'tone' => 'amber'],
            ['key' => 'escalated', 'label' => 'Escalated', 'value' => (clone $query)->where('case_status', 'Escalated')->count(), 'tone' => 'red'],
            ['key' => 'completed', 'label' => 'Completed', 'value' => (clone $query)->whereIn('case_status', ['Resolved', 'Closed'])->count(), 'tone' => 'green'],
            ['key' => 'collected', 'label' => 'Collected value', 'value' => round((float) $this->paymentsOnScope($scopedIds)->sum('amount'), 2), 'tone' => 'green', 'money' => true],
            ['key' => 'outstanding', 'label' => 'Outstanding', 'value' => round((float) (clone $query)->sum('outstanding_balance'), 2), 'tone' => 'red', 'money' => true],
        ];
    }

    private function paymentsOnScope(array $scopedIds): Builder
    {
        $q = Payment::query();

        if ($scopedIds === []) {
            $q->whereRaw('1 = 0');

            return $q;
        }

        return $scopedIds === null ? $q->whereNotNull('bill_id') : $q->whereIn('bill_id', $scopedIds);
    }

    /* ------------------------------------------------------------------ */
    /*  Work queues */
    /* ------------------------------------------------------------------ */

    private function buildQueues(string $type, User $user, Builder $query, array $scopedIds): array
    {
        $total = (clone $query)->count();

        if ($type === 'account') {
            $pendingVerification = 0;
            $amendments = 0;
            $dataQuality = 0;

            if ($scopedIds !== []) {
                $pendingVerification = PaymentVerification::query()->whereIn('bill_id', $scopedIds)->where('verification_status', 'Pending')->count();
                $amendments = BillAmendment::query()->whereIn('bill_id', $scopedIds)->count();
                $dataQuality = DataQualityFlag::query()->whereIn('bill_id', $scopedIds)->where('status', '!=', 'Resolved')->count();
            }

            return [
                ['key' => 'my_bills', 'label' => 'My bills (range)', 'value' => $total, 'tone' => 'navy', 'route' => '/bills'],
                ['key' => 'pending_processing', 'label' => 'Pending processing', 'value' => (clone $query)->whereIn('case_status', ['Logged', 'Awaiting Assignment'])->count(), 'tone' => 'amber', 'route' => '/bills'],
                ['key' => 'payment_verification', 'label' => 'Payment verification', 'value' => $pendingVerification, 'tone' => 'blue', 'route' => '/payments'],
                ['key' => 'amendments', 'label' => 'Bill amendments', 'value' => $amendments, 'tone' => 'brand', 'route' => '/bills'],
                ['key' => 'data_quality', 'label' => 'Data-quality flags', 'value' => $dataQuality, 'tone' => 'red', 'route' => '/bills'],
            ];
        }

        $fieldDelivery = ['Assigned', 'Out for Delivery', 'Delivered'];
        $followups = ['Payment Follow-up', 'Under Verification'];
        $notices = ['30-Day Warning', '72-Hour Warning'];

        $visits = 0;
        if ($scopedIds !== []) {
            $visits = EnforcementVisit::query()->whereIn('bill_id', $scopedIds)->count();
        }

        return [
            ['key' => 'my_assignments', 'label' => 'My assignments', 'value' => $total, 'tone' => 'navy', 'route' => '/enforcement'],
            ['key' => 'pending_delivery', 'label' => 'Pending delivery', 'value' => (clone $query)->whereIn('case_status', $fieldDelivery)->count(), 'tone' => 'blue', 'route' => '/enforcement'],
            ['key' => 'payment_followups', 'label' => 'Payment follow-ups', 'value' => (clone $query)->whereIn('case_status', $followups)->count(), 'tone' => 'amber', 'route' => '/enforcement'],
            ['key' => 'notices', 'label' => 'Notices / reminders', 'value' => (clone $query)->whereIn('case_status', $notices)->count(), 'tone' => 'amber', 'route' => '/enforcement'],
            ['key' => 'escalations', 'label' => 'Escalated cases', 'value' => (clone $query)->where('case_status', 'Escalated')->count(), 'tone' => 'red', 'route' => '/enforcement'],
            ['key' => 'field_visits', 'label' => 'Field visits', 'value' => $visits, 'tone' => 'green', 'route' => '/enforcement'],
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Records */
    /* ------------------------------------------------------------------ */

    private function buildRecords(Request $request, string $type, Builder $query): array
    {
        $this->applyDrill($type, $query, $request);

        $perPage = max(1, min(50, (int) $request->query('per_page', 10)));
        $rows = $query->orderBy('date_logged', 'desc')->orderByDesc('id')->paginate($perPage)->withQueryString();

        [$columns, $transform] = $this->recordShape($type);

        return [
            'columns' => $columns,
            'rows' => $rows->getCollection()->map(fn ($b) => $transform($b))->values()->toArray(),
            'total' => $rows->total(),
            'per_page' => $rows->perPage(),
            'current_page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
        ];
    }

    private function applyDrill(string $type, Builder $query, Request $request): void
    {
        if (! $request->filled('record_dim') || ! $request->filled('record_value')) {
            return;
        }

        $dim = $request->query('record_dim');
        $value = $request->query('record_value');

        if ($dim === 'payment_status') {
            $query->where('payment_status', $value);

            return;
        }

        if ($type === 'enforcement' && $dim === 'enforcement_stage') {
            $query->whereRaw('('.self::ENF_STAGE_BILLS.') = ?', [(string) $value]);

            return;
        }

        if ($type === 'account' && $dim === 'bill_status') {
            $query->where('case_status', $value);

            return;
        }
    }

    private function recordShape(string $type): array
    {
        if ($type === 'enforcement') {
            $columns = ['Document #', 'Property ID', 'Taxpayer', 'TIN', 'Stage', 'Payment', 'Outstanding', 'Delivery', 'Assigned to', 'Next notice', 'Logged date'];

            return [$columns, function (PropertyBill $b) {
                return [
                    $b->document_number ?? '—',
                    $b->property_id ?? '—',
                    $b->taxpayer_name ?? '—',
                    $b->tin ?? '—',
                    $this->stageLabel($b->case_status),
                    $b->payment_status ?? '—',
                    number_format((float) $b->outstanding_balance, 2),
                    $b->delivery_status ?? '—',
                    $b->relationLoaded('enforcementOfficer') ? ($b->enforcementOfficer?->full_name ?? '—') : '—',
                    $b->final_notice_date?->toDateString() ?? $b->thirty_day_notice_date?->toDateString() ?? '—',
                    $b->date_logged?->toDateString() ?? '—',
                ];
            }];
        }

        $columns = ['Document #', 'Property ID', 'Taxpayer', 'TIN', 'Classification', 'Tax due', 'Outstanding', 'Payment', 'Case', 'Logged by', 'Logged date'];

        return [$columns, function (PropertyBill $b) {
            return [
                $b->document_number ?? '—',
                $b->property_id ?? '—',
                $b->taxpayer_name ?? '—',
                $b->tin ?? '—',
                $b->property_classification ?? '—',
                number_format((float) $b->total_tax_due, 2),
                number_format((float) $b->outstanding_balance, 2),
                $b->payment_status ?? '—',
                $b->case_status ?? '—',
                $b->relationLoaded('accountStaff') ? ($b->accountStaff?->full_name ?? '—') : '—',
                $b->date_logged?->toDateString() ?? '—',
            ];
        }];
    }

    private function stageLabel(?string $status): string
    {
        if ($status === null) {
            return '—';
        }

        $map = [
            'Logged' => 'Queued',
            'Awaiting Assignment' => 'Queued',
            'Assigned' => 'Field / Delivery',
            'Out for Delivery' => 'Field / Delivery',
            'Delivered' => 'Field / Delivery',
            'Payment Follow-up' => 'Payment Follow-up',
            'Under Verification' => 'Payment Follow-up',
            '30-Day Warning' => '30-Day Reminder',
            '72-Hour Warning' => '72-Hour Demand / Escalated',
            'Escalated' => '72-Hour Demand / Escalated',
            'Resolved' => 'Completed',
            'Closed' => 'Completed',
        ];

        return $map[$status] ?? $status;
    }

    /* ------------------------------------------------------------------ */
    /*  Export */
    /* ------------------------------------------------------------------ */

    private function filterContext(Request $request): string
    {
        $labels = ['year' => 'Year', 'quarter' => 'Quarter', 'month' => 'Month', 'from' => 'From', 'to' => 'To', 'bill_status' => 'Bill status', 'enforcement_stage' => 'Stage', 'payment_status' => 'Payment status'];
        $parts = [];

        foreach ($labels as $key => $label) {
            if ($request->filled($key)) {
                $parts[] = $label.'='.$request->query($key);
            }
        }

        return $parts ? implode(' | ', $parts) : 'Current period (no other filters)';
    }

    private function toCsv(string $label, array $summary, User $user, string $filterContext): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [$label]);
        fputcsv($out, ['Generated '.now('Africa/Monrovia')->format('Y-m-d H:i').' by '.$user->full_name]);
        fputcsv($out, ['Period', $summary['meta']['range']['start'].' → '.$summary['meta']['range']['end']]);
        fputcsv($out, ['Filters', $filterContext]);
        fputcsv($out, []);
        fputcsv($out, ['KPI', 'Value']);
        foreach ($summary['kpis'] as $k) {
            $value = isset($k['money']) && $k['money'] ? number_format((float) $k['value'], 2) : $k['value'];
            fputcsv($out, [$k['label'], $value]);
        }
        fputcsv($out, []);
        if (! empty($summary['my_tasks'])) {
            fputcsv($out, ['Task register snapshot', 'Value']);
            foreach ($summary['my_tasks'] as $key => $value) {
                fputcsv($out, [str_replace('_', ' ', ucfirst($key)), $value]);
            }
            fputcsv($out, []);
        }
        fputcsv($out, ['Work queues', 'Value']);
        foreach ($summary['queues'] as $q) {
            fputcsv($out, [$q['label'], $q['value']]);
        }
        fputcsv($out, []);
        fputcsv($out, ['Distribution — '.($summary['bar']['dimension'] === 'enforcement_stage' ? 'Enforcement stage' : 'Bill status')]);
        fputcsv($out, ['Label', 'Count']);
        foreach ($summary['bar']['data'] as $row) {
            fputcsv($out, [$row['label'], $row['value']]);
        }
        fputcsv($out, []);
        fputcsv($out, ['Breakdown — Payment status']);
        fputcsv($out, ['Label', 'Count']);
        foreach ($summary['pie']['data'] as $row) {
            fputcsv($out, [$row['label'], $row['value']]);
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

    private function toPdfHtml(string $label, array $summary, User $user, string $filterContext): string
    {
        $range = $summary['meta']['range'];
        $now = now('Africa/Monrovia')->format('Y-m-d H:i');

        $kpiRow = collect($summary['kpis'])->map(function ($k) {
            $value = isset($k['money']) && $k['money'] ? '$'.number_format((float) $k['value'], 2) : number_format((float) $k['value']);
            $tone = $k['tone'] ?? 'navy';
            $bg = ['red' => '#fef2f2', 'green' => '#f0fdf4', 'amber' => '#fffbeb', 'blue' => '#eff6ff', 'navy' => '#f8fafc'][$tone] ?? '#f8fafc';

            return '<div style="background:'.$bg.';border:1px solid #e2e8f0;border-radius:8px;padding:8px 10px;min-width:120px"><div style="font-size:9px;color:#475569">'.e($k['label']).'</div><div style="font-size:13px;font-weight:800;color:#0f172a">'.$value.'</div></div>';
        })->implode('');

        $queueRow = collect($summary['queues'])->map(function ($q) {
            return '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:6px 10px;min-width:110px"><div style="font-size:9px;color:#475569">'.$q['label'].'</div><div style="font-size:13px;font-weight:800;color:#0f172a">'.$q['value'].'</div></div>';
        })->implode('');

        $head = '<tr>'.collect($summary['records']['columns'])->map(fn ($h) => '<th>'.e($h).'</th>')->join('').'</tr>';
        $body = collect($summary['records']['rows'])->map(fn ($row) => '<tr>'.collect($row)->map(fn ($v) => '<td>'.e((string) (is_array($v) ? json_encode($v) : ($v ?? ''))).'</td>')->join('').'</tr>')->join('');

        $barRows = collect($summary['bar']['data'])->map(fn ($r) => '<tr><td>'.e($r['label']).'</td><td style="text-align:right">'.number_format((float) $r['value'], 2).'</td></tr>')->join('');
        $pieRows = collect($summary['pie']['data'])->map(fn ($r) => '<tr><td>'.e($r['label']).'</td><td style="text-align:right">'.number_format((float) $r['value'], 2).'</td></tr>')->join('');

        $html = <<<'HTML'
        <!DOCTYPE html>
        <html><head><meta charset="utf-8"><style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1f2937; margin: 0; }
        h1 { font-size: 15px; margin: 0 0 2px 0; }
        .meta { font-size: 8px; color: #6b7280; margin-bottom: 10px; }
        .kpis { display: flex; gap: 5px; flex-wrap: wrap; margin-bottom: 10px; }
        .queues { display: flex; gap: 5px; flex-wrap: wrap; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        th { background: #111827; color: #fff; padding: 4px 5px; text-align: left; font-size: 8px; }
        td { padding: 3px 5px; border-bottom: 1px solid #e5e7eb; font-size: 8px; }
        tr:nth-child(even) td { background: #f9fafb; }
        .section-title { font-size: 10px; font-weight: 800; color: #0f172a; margin: 9px 0 4px 0; }
        </style></head>
        <body>
            <h1>__TITLE__</h1>
            <div class="meta">__META__</div>
            <div class="section-title">KPI Summary</div>
            <div class="kpis">__KPIS__</div>
            <div class="section-title">Work Queues</div>
            <div class="queues">__QUEUES__</div>
            <div class="section-title">Detail Records (__COUNT__ total)</div>
            <table>__HEAD____BODY__</table>
            <div class="section-title">Distribution — __GROUPLABEL__</div>
            <table style="width:60%"><tr><th>Label</th><th>Count</th></tr>__BARDATA__</table>
            <div class="meta">Breakdown — __PIELABEL__</div>
            <table style="width:60%"><tr><th>Label</th><th>Count</th></tr>__PIEDATA__</table>
            <script type="text/php">
                if (isset($pdf)) {
                    $font = $fontMetrics->get_font("DejaVu Sans", "normal");
                    $pdf->page_text(320, $pdf->get_height() - 16, "Page {PAGE_NUM} of {PAGE_COUNT}", $font, 8, array(0.35, 0.41, 0.51));
                    $pdf->page_text(30, $pdf->get_height() - 16, "RETD Officer Dashboard Report", $font, 8, array(0.35, 0.41, 0.51));
                }
            </script>
        </body></html>
        HTML;

        return strtr($html, [
            '__TITLE__' => $this->escape($label),
            '__META__' => 'Generated '.$now.' by '.$this->escape($user->full_name ?? 'System').'<br>Period: '.$range['start'].' → '.$range['end'].' · Filters: '.$this->escape($filterContext),
            '__KPIS__' => $kpiRow,
            '__QUEUES__' => $queueRow,
            '__COUNT__' => $summary['records']['total'],
            '__HEAD__' => $head,
            '__BODY__' => $body,
            '__GROUPLABEL__' => $summary['bar']['dimension'] === 'enforcement_stage' ? 'Enforcement stage' : 'Bill status',
            '__BARDATA__' => $barRows,
            '__PIELABEL__' => 'Payment status',
            '__PIEDATA__' => $pieRows,
        ]);
    }

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
