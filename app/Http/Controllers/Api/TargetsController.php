<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StaffTarget;
use App\Services\TaskService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Staff Target Management — configurable targets by staff function. The AC,
 * managers and M&E compare actual performance against approved targets.
 */
class TargetsController extends Controller
{
    public function __construct(private readonly TaskService $tasks) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canPermission('targets.view'), 403, 'Missing permission: targets.view');

        $query = StaffTarget::query()->with(['user:id,full_name,role_id', 'creator:id,full_name', 'approver:id,full_name']);

        if ($userId = $request->query('user_id')) {
            $query->where('user_id', $userId);
        }
        if ($section = $request->query('section')) {
            $query->where('section', $section);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($metric = $request->query('metric')) {
            $query->where('metric', $metric);
        }
        if ($period = $request->query('period')) {
            $query->where('period', $period);
        }

        $this->applyVisibility($query, $user);

        $rows = $query->orderByDesc('id')->paginate($request->query('per_page', 50))->withQueryString();
        $rows->getCollection()->transform(fn ($t) => $this->present($t));

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canPermission('targets.create'), 403, 'Missing permission: targets.create');

        $data = $request->validate($this->rules());

        $target = StaffTarget::create([
            'user_id' => $data['user_id'],
            'section' => $data['section'] ?? null,
            'metric' => $data['metric'],
            'target_value' => $data['target_value'],
            'achieved_value' => 0,
            'measurement_unit' => $data['measurement_unit'] ?? null,
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
            'frequency' => $data['frequency'] ?? 'Monthly',
            'period' => $data['period'] ?? ($data['start_date'] ? substr($data['start_date'], 0, 4) : date('Y')),
            'status' => 'Draft',
            'created_by' => $user->id,
        ]);

        $this->tasks->notify(\App\Models\User::find($target->user_id), 'Performance target set', "A {$data['metric']} target for {$target->period} has been set for you.", 'targets');

        return response()->json([
            'data' => $this->present($target->fresh(['user:id,full_name'])),
            'message' => 'Target created (awaiting approval).',
        ], 201);
    }

    public function update(Request $request, StaffTarget $target)
    {
        $user = $request->user();
        abort_unless($user->canPermission('targets.edit'), 403, 'Missing permission: targets.edit');
        abort_if(in_array($target->status, ['Approved', 'Archived'], true), 422, 'Approved targets cannot be edited directly; create a revision.');

        $data = $request->validate($this->rules());

        $target->update([
            'user_id' => $data['user_id'],
            'section' => $data['section'] ?? $target->section,
            'metric' => $data['metric'],
            'target_value' => $data['target_value'],
            'measurement_unit' => $data['measurement_unit'] ?? $target->measurement_unit,
            'start_date' => $data['start_date'] ?? $target->start_date,
            'end_date' => $data['end_date'] ?? $target->end_date,
            'frequency' => $data['frequency'] ?? $target->frequency,
            'period' => $data['period'] ?? $target->period,
        ]);

        return response()->json(['data' => $this->present($target->fresh()), 'message' => 'Target updated.']);
    }

    public function approve(Request $request, StaffTarget $target)
    {
        $user = $request->user();
        abort_unless($user->canPermission('targets.approve'), 403, 'Missing permission: targets.approve');

        $target->approve($user);

        $this->tasks->notify(\App\Models\User::find($target->user_id), 'Target approved', "Your {$target->metric} target for {$target->period} is approved.", 'targets');

        return response()->json(['data' => $this->present($target->fresh()), 'message' => 'Target approved.']);
    }

    /**
     * Recompute achieved metrics for a target (or all of a user's targets in a
     * period) from the operational records — the AC/M&E compare against this.
     */
    public function refresh(Request $request, ?StaffTarget $target = null)
    {
        $user = $request->user();
        abort_unless($user->canPermission('targets.refresh'), 403, 'Missing permission: targets.refresh');

        $targets = $target
            ? collect([$target])
            : StaffTarget::where('status', 'Approved')
                ->when($request->query('user_id'), fn ($q, $id) => $q->where('user_id', $id))
                ->when($request->query('period'), fn ($q, $p) => $q->where('period', $p))
                ->limit(500)
                ->get();

        $run = $targets->map(fn (StaffTarget $t) => $t->update([
            'achieved_value' => $this->computeAchieved($t),
        ]))->count();

        return response()->json(['data' => ['refreshed' => $run], 'message' => "Refreshed {$run} target(s) from operational records."]);
    }

    private function computeAchieved(StaffTarget $target): float
    {
        return $target->computeAchievedValue();
    }

    private function applyVisibility(Builder $query, \App\Models\User $user): void
    {
        $scope = $user->scopeLevel();

        if (in_array($scope, ['system', 'division'], true)) {
            return;
        }
        if ($scope === 'section') {
            $query->whereHas('user', fn ($u) => $u->where('section_id', $user->section_id));

            return;
        }
        if ($scope === 'team') {
            $query->whereHas('user', fn ($u) => $u->where('supervisor_id', $user->id)->orWhere('id', $user->id));

            return;
        }
        // own
        $query->where('user_id', $user->id);
    }

    private function rules(): array
    {
        return [
            'user_id' => 'required|integer|exists:users,id',
            'section' => 'nullable|string|max:60',
            'metric' => ['required', Rule::in(StaffTarget::METRICS)],
            'target_value' => 'required|numeric|min:0',
            'measurement_unit' => 'nullable|string|max:50',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'frequency' => ['nullable', Rule::in(StaffTarget::FREQUENCIES)],
            'period' => 'nullable|string|max:20',
        ];
    }

    private function present(StaffTarget $t): array
    {
        return [
            'id' => $t->id,
            'user_id' => $t->user_id,
            'staff' => $t->relationLoaded('user') ? $t->user?->full_name : "User #{$t->user_id}",
            'section' => $t->section,
            'metric' => $t->metric,
            'target_value' => (float) $t->target_value,
            'achieved_value' => (float) $t->achieved_value,
            'achievement_pct' => $t->progressPercent(),
            'measurement_unit' => $t->measurement_unit,
            'start_date' => $t->start_date?->toDateString(),
            'end_date' => $t->end_date?->toDateString(),
            'frequency' => $t->frequency,
            'period' => $t->period,
            'status' => $t->status,
            'created_by' => $t->relationLoaded('creator') ? ($t->creator?->full_name ?? $t->created_by) : $t->created_by,
            'approved_by' => $t->relationLoaded('approver') ? ($t->approver?->full_name ?? $t->approved_by) : $t->approved_by,
            'approved_at' => $t->approved_at?->toISOString(),
            'created_at' => $t->created_at?->toISOString(),
        ];
    }
}