<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add the property.export permission and its role grants.
 *
 * The Property Record sheet is the one document RETD sends out of the building, so
 * the authority to take a copy is deliberately a permission of its own rather than a
 * side effect of being allowed to view a property. Someone may legitimately read a
 * record all day and still not be the person who files a copy of it.
 *
 * It is added by migration rather than left to the seeder because the seeder also
 * rewrites role scope and the canonical demo users, and a deployed database may hold
 * real staff accounts. A narrowly scoped insert cannot disturb those. The seeder
 * still lists the permission, so a fresh install and an upgraded one agree.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permissionId = DB::table('permissions')->where('name', 'property.export')->value('id');

        if (! $permissionId) {
            $permissionId = DB::table('permissions')->insertGetId([
                'module' => 'property',
                'action' => 'export',
                'name' => 'property.export',
                'description' => 'Print and download Property Record documents (PDF)',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Granted to exactly the roles that already hold reports.print. Anyone
        // authorised to produce official reports is the population that produces
        // official documents, and holding the two lists separately is how an export
        // ends up in the hands of a role nobody expected. Roles created later pick
        // the permission up from the seeder, so this only has to cover the ones that
        // exist at upgrade time.
        $roleIds = DB::table('roles')->whereIn('name', DB::table('roles')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'roles.id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('p.name', 'reports.print')
            ->pluck('roles.name'))->pluck('id');

        foreach ($roleIds as $roleId) {
            $exists = DB::table('role_permissions')
                ->where('role_id', $roleId)
                ->where('permission_id', $permissionId)
                ->exists();

            if (! $exists) {
                DB::table('role_permissions')->insert([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permissionId = DB::table('permissions')->where('name', 'property.export')->value('id');

        if (! $permissionId) {
            return;
        }

        DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
        DB::table('user_permissions')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
    }
};
