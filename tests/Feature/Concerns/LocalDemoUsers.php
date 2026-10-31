<?php

namespace Tests\Feature\Concerns;

use App\Models\Role;
use App\Models\Section;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Local fixtures for roles whose canonical demo ACCOUNTS are intentionally NOT
 * part of the trimmed seed baseline (only the System Administrator, Account
 * Manager, Account & Records Officer, Assistant Commissioner, Enforcement
 * Officer, Valuation Manager and Valuation Officer user rows ship).
 *
 * The ROLES themselves still seed with their full grant sets, so tests that
 * exercise a trimmed-role behaviour create that user row on demand — together
 * with the same team wiring the seeder used — and are behaviourally identical
 * to the old canonical demo account.
 */
trait LocalDemoUsers
{
    private function localRoleUser(string $email, string $roleName, string $sectionCode, array $supervising = []): User
    {
        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'staff_id' => strtoupper(substr(md5($email), 0, 6)),
                'full_name' => $roleName,
                'password' => Hash::make('officer123'),
                'role_id' => Role::where('name', $roleName)->value('id'),
                'section_id' => Section::where('code', $sectionCode)->value('id'),
                'is_active' => true,
                'must_reset_password' => false,
            ]
        );

        foreach ($supervising as $memberEmail) {
            User::where('email', $memberEmail)->update(['supervisor_id' => $user->id]);
        }

        return $user->refresh();
    }

    /** Enforcement Supervisor user + the Enforcement Officer wired under it. */
    private function localEnforcementSupervisor(): User
    {
        return $this->localRoleUser(
            'enf.supervisor@test.lra',
            'Enforcement Supervisor',
            'ENF',
            ['enf.officer@test.lra'],
        );
    }

    /** M&E Officer user (watchdog role, division scope + me.* grants). */
    private function localMeOfficer(): User
    {
        return $this->localRoleUser('me.officer@test.lra', 'M&E Officer', 'ENF');
    }
}
