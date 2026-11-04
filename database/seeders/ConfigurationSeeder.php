<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Section;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ConfigurationSeeder extends Seeder
{
    /**
     * @var array<string, array<int, string>>
     */
    private array $expectedRoleGrants = [];

    public function run(): void
    {
        DB::transaction(function (): void {
            $this->seedSections();
            $this->seedPermissions();
            $this->seedRoles();

            // Idempotent grants for the management-intelligence / discovery additions
            // (kept separate so previously-seeded databases pick them up on re-seed).
            $this->seedRoleExtras();
            $this->syncRolePermissions();
        });
    }

    private function seedRoleExtras(): void
    {
        $grant = function (string $role, array $perms): void {
            $this->expectedRoleGrants[$role] = array_values(array_unique([
                ...($this->expectedRoleGrants[$role] ?? []),
                ...$perms,
            ]));
        };

        $fieldDiscovery = [
            'discovery.view', 'discovery.create', 'discovery.submit', 'discovery.capture_gps', 'discovery.upload_photo',
        ];

        // Linked register access for roles that manage bill registers.
        $recordsAccess = ['records.view'];

        // Authority to produce an official document that leaves the system: a printed
        // report, or a Property Record sheet carrying the property's photographs, TIN,
        // GPS fix and value figures. property.export is deliberately kept on the same
        // footing as reports.print so that "may take official output away from RETD"
        // stays a single auditable decision rather than a per-role judgement repeated
        // across a dozen grant lists — and so supervisors and field officers, who may
        // open a property in the Property View, are not thereby entitled to export it.
        $officialOutput = ['reports.print', 'property.export'];

        $grant('Assistant Commissioner', [
            ...$officialOutput,
            ...$recordsAccess,
            'discovery.view', 'discovery.review', 'discovery.classify',
            'discovery.route_to_account', 'discovery.route_to_valuation',
            'discovery.approve', 'discovery.reject', 'discovery.reopen',
            'discovery.property_registration',
            'targets.approve',
            'me.assign_walkin', 'me.reassign', 'me.revise', 'me.flag_data_quality',
            'me.review', 'me.responsible',
            'enforcement.escalation_override', 'notifications.broadcast',
        ]);
        $grant('Account Manager', [
            ...$officialOutput,
            ...$recordsAccess,
            ...$fieldDiscovery,
            'discovery.route_to_account', 'discovery.property_registration',
            'discovery.aarm_review', 'discovery.aarm_approve', 'discovery.refer_to_valuation', 'discovery.assign',
            'valuation.account_review', 'valuation.complete', 'valuation.property_registration',
            'targets.approve', 'me.reassign', 'me.flag_data_quality',
            'enforcement.escalation_override', 'me.responsible',
        ]);
        $grant('Account Supervisor', [...$recordsAccess, ...$fieldDiscovery, 'me.flag_data_quality']);
        $grant('Account & Records Officer', [
            ...$recordsAccess, ...$fieldDiscovery,
            'discovery.property_registration', 'discovery.assign',
            'valuation.property_registration',
            'me.flag_data_quality',
        ]);
        $grant('Enforcement Manager', [
            ...$officialOutput, ...$recordsAccess, ...$fieldDiscovery, 'discovery.review',
            'targets.approve', 'me.assign_walkin', 'me.reassign', 'me.revise', 'me.flag_data_quality',
            'enforcement.escalation_override', 'me.review',
        ]);
        $grant('Enforcement Supervisor', [...$recordsAccess, ...$fieldDiscovery, 'discovery.review', 'me.assign_walkin', 'me.reassign', 'me.revise', 'me.flag_data_quality', 'enforcement.escalation_override']);
        $grant('Enforcement Officer', $fieldDiscovery);
        $grant('M&E Officer', [
            ...$officialOutput, 'discovery.view', ...$recordsAccess,
            'me.assign_walkin', 'me.reassign', 'me.revise', 'me.flag_data_quality',
            'me.review', 'me.responsible',
        ]);
        $grant('Valuation Manager', [
            ...$officialOutput, ...$recordsAccess,
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
            $section = Section::query()
                ->where('code', $s['code'])
                ->orWhere('name', $s['name'])
                ->first();

            if ($section && ($section->code !== $s['code'] || $section->name !== $s['name'])) {
                throw new RuntimeException("Section configuration conflict for {$s['code']}.");
            }

            Section::firstOrCreate(
                ['code' => $s['code']],
                ['name' => $s['name'], 'description' => null]
            );
        }
    }

    private function seedPermissions(): void
    {
        foreach (config('permissions') as $p) {
            $permission = Permission::firstOrNew(['name' => $p['name']]);
            if ($permission->exists
                && ($permission->module !== $p['module'] || $permission->action !== $p['action'])) {
                throw new RuntimeException("Permission configuration conflict for {$p['name']}.");
            }

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
        if (! $sectionId) {
            throw new RuntimeException("Missing section configuration for role {$name}.");
        }

        $attributes = [
            'description' => $name,
            'section_id' => $sectionId,
            'is_system_role' => $system,
            'is_active' => true,
            'default_scope' => $scope,
        ];
        $role = Role::firstOrNew(['name' => $name]);

        if ($role->exists) {
            foreach ($attributes as $attribute => $expected) {
                if ($role->{$attribute} != $expected) {
                    throw new RuntimeException("Role configuration conflict for {$name} ({$attribute}).");
                }
            }
        } else {
            $role->fill($attributes)->save();
        }

        $this->expectedRoleGrants[$name] = array_values(array_unique($grants));

        return $role;
    }

    private function syncRolePermissions(): void
    {
        $catalogue = Permission::query()->pluck('id', 'name');

        foreach ($this->expectedRoleGrants as $roleName => $expectedNames) {
            $missingDefinitions = array_values(array_diff($expectedNames, $catalogue->keys()->all()));
            if ($missingDefinitions !== []) {
                throw new RuntimeException(
                    "Role {$roleName} references permissions missing from config/permissions.php: "
                    .implode(', ', $missingDefinitions)
                );
            }

            $role = Role::where('name', $roleName)->firstOrFail();
            $currentNames = $role->permissions()->pluck('permissions.name')->all();
            $unexpected = array_values(array_diff($currentNames, $expectedNames));
            if ($unexpected !== []) {
                throw new RuntimeException(
                    "Role permission configuration conflict for {$roleName}: "
                    .implode(', ', $unexpected)
                );
            }

            $ids = array_map(fn (string $name): int => (int) $catalogue[$name], $expectedNames);
            $role->permissions()->syncWithoutDetaching($ids);
        }
    }

    private function seedRoles(): void
    {
        $this->role('System Administrator', 'SYS', 'system', [], true);
        $this->role('Assistant Commissioner', 'MGT', 'division', [
            'dashboard.view_division', 'dashboard.view_section', 'dashboard.view_own',
            'bills.view', 'bills.history', 'bills.export',
            'tasks.view_division', 'tasks.view_section', 'tasks.view_own', 'tasks.view_history',
            'enforcement.view_assignments', 'enforcement.escalate',
            'enforcement.org.review',
            'payments.view_queue', 'payments.view_history',
            'valuation.approve', 'valuation.reject', 'valuation.view_history',
            'valuation.property_registration', 'valuation.review',
            'staff.view', 'staff.view_performance',
            'reports.view', 'reports.export', 'me.view', 'notifications.view', 'audit.view',
            'targets.view',
            'gps.view', 'gps.update',
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
            'valuation.property_registration',
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
            'bills.view', 'bills.history', 'bills.export',
            'tasks.view_section', 'tasks.view_own',
            'tasks.priority', 'tasks.reopen', 'tasks.view_history',
            'enforcement.bill_delivery', 'enforcement.visit', 'enforcement.payment_followup',
            'enforcement.view_assignments', 'enforcement.issue_warning', 'enforcement.escalate',
            'enforcement.org.recommend',
            'payments.view_queue', 'payments.view_history',
            'staff.view', 'staff.view_performance',
            'reports.view', 'reports.export', 'me.view', 'notifications.view', 'audit.view',
            'targets.view', 'targets.create', 'targets.edit', 'targets.refresh',
            'gps.view', 'gps.update', 'enforcement.org.manage', 'enforcement.org.recommend',
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
            'gps.view', 'gps.approve', 'gps.review',
            'enforcement.org.recommend',
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
            'me.assign_walkin', 'me.reassign', 'me.revise', 'me.flag_data_quality', 'me.review',
            'notifications.view', 'audit.view', 'targets.view',
            'gps.view',
        ]);
        $this->role('Enforcement Officer', 'ENF', 'own', [
            'dashboard.view_own',
            'tasks.view_own', 'tasks.view_history', 'tasks.complete', 'tasks.escalate',
            'enforcement.bill_delivery', 'enforcement.visit', 'enforcement.payment_followup',
            'enforcement.record_visit', 'enforcement.upload_evidence',
            'payments.claim',
            'gps.capture', 'gps.certify',
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
        // View-only across the staff they supervise, full conduct on their own records.
        // The team scope let them open, edit and assess a colleague's valuation, which
        // is a second, parallel process over the same record. Routing and review stay
        // with the Valuation Manager, who holds section scope.
        $this->role('Valuation Supervisor', 'VAL', 'team_view', [
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
}
