<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ManagerDashboardService;
use Illuminate\Http\Request;

/**
 * Assistant Commissioner dashboard.
 *
 * The AC holds the `division` scope, so this is the single leadership view
 * that aggregates every section (Account & Record, Valuation and Enforcement)
 * into one RETD-wide overview. The payload follows the exact same contract as
 * the manager dashboards (KPIs, financial, donuts, pipeline, workload, charts,
 * priority, activity, alerts, quick_actions) so web and mobile render it
 * through the same surfaces.
 *
 * RBAC gate: only the Assistant Commissioner role can reach this endpoint.
 */
class AssistantCommissionerDashboardController extends Controller
{
    public function overview(Request $request)
    {
        $user = $request->user();
        abort_unless($user->role?->name === 'Assistant Commissioner', 403, 'This dashboard is only available to the Assistant Commissioner role.');

        return response()->json([
            'data' => app(ManagerDashboardService::class)->forAssistantCommissioner($user),
        ]);
    }
}
