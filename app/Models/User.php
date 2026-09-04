<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    public const SCOPE_OWN = 'own';
    public const SCOPE_TEAM = 'team';
    public const SCOPE_SECTION = 'section';
    public const SCOPE_DIVISION = 'division';
    public const SCOPE_SYSTEM = 'system';

    protected $fillable = [
        'staff_id',
        'full_name',
        'email',
        'password',
        'section_id',
        'role_id',
        'is_active',
        'must_reset_password',
        'supervisor_id',
        'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'must_reset_password' => 'boolean',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'section_id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function staff(): HasMany
    {
        return $this->hasMany(User::class, 'supervisor_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(\App\Models\Notification::class, 'user_id');
    }

    /**
     * Per-user permission overrides keyed by permission name.
     * Each value is 'allow' (explicit grant) or 'deny' (explicit denial).
     */
    public function permissionOverrides(): array
    {
        return $this->permissionOverridesRelation()
            ->with('permission')
            ->get()
            ->mapWithKeys(fn ($row) => [
                $row->permission->name => $row->allow ? 'allow' : 'deny',
            ])
            ->all();
    }

    public function permissionOverridesRelation(): HasMany
    {
        return $this->hasMany(UserPermission::class, 'user_id');
    }

    /**
     * Effective permission checklist = role permissions + individual grants,
     * minus individual denials. System Administrator keeps the '*' wildcard
     * (full access) and is not affected by overrides.
     */
    public function permissions(): array
    {
        if (! $this->role) {
            return [];
        }

        if ($this->role->isSystemAdministrator()) {
            return ['*'];
        }

        $rolePerms = $this->role->permissions()->pluck('name')->all();

        $overrides = $this->permissionOverrides();
        if (empty($overrides)) {
            return $rolePerms;
        }

        $granted = array_filter($overrides, fn ($v) => $v === 'allow');

        $steps = array_merge($rolePerms, array_keys($granted));

        $denied = array_filter($overrides, fn ($v) => $v === 'deny');

        return array_values(array_unique(array_diff($steps, array_keys($denied))));
    }

    /**
     * For a given permission name return the tri-state resolution relative to
     * the role: 'allow' | 'deny' | null (inherit).
     */
    public function overrideStateFor(string $permission): ?string
    {
        return $this->permissionOverrides()[$permission] ?? null;
    }

    /**
     * Replace this user's permission overrides in bulk.
     *
     * @param  array<string, bool>  $overrides  map of permission name => true (grant) / false (deny)
     */
    public function syncPermissionOverrides(array $overrides): void
    {
        $names = array_keys($overrides);

        $ids = \App\Models\Permission::whereIn('name', $names)->pluck('id', 'name');

        $rows = [];
        foreach ($overrides as $name => $allow) {
            if (! isset($ids[$name])) {
                continue;
            }
            $rows[$ids[$name]] = ['allow' => (bool) $allow, 'updated_at' => now(), 'created_at' => now()];
        }

        $this->permissionOverridesRelation()->getQuery()->delete();

        if (! empty($rows)) {
            $this->permissionOverridesRelation()->getQuery()->insert(
                array_map(fn ($permissionId, $attrs) => array_merge(
                    ['user_id' => $this->id, 'permission_id' => $permissionId],
                    $attrs
                ), array_keys($rows), $rows)
            );
        }
    }

    /** Permission check (RBAC permission checklist). '*' grants everything. */
    public function canPermission(string $permission): bool
    {
        $perms = $this->permissions();

        if (in_array('*', $perms, true)) {
            return true;
        }

        return in_array($permission, $perms, true);
    }

    public function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->canPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    public function hasRole(string|array $roles): bool
    {
        if (! $this->role) {
            return false;
        }

        return in_array($this->role->name, (array) $roles, true);
    }

    public function hasAnyRole(array $roles): bool
    {
        return $this->hasRole($roles);
    }

    /** Data access scope: own | team | section | division | system */
    public function scopeLevel(): string
    {
        if (! $this->role) {
            return self::SCOPE_OWN;
        }

        return $this->role->default_scope ?? self::SCOPE_OWN;
    }

    public function isSystemAdministrator(): bool
    {
        return $this->role?->isSystemAdministrator() ?? false;
    }

    /** Apply the user's data scope to an owner-column query builder. */
    public function applyScope($query, string $ownerColumn = 'assigned_to'): void
    {
        $scope = $this->scopeLevel();

        if ($scope === self::SCOPE_SYSTEM || $scope === self::SCOPE_DIVISION) {
            return; // unrestricted
        }

        if ($scope === self::SCOPE_SECTION) {
            $query->whereIn($ownerColumn, function ($q) {
                $q->select('id')->from('users')
                    ->where('section_id', $this->section_id)
                    ->where('is_active', true);
            });

            return;
        }

        if ($scope === self::SCOPE_TEAM) {
            $column = $ownerColumn;
            $query->where(function ($q) use ($column) {
                $q->where($column, $this->id)
                    ->orWhereIn($column, function ($qq) {
                        $qq->select('id')->from('users')
                            ->where('supervisor_id', $this->id)
                            ->where('is_active', true);
                    });
            });

            return;
        }

        // OWN
        $query->where($ownerColumn, $this->id);
    }
}