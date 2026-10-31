<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardDataService;
use Illuminate\Http\Request;

/**
 * Dashboard data endpoint.
 *
 * Returns a single authoritative payload derived from the
 * DashboardDataService pipeline — KPIs, charts, tables, and drill state
 * all come from the same filtered, RBAC-scoped dataset so widgets cannot
 * drift into inconsistency.
 *
 * Expected query params (all optional; defaults supplied by the service):
 *   year, quarter, month, section, staff_id, task_type,
 *   property_classification, status, date_range, etc.
 *
 * The service.run() method does the heavy lifting:
 *   1. Resolve filter context + user scope.
 *   2. Build one authoritative dataset.
 *   3. Derive KPIs from that dataset.
 *   4. Derive chart data from that dataset.
 *   5. Derive table rows from that dataset.
 *   6. Derive drill-down state from that dataset.
 *
 * The frontend must NOT independently query or calculate dashboard totals.
 */
class DashboardDataController extends Controller
{
    protected $service;

    public function __construct()
    {
        $this->service = new DashboardDataService;
    }

    public function data(Request $request)
    {
        // Extract filter overrides from the request.
        $filters = $request->only([
            'year', 'quarter', 'month', 'section_id', 'staff_id',
            'task_type', 'property_classification', 'status',
            'date_range', 'sort', 'direction', 'per_page',
        ]);

        // Allow the service to fill in defaults (e.g. current year/quarter/month).
        $payload = $this->service->run($filters);

        return response()->json(['data' => $payload]);
    }
}
