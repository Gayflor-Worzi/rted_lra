<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Gate 9 — development/test demo baseline pin.
 *
 * Locks the walkthrough duty roster in docs/UAT_WALKTHROUGH_SCRIPT.md to the
 * development/test demo seeder so the human-led exercise cannot silently drift:
 *   - every demo login exists, holds the documented role/section, is active,
 *     has no forced reset, and its demo password hash still matches; and
 *   - the G2 "expected 403" (negative) matrix asserts exact codes with the
 *     retained accounts (the demo baseline ships 6 accounts; supervisor and
 *     M&E role-behaviour coverage lives in the automated suite via
 *     tests/Feature/Concerns/LocalDemoUsers fixtures).
 */
class UatBaselineCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    public function test_retained_duty_roster_logins_exist_with_documented_credentials(): void
    {
        $roster = [
            'ac@test.lra' => 'Assistant Commissioner',
            'account.manager@test.lra' => 'Account Manager',
            'account.officer@test.lra' => 'Account & Records Officer',
            'enf.officer@test.lra' => 'Enforcement Officer',
            'val.manager@test.lra' => 'Valuation Manager',
            'val.officer@test.lra' => 'Valuation Officer',
        ];

        foreach ($roster as $email => $roleName) {
            $user = $this->user($email);
            $this->assertSame($roleName, $user->role?->name, "Roster role drift for {$email}");
            $this->assertTrue($user->is_active, "Roster login {$email} must be active for the walkthrough");
            $this->assertFalse((bool) $user->must_reset_password, "Roster login {$email} must not force a reset during UAT");
        }

        $this->assertSame(6, User::count(), 'The demo baseline must ship exactly the 6 retained accounts.');
        $this->assertNull(
            User::whereHas('role', fn ($query) => $query->where('name', 'System Administrator'))->first(),
            'The demo seeder must not provision the System Administrator.'
        );
        $this->assertNull(User::where('email', 'enf.supervisor@test.lra')->first(), 'Enforcement Supervisor demo account trimmed.');
        $this->assertNull(User::where('email', 'val.supervisor@test.lra')->first(), 'Valuation Supervisor demo account trimmed.');
        $this->assertNull(User::where('email', 'me.officer@test.lra')->first(), 'M&E Officer demo account trimmed.');
    }

    public function test_demo_passwords_match_the_walkthrough_documentation(): void
    {
        foreach (['account.manager@test.lra', 'account.officer@test.lra'] as $email) {
            $this->assertTrue(Hash::check('account123', $this->user($email)->password), "Account-role demo password drift for {$email}");
        }

        foreach (['ac@test.lra', 'enf.officer@test.lra', 'val.manager@test.lra', 'val.officer@test.lra'] as $email) {
            $this->assertTrue(Hash::check('officer123', $this->user($email)->password), "Field-role demo password drift for {$email}");
        }
    }

    public function test_supervisor_team_wiring_is_present(): void
    {
        $this->assertSame(
            $this->user('account.manager@test.lra')->id,
            $this->user('account.officer@test.lra')->supervisor_id,
        );
        $this->assertNull($this->user('enf.officer@test.lra')->supervisor_id, 'Enforcement officer has no demo supervisor.');
        $this->assertNull($this->user('val.officer@test.lra')->supervisor_id, 'Valuation officer has no demo supervisor.');
    }

    public function test_walkthrough_negative_matrix_string_codes(): void
    {
        foreach ([
            'Enforcement officer: no reports' => ['enf.officer@test.lra', '/api/v1/reports/bills'],
            'Enforcement officer: no payment queue' => ['enf.officer@test.lra', '/api/v1/payments/queue'],
            'Valuation officer: no integrity audit' => ['val.officer@test.lra', '/api/v1/dashboard/integrity'],
            'Enforcement officer: no integrity audit' => ['enf.officer@test.lra', '/api/v1/dashboard/integrity'],
            'Account officer: no M&E queries' => ['account.officer@test.lra', '/api/v1/me/queries'],
            'Enforcement officer: no M&E queries' => ['enf.officer@test.lra', '/api/v1/me/queries'],
            'Account officer: no GPS review queue' => ['account.officer@test.lra', '/api/v1/gps/reviews'],
            'Valuation officer: no GPS review queue' => ['val.officer@test.lra', '/api/v1/gps/reviews'],
        ] as $label => [$email, $url]) {
            Sanctum::actingAs($this->user($email));
            $this->getJson($url)->assertStatus(403, $label);
        }
    }
}
