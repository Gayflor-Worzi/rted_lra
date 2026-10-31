<?php

namespace App\Services;

use App\Models\Section;
use App\Models\StaffOrganizationHistory;
use App\Models\StaffTarget;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ManagementPerformanceService
{
    private const MANAGEMENT_ROLES = [
        'Account Manager',
        'Valuation Manager',
        'Enforcement Manager',
        'Account Supervisor',
        'Valuation Supervisor',
        'Enforcement Supervisor',
        'Assistant Commissioner',
        'System Administrator',
    ];

    public function summarize(User $manager): array
    {
        $roleName = $manager->role?->name;
        $scope = $this->scopeFor($roleName);
        abort_unless($scope, 403, 'Management performance is only available to mapped management roles.');

        $managerHistory = StaffOrganizationHistory::query()
            ->where('user_id', $manager->id)
            ->where('role_name', $roleName)
            ->orderBy('effective_from')
            ->get();
        $operationalSectionIds = Section::query()
            ->where('is_active', true)
            ->whereNotIn('code', ['MGT', 'SYS'])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $staffAssignments = StaffOrganizationHistory::query()
            ->whereNotIn('role_name', self::MANAGEMENT_ROLES)
            ->when(
                $scope === 'section',
                fn ($query) => $query->whereIn('section_id', $managerHistory->pluck('role_section_id')->filter()->unique())
            )
            ->when($scope === 'supervisor', fn ($query) => $query->where('supervisor_id', $manager->id))
            ->when($scope === 'division', fn ($query) => $query->whereIn('section_id', $operationalSectionIds))
            ->get();
        $staffIds = $staffAssignments->pluck('user_id')->unique();
        $targets = StaffTarget::query()
            ->where('status', 'Approved')
            ->whereIn('user_id', $staffIds)
            ->orderBy('id')
            ->get();
        $staffHistories = StaffOrganizationHistory::query()
            ->whereIn('user_id', $staffIds)
            ->orderBy('effective_from')
            ->get()
            ->groupBy('user_id');

        $groups = [];
        $unsupported = [];
        $achievementCache = [];
        $unattributedTargets = [];

        foreach ($targets as $target) {
            [$windowStart, $windowEnd] = $target->effectiveWindow();
            $windowStart = $windowStart->copy()->startOfDay();
            $windowEnd = $windowEnd->copy()->startOfDay();
            $windowDays = $windowStart->diffInDays($windowEnd) + 1;
            $targetKey = implode('|', [
                $target->metric,
                $target->measurement_unit ?? '',
                $windowStart->toDateString(),
                $windowEnd->toDateString(),
            ]);

            $staffHistory = ($staffHistories[$target->user_id] ?? collect())
                ->filter(fn (StaffOrganizationHistory $assignment) => $assignment->effective_from->lte($windowEnd)
                    && (! $assignment->effective_to || $assignment->effective_to->gte($windowStart)))
                ->values();

            $coveredDays = $this->coveredDays($staffHistory, $windowStart, $windowEnd);

            $segments = $this->attributableSegments(
                $scope,
                $manager,
                $managerHistory,
                $staffHistory,
                $operationalSectionIds,
                $windowStart,
                $windowEnd
            );

            if ($segments === []) {
                continue;
            }

            if ($coveredDays < $windowDays) {
                $unattributedTargets[$target->id] = true;
            }

            if (! in_array($target->metric, StaffTarget::COMPUTED_METRICS, true)) {
                $unsupported[$target->metric] = ($unsupported[$target->metric] ?? 0) + 1;

                continue;
            }

            $groups[$targetKey] ??= [
                'metric' => $target->metric,
                'measurement_unit' => $target->measurement_unit,
                'start_date' => $windowStart->toDateString(),
                'end_date' => $windowEnd->toDateString(),
                'target_value' => 0.0,
                'achieved_value' => 0.0,
                'targets_count' => 0,
                'staff_ids' => [],
                'achievement_keys' => [],
                'attribution_limited' => false,
            ];
            $groups[$targetKey]['targets_count']++;

            foreach ($segments as [$segmentStart, $segmentEnd]) {
                $segmentDays = $segmentStart->diffInDays($segmentEnd) + 1;
                // Split the approved target by inclusive assignment days; achievements use that exact segment.
                $groups[$targetKey]['target_value'] += (float) $target->target_value * ($segmentDays / $windowDays);
                $groups[$targetKey]['staff_ids'][$target->user_id] = true;

                $achievementKey = implode('|', [
                    $target->user_id,
                    $target->metric,
                    $target->measurement_unit ?? '',
                    $windowStart->toDateString(),
                    $windowEnd->toDateString(),
                    $segmentStart->toDateString(),
                    $segmentEnd->toDateString(),
                ]);
                if (! isset($groups[$targetKey]['achievement_keys'][$achievementKey])) {
                    if (! array_key_exists($achievementKey, $achievementCache)) {
                        $segmentTarget = clone $target;
                        $segmentTarget->start_date = $segmentStart->toDateString();
                        $segmentTarget->end_date = $segmentEnd->toDateString();
                        $achievementCache[$achievementKey] = round($segmentTarget->computeAchievedValue(), 2);
                    }

                    $groups[$targetKey]['achieved_value'] += $achievementCache[$achievementKey];
                    $groups[$targetKey]['achievement_keys'][$achievementKey] = true;
                }
            }

            if ($coveredDays < $windowDays) {
                $groups[$targetKey]['attribution_limited'] = true;
            }
        }

        $periods = collect($groups)->map(function (array $group): array {
            $targetValue = round($group['target_value'], 2);
            $achievedValue = round($group['achieved_value'], 2);
            $hasTarget = $targetValue > 0;

            return [
                'metric' => $group['metric'],
                'measurement_unit' => $group['measurement_unit'],
                'start_date' => $group['start_date'],
                'end_date' => $group['end_date'],
                'target_value' => $targetValue,
                'achieved_value' => $achievedValue,
                'completion_rate' => $hasTarget ? round(($achievedValue / $targetValue) * 100, 1) : null,
                'targets_count' => $group['targets_count'],
                'contributing_staff_count' => count($group['staff_ids']),
                'has_achievements' => $achievedValue > 0,
                'attribution_limited' => $group['attribution_limited'],
                'status' => $hasTarget ? ($achievedValue > 0 ? 'available' : 'no_achievements') : 'unavailable',
            ];
        })->values()->all();

        return [
            'classification' => 'management',
            'scope' => $scope,
            'scope_label' => $this->scopeLabel($scope, $manager),
            'period_basis' => 'approved_target_window',
            'periods' => $periods,
            'unsupported_metrics' => array_keys($unsupported),
            'unsupported_targets_count' => array_sum($unsupported),
            'unattributed_targets_count' => count($unattributedTargets),
            'attribution_limited' => count($unattributedTargets) > 0,
            'status' => $periods === [] ? 'unavailable' : 'available',
        ];
    }

    public function isManagementRole(User $user): bool
    {
        return $this->scopeFor($user->role?->name) !== null;
    }

    private function attributableSegments(
        string $scope,
        User $manager,
        Collection $managerHistory,
        Collection $staffHistory,
        array $operationalSectionIds,
        Carbon $windowStart,
        Carbon $windowEnd
    ): array {
        $segments = [];

        foreach ($staffHistory as $staffAssignment) {
            if (in_array($staffAssignment->role_name, self::MANAGEMENT_ROLES, true)) {
                continue;
            }

            foreach ($managerHistory as $managerAssignment) {
                if (! $this->sameScope($scope, $manager, $managerAssignment, $staffAssignment, $operationalSectionIds)) {
                    continue;
                }

                $start = max(
                    $windowStart->toDateString(),
                    $staffAssignment->effective_from->toDateString(),
                    $managerAssignment->effective_from->toDateString()
                );
                $end = min(
                    $windowEnd->toDateString(),
                    ($staffAssignment->effective_to ?? $windowEnd)->toDateString(),
                    ($managerAssignment->effective_to ?? $windowEnd)->toDateString()
                );

                if ($start <= $end) {
                    $segments[] = [Carbon::parse($start), Carbon::parse($end)];
                }
            }
        }

        return $this->mergeSegments($segments);
    }

    private function sameScope(
        string $scope,
        User $manager,
        StaffOrganizationHistory $managerAssignment,
        StaffOrganizationHistory $staffAssignment,
        array $operationalSectionIds
    ): bool
    {
        if ($scope === 'division') {
            return in_array((int) $staffAssignment->section_id, $operationalSectionIds, true);
        }

        if ($scope === 'section') {
            return $managerAssignment->role_section_id !== null
                && (int) $managerAssignment->role_section_id === (int) $staffAssignment->section_id;
        }

        return (int) $staffAssignment->supervisor_id === (int) $manager->id;
    }

    private function coveredDays(Collection $history, Carbon $start, Carbon $end): int
    {
        return array_sum(array_map(
            fn (array $segment) => $segment[0]->diffInDays($segment[1]) + 1,
            $this->mergeSegments($history->map(function (StaffOrganizationHistory $assignment) use ($start, $end) {
                $segmentStart = max($start->toDateString(), $assignment->effective_from->toDateString());
                $segmentEnd = min($end->toDateString(), ($assignment->effective_to ?? $end)->toDateString());

                return $segmentStart <= $segmentEnd
                    ? [Carbon::parse($segmentStart), Carbon::parse($segmentEnd)]
                    : null;
            })->filter()->values()->all())
        ));
    }

    private function mergeSegments(array $segments): array
    {
        usort($segments, fn (array $a, array $b) => $a[0]->timestamp <=> $b[0]->timestamp);
        $merged = [];

        foreach ($segments as [$start, $end]) {
            $last = array_key_last($merged);
            if ($last !== null && $start->lte($merged[$last][1]->copy()->addDay())) {
                if ($end->gt($merged[$last][1])) {
                    $merged[$last][1] = $end;
                }

                continue;
            }
            $merged[] = [$start, $end];
        }

        return $merged;
    }

    private function scopeFor(?string $role): ?string
    {
        return match ($role) {
            'Account Manager', 'Valuation Manager', 'Enforcement Manager' => 'section',
            'Account Supervisor', 'Valuation Supervisor', 'Enforcement Supervisor' => 'supervisor',
            'Assistant Commissioner' => 'division',
            default => null,
        };
    }

    private function scopeLabel(string $scope, User $manager): string
    {
        return match ($scope) {
            'section' => Section::query()->whereKey($manager->role?->section_id)->value('name') ?? 'Assigned section',
            'supervisor' => 'Supervised operational staff',
            default => 'All active operational sections',
        };
    }
}
