<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentVerification;
use App\Models\PropertyBill;
use App\Models\PropertyDiscovery;
use App\Models\Task;
use App\Models\Valuation;
use App\Traits\ScopeDateFilter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/**
 * Operational reports — bills, collections, enforcement, valuations, tasks.
 */
class ReportsController extends Controller
{
    use ScopeDateFilter;

    public function bills(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canPermission('reports.view'), 403, 'Missing permission: reports.view');

        $query = PropertyBill::query();
        $this->applyDateFilter($request, $query, 'date_logged');
        $this->applyReportFilters($request, $query, 'bills');

        $rows = $query->orderBy('tin')->orderByDesc('id')->paginate($request->query('per_page', 20))->withQueryString();

        return response()->json(['data' => ['rows' => $rows]]);
    }

    public function collections(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canPermission('reports.view'), 403, 'Missing permission: reports.view');

        $query = Payment::query()->with('verifier:id,full_name');
        $this->applyDateFilter($request, $query, 'verified_at');
        $this->applyReportFilters($request, $query, 'collections');

        $rows = $query->orderByDesc('id')->paginate($request->query('per_page', 20))->withQueryString();
        $rows->getCollection()->transform(fn ($p) => [
            'id' => $p->id,
            'property_id' => $p->bill?->property_id,
            'tin' => $p->bill?->tin,
            'document_number' => $p->document_number,
            'amount' => $p->amount,
            'payment_period' => $p->payment_period,
            'receipt_number' => $p->receipt_number,
            'verified_by' => $p->relationLoaded('verifier') ? $p->verifier?->full_name : null,
            'verified_at' => $p->verified_at?->toISOString(),
        ]);

        $totals = [
            'verified_amount' => (clone $query)->sum('amount'),
            'count' => (clone $query)->count(),
        ];

        return response()->json(['data' => ['rows' => $rows, 'totals' => $totals]]);
    }

    public function enforcement(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['reports.view', 'enforcement.view_assignments']), 403, 'Missing permission.');

        $query = Task::query()->with('assignedTo:id,full_name')->where('section', 'Enforcement');
        $this->applyDateFilter($request, $query, 'created_at');
        $this->applyReportFilters($request, $query, 'enforcement');

        $rows = $query->orderByDesc('id')->paginate($request->query('per_page', 20))->withQueryString();
        $rows->getCollection()->transform(fn ($t) => [
            'id' => $t->id,
            'property_id' => $t->bill?->property_id,
            'tin' => $t->bill?->tin,
            'task_reference' => $t->task_reference,
            'task_type' => $t->task_type,
            'status' => $t->status,
            'assigned_to' => $t->relationLoaded('assignedTo') ? $t->assignedTo?->full_name : null,
            'due_date' => $t->due_date?->toDateString(),
            'created_at' => $t->created_at?->toISOString(),
        ]);

        $summary = [
            'total' => (clone $query)->count(),
            'assigned' => (clone $query)->where('status', 'Assigned')->count(),
            'delivered' => (clone $query)->whereIn('status', ['Delivered', 'Resolved', 'Closed'])->count(),
            'escalated' => (clone $query)->where('status', 'Escalated')->count(),
        ];

        return response()->json(['data' => ['rows' => $rows, 'summary' => $summary]]);
    }

    public function valuations(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['reports.view', 'valuation.view_history']), 403, 'Missing permission.');

        $query = Valuation::query()->with('valuationOfficer:id,full_name');
        $this->applyDateFilter($request, $query, 'created_at');
        $this->applyReportFilters($request, $query, 'valuations');

        $rows = $query->orderByDesc('id')->paginate($request->query('per_page', 20))->withQueryString();
        $rows->getCollection()->transform(fn ($v) => [
            'id' => $v->id,
            'valuation_reference' => $v->valuation_reference,
            'property_id' => $v->property_id,
            'owner_name' => $v->owner_name,
            'status' => $v->status,
            'assessed_value' => $v->assessed_value,
            'annual_tax' => $v->annual_tax,
            'valuation_officer' => $v->relationLoaded('valuationOfficer') ? $v->valuationOfficer?->full_name : null,
            'created_at' => $v->created_at?->toISOString(),
        ]);

        return response()->json(['data' => ['rows' => $rows]]);
    }

    public function discoveries(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['reports.view', 'discovery.view', 'discovery.create']), 403, 'Missing permission: reports.view');

        $query = PropertyDiscovery::query()->with('discoverer:id,full_name');
        $this->applyDateFilter($request, $query, 'discovery_date');
        $this->applyReportFilters($request, $query, 'discoveries');

        $rows = $query->orderByDesc('id')->paginate($request->query('per_page', 20))->withQueryString();
        $rows->getCollection()->transform(fn ($d) => [
            'id' => $d->id,
            'discovery_reference' => $d->discovery_reference,
            'property_id' => $d->property_id,
            'tin' => $d->tin,
            'owner_name' => $d->owner_name,
            'property_address' => $d->property_address,
            'property_classification' => $d->property_classification,
            'decision_path' => $d->decision_path,
            'status' => $d->status,
            'discovered_by' => $d->relationLoaded('discoverer') ? $d->discoverer?->full_name : null,
            'discovery_date' => $d->discovery_date?->toDateString(),
        ]);

        return response()->json(['data' => ['rows' => $rows]]);
    }

    public function paymentQueue(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['reports.view', 'payments.view_queue', 'payments.view_history']), 403, 'Missing permission.');

        $query = PaymentVerification::query()->with('verifier:id,full_name');
        $this->applyDateFilter($request, $query, 'created_at');
        $this->applyReportFilters($request, $query, 'payment-queue');

        $rows = $query->orderBy('tin')->orderByDesc('id')->paginate($request->query('per_page', 20))->withQueryString();
        $rows->getCollection()->transform(fn ($v) => [
            'id' => $v->id,
            'property_id' => $v->property_id,
            'tin' => $v->tin,
            'document_number' => $v->document_number,
            'receipt_number' => $v->receipt_number,
            'amount_claimed' => $v->amount_claimed,
            'match_status' => $v->match_status,
            'verification_status' => $v->verification_status,
            'created_at' => $v->created_at?->toISOString(),
        ]);

        return response()->json(['data' => ['rows' => $rows]]);
    }

    /**
     * Titled CSV/PDF export that honours the same date filters as the lists.
     */
    public function export(Request $request, string $kind)
    {
        $user = $request->user();

        $guards = [
            'bills' => fn () => abort_unless($user->hasAnyPermission(['reports.view', 'reports.export']), 403, 'Missing permission: reports.export'),
            'collections' => fn () => abort_unless($user->hasAnyPermission(['reports.view', 'reports.export']), 403, 'Missing permission: reports.export'),
            'enforcement' => fn () => abort_unless($user->hasAnyPermission(['reports.view', 'reports.export', 'enforcement.view_assignments']), 403, 'Missing permission.'),
            'valuations' => fn () => abort_unless($user->hasAnyPermission(['reports.view', 'reports.export', 'valuation.view_history']), 403, 'Missing permission.'),
            'discoveries' => fn () => abort_unless($user->hasAnyPermission(['reports.view', 'reports.export', 'discovery.view', 'discovery.create']), 403, 'Missing permission.'),
            'payment-queue' => fn () => abort_unless($user->hasAnyPermission(['reports.view', 'reports.export', 'payments.view_queue', 'payments.view_history']), 403, 'Missing permission.'),
        ];

        abort_unless(isset($guards[$kind]), 422, 'Unknown report kind.');
        $guards[$kind]();

        $format = $request->query('format', 'csv');
        abort_unless(in_array($format, ['csv', 'pdf'], true), 422, 'format must be csv or pdf.');

        [$title, $headers, $rows] = $this->dataset($kind, $request);
        $kpis = $this->summarize($kind, $request);

        $filename = 'retd_'.$kind.'_'.date('Ymd_His').'.'.$format;

        if ($format === 'csv') {
            $csv = $this->toCsv($title, $headers, $rows, $user, $request, $kpis);

            return response($csv)
                ->header('Content-Type', 'text/csv; charset=UTF-8')
                ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
        }

        $html = $this->toPdfHtml($title, $headers, $rows, $user, $request, $kpis);

        return Pdf::setOption('isPhpEnabled', true)
            ->loadHTML($html)->setPaper('a4', 'landscape')->download($filename);
    }

    /**
     * KPI block for the export header — recomputed from the same filtered
     * dataset as the report so the exported summary equals the on-screen one.
     */
    /**
     * KPI block for the export header — recomputed from the SAME filtered
     * dataset as the report rows, so the exported summary equals the on-screen
     * figures (Req: Dashboard Value = Query Result = Underlying Records).
     */
    private function summarize(string $kind, Request $request): array
    {
        $kpis = [['label' => 'Generated by', 'value' => $request->user()->full_name ?? 'System']];

        if ($kind === 'bills') {
            $query = PropertyBill::query();
            $this->applyDateFilter($request, $query, 'date_logged');
            $this->applyReportFilters($request, $query, 'bills');
            $kpis[] = ['label' => 'Total bills', 'value' => (string) (clone $query)->count()];
            $kpis[] = ['label' => 'Total tax due', 'value' => '$'.number_format((float) ($clone = clone $query)->sum('total_tax_due'), 2)];
            $kpis[] = ['label' => 'Outstanding balance', 'value' => '$'.number_format((float) (clone $query)->sum('outstanding_balance'), 2)];
            $kpis[] = ['label' => 'Paid', 'value' => (string) (clone $query)->where('payment_status', 'Paid')->count()];
            $kpis[] = ['label' => 'Awaiting assignment', 'value' => (string) (clone $query)->where('case_status', 'Awaiting Assignment')->count()];
        } elseif ($kind === 'collections') {
            $query = Payment::query();
            $this->applyDateFilter($request, $query, 'verified_at');
            $this->applyReportFilters($request, $query, 'collections');
            $kpis[] = ['label' => 'Verified amount', 'value' => '$'.number_format((float) (clone $query)->sum('amount'), 2)];
            $kpis[] = ['label' => 'Records', 'value' => (string) (clone $query)->count()];
        } elseif ($kind === 'enforcement') {
            $query = Task::query()->where('section', 'Enforcement');
            $this->applyDateFilter($request, $query, 'created_at');
            $this->applyReportFilters($request, $query, 'enforcement');
            $kpis[] = ['label' => 'Total tasks', 'value' => (string) (clone $query)->count()];
            $kpis[] = ['label' => 'Assigned', 'value' => (string) (clone $query)->where('status', 'Assigned')->count()];
            $kpis[] = ['label' => 'Delivered/settled', 'value' => (string) (clone $query)->whereIn('status', ['Delivered', 'Resolved', 'Closed'])->count()];
            $kpis[] = ['label' => 'Escalated', 'value' => (string) (clone $query)->where('status', 'Escalated')->count()];
        } elseif ($kind === 'valuations') {
            $query = Valuation::query();
            $this->applyDateFilter($request, $query, 'created_at');
            $this->applyReportFilters($request, $query, 'valuations');
            $kpis[] = ['label' => 'Total valuations', 'value' => (string) (clone $query)->count()];
            $kpis[] = ['label' => 'Assessed value', 'value' => '$'.number_format((float) (clone $query)->sum('assessed_value'), 2)];
            $kpis[] = ['label' => 'In review', 'value' => (string) (clone $query)->whereIn('status', ['Submitted', 'Manager Review'])->count()];
            $kpis[] = ['label' => 'AC approval', 'value' => (string) (clone $query)->where('status', 'AC Approval')->count()];
        } elseif ($kind === 'discoveries') {
            $query = PropertyDiscovery::query();
            $this->applyDateFilter($request, $query, 'discovery_date');
            $this->applyReportFilters($request, $query, 'discoveries');
            $kpis[] = ['label' => 'Total discoveries', 'value' => (string) (clone $query)->count()];
            $kpis[] = ['label' => 'Requiring valuation', 'value' => (string) (clone $query)->where('decision_path', 'valuation')->whereIn('status', ['VALUATION_REQUIRED', 'VALUATION_ASSIGNED', 'UNDER_VALUATION', 'VALUATION_MANAGER_REVIEW', 'PENDING_AC_APPROVAL'])->count()];
            $kpis[] = ['label' => 'Awaiting AC approval', 'value' => (string) (clone $query)->where('status', 'PENDING_AC_APPROVAL')->count()];
            $kpis[] = ['label' => 'Registered in LITAS', 'value' => (string) (clone $query)->whereNotNull('property_id')->where('property_id', '!=', '')->count()];
        } else {
            $query = PaymentVerification::query();
            $this->applyDateFilter($request, $query, 'created_at');
            $this->applyReportFilters($request, $query, 'payment-queue');
            $kpis[] = ['label' => 'Pending', 'value' => (string) (clone $query)->where('verification_status', 'Pending')->count()];
            $kpis[] = ['label' => 'Verified', 'value' => (string) (clone $query)->whereIn('verification_status', ['Verified', 'Confirmed'])->count()];
            $kpis[] = ['label' => 'Amount claimed', 'value' => '$'.number_format((float) (clone $query)->sum('amount_claimed'), 2)];
        }

        return $kpis;
    }

    private function dataset(string $kind, Request $request): array
    {
        $titles = [
            'bills' => 'Property Bills Register',
            'collections' => 'Collections Register',
            'enforcement' => 'Enforcement Task Register',
            'valuations' => 'Valuations Register',
            'discoveries' => 'Property Discovery Register',
            'payment-queue' => 'Payment Verification Queue',
        ];
        $title = $titles[$kind];

        if ($kind === 'bills') {
            $query = PropertyBill::query();
            $this->applyDateFilter($request, $query, 'date_logged');
            $this->applyReportFilters($request, $query, 'bills');
            $rows = $query->orderBy('tin')->orderByDesc('id')->get()->map(fn ($b) => [
                'Property ID' => $b->property_id,
                'TIN' => $b->tin,
                'Reference' => $b->document_number ?? $b->bill_reference,
                'Taxpayer' => $b->taxpayer_name ?? $b->owner_name ?? $b->owner_id,
                'Total Due' => number_format((float) ($b->total_tax_due ?? 0), 2),
                'Payment Status' => $b->payment_status,
                'Recipient Type' => $b->recipient_type,
                'Status' => $b->case_status,
                'Date Logged' => $b->date_logged?->toDateString(),
            ])->toArray();

            return [$title, ['Property ID', 'TIN', 'Reference', 'Taxpayer', 'Total Due', 'Payment Status', 'Recipient Type', 'Status', 'Date Logged'], $rows];
        }

        if ($kind === 'collections') {
            $query = Payment::query()->with(['verifier:id,full_name', 'bill:id,property_id,tin']);
            $this->applyDateFilter($request, $query, 'verified_at');
            $this->applyReportFilters($request, $query, 'collections');
            $query->orderBy(
                \DB::raw('(SELECT `tin` FROM `property_bills` WHERE `property_bills`.`id` = `payments`.`bill_id` LIMIT 1)')
            );
            $rows = $query->orderByDesc('id')->get()->map(fn ($p) => [
                'Property ID' => $p->bill?->property_id,
                'TIN' => $p->bill?->tin,
                'Document #' => $p->document_number,
                'Amount' => number_format((float) $p->amount, 2),
                'Payment Period' => $p->payment_period,
                'Receipt #' => $p->receipt_number,
                'Verified By' => $p->relationLoaded('verifier') ? $p->verifier?->full_name : null,
                'Verified At' => $p->verified_at?->toISOString(),
            ])->toArray();

            return [$title, ['Property ID', 'TIN', 'Document #', 'Amount', 'Payment Period', 'Receipt #', 'Verified By', 'Verified At'], $rows];
        }

        if ($kind === 'enforcement') {
        $query = Task::query()->with(['assignedTo:id,full_name', 'bill:id,property_id,tin'])->where('section', 'Enforcement');
        $this->applyDateFilter($request, $query, 'created_at');
        $this->applyReportFilters($request, $query, 'enforcement');
        $query->orderBy(
            \DB::raw('(SELECT `tin` FROM `property_bills` WHERE `property_bills`.`id` = `tasks`.`reference_id` LIMIT 1)')
        );
            $rows = $query->get()->map(fn ($t) => [
                'Property ID' => $t->bill?->property_id,
                'TIN' => $t->bill?->tin,
                'Reference' => $t->task_reference,
                'Type' => $t->task_type,
                'Status' => $t->status,
                'Assigned To' => $t->relationLoaded('assignedTo') ? $t->assignedTo?->full_name : null,
                'Due Date' => $t->due_date?->toDateString(),
                'Created At' => $t->created_at?->toISOString(),
            ])->toArray();

            return [$title, ['Property ID', 'TIN', 'Reference', 'Type', 'Status', 'Assigned To', 'Due Date', 'Created At'], $rows];
        }

        if ($kind === 'valuations') {
            $query = Valuation::query()->with('valuationOfficer:id,full_name');
            $this->applyDateFilter($request, $query, 'created_at');
            $this->applyReportFilters($request, $query, 'valuations');
            $rows = $query->orderByDesc('id')->get()->map(fn ($v) => [
                'Reference' => $v->valuation_reference,
                'Property ID' => $v->property_id,
                'Owner' => $v->owner_name,
                'Status' => $v->status,
                'Assessed Value' => number_format((float) $v->assessed_value, 2),
                'Annual Tax' => number_format((float) $v->annual_tax, 2),
                'Officer' => $v->relationLoaded('valuationOfficer') ? $v->valuationOfficer?->full_name : null,
                'Created At' => $v->created_at?->toISOString(),
            ])->toArray();

            return [$title, ['Reference', 'Property ID', 'Owner', 'Status', 'Assessed Value', 'Annual Tax', 'Officer', 'Created At'], $rows];
        }

        if ($kind === 'discoveries') {
            $query = PropertyDiscovery::query()->with('discoverer:id,full_name');
            $this->applyDateFilter($request, $query, 'discovery_date');
            $this->applyReportFilters($request, $query, 'discoveries');
            $rows = $query->orderByDesc('id')->get()->map(fn ($d) => [
                'Reference' => $d->discovery_reference,
                'LITAS Property ID' => $d->property_id ?: '— (unregistered)',
                'TIN' => $d->tin ?: '—',
                'Owner' => $d->owner_name ?: '—',
                'Address' => $d->property_address ?: '—',
                'Classification' => $d->property_classification ?: '—',
                'Path' => $d->decision_path ?: '—',
                'Status' => $d->status,
                'Discovered By' => $d->relationLoaded('discoverer') ? $d->discoverer?->full_name : '—',
                'Date' => $d->discovery_date?->toDateString(),
            ])->toArray();

            return [$title, ['Reference', 'LITAS Property ID', 'TIN', 'Owner', 'Address', 'Classification', 'Path', 'Status', 'Discovered By', 'Date'], $rows];
        }

        $query = PaymentVerification::query()->with('verifier:id,full_name');
        $this->applyDateFilter($request, $query, 'created_at');
        $this->applyReportFilters($request, $query, 'payment-queue');
        $rows = $query->orderBy('tin')->orderByDesc('id')->get()->map(fn ($v) => [
            'Property ID' => $v->property_id,
            'TIN' => $v->tin,
            'Document #' => $v->document_number,
            'Receipt #' => $v->receipt_number,
            'Amount Claimed' => number_format((float) $v->amount_claimed, 2),
            'Match Status' => $v->match_status,
            'Verification Status' => $v->verification_status,
            'Verified By' => $v->relationLoaded('verifier') ? $v->verifier?->full_name : null,
            'Created At' => $v->created_at?->toISOString(),
        ])->toArray();

        return [$title, ['Property ID', 'TIN', 'Document #', 'Receipt #', 'Amount Claimed', 'Match Status', 'Verification Status', 'Verified By', 'Created At'], $rows];
    }

    /**
     * Additional report filters (beyond the shared date range) — applied both
     * to the on-screen list and to CSV/PDF exports.
     */
    private function applyReportFilters(Request $request, $query, string $kind)
    {
        $map = [
            'bills' => [
                'case_status' => 'case_status',
                'payment_status' => 'payment_status',
                'recipient_type' => 'recipient_type',
                'logged_by' => 'account_staff_id',
                'assigned_to' => 'assigned_enforcement_officer_id',
            ],
            'collections' => [
                'verified_by' => 'verified_by',
            ],
            'enforcement' => [
                'status' => 'status',
                'assigned_to' => 'assigned_to',
            ],
            'valuations' => [
                'status' => 'status',
                'valuation_officer' => 'valuation_officer_id',
            ],
            'discoveries' => [
                'status' => 'status',
                'decision_path' => 'decision_path',
                'discovered_by' => 'discovered_by',
                'property_classification' => 'property_classification',
            ],
            'payment-queue' => [
                'verification_status' => 'verification_status',
                'match_status' => 'match_status',
            ],
        ];

        foreach ($map[$kind] ?? [] as $param => $column) {
            $value = $request->query($param);
            if ($value !== null && $value !== '') {
                $query->where($column, $value);
            }
        }
    }

    private function filterContext(Request $request): string
    {
        $parts = [];
        foreach (['filter', 'date', 'month', 'quarter', 'year', 'start_date', 'end_date', 'status', 'case_status', 'payment_status', 'recipient_type', 'logged_by', 'assigned_to', 'verified_by', 'assigned_to_id', 'valuation_officer', 'verification_status', 'match_status'] as $key) {
            if ($request->filled($key)) {
                $parts[] = "{$key}={$request->query($key)}";
            }
        }

        return $parts ? implode(' | ', $parts) : 'All records';
    }

    private function exportTitle(string $title, Request $request): string
    {
        $context = $this->filterContext($request);

        return $title.' — '.$context;
    }

    private function toCsv(string $title, array $headers, array $rows, $user, Request $request, array $kpis = []): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [$this->exportTitle($title, $request)]);
        fputcsv($out, ['Generated '.now('Africa/Monrovia')->format('Y-m-d H:i').' by '.$user->full_name]);
        fputcsv($out, ['Endpoint', $request->path()]);
        fputcsv($out, ['Summary: '.count($rows).' record(s)', 'Filters: '.$this->filterContext($request)]);
        fputcsv($out, []);
        if ($kpis) {
            fputcsv($out, ['KPI', 'Value']);
            foreach ($kpis as $k) {
                fputcsv($out, [$k['label'], $k['value']]);
            }
            fputcsv($out, []);
        }
        fputcsv($out, $headers);
        foreach ($rows as $row) {
            fputcsv($out, array_values($row));
        }
        fputcsv($out, []);
        fputcsv($out, ['Total records', count($rows)]);
        rewind($out);

        return stream_get_contents($out);
    }

    private function toPdfHtml(string $title, array $headers, array $rows, $user, Request $request, array $kpis = []): string
    {
        $heading = $this->exportTitle($title, $request);
        $meta = 'Generated '.now('Africa/Monrovia')->format('Y-m-d H:i').' by '.e($user->full_name ?? 'System');
        $meta .= ' · Endpoint '.$request->path().' · Summary: '.count($rows).' record(s) · Filters: '.$this->filterContext($request);

        $head = '<tr>'.collect($headers)->map(fn ($h) => '<th>'.e($h).'</th>')->join('').'</tr>';
        $body = collect($rows)->map(fn ($row) => '<tr>'.collect($row)->map(fn ($v) => '<td>'.e((string) ($v ?? '')).'</td>')->join('').'</tr>')->join('');

        $kpiBand = collect($kpis)->map(fn ($k) => '<div style="display:inline-block;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:6px 10px;margin:0 4px 6px 0"><span style="color:#475569;font-size:8px;display:block">'.e($k['label']).'</span><span style="font-size:12px;font-weight:800;color:#0f172a">'.e((string) $k['value']).'</span></div>')->join('');

        $html = <<<'HTML'
        <!DOCTYPE html>
        <html><head><meta charset="utf-8"><style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2937; }
        h1 { font-size: 15px; margin: 0 0 2px 0; }
        .meta { font-size: 9px; color: #6b7280; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #111827; color: #fff; padding: 5px 6px; text-align: left; font-size: 9px; }
        td { padding: 4px 6px; border-bottom: 1px solid #e5e7eb; }
        tr:nth-child(even) td { background: #f9fafb; }
        </style></head>
        <body>
        <h1>__TITLE__</h1>
        <div class="meta">__META__</div>
        <div>__KPIBAND__</div>
        <table>__HEAD____BODY__</table>
        <div class="meta">Total records: __COUNT__</div>
        <script type="text/php">
            if (isset($pdf)) {
                $font = $fontMetrics->get_font("DejaVu Sans", "normal");
                $pdf->page_text(400, $pdf->get_height() - 16, "Page {PAGE_NUM} of {PAGE_COUNT}", $font, 8, array(0.35, 0.41, 0.51));
                $pdf->page_text(30, $pdf->get_height() - 16, "RETD Report", $font, 8, array(0.35, 0.41, 0.51));
            }
        </script>
        </body></html>
        HTML;

        return strtr($html, [
            '__TITLE__' => $this->escape($heading),
            '__META__' => $meta,
            '__KPIBAND__' => $kpiBand,
            '__HEAD__' => $head,
            '__BODY__' => $body,
            '__COUNT__' => $this->escape((string) count($rows)),
        ]);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
