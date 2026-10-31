<?php

namespace App\Services;

use App\Models\EnforcementTeamMembership;
use App\Models\User;

class EnforcementOfficerAssignmentService
{
    /**
     * Resolve the officer's current placement from the active approved team
     * membership, its designated primary district, and that district's region.
     */
    public function forOfficer(User $officer): array
    {
        $membership = EnforcementTeamMembership::query()
            ->where('officer_id', $officer->id)
            ->whereNull('ended_at')
            ->where(function ($query) {
                $query->whereNull('recommendation_id')
                    ->orWhereHas('recommendation', fn ($recommendation) => $recommendation->where('status', 'approved'));
            })
            ->with([
                'team.districts' => fn ($query) => $query->where('enforcement_districts.is_active', true),
                'team.currentMemberships' => fn ($query) => $query
                    ->where('is_team_lead', true)
                    ->where(function ($membership) {
                        $membership->whereNull('recommendation_id')
                            ->orWhereHas('recommendation', fn ($recommendation) => $recommendation->where('status', 'approved'));
                    })
                    ->orderByDesc('started_at')
                    ->orderByDesc('id')
                    ->with('officer:id,full_name,is_active'),
                'primaryDistrict.region.supervisor:id,full_name,is_active',
            ])
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();

        $team = $membership?->team;
        if (! $team || ! $team->is_active) {
            return $this->emptyAssignment();
        }

        $primaryDistrict = $membership->primaryDistrict;
        $teamDistricts = $team->districts;
        $primaryIsCovered = $primaryDistrict
            && $primaryDistrict->is_active
            && $teamDistricts->contains('id', $primaryDistrict->id);
        $region = $primaryIsCovered && $primaryDistrict->region?->is_active
            ? $primaryDistrict->region
            : null;
        $teamLead = $team->currentMemberships
            ->first(fn ($lead) => $lead->officer?->is_active);

        return [
            'tax_region' => $region?->name,
            'tax_district' => $primaryIsCovered ? $primaryDistrict->name : null,
            'enforcement_team' => $team->name,
            'team_lead' => $teamLead?->officer?->full_name,
            'regional_supervisor' => $region?->supervisor?->is_active
                ? $region->supervisor->full_name
                : null,
            'additional_coverage_districts' => $teamDistricts
                ->reject(fn ($district) => $primaryIsCovered && (int) $district->id === (int) $primaryDistrict->id)
                ->pluck('name')
                ->sort()
                ->values()
                ->all(),
        ];
    }

    private function emptyAssignment(): array
    {
        return [
            'tax_region' => null,
            'tax_district' => null,
            'enforcement_team' => null,
            'team_lead' => null,
            'regional_supervisor' => null,
            'additional_coverage_districts' => [],
        ];
    }
}
