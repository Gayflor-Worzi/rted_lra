<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Section;
use App\Models\User;
use Database\Seeders\ConfigurationSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CleanDatabaseInitializationTest extends TestCase
{
    use RefreshDatabase;

    private function provisionOptions(): array
    {
        return [
            '--name' => 'Initial System Administrator',
            '--email' => 'initial-admin@example.test',
            '--staff-id' => 'SYS-0001',
        ];
    }

    private function runProvisioning(string $password = 'Temp-Only-Password-481!'): void
    {
        $this->artisan('retd:provision-system-admin', $this->provisionOptions())
            ->expectsQuestion('Temporary password (minimum 12 characters)', $password)
            ->expectsQuestion('Confirm temporary password', $password)
            ->expectsConfirmation('Create the one-time System Superadmin account now?', 'yes')
            ->assertExitCode(0);
    }

    public function test_configuration_seeder_creates_only_reference_data_and_is_idempotent(): void
    {
        $this->seed(ConfigurationSeeder::class);

        $counts = [
            'sections' => Section::count(),
            'roles' => Role::count(),
            'permissions' => Permission::count(),
            'role_permissions' => DB::table('role_permissions')->count(),
        ];

        $this->assertSame(5, $counts['sections']);
        $this->assertSame(12, $counts['roles']);
        $this->assertSame(count(config('permissions')), $counts['permissions']);
        $this->assertGreaterThan(0, $counts['role_permissions']);
        $this->assertSame(0, User::count());

        foreach ([
            'properties', 'property_bills', 'bill_amendments', 'property_discoveries',
            'property_discovery_evidence', 'valuations', 'valuation_reviews',
            'valuation_property_descriptions', 'payments', 'payment_verifications',
            'receipt_fingerprints', 'enforcement_visits', 'tasks', 'task_histories',
            'task_engagements', 'notifications', 'audit_logs', 'attachments',
            'gps_locations', 'gps_location_history', 'sync_transactions', 'staff_targets',
            'data_quality_flags', 'me_queries', 'appeals', 'staff_organization_histories',
            'enforcement_org_recommendations',
        ] as $table) {
            $this->assertDatabaseCount($table, 0);
        }

        $this->seed(ConfigurationSeeder::class);

        $this->assertSame($counts, [
            'sections' => Section::count(),
            'roles' => Role::count(),
            'permissions' => Permission::count(),
            'role_permissions' => DB::table('role_permissions')->count(),
        ]);
        $this->assertSame(0, User::count());
    }

    public function test_configuration_conflicts_fail_without_adding_unexpected_grants(): void
    {
        $this->seed(ConfigurationSeeder::class);
        $role = Role::where('name', 'Account Manager')->firstOrFail();
        $permission = Permission::where('name', 'gps.approve')->firstOrFail();
        $role->permissions()->syncWithoutDetaching([$permission->id]);

        try {
            $this->seed(ConfigurationSeeder::class);
            $this->fail('Unexpected role grants should be reported as a configuration conflict.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Role permission configuration conflict', $exception->getMessage());
        }
    }

    public function test_role_and_permission_definition_conflicts_are_reported(): void
    {
        $this->seed(ConfigurationSeeder::class);

        Role::where('name', 'Account Manager')->update(['default_scope' => 'own']);

        try {
            $this->seed(ConfigurationSeeder::class);
            $this->fail('A role definition conflict should be reported.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Role configuration conflict for Account Manager', $exception->getMessage());
        }

        Role::where('name', 'Account Manager')->update(['default_scope' => 'section']);
        Permission::where('name', 'gps.approve')->update(['module' => 'unexpected']);

        try {
            $this->seed(ConfigurationSeeder::class);
            $this->fail('A permission definition conflict should be reported.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Permission configuration conflict for gps.approve', $exception->getMessage());
        }
    }

    public function test_production_database_seeding_never_creates_demo_or_admin_users(): void
    {
        $this->app['env'] = 'production';

        app(DatabaseSeeder::class)->run();

        $this->assertSame(5, Section::count());
        $this->assertSame(12, Role::count());
        $this->assertSame(0, User::count());

        try {
            app(DemoUserSeeder::class)->run();
            $this->fail('The demo seeder must reject production environments.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('only be seeded in local or testing', $exception->getMessage());
        }

        $this->assertSame(0, User::count());
    }

    public function test_noninteractive_provisioning_without_credentials_fails_without_creating_a_user(): void
    {
        $this->seed(ConfigurationSeeder::class);

        $this->artisan('retd:provision-system-admin', ['--no-interaction' => true])
            ->expectsOutput('Provisioning requires an interactive terminal so the password can be entered securely.')
            ->assertExitCode(1);

        $this->assertSame(0, User::count());
    }

    public function test_missing_identity_credentials_prevent_provisioning(): void
    {
        $this->seed(ConfigurationSeeder::class);

        $this->artisan('retd:provision-system-admin')
            ->assertExitCode(1);

        $this->assertSame(0, User::count());
    }

    public function test_provisioned_system_admin_is_one_time_active_forced_to_reset_and_has_wildcard_role_access(): void
    {
        $this->seed(ConfigurationSeeder::class);
        Log::spy();
        $password = 'Temp-Only-Password-481!';

        $this->runProvisioning($password);

        $user = User::where('email', 'initial-admin@example.test')->firstOrFail();
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->must_reset_password);
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertSame('SYS', $user->section->code);
        $this->assertSame(Role::SYSTEM_ADMIN, $user->role->name);
        $this->assertSame(['*'], $user->permissions());
        $this->assertSame([], $user->role->permissions()->pluck('name')->all());

        $duplicateOptions = $this->provisionOptions();
        $duplicateOptions['--email'] = 'second-admin@example.test';
        $duplicateOptions['--staff-id'] = 'SYS-0002';

        $this->artisan('retd:provision-system-admin', $duplicateOptions)
            ->expectsOutput('A System Administrator already exists; provisioning is one-time only.')
            ->assertExitCode(1);

        $this->assertSame(1, User::where('role_id', $user->role_id)->count());
        $this->assertTrue(Hash::check($password, $user->fresh()->password));
        $this->assertSame('initial-admin@example.test', $user->fresh()->email);
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('warning');
    }

    public function test_provisioned_account_can_login_and_authenticate_with_sanctum_then_reset_password(): void
    {
        $this->seed(ConfigurationSeeder::class);
        $temporaryPassword = 'Temp-Only-Password-481!';
        $this->runProvisioning($temporaryPassword);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'initial-admin@example.test',
            'password' => $temporaryPassword,
        ])->assertOk()->assertJsonPath('data.must_reset', true);

        $token = $login->json('data.token');
        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.role', Role::SYSTEM_ADMIN)
            ->assertJsonPath('data.must_reset_password', true);

        $this->withToken($token)->postJson('/api/v1/auth/reset-password', [
            'password' => 'Permanent-Password-492!',
            'password_confirmation' => 'Permanent-Password-492!',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'initial-admin@example.test',
            'password' => 'Permanent-Password-492!',
        ])->assertOk()->assertJsonPath('data.must_reset', false);
    }

    public function test_provisioning_is_not_exposed_to_ordinary_users_through_the_api(): void
    {
        $this->seed();
        Sanctum::actingAs(User::where('email', 'account.officer@test.lra')->firstOrFail());

        $this->postJson('/api/v1/system-admin/provision', [
            'name' => 'Unauthorized User',
            'email' => 'unauthorized@example.test',
            'password' => 'Temp-Only-Password-481!',
        ])->assertMethodNotAllowed();

        $this->assertDatabaseMissing('users', ['email' => 'unauthorized@example.test']);
        $this->assertSame(0, User::whereHas('role', fn ($query) => $query->where('name', Role::SYSTEM_ADMIN))->count());
    }
}
