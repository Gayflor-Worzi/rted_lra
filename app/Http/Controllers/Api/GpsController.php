<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EnforcementVisit;
use App\Models\GpsLocation;
use App\Models\GpsLocationHistory;
use App\Models\PropertyBill;
use App\Models\Task;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GPS property-location API.
 *
 * Reading                      gps.view / gps.view_history
 * Field capture + certify      gps.capture (authorized engagement) + gps.certify
 * Direct official update       gps.update (authorized correction)
 * Review / decision            gps.review + gps.approve
 *
 * Rules honoured:
 *  - A field capture NEVER silently replaces the official location. It becomes
 *    the official location only through explicit certification (gps.update) or
 *    an approved gps.approve decision.
 *  - "No — not the actual property" keeps the captured coordinate on its
 *    engagement record and records the decision immutably in history.
 *  - Every certification event is appended to gps_location_history with the
 *    previous official coordinate preserved for audit/rollback.
 */
class GpsController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    // ------------------------------------------------------------------
    // Read endpoints
    // ------------------------------------------------------------------

    /** Official location + recent engagement captures + summary for one property. */
    public function current(Request $request, string $propertyId)
    {
        $user = $request->user();
        abort_unless($user->canPermission('gps.view'), 403, 'Missing permission: gps.view');
        $this->assertCanAccessProperty($user, $propertyId);

        $official = GpsLocation::where('property_id', $propertyId)->first();

        $recentCaptures = EnforcementVisit::query()
            ->where('property_id', $propertyId)
            ->whereNotNull('gps_coordinate')
            ->orderByDesc('id')
            ->limit(5)
            ->get([
                'id', 'visit_reference', 'visit_date',
                'gps_coordinate', 'gps_accuracy', 'gps_captured_at',
                'gps_verified', 'gps_certified_at', 'officer_id',
            ]);

        $pendingCount = GpsLocationHistory::where('property_id', $propertyId)
            ->where('verification_status', 'pending_review')
            ->count();

        return response()->json(['data' => [
            'property_id' => $propertyId,
            'official' => $official ? $this->formatLocation($official) : null,
            'pending_count' => $pendingCount,
            'recent_captures' => $recentCaptures,
        ]]);
    }

    public function history(Request $request, string $propertyId)
    {
        $user = $request->user();
        abort_unless($user->canPermission('gps.view_history'), 403, 'Missing permission: gps.view_history');
        $this->assertCanAccessProperty($user, $propertyId);

        $items = GpsLocationHistory::query()
            ->where('property_id', $propertyId)
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json(['data' => $items->items(), 'meta' => [
            'property_id' => $propertyId,
            'current_page' => $items->currentPage(),
            'per_page' => $items->perPage(),
            'total' => $items->total(),
        ]]);
    }

    /** Field-app helper: official GPS + the caller's open engagements for a property. */
    public function captureContext(Request $request, string $propertyId)
    {
        $user = $request->user();
        abort_unless($user->canPermission('gps.capture'), 403, 'Missing permission: gps.capture');
        $this->assertCanAccessProperty($user, $propertyId);

        $official = GpsLocation::where('property_id', $propertyId)->first();

        $openTasks = Task::query()
            ->where('assigned_to', $user->id)
            ->whereIn('status', ['Assigned', 'Out for Delivery'])
            ->where('reference_type', 'property_bill')
            ->whereIn('reference_id', PropertyBill::where('property_id', $propertyId)->pluck('id'))
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (Task $t) => [
                'task_id' => $t->id,
                'reference' => $t->reference ?? null,
                'status' => $t->status,
                'due_date' => $t->due_date?->toDateString(),
            ]);

        $openVisits = EnforcementVisit::query()
            ->where('property_id', $propertyId)
            ->where('officer_id', $user->id)
            ->whereNull('gps_verified')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (EnforcementVisit $v) => [
                'visit_id' => $v->id,
                'visit_reference' => $v->visit_reference,
                'visit_date' => $v->visit_date?->toDateString(),
                'gps_coordinate' => $v->gps_coordinate,
                'gps_certified' => false,
            ]);

        return response()->json(['data' => [
            'property_id' => $propertyId,
            'official' => $this->formatLocation($official),
            'open_tasks' => $openTasks,
            'open_visits' => $openVisits,
        ]]);
    }

    /** Pending certifications awaiting review/approval. */
    public function pendingReviews(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['gps.review', 'gps.approve']), 403, 'Missing permission: gps.review');

        $items = GpsLocationHistory::query()
            ->with(['capturedBy:id,full_name', 'certifiedBy:id,full_name'])
            ->where('verification_status', 'pending_review')
            ->when(! $user->canPermission('gps.approve'), function (Builder $q) use ($user) {
                // reviewers without approve power only see candidates they certified
                $q->where('certified_by', $user->id);
            })
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $items]);
    }

    // ------------------------------------------------------------------
    // Write endpoints
    // ------------------------------------------------------------------

    /** Field certification — "is this capture the actual property location?" */
    public function certify(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canPermission('gps.capture'), 403, 'Missing permission: gps.capture');
        abort_unless($user->canPermission('gps.certify'), 403, 'Missing permission: gps.certify');

        $data = $request->validate([
            'property_id' => 'required|string|max:60',
            'tin' => 'nullable|string|max:50',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'accuracy' => 'nullable|numeric|min:0',
            'certification_decision' => 'required|in:yes,no',
            'remarks' => 'required_if:certification_decision,no|nullable|string|max:2000',
            'source' => 'required|in:enforcement_visit,valuation,discovery,correction,other',
            'task_id' => 'nullable|integer|exists:tasks,id',
            'visit_id' => 'nullable|integer|exists:enforcement_visits,id',
            'valuation_id' => 'nullable|integer|exists:valuations,id',
            'discovery_id' => 'nullable|integer|exists:property_discoveries,id',
            'captured_at' => 'nullable|date',
            'device_metadata' => 'nullable|array',
        ]);

        $this->assertCanCertifyEngagement($user, $data);

        $capturedAt = $data['captured_at'] ?? now();
        $device = isset($data['device_metadata']) ? json_encode($data['device_metadata']) : null;

        $official = GpsLocation::where('property_id', $data['property_id'])->first();

        // Mirror the certification decision onto the linked field engagement.
        if (! empty($data['visit_id'])) {
            $visit = EnforcementVisit::findOrFail($data['visit_id']);
            $visit->update([
                'gps_verified' => $data['certification_decision'],
                'gps_certified_by' => $user->id,
                'gps_certified_at' => now(),
                'gps_cert_remarks' => $data['remarks'] ?? null,
            ]);
            if (! $visit->gps_coordinate) {
                $visit->update([
                    'gps_coordinate' => trim($data['latitude'].','.$data['longitude']),
                    'gps_accuracy' => $data['accuracy'] ?? null,
                ]);
            }
        }

        if (! empty($data['valuation_id'])) {
            $valuation = \App\Models\Valuation::findOrFail($data['valuation_id']);
            $valuation->update([
                'gps_verified' => $data['certification_decision'],
                'gps_certified_by' => $user->id,
                'gps_certified_at' => now(),
                'gps_cert_remarks' => $data['remarks'] ?? null,
            ]);
            if (! $valuation->gps_coordinate) {
                $valuation->update([
                    'gps_coordinate' => trim($data['latitude'].','.$data['longitude']),
                    'gps_accuracy' => $data['accuracy'] ?? null,
                ]);
            }
        }

        if (! empty($data['discovery_id'])) {
            $discovery = \App\Models\PropertyDiscovery::findOrFail($data['discovery_id']);
            $discovery->update([
                'gps_verified' => $data['certification_decision'],
                'gps_certified_by' => $user->id,
                'gps_certified_at' => now(),
                'gps_cert_remarks' => $data['remarks'] ?? null,
            ]);
            if (! $discovery->gps_coordinate) {
                $discovery->update([
                    'gps_coordinate' => trim($data['latitude'].','.$data['longitude']),
                    'gps_accuracy' => $data['accuracy'] ?? null,
                ]);
            }
        }

        // "No — not the actual property": keep capture, record rejection, never touch official.
        if ($data['certification_decision'] === 'no') {
            $history = GpsLocationHistory::create([
                'property_id' => $data['property_id'],
                'tin' => $data['tin'] ?? null,
                'previous_latitude' => $official?->latitude,
                'previous_longitude' => $official?->longitude,
                'new_latitude' => $data['latitude'],
                'new_longitude' => $data['longitude'],
                'accuracy' => $data['accuracy'] ?? null,
                'source' => $data['source'],
                'verification_status' => 'not_property',
                'certification_decision' => 'no',
                'remarks' => $data['remarks'],
                'captured_by' => $user->id,
                'certified_by' => $user->id,
                'task_id' => $data['task_id'] ?? null,
                'visit_id' => $data['visit_id'] ?? null,
                'valuation_id' => $data['valuation_id'] ?? null,
                'discovery_id' => $data['discovery_id'] ?? null,
                'device_metadata' => $device,
                'captured_at' => $capturedAt,
                'certified_at' => now(),
            ]);

            $this->audit->record($history, 'gps.certified_not_property', $user->id, [], [
                'property_id' => $data['property_id'],
                'visit_id' => $data['visit_id'] ?? null,
                'remarks' => $data['remarks'],
            ]);

            return response()->json(['data' => [
                'property_id' => $data['property_id'],
                'decision' => 'no',
                'official_unchanged' => true,
                'history_id' => $history->id,
                'message' => 'Captured location recorded as not the actual property. Official GPS unchanged.',
            ]], 201);
        }

        // "Yes — this is the property." Certification may auto-apply (gps.update)
        // or queue for supervisory approval (pending_review).
        $canUpdate = $user->canPermission('gps.update');

        $history = DB::transaction(function () use ($data, $user, $official, $capturedAt, $device, $canUpdate) {
            $history = GpsLocationHistory::create([
                'property_id' => $data['property_id'],
                'tin' => $data['tin'] ?? null,
                'previous_latitude' => $official?->latitude,
                'previous_longitude' => $official?->longitude,
                'new_latitude' => $data['latitude'],
                'new_longitude' => $data['longitude'],
                'accuracy' => $data['accuracy'] ?? null,
                'source' => $data['source'],
                'verification_status' => $canUpdate ? 'approved' : 'pending_review',
                'certification_decision' => 'yes',
                'remarks' => $data['remarks'] ?? null,
                'captured_by' => $user->id,
                'certified_by' => $user->id,
                'reviewed_by' => $canUpdate ? $user->id : null,
                'task_id' => $data['task_id'] ?? null,
                'visit_id' => $data['visit_id'] ?? null,
                'valuation_id' => $data['valuation_id'] ?? null,
                'discovery_id' => $data['discovery_id'] ?? null,
                'device_metadata' => $device,
                'captured_at' => $capturedAt,
                'certified_at' => now(),
                'reviewed_at' => $canUpdate ? now() : null,
            ]);

            if ($canUpdate) {
                $this->applyOfficialLocation($history, $user);
            }

            return $history;
        });

        $this->audit->record($history, $canUpdate ? 'gps.location_certified' : 'gps.certification_pending', $user->id, [], [
            'property_id' => $data['property_id'],
            'visit_id' => $data['visit_id'] ?? null,
            'verification_status' => $history->verification_status,
        ]);

        return response()->json(['data' => [
            'property_id' => $data['property_id'],
            'decision' => 'yes',
            'verification_status' => $history->verification_status,
            'history_id' => $history->id,
            'official' => $this->formatLocation(GpsLocation::where('property_id', $data['property_id'])->first()),
            'message' => $canUpdate
                ? 'Location certified and applied as the official property GPS.'
                : 'Location certified. Awaiting supervisory approval before it becomes the official GPS.',
        ]], 201);
    }

    /** Approve or reject a pending certification. */
    public function decision(Request $request, int $historyId)
    {
        $user = $request->user();
        abort_unless($user->canPermission('gps.approve'), 403, 'Missing permission: gps.approve');

        $history = GpsLocationHistory::findOrFail($historyId);
        abort_if($history->verification_status !== 'pending_review', 422, 'Only pending certifications can be decided.');

        $data = $request->validate([
            'decision' => 'required|in:approve,reject',
            'remarks' => 'nullable|string|max:2000',
        ]);

        DB::transaction(function () use ($history, $user, $data) {
            if ($data['decision'] === 'approve') {
                $this->applyOfficialLocation($history, $user);
                $history->update([
                    'verification_status' => 'approved',
                    'reviewed_by' => $user->id,
                    'approved_by' => $user->id,
                    'reviewed_at' => now(),
                    'remarks' => $data['remarks'] ?? $history->remarks,
                ]);
            } else {
                $history->update([
                    'verification_status' => 'rejected',
                    'reviewed_by' => $user->id,
                    'approved_by' => $user->id,
                    'reviewed_at' => now(),
                    'remarks' => $data['remarks'] ?? $history->remarks,
                ]);
            }
        });

        $this->audit->record($history, 'gps.certification_'.$data['decision'], $user->id, [], [
            'property_id' => $history->property_id,
            'decision' => $data['decision'],
            'remarks' => $data['remarks'] ?? null,
        ]);

        return response()->json(['data' => [
            'history_id' => $history->id,
            'verification_status' => $history->fresh()->verification_status,
            'official' => $this->formatLocation(GpsLocation::where('property_id', $history->property_id)->first()),
            'message' => $data['decision'] === 'approve'
                ? 'Certification approved. Official GPS updated.'
                : 'Certification rejected. Official GPS unchanged.',
        ]]);
    }

    /** Authorized manual correction of the official property GPS (gps.update). */
    public function correct(Request $request, string $propertyId)
    {
        $user = $request->user();
        abort_unless($user->canPermission('gps.update'), 403, 'Missing permission: gps.update');
        $this->assertCanAccessProperty($user, $propertyId);

        $data = $request->validate([
            'tin' => 'nullable|string|max:50',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'accuracy' => 'nullable|numeric|min:0',
            'remarks' => 'required|string|max:2000',
        ]);

        $official = GpsLocation::where('property_id', $propertyId)->first();

        $history = DB::transaction(function () use ($data, $user, $official, $propertyId) {
            $history = GpsLocationHistory::create([
                'property_id' => $propertyId,
                'tin' => $data['tin'] ?? $official?->tin,
                'previous_latitude' => $official?->latitude,
                'previous_longitude' => $official?->longitude,
                'new_latitude' => $data['latitude'],
                'new_longitude' => $data['longitude'],
                'accuracy' => $data['accuracy'] ?? null,
                'source' => 'correction',
                'verification_status' => 'approved',
                'certification_decision' => 'yes',
                'remarks' => $data['remarks'],
                'captured_by' => $user->id,
                'certified_by' => $user->id,
                'reviewed_by' => $user->id,
                'approved_by' => $user->id,
                'certified_at' => now(),
                'reviewed_at' => now(),
            ]);

            if ($official) {
                $official->update([
                    'latitude' => $data['latitude'],
                    'longitude' => $data['longitude'],
                    'accuracy' => $data['accuracy'] ?? null,
                    'tin' => $data['tin'] ?? $official->tin,
                    'source' => 'correction',
                    'certified_by' => $user->id,
                    'reviewed_by' => $user->id,
                    'approved_by' => $user->id,
                    'captured_at' => now(),
                ]);
            } else {
                GpsLocation::create([
                    'property_id' => $propertyId,
                    'tin' => $data['tin'] ?? null,
                    'latitude' => $data['latitude'],
                    'longitude' => $data['longitude'],
                    'accuracy' => $data['accuracy'] ?? null,
                    'source' => 'correction',
                    'verification_status' => 'verified',
                    'captured_by' => $user->id,
                    'certified_by' => $user->id,
                    'reviewed_by' => $user->id,
                    'approved_by' => $user->id,
                    'captured_at' => now(),
                ]);
            }

            return $history;
        });

        $this->audit->record($history, 'gps.location_corrected', $user->id, [], ['property_id' => $propertyId]);

        return response()->json(['data' => [
            'property_id' => $propertyId,
            'official' => $this->formatLocation(GpsLocation::where('property_id', $propertyId)->first()),
            'message' => 'Official GPS corrected.',
        ]]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function formatLocation(?GpsLocation $location): ?array
    {
        if (! $location) {
            return null;
        }

        return [
            'property_id' => $location->property_id,
            'tin' => $location->tin,
            'latitude' => $location->latitude,
            'longitude' => $location->longitude,
            'accuracy' => $location->accuracy,
            'source' => $location->source,
            'verification_status' => $location->verification_status,
            'captured_by' => $location->captured_by,
            'certified_by' => $location->certified_by,
            'approved_by' => $location->approved_by,
            'captured_at' => $location->captured_at?->toIso8601String(),
            'updated_at' => $location->updated_at?->toIso8601String(),
        ];
    }

    /** Apply the coordinates of a certified history row onto the official location. */
    private function applyOfficialLocation(GpsLocationHistory $history, User $user): void
    {
        $official = GpsLocation::where('property_id', $history->property_id)->first();

        if ($official) {
            $official->update([
                'latitude' => $history->new_latitude,
                'longitude' => $history->new_longitude,
                'accuracy' => $history->accuracy,
                'tin' => $history->tin ?? $official->tin,
                'source' => $history->source,
                'verification_status' => 'verified',
                'captured_by' => $history->captured_by ?? $user->id,
                'certified_by' => $user->id,
                'reviewed_by' => $user->id,
                'approved_by' => $history->approved_by ?? $user->id,
                'task_id' => $history->task_id,
                'visit_id' => $history->visit_id,
                'valuation_id' => $history->valuation_id,
                'discovery_id' => $history->discovery_id,
                'captured_at' => $history->captured_at,
            ]);
        } else {
            GpsLocation::create([
                'property_id' => $history->property_id,
                'tin' => $history->tin,
                'latitude' => $history->new_latitude,
                'longitude' => $history->new_longitude,
                'accuracy' => $history->accuracy,
                'source' => $history->source,
                'verification_status' => 'verified',
                'captured_by' => $history->captured_by ?? $user->id,
                'certified_by' => $user->id,
                'reviewed_by' => $user->id,
                'approved_by' => $history->approved_by ?? $user->id,
                'task_id' => $history->task_id,
                'visit_id' => $history->visit_id,
                'valuation_id' => $history->valuation_id,
                'discovery_id' => $history->discovery_id,
                'device_metadata' => $history->device_metadata,
                'captured_at' => $history->captured_at,
            ]);
        }
    }

    /** GPS metadata access: admins/approvers and anyone scoped wide enough, or with a direct engagement on the property. */
    private function assertCanAccessProperty(User $user, string $propertyId): void
    {
        if ($user->canPermission('gps.approve') || $user->isSystemAdministrator()) {
            return;
        }

        if (in_array($user->scopeLevel(), ['system', 'division', 'section'], true)) {
            return;
        }

        if (EnforcementVisit::where('property_id', $propertyId)->where('officer_id', $user->id)->exists()) {
            return;
        }

        $hasTask = Task::where('assigned_to', $user->id)
            ->where('reference_type', 'property_bill')
            ->whereIn('reference_id', PropertyBill::where('property_id', $propertyId)->pluck('id'))
            ->exists();

        abort_unless($hasTask, 403, 'You do not have access to GPS data for this property.');
    }

    /** Field officers may certify engagements they own; supervisors/broader managers may certify anything visible. */
    private function assertCanCertifyEngagement(User $user, array $data): void
    {
        $this->assertCanAccessProperty($user, $data['property_id']);

        if ($user->hasAnyPermission(['gps.approve', 'gps.review']) || in_array($user->scopeLevel(), ['section', 'division', 'system'], true)) {
            return;
        }

        $owned = true;

        if (! empty($data['visit_id'])) {
            $owned = $owned
                && EnforcementVisit::where('id', $data['visit_id'])->where('officer_id', $user->id)->exists();
        }

        if (! empty($data['discovery_id'])) {
            $owned = $owned
                && \App\Models\PropertyDiscovery::where('id', $data['discovery_id'])->where('discovered_by', $user->id)->exists();
        }

        if (! empty($data['valuation_id'])) {
            $owned = $owned
                && \App\Models\Valuation::where('id', $data['valuation_id'])->where('valuation_officer_id', $user->id)->exists();
        }

        if (! empty($data['task_id'])) {
            $owned = $owned
                && Task::where('id', $data['task_id'])->where('assigned_to', $user->id)->exists();
        }

        abort_unless($owned, 403, 'You may only certify GPS for your own engagements.');
    }
}