<?php

namespace Tests\Feature\Concerns;

use App\Models\Role;
use App\Models\Section;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

trait SystemAdministratorFixture
{
    protected function systemAdministratorFixture(string $email = 'system.admin@test.lra'): User
    {
        return User::create([
            'staff_id' => strtoupper(Str::random(10)),
            'full_name' => 'Test System Administrator',
            'email' => $email,
            'password' => Hash::make(Str::random(48)),
            'section_id' => Section::where('code', 'SYS')->value('id'),
            'role_id' => Role::where('name', Role::SYSTEM_ADMIN)->value('id'),
            'is_active' => true,
            'must_reset_password' => false,
        ]);
    }
}
