<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RolesController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->hasAnyPermission(['rbac.assign_permissions', 'rbac.edit_role', 'rbac.create_role']), 403);

        $roles = Role::with('permissions')->orderBy('id')->get()->map(fn ($r) => $this->present($r));

        return response()->json(['data' => $roles]);
    }

    public function permissions(Request $request)
    {
        abort_unless($request->user()->hasAnyPermission(['rbac.assign_permissions', 'rbac.edit_role']), 403);

        $catalog = collect(config('permissions'))
            ->groupBy('module')
            ->map(fn ($group) => $group->values()->map(fn ($p) => [
                'name' => $p['name'],
                'action' => $p['action'],
                'description' => $p['description'] ?? null,
            ]));

        return response()->json(['data' => $catalog]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->canPermission('rbac.create_role'), 403, 'Missing permission: rbac.create_role');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('roles', 'name')],
            'description' => ['nullable', 'string', 'max:255'],
            'section_id' => ['nullable', 'integer', Rule::exists('sections', 'id')],
            'default_scope' => ['required', Rule::in(['own', 'team', 'section', 'division', 'system'])],
        ]);

        $role = Role::create(array_merge($data, ['is_active' => true, 'is_system_role' => false]));

        $this->audit->record($role, 'role.created', $request->user()->id, [], [
            'name' => $role->name,
            'description' => $role->description,
            'default_scope' => $role->default_scope,
            'section_id' => $role->section_id,
            'permissions' => [],
        ]);

        return response()->json(['data' => $this->present($role->fresh('permissions')), 'message' => 'Role created.'], 201);
    }

    /**
     * Clone an existing role (metadata + permission checklist) and save as a new role.
     */
    public function clone(Request $request, Role $role)
    {
        abort_unless($request->user()->canPermission('rbac.create_role'), 403, 'Missing permission: rbac.create_role');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('roles', 'name')],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $copy = Role::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? ($role->description ? $role->description.' (copy)' : null),
            'section_id' => $role->section_id,
            'default_scope' => $role->default_scope,
            'is_active' => $role->is_active,
            'is_system_role' => false,
        ]);

        $copy->permissions()->sync($role->permissions()->pluck('permissions.id'));

        $this->audit->record($copy, 'role.cloned', $request->user()->id, [], [
            'name' => $copy->name,
            'source_role_id' => $role->id,
            'source_role' => $role->name,
            'default_scope' => $copy->default_scope,
            'permission_count' => $copy->permissions()->count(),
        ]);

        return response()->json(['data' => $this->present($copy->fresh('permissions')), 'message' => 'Role cloned.'], 201);
    }

    /**
     * Update a role: metadata + the permission checklist (RBAC admin UI).
     */
    public function update(Request $request, Role $role)
    {
        abort_unless($request->user()->canPermission('rbac.assign_permissions'), 403, 'Missing permission: rbac.assign_permissions');

        if ($role->isSystemAdministrator()) {
            return response()->json(['message' => 'The System Administrator role is protected and cannot be modified.'], 422);
        }

        $data = $request->validate([
            'description' => ['nullable', 'string', 'max:255'],
            'section_id' => ['nullable', 'integer', Rule::exists('sections', 'id')],
            'default_scope' => ['sometimes', Rule::in(['own', 'team', 'section', 'division', 'system'])],
            'is_active' => ['sometimes', 'boolean'],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ]);

        $old = [
            'description' => $role->description,
            'section_id' => $role->section_id,
            'default_scope' => $role->default_scope,
            'is_active' => $role->is_active,
            'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
        ];

        $role->update([
            'description' => $data['description'] ?? $role->description,
            'section_id' => $data['section_id'] ?? $role->section_id,
            'default_scope' => $data['default_scope'] ?? $role->default_scope,
            'is_active' => $data['is_active'] ?? $role->is_active,
        ]);

        if (array_key_exists('permissions', $data)) {
            $role->permissions()->sync(Permission::whereIn('name', $data['permissions'])->pluck('id'));
        }

        $role->refresh();

        $new = [
            'description' => $role->description,
            'section_id' => $role->section_id,
            'default_scope' => $role->default_scope,
            'is_active' => $role->is_active,
            'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
        ];

        if ($old !== $new) {
            $this->audit->record($role, 'role.updated', $request->user()->id, $old, $new);
        }

        return response()->json([
            'data' => $this->present($role->fresh('permissions')),
            'message' => 'Role updated.',
        ]);
    }

    private function present(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'description' => $role->description,
            'section_id' => $role->section_id,
            'is_system_role' => $role->is_system_role,
            'is_active' => $role->is_active,
            'default_scope' => $role->default_scope,
            'permissions' => $role->relationLoaded('permissions') ? $role->permissions->pluck('name')->values() : [],
        ];
    }
}