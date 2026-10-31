<?php

namespace App\Services;

use App\Models\Role;
use App\Models\StaffOrganizationHistory;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class StaffOrganizationHistoryService
{
    public function record(User $user): void
    {
        $role = $user->role_id ? Role::find($user->role_id) : null;
        $values = [
            'role_id' => $role?->id,
            'role_name' => $role?->name,
            'role_scope' => $role?->default_scope,
            'role_section_id' => $role?->section_id,
            'section_id' => $user->section_id,
            'supervisor_id' => $user->supervisor_id,
        ];
        $today = today()->toDateString();

        DB::transaction(function () use ($user, $values, $today): void {
            $current = StaffOrganizationHistory::query()
                ->where('user_id', $user->id)
                ->whereNull('effective_to')
                ->orderByDesc('effective_from')
                ->lockForUpdate()
                ->first();

            if ($current && $this->sameAssignment($current, $values)) {
                return;
            }

            if ($current && $current->effective_from->toDateString() === $today) {
                // Organizational attribution is day-granular; same-day edits settle on the final assignment.
                $current->update($values);

                return;
            }

            if ($current) {
                $current->update(['effective_to' => Carbon::parse($today)->subDay()->toDateString()]);
            }

            StaffOrganizationHistory::create($values + [
                'user_id' => $user->id,
                'effective_from' => $today,
            ]);
        });
    }

    private function sameAssignment(StaffOrganizationHistory $history, array $values): bool
    {
        foreach ($values as $key => $value) {
            if ($history->{$key} != $value) {
                return false;
            }
        }

        return true;
    }
}
