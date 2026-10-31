<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('role_permissions')) {
            return;
        }

        $permissions = [
            'enforcement.org.manage' => [
                'action' => 'org_manage',
                'description' => 'Manage Enforcement teams and officer memberships',
            ],
            'enforcement.org.recommend' => [
                'action' => 'org_recommend',
                'description' => 'Recommend officer membership changes within supervised Enforcement teams',
            ],
            'enforcement.org.review' => [
                'action' => 'org_review',
                'description' => 'Approve or reject Enforcement team and district structure changes',
            ],
        ];

        foreach ($permissions as $name => $details) {
            $permissionId = DB::table('permissions')->where('name', $name)->value('id');
            if (! $permissionId) {
                $permissionId = DB::table('permissions')->insertGetId([
                    'module' => 'enforcement',
                    'action' => $details['action'],
                    'name' => $name,
                    'description' => $details['description'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $roleNames = match ($name) {
                'enforcement.org.manage' => ['Enforcement Manager'],
                'enforcement.org.recommend' => ['Enforcement Supervisor'],
                'enforcement.org.review' => ['Assistant Commissioner'],
            };

            $roleIds = DB::table('roles')->whereIn('name', $roleNames)->pluck('id');
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
    }

    public function down(): void
    {
        // Keep permission grants intact on rollback; they may have been customized after migration.
    }
};
