<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\LocalDemoUsers;
use Tests\TestCase;

/**
 * Gate 2 — RBAC positive/negative sweep across module clusters.
 *
 * Complements the workspace-separation suite: one representative surface per
 * permission cluster (payments, reports, audit, me, gps, integrity, account
 * desk, field workspace) is asserted BOTH ways — the role that holds the
 * permission succeeds, the nearest staff role that does NOT is refused with
 * 403. Also guards the dead-permission cleanups: /dashboard/integrity was
 * previously gated on permission names no seeded role held (only the System
 * Administrator wildcard passed); an Assistant Commissioner (audit.view) can
 * now run it, an Enforcement Officer cannot.
 */
class RbacGate2SweepTest extends TestCase
{
    use LocalDemoUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->localMeOfficer();
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    public function test_positive_matrix_authorized_roles_succeed(): void
    {
        foreach ([
            'AC runs the data integrity audit' => ['ac@test.lra', '/api/v1/dashboard/integrity', 200],
            'Account officer opens the payment queue' => ['account.officer@test.lra', '/api/v1/payments/queue', 200],
            'Account officer opens the account desk' => ['account.officer@test.lra', '/api/v1/account/my-tasks', 200],
            'Enforcement officer lists field assignments' => ['enf.officer@test.lra', '/api/v1/enforcement-assignments/my', 200],
            'Enforcement officer lists discoveries' => ['enf.officer@test.lra', '/api/v1/discoveries', 200],
            'M&E officer opens the query desk' => ['me.officer@test.lra', '/api/v1/me/queries', 200],
            'Valuation manager opens the valuation desk' => ['val.manager@test.lra', '/api/v1/valuation/tasks/summary', 200],
        ] as $label => [$email, $url, $code]) {
            Sanctum::actingAs($this->user($email));
            $this->getJson($url)->assertStatus($code, $label);
        }
    }

    public function test_negative_matrix_nearest_staff_role_is_refused(): void
    {
        foreach ([
            'Enforcement officer: no payment queue' => ['enf.officer@test.lra', '/api/v1/payments/queue', 403],
            'Enforcement officer: no reports' => ['enf.officer@test.lra', '/api/v1/reports/bills', 403],
            'Enforcement officer: no audit log' => ['enf.officer@test.lra', '/api/v1/audit-logs', 403],
            'Enforcement officer: no M&E queries' => ['enf.officer@test.lra', '/api/v1/me/queries', 403],
            'Enforcement officer: no account desk' => ['enf.officer@test.lra', '/api/v1/account/my-tasks', 403],
            'Enforcement officer: no integrity audit' => ['enf.officer@test.lra', '/api/v1/dashboard/integrity', 403],
            'Enforcement officer: no GPS certification reviews' => ['enf.officer@test.lra', '/api/v1/gps/reviews', 403],
            'Valuation officer: no payment queue' => ['val.officer@test.lra', '/api/v1/payments/queue', 403],
            'Valuation officer: no account desk' => ['val.officer@test.lra', '/api/v1/account/my-tasks', 403],
            'Account officer: no M&E review board' => ['account.officer@test.lra', '/api/v1/me/review-board', 403],
            'Account officer: no GPS certification reviews' => ['account.officer@test.lra', '/api/v1/gps/reviews', 403],
            'Account officer: no valuation workspace' => ['account.officer@test.lra', '/api/v1/valuation/tasks/summary', 403],
            'M&E officer: no account desk' => ['me.officer@test.lra', '/api/v1/account/my-tasks', 403],
        ] as $label => [$email, $url, $code]) {
            Sanctum::actingAs($this->user($email));
            $this->getJson($url)->assertStatus($code, $label);
        }
    }
}
