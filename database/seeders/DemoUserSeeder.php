<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Section;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demo users may only be seeded in local or testing environments.');
        }

        $accounts = [
            ['name' => 'Account Manager', 'role' => 'Account Manager', 'section' => 'ACCT', 'email' => 'account.manager@test.lra', 'password' => 'account123'],
            ['name' => 'Account Officer', 'role' => 'Account & Records Officer', 'section' => 'ACCT', 'email' => 'account.officer@test.lra', 'password' => 'account123'],
            ['name' => 'Assistant Commissioner', 'role' => 'Assistant Commissioner', 'section' => 'MGT', 'email' => 'ac@test.lra', 'password' => 'officer123'],
            ['name' => 'Enforcement Officer', 'role' => 'Enforcement Officer', 'section' => 'ENF', 'email' => 'enf.officer@test.lra', 'password' => 'officer123'],
            ['name' => 'Valuation Manager', 'role' => 'Valuation Manager', 'section' => 'VAL', 'email' => 'val.manager@test.lra', 'password' => 'officer123'],
            ['name' => 'Valuation Officer', 'role' => 'Valuation Officer', 'section' => 'VAL', 'email' => 'val.officer@test.lra', 'password' => 'officer123'],
        ];

        foreach ($accounts as $account) {
            $roleId = Role::where('name', $account['role'])->value('id');
            $sectionId = Section::where('code', $account['section'])->value('id');
            if (! $roleId || ! $sectionId) {
                throw new RuntimeException("Configuration seeding is required before demo user {$account['email']}.");
            }

            User::updateOrCreate(
                ['email' => $account['email']],
                [
                    'staff_id' => strtoupper(substr(md5($account['email']), 0, 6)),
                    'full_name' => $account['name'],
                    'password' => Hash::make($account['password']),
                    'role_id' => $roleId,
                    'section_id' => $sectionId,
                    'is_active' => true,
                    'must_reset_password' => false,
                ]
            );
        }

        $managerId = User::where('email', 'account.manager@test.lra')->value('id');
        User::where('email', 'account.officer@test.lra')->update(['supervisor_id' => $managerId]);
    }
}
