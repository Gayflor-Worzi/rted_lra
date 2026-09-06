<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Section;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedSections();
        $this->seedPermissions();
        $this->seedRoles();

        // Idempotent grants for the management-intelligence / discovery additions
        // (kept separate so previously-seeded databases pick them up on re-seed).
        $this->seedRoleExtras();

        $this->seedUsers();
    }

    private function seedRoleExtras(): void
    {
        $grant = function (string $role, array $perms): void {
            $r = Role::where('name', $role)->first();
            foreach ($perms as $p) {
                $r?->grant($p);
            }
        };

        $fieldDiscovery = [
            'discovery.view', 'discovery.create', 'discovery.submit', 'discovery.capture_gps', 'discovery.upload_photo',
        ];

        // Linked register access for roles that manage bill records.
        $recordsAccess = ['records.view'];

        $grant('Assistant Commissioner', [
            'reports.print',
            ...$recordsAccess,
            'discovery.view', 'discovery.review', 'discovery.classify',
            'discovery.route_to_account', 'discovery.route_to_valuation',
            'discovery.approve', 'discovery.reject', 'discovery.reopen',
            'discovery.litas_processing',
            'targets.approve',
            'me.assign_walkin', 'me.reassign', 'me.revise', 'me.flag_data_quality',
            'me.review', 'me.responsible',
            'enforcement.escalation_override', 'notifications.broadcast',
        ]);
        $grant('Account Manager', [
            'reports.print',
            ...$recordsAccess,
            ...$fieldDiscovery, 'discovery.route_to_account', 'discovery.litas_processing',
            'targets.approve', 'me.reassign', 'me.flag_data_quality',
            'enforcement.escalation_override', 'me.responsible',
        ]);
        $grant('Account Supervisor', [...$recordsAccess, ...$fieldDiscovery, 'me.flag_data_quality']);
        $grant('Account & Records Officer', [...$recordsAccess, ...$fieldDiscovery, 'me.flag_data_quality']);
        $grant('Enforcement Manager', [
            'reports.print', ...$recordsAccess, ...$fieldDiscovery, 'discovery.review',
            'targets.approve', 'me.assign_walkin', 'me.reassign', 'me.revise', 'me.flag_data_quality',
            'enforcement.escalation_override', 'me.review',
        ]);
        $grant('Enforcement Supervisor', [...$recordsAccess, ...$fieldDiscovery, 'discovery.review', 'me.assign_walkin', 'me.reassign', 'me.revise', 'me.flag_data_quality', 'enforcement.escalation_override']);
        $grant('Enforcement Officer', $fieldDiscovery);
        $grant('M&E Officer', [
            'reports.print', 'discovery.view', ...$recordsAccess,
            'me.assign_walkin', 'me.reassign', 'me.revise', 'me.flag_data_quality',
            'me.review', 'me.responsible',
        ]);
        $grant('Valuation Manager', [
            'reports.print', ...$recordsAccess,
            ...$fieldDiscovery, 'discovery.review', 'discovery.classify',
            'discovery.route_to_account', 'discovery.route_to_valuation',
            'targets.approve', 'me.flag_data_quality',
        ]);
        $grant('Valuation Supervisor', [...$fieldDiscovery, 'discovery.review']);
        $grant('Valuation Officer', $fieldDiscovery);
    }

    private function seedSections(): void
    {
        foreach ([
            ['name' => 'System Administration', 'code' => 'SYS'],
            ['name' => 'Account & Records', 'code' => 'ACCT'],
            ['name' => 'Enforcement', 'code' => 'ENF'],
            ['name' => 'Valuation', 'code' => 'VAL'],
            ['name' => 'Management', 'code' => 'MGT'],
        ] as $s) {
            Section::firstOrCreate(['code' => $s['code']], ['name' => $s['name'], 'description' => null]);
        }
    }

    private function seedPermissions(): void
    {
        foreach (config('permissions') as $p) {
            Permission::firstOrCreate(['name' => $p['name']], [
                'module' => $p['module'],
                'action' => $p['action'],
                'description' => $p['description'] ?? null,
            ]);
        }
    }

    private function role(string $name, string $section, string $scope, array $grants, bool $system = false): Role
    {
        $sectionId = Section::where('code', $section)->value('id');

        $role = Role::firstOrCreate(['name' => $name], [
            'description' => $name,
            'section_id' => $sectionId,
            'is_system_role' => $system,
            'is_active' => true,
            'default_scope' => $scope,
        ]);

        foreach ($grants as $g) {
            $role->grant($g);
        }

        return $role;
    }

    private function seedRoles(): void
    {
        $this->role('System Administrator', 'SYS', 'system', [], true);
        $this->role('Assistant Commissioner', 'MGT', 'division', [
            'dashboard.view_division', 'dashboard.view_section', 'dashboard.view_own',
            'bills.view', 'bills.history', 'bills.export',
            'tasks.view_division', 'tasks.view_section', 'tasks.view_own', 'tasks.view_history',
            'enforcement.view_assignments', 'enforcement.escalate',
            'payments.view_queue', 'payments.view_history',
            'valuation.approve', 'valuation.reject', 'valuation.view_history',
            'valuation.litas_processing', 'valuation.review',
            'staff.view', 'staff.view_performance',
            'reports.view', 'reports.export', 'me.view', 'notifications.view', 'audit.view',
            'targets.view',
        ]);
        $this->role('Account Manager', 'ACCT', 'section', [
            'dashboard.view_section', 'dashboard.view_own',
            'bills.view', 'bills.create', 'bills.edit', 'bills.amend_document',
            'bills.amend_penalty_interest', 'bills.assign', 'bills.reassign',
            'bills.history', 'bills.export',
            'tasks.view_section', 'tasks.view_own', 'tasks.view_history',
            'tasks.assign', 'tasks.reassign', 'tasks.priority', 'tasks.reopen',
            'enforcement.view_assignments',
            'payments.view_queue', 'payments.review_receipt', 'payments.compare_receipt',
            'payments.verify', 'payments.reject', 'payments.view_history',
            'valuation.litas_processing',
            'staff.view', 'staff.create', 'staff.edit', 'staff.activate', 'staff.assign_role',
            'reports.view', 'reports.export', 'me.view', 'notifications.view',
            'targets.view', 'targets.create', 'targets.edit', 'targets.refresh',
            'audit.view',
        ]);
        $this->role('Account Supervisor', 'ACCT', 'team', [
            'dashboard.view_section', 'dashboard.view_own',
            'bills.view', 'bills.create', 'bills.edit', 'bills.history',
            'payments.view_queue', 'payments.review_receipt', 'payments.compare_receipt',
            'payments.verify', 'payments.reject', 'payments.view_history',
            'tasks.view_section', 'tasks.view_own', 'tasks.view_history',
            'reports.view', 'notifications.view',
        ]);
        $this->role('Account & Records Officer', 'ACCT', 'own', [
            'dashboard.view_own',
            'bills.view', 'bills.create', 'bills.edit', 'bills.amend_penalty_interest',
            'bills.history',
            'payments.view_queue', 'payments.review_receipt', 'payments.compare_receipt',
            'payments.verify', 'payments.reject', 'payments.view_history',
            'tasks.view_own', 'tasks.view_history',
            'notifications.view',
        ]);
        $this->role('Enforcement Manager', 'ENF', 'section', [
            'dashboard.view_section', 'dashboard.view_own',
            'bills.view', 'bills.assign', 'bills.reassign', 'bills.history', 'bills.export',
            'tasks.view_section', 'tasks.view_own', 'tasks.assign', 'tasks.reassign',
            'tasks.priority', 'tasks.reopen', 'tasks.view_history',
            'enforcement.bill_delivery', 'enforcement.visit', 'enforcement.payment_followup',
            'enforcement.view_assignments', 'enforcement.issue_warning', 'enforcement.escalate',
            'payments.view_queue', 'payments.view_history',
            'staff.view', 'staff.view_performance',
            'reports.view', 'reports.export', 'me.view', 'notifications.view', 'audit.view',
            'targets.view', 'targets.create', 'targets.edit', 'targets.refresh',
        ]);
        $this->role('Enforcement Supervisor', 'ENF', 'team', [
            'dashboard.view_section', 'dashboard.view_own',
            'bills.view', 'bills.assign', 'bills.reassign', 'bills.history',
            'tasks.view_section', 'tasks.view_own', 'tasks.assign', 'tasks.reassign',
            'tasks.view_history',
            'enforcement.bill_delivery', 'enforcement.visit', 'enforcement.payment_followup',
            'enforcement.view_assignments', 'enforcement.issue_warning', 'enforcement.escalate',
            'payments.view_queue', 'payments.view_history',
            'staff.view', 'staff.view_performance', 'reports.view', 'notifications.view',
        ]);
        $this->role('M&E Officer', 'ENF', 'division', [
            'dashboard.view_division', 'dashboard.view_section', 'dashboard.view_own',
            'bills.view', 'bills.history', 'bills.export',
            'tasks.view_division', 'tasks.view_section', 'tasks.view_own',
            'tasks.view_history',
            'enforcement.bill_delivery', 'enforcement.visit',
            'enforcement.view_assignments',
            'payments.view_queue', 'payments.view_history',
            'staff.view', 'staff.view_performance',
            'reports.view', 'reports.export', 'me.view', 'me.create', 'me.respond', 'me.close',
            'notifications.view', 'audit.view', 'targets.view',
        ]);
        $this->role('Enforcement Officer', 'ENF', 'own', [
            'dashboard.view_own',
            'tasks.view_own', 'tasks.view_history', 'tasks.complete', 'tasks.escalate',
            'enforcement.bill_delivery', 'enforcement.visit', 'enforcement.payment_followup',
            'enforcement.record_visit', 'enforcement.upload_evidence',
            'payments.claim',
            'notifications.view',
        ]);
        $this->role('Valuation Manager', 'VAL', 'section', [
            'dashboard.view_section', 'dashboard.view_own',
            'bills.view',
            'tasks.view_section', 'tasks.view_own', 'tasks.view_history',
            'valuation.prepare', 'valuation.review', 'valuation.forward_ac', 'valuation.return',
            'valuation.view_history',
            'staff.view', 'staff.view_performance',
            'reports.view', 'me.view', 'notifications.view', 'audit.view',
            'targets.view', 'targets.create', 'targets.edit', 'targets.refresh',
        ]);
        $this->role('Valuation Supervisor', 'VAL', 'team', [
            'dashboard.view_section', 'dashboard.view_own',
            'bills.view',
            'tasks.view_section', 'tasks.view_own', 'tasks.view_history',
            'valuation.prepare', 'valuation.create', 'valuation.edit', 'valuation.submit',
            'valuation.review', 'valuation.return', 'valuation.view_history',
            'notifications.view',
        ]);
        $this->role('Valuation Officer', 'VAL', 'own', [
            'dashboard.view_own',
            'bills.view',
            'tasks.view_own', 'tasks.view_history',
            'valuation.prepare', 'valuation.create', 'valuation.edit', 'valuation.submit', 'valuation.view_history',
            'notifications.view',
        ]);
    }

    private function seedUsers(): void
    {
        $adminPassword = env('SEED_ADMIN_PASSWORD');
        if (! $adminPassword) {
            $adminPassword = Str::random(16);
            info('Admin seed password (wooze27@gmail.com): '.$adminPassword.' — set SEED_ADMIN_PASSWORD to pin it for local dev.');
        }

        $accounts = [
            ['name' => 'System Administrator', 'role' => 'System Administrator', 'section' => 'SYS', 'email' => 'wooze27@gmail.com', 'password' => $adminPassword],
            ['name' => 'Account Officer', 'role' => 'Account & Records Officer', 'section' => 'ACCT', 'email' => 'account.officer@test.lra', 'password' => 'account123'],
            ['name' => 'Enforcement Supervisor', 'role' => 'Enforcement Supervisor', 'section' => 'ENF', 'email' => 'enf.supervisor@test.lra', 'password' => 'officer123'],
            ['name' => 'Enforcement Officer', 'role' => 'Enforcement Officer', 'section' => 'ENF', 'email' => 'enf.officer@test.lra', 'password' => 'officer123'],
            ['name' => 'M&E Officer', 'role' => 'M&E Officer', 'section' => 'ENF', 'email' => 'me.officer@test.lra', 'password' => 'officer123'],
            ['name' => 'Valuation Supervisor', 'role' => 'Valuation Supervisor', 'section' => 'VAL', 'email' => 'val.supervisor@test.lra', 'password' => 'officer123'],
        ];

        // Only purge non-canonical users in non-production environments to avoid
        // accidentally deleting real user accounts on a production db:seed.
        if (! app()->isProduction()) {
            User::whereNotIn('email', array_column($accounts, 'email'))->delete();
        }

        $user = fn (array $a) => User::firstOrCreate(
            ['email' => $a['email']],
            [
                'staff_id' => strtoupper(substr(md5($a['email']), 0, 6)),
                'full_name' => $a['name'],
                'password' => Hash::make($a['password']),
                'role_id' => Role::where('name', $a['role'])->value('id'),
                'section_id' => Section::where('code', $a['section'])->value('id'),
                'is_active' => true,
                'must_reset_password' => false,
            ]
        );

        foreach ($accounts as $a) {
            $user($a);
        }

        // Team wiring for supervisors (supervisor_id drives the `team` data scope).
        $byEmail = fn (string $email) => User::where('email', $email)->value('id');

        User::where('email', 'enf.officer@test.lra')->update(['supervisor_id' => $byEmail('enf.supervisor@test.lra')]);
        User::where('email', 'me.officer@test.lra')->update(['supervisor_id' => $byEmail('enf.supervisor@test.lra')]);
    }
}