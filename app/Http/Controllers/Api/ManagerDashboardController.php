<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ManagerDashboardService;
use Illuminate\Http\Request;

/**
 * Role-specific Manager dashboards.
 *
 *   - Account & Record Manager (ACCT)      → account lifecycle, financial,
 *     payment verification, amendment review, staff workload.
 *   - Valuation Manager (VAL)              → discovery pipeline, valuations,
 *     AC approval, GPS/evidence quality, review queue.
 *   - Enforcement Manager (ENF)            → bill lifecycle, delivery,
 *     field activity, notices, escalation, payment security.
 *
 * Each endpoint is served by the SAME business logic (ManagerDashboardService)
 * so web and mobile stay in parity. All figures are computed from the
 * authenticated user's authorization-scoped dataset — nothing hardcoded.
 *
 * RBAC gate: only the three manager roles can reach these endpoints.
 */
class ManagerDashboardController extends Controller
{
    public const ACCOUNT_ROLES = ['Account Manager'];

    public const VALUATION_ROLES = ['Valuation Manager'];

    public const ENFORCEMENT_ROLES = ['Enforcement Manager', 'Enforcement Supervisor'];

    public function account(Request $request)
    {
        return $this->serve($request, 'account');
    }

    public function valuation(Request $request)
    {
        return $this->serve($request, 'valuation');
    }

    public function enforcement(Request $request)
    {
        return $this->serve($request, 'enforcement');
    }

    private function serve(Request $request, string $type)
    {
        $user = $request->user();
        abort_unless($this->typeFor($user) === $type, 403, 'This dashboard is only available to the corresponding manager role.');

        $service = app(ManagerDashboardService::class);

        return response()->json([
            'data' => match ($type) {
                'account' => $service->forAccount($user),
                'valuation' => $service->forValuation($user),
                'enforcement' => $service->forEnforcement($user),
            },
        ]);
    }

    private function typeFor(User $user): ?string
    {
        $role = $user->role?->name;

        return match (true) {
            in_array($role, self::ACCOUNT_ROLES, true) => 'account',
            in_array($role, self::VALUATION_ROLES, true) => 'valuation',
            in_array($role, self::ENFORCEMENT_ROLES, true) => 'enforcement',
            default => null,
        };
    }
}
