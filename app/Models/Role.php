<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    public const SYSTEM_ADMIN = 'System Administrator';

    protected $fillable = [
        'name',
        'description',
        'section_id',
        'is_system_role',
        'is_active',
        'default_scope',
    ];

    protected $casts = [
        'is_system_role' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions', 'role_id', 'permission_id');
    }

    public function isSystemAdministrator(): bool
    {
        return $this->name === self::SYSTEM_ADMIN;
    }

    public function grant(string|Permission $permission): void
    {
        $permission = $permission instanceof Permission ? $permission : Permission::firstOrCreate(
            ['name' => $permission],
            [
                'module' => explode('.', $permission)[0] ?? 'general',
                'action' => explode('.', $permission)[1] ?? $permission,
            ]
        );

        $this->permissions()->syncWithoutDetaching($permission->id);
    }

    public function revoke(string|Permission $permission): void
    {
        $permission = $permission instanceof Permission ? $permission : Permission::where('name', $permission)->first();

        if ($permission) {
            $this->permissions()->detach($permission->id);
        }
    }
}