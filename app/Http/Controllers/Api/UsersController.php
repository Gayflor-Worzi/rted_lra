<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use App\Services\EffectivePermissionResolver;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UsersController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly EffectivePermissionResolver $effectivePermissions,
    ) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->canPermission('staff.view'), 403, 'Missing permission: staff.view');

        $query = User::query()->with(['role', 'section', 'supervisor:id,full_name']);

        if (in_array($request->user()->scopeLevel(), ['own', 'team', 'section'], true)) {
            $request->user()->applyScope($query, 'id');
        }

        if ($request->query('section') || $request->query('role')) {
            $roleName = $request->query('role');

            $query->when($request->query('section'), fn ($q, $v) => $q->where('section_id', $v))
                ->when($roleName, function ($q, $v) {
                    if (is_numeric($v)) {
                        $q->where('role_id', (int) $v);
                    } else {
                        $q->whereHas('role', fn ($r) => $r->where('name', $v));
                    }
                });
        }

        $query->when($request->query('q'), fn ($q, $v) => $q->where(function ($b) use ($v) {
            $b->where('full_name', 'like', like_term($v))->orWhere('email', 'like', like_term($v))->orWhere('staff_id', 'like', like_term($v));
        }));

        $rows = $query->orderBy('full_name')->paginate($request->query('per_page', 25))->withQueryString();
        $rows->getCollection()->transform(fn ($u) => $this->present($u));

        return response()->json(['data' => $rows]);
    }

    public function supervisors(Request $request)
    {
        $query = User::query()->with(['role', 'section'])->where('is_active', true);

        if (in_array($request->user()->scopeLevel(), ['own', 'team', 'section'], true)) {
            $request->user()->applyScope($query, 'id');
        }

        $rows = $query->orderBy('full_name')->get([
            'id', 'full_name', 'staff_id', 'role_id', 'section_id',
        ])->map(fn ($u) => [
            'id' => $u->id,
            'full_name' => $u->full_name,
            'staff_id' => $u->staff_id,
            'role' => $u->role?->name,
            'section' => $u->section?->name,
        ]);

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->canPermission('staff.create'), 403, 'Missing permission: staff.create');
        $data = $request->validate([
            'full_name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => 'required|string|min:8',
            'section_id' => ['required', 'integer', Rule::exists('sections', 'id')],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
            'supervisor_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'staff_id' => ['nullable', 'string', 'max:40', Rule::unique('users', 'staff_id')],
        ]);

        $actor = $request->user();

        $this->assertCanAssignRole($actor, Role::find($data['role_id']));

        $user = User::create(array_merge($data, [
            'is_active' => false,
            'must_reset_password' => true,
        ]));

        $this->audit->record($user, 'user.created', $actor->id, [], [
            'full_name' => $user->full_name,
            'staff_id' => $user->staff_id,
            'section_id' => $user->section_id,
            'role_id' => $user->role_id,
            'role' => Role::find($user->role_id)?->name,
            'supervisor_id' => $user->supervisor_id,
        ]);

        return response()->json([
            'data' => $this->present($user),
            'message' => 'Account created. It is inactive until activated; first login forces a password change.',
        ], 201);
    }

    public function update(Request $request, User $user)
    {
        abort_unless($request->user()->canPermission('staff.edit'), 403, 'Missing permission: staff.edit');

        $data = $request->validate([
            'full_name' => 'sometimes|string|max:255',
            'section_id' => ['sometimes', 'integer', Rule::exists('sections', 'id')],
            'role_id' => ['sometimes', 'integer', Rule::exists('roles', 'id')],
            'supervisor_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ]);

        if (isset($data['role_id']) && $data['role_id'] != $user->role_id) {
            $this->assertCanAssignRole($request->user(), Role::find($data['role_id']));
        }

        $oldRoleId = $user->role_id;
        $oldRole = $user->role?->name;

        $user->update($data);

        if (isset($data['role_id']) && (int) $data['role_id'] !== (int) $oldRoleId) {
            $this->audit->record($user, 'user.role_changed', $request->user()->id, [
                'role_id' => $oldRoleId,
                'role' => $oldRole,
            ], [
                'role_id' => (int) $data['role_id'],
                'role' => Role::find($data['role_id'])?->name,
            ]);
        }

        return response()->json(['data' => $this->present($user->fresh(['role', 'section'])), 'message' => 'Account updated.']);
    }

    public function setActive(Request $request, User $user)
    {
        abort_unless($request->user()->canPermission('staff.activate'), 403, 'Missing permission: staff.activate');

        $data = $request->validate([
            'is_active' => 'required|boolean',
        ]);

        if ($user->id === $request->user()->id && ! $data['is_active']) {
            return response()->json(['message' => 'You cannot deactivate your own account.'], 422);
        }

        $user->update(['is_active' => $data['is_active']]);

        $this->audit->record($user, 'user.'.($data['is_active'] ? 'activated' : 'deactivated'), $request->user()->id, [
            'is_active' => ! $data['is_active'],
        ], [
            'is_active' => $data['is_active'],
        ]);

        return response()->json([
            'data' => ['id' => $user->id, 'is_active' => $user->is_active],
            'message' => $data['is_active'] ? 'Account activated.' : 'Account deactivated.',
        ]);
    }

    /**
     * Force the account holder to choose a new password before they can use the
     * system again (first-login reset). Ends existing sessions so the change is
     * effective immediately.
     */
    public function forcePasswordReset(Request $request, User $user)
    {
        abort_unless($request->user()->canPermission('staff.edit'), 403, 'Missing permission: staff.edit');

        abort_unless($user->is_active, 422, 'Cannot force a password reset on a deactivated account.');

        $this->audit->record($user, 'user.password_reset_forced', $request->user()->id, [
            'must_reset_password' => $user->must_reset_password,
        ], [
            'must_reset_password' => true,
        ]);

        $user->update(['must_reset_password' => true]);
        // End current sessions: the holder must sign in again and reset first.
        $user->tokens()->delete();

        return response()->json([
            'data' => ['id' => $user->id, 'must_reset_password' => true],
            'message' => "Password reset forced for {$user->full_name}. They must set a new password on their next sign-in.",
        ]);
    }

    /**
     * Effective permission view for a staff member (spec 12): role, section,
     * data scope and the full granular catalogue marked granted/denied.
     */
    public function effectivePermissions(Request $request, User $user)
    {
        $actor = $request->user();
        abort_unless($actor->hasAnyPermission(['staff.view', 'rbac.assign_role_to_user', 'rbac.assign_permissions']), 403, 'Missing permission: staff/view RBAC.');

        if (in_array($actor->scopeLevel(), ['own', 'team', 'section'], true)) {
            $scoped = User::query()->where('id', $user->id);
            $actor->applyScope($scoped, 'id');
            if (! $scoped->exists()) {
                abort(403, 'Staff member outside your data scope.');
            }
        }

        $isAdmin = $user->isSystemAdministrator();
        $canEdit = $actor->hasAnyPermission(['rbac.assign_permissions', 'rbac.assign_role_to_user']);

        $resolved = $this->effectivePermissions->resolve($user);

        return response()->json(['data' => array_merge($resolved, [
            'user' => [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'staff_id' => $user->staff_id,
                'email' => $user->email,
                'is_active' => $user->is_active,
            ],
            'can_edit' => $canEdit && ! $isAdmin,
        ])]);
    }

    /**
     * Replace a user's individual permission overrides (grants/denies on top of
     * their role). Expects a map of permission name => allow (bool).
     */
    public function setPermissions(Request $request, User $user)
    {
        $actor = $request->user();
        abort_unless($actor->isSystemAdministrator() || $actor->hasAnyPermission(['rbac.assign_permissions', 'rbac.assign_role_to_user']), 403, 'Missing permission: rbac.assign_permissions');

        if ($user->isSystemAdministrator()) {
            return response()->json(['message' => 'The System Administrator role has full access and cannot be overridden.'], 422);
        }

        if (in_array($actor->scopeLevel(), ['own', 'team', 'section'], true)) {
            $scoped = User::query()->where('id', $user->id);
            $actor->applyScope($scoped, 'id');
            if (! $scoped->exists()) {
                abort(403, 'Staff member outside your data scope.');
            }
        }

        $data = $request->validate([
            'overrides' => 'array',
            'overrides.*' => 'boolean',
        ]);

        $overrides = $data['overrides'] ?? [];

        $user->syncPermissionOverrides($overrides);

        $this->audit->record($user, 'user.permissions_updated', $actor->id, [], [
            'overrides' => $overrides,
        ]);

        $fresh = $user->fresh(['role', 'permissionOverridesRelation.permission']);

        return response()->json([
            'data' => [
                'id' => $fresh->id,
                'full_name' => $fresh->full_name,
                'role' => $fresh->role?->name,
                'permissions' => $fresh->permissions(),
                'overrides' => $fresh->permissionOverrides(),
            ],
            'message' => $fresh->full_name.' permission overrides updated. They take effect on the next request/refresh.',
        ]);
    }

    /**
     * Guard role assignment: System Administrator may only be granted by another
     * System Administrator; other creators are limited to operational roles.
     */
    private function assertCanAssignRole(User $actor, ?Role $role): void
    {
        abort_unless($role, 422, 'Role not found.');

        if (! $role->is_active) {
            abort(422, 'Role is inactive.');
        }

        if ($actor->isSystemAdministrator()) {
            return;
        }

        if ($role->isSystemAdministrator()) {
            abort(403, 'Only a System Administrator can grant that role.');
        }

        $operational = [
            'Account & Records Officer', 'Account Supervisor', 'Enforcement Officer',
            'Enforcement Supervisor', 'M&E Officer', 'Valuation Officer', 'Valuation Supervisor',
        ];

        abort_unless(in_array($role->name, $operational, true), 403, "You may not create that role's accounts.");
    }

    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'staff_id' => $user->staff_id,
            'full_name' => $user->full_name,
            'email' => $user->email,
            'is_active' => $user->is_active,
            'must_reset_password' => $user->must_reset_password,
            'last_login_at' => $user->last_login_at?->toISOString(),
            'role' => $user->relationLoaded('role') ? $user->role?->name : null,
            'role_id' => $user->role_id,
            'section' => $user->relationLoaded('section') ? $user->section?->name : null,
            'section_id' => $user->section_id,
            'supervisor' => $user->relationLoaded('supervisor') ? $user->supervisor?->full_name : null,
            'supervisor_id' => $user->supervisor_id,
        ];
    }
}