<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EnforcementOrgRecommendation;
use App\Services\AuditService;
use App\Services\EnforcementOrganizationService;
use Illuminate\Http\Request;

class EnforcementOrganizationController extends Controller
{
    public function __construct(
        private readonly EnforcementOrganizationService $organization,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission([
            'enforcement.org.manage',
            'enforcement.org.recommend',
            'enforcement.org.review',
        ]), 403, 'Missing Enforcement organization permission.');

        return response()->json(['data' => $this->organization->index($user)]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['enforcement.org.manage', 'enforcement.org.recommend']), 403);

        $data = $request->all();
        $recommendation = $this->organization->submit($user, $data);
        if ($recommendation->status === 'pending') {
            $this->audit->record($recommendation, 'enforcement.organization.submitted', $user->id, [], [
                'change_type' => $recommendation->change_type,
                'team_id' => $recommendation->team_id,
                'payload' => $recommendation->payload,
            ], $request->ip());
        }

        return response()->json([
            'data' => [
                'id' => $recommendation->id,
                'change_type' => $recommendation->change_type,
                'status' => $recommendation->status,
                'submitted_at' => $recommendation->submitted_at?->toISOString(),
            ],
            'message' => $recommendation->status === 'approved'
                ? 'Organization change applied by the Enforcement Manager.'
                : 'Organization change submitted for review.',
        ], 201);
    }

    public function approve(Request $request, EnforcementOrgRecommendation $recommendation)
    {
        abort_unless($request->user()->hasAnyPermission(['enforcement.org.manage', 'enforcement.org.review']), 403);
        $result = $this->organization->approve($request->user(), $recommendation);

        return response()->json([
            'data' => [
                'id' => $result->id,
                'status' => $result->status,
                'applied_record_id' => $result->applied_record_id,
                'previous_values' => $result->previous_values,
            ],
            'message' => 'Organization change approved and applied.',
        ]);
    }

    public function reject(Request $request, EnforcementOrgRecommendation $recommendation)
    {
        abort_unless($request->user()->hasAnyPermission(['enforcement.org.manage', 'enforcement.org.review']), 403);
        $data = $request->validate(['notes' => ['required', 'string', 'max:2000']]);
        $result = $this->organization->reject($request->user(), $recommendation, $data['notes']);

        return response()->json([
            'data' => [
                'id' => $result->id,
                'status' => $result->status,
                'decision_notes' => $result->decision_notes,
            ],
            'message' => 'Organization change rejected.',
        ]);
    }
}
