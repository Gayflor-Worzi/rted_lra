<?php

namespace App\Services;

use App\Models\User;

/**
 * Single, shared RBAC resolution used by navigation, route guards on both
 * clients and the administration screens. Produces the module-grouped
 * catalogue marked granted / granted-by-role / granted-individually /
 * denied-individually, plus capability flags.
 *
 * Both the admin effective-permissions endpoint and the self-service
 * /auth/effective-permissions endpoint resolve through here, guaranteeing the
 * navigation, the pages, the admin checklist and the API all read the SAME
 * permission engine.
 */
class EffectivePermissionResolver
{
    public function resolve(User $user): array
    {
        $user->loadMissing(['role', 'section']);

        $isAdmin = $user->isSystemAdministrator();
        $perms = $user->permissions();
        $overrides = $user->permissionOverrides();

        $catalog = collect(config('permissions'))
            ->groupBy('module')
            ->map(fn ($group) => $group->values()->map(function ($p) use ($isAdmin, $perms, $overrides) {
                $name = $p['name'];
                $grantedByRole = ! $isAdmin && in_array($name, $perms, true) && ! array_key_exists($name, $overrides);
                $override = $overrides[$name] ?? null;

                return [
                    'name' => $name,
                    'action' => $p['action'],
                    'description' => $p['description'] ?? null,
                    'granted' => $isAdmin || in_array($name, $perms, true),
                    'granted_by_role' => $grantedByRole,
                    'granted_individually' => $override === 'allow',
                    'denied_individually' => $override === 'deny',
                    'override' => $override,
                ];
            }))
            ->toArray();

        $grantedCount = 0;
        $overriddenCount = 0;
        foreach ($catalog as $group) {
            foreach ($group as $p) {
                if ($p['granted']) {
                    $grantedCount++;
                }
                if ($p['override'] !== null) {
                    $overriddenCount++;
                }
            }
        }

        return [
            'role' => $user->role?->name ?? null,
            'role_id' => $user->role_id,
            'role_is_system' => $isAdmin,
            'section' => $user->section?->name ?? null,
            'default_scope' => $isAdmin ? 'system' : $user->scopeLevel(),
            'is_system_admin' => $isAdmin,
            'permissions' => $catalog,
            'permission_count' => $grantedCount,
            'overridden_count' => $overriddenCount,
            'capabilities' => [
                'submission' => $user->hasAnyPermission(['valuation.submit', 'discovery.submit', 'payments.claim', 'bills.create']),
                'approval' => $user->hasAnyPermission(['valuation.approve', 'valuation.reject', 'discovery.approve', 'discovery.reject', 'payments.verify', 'payments.reject', 'targets.approve']),
                'workflow_management' => $user->hasAnyPermission(['discovery.review', 'discovery.classify', 'valuation.review', 'valuation.forward_ac', 'me.review']),
                'reporting' => $user->hasAnyPermission(['reports.view', 'reports.export', 'reports.print', 'audit.view']),
                'staff_management' => $user->hasAnyPermission(['staff.create', 'staff.edit', 'staff.activate', 'staff.assign_role']),
                'rbac_management' => $user->hasAnyPermission(['rbac.create_role', 'rbac.edit_role', 'rbac.assign_permissions', 'rbac.assign_role_to_user']),
            ],
        ];
    }
}
