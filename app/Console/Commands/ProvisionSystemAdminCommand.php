<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\Section;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class ProvisionSystemAdminCommand extends Command
{
    protected $signature = 'retd:provision-system-admin
                            {--name= : Full name of the authorized System Superadmin}
                            {--email= : Email address of the authorized System Superadmin}
                            {--staff-id= : Staff identifier of the authorized System Superadmin}';

    protected $description = 'Provision the one-time System Superadmin account';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Provisioning requires an interactive terminal so the password can be entered securely.');

            return self::FAILURE;
        }

        $identity = [
            'full_name' => trim((string) $this->option('name')),
            'email' => strtolower(trim((string) $this->option('email'))),
            'staff_id' => trim((string) $this->option('staff-id')),
        ];

        $validator = Validator::make($identity, [
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'staff_id' => ['required', 'string', 'max:255', 'unique:users,staff_id'],
        ]);

        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        $role = Role::where('name', Role::SYSTEM_ADMIN)->first();
        $section = Section::where('code', 'SYS')->first();

        if (! $role || ! $section) {
            $this->error('System Administrator role and SYS section must be configured before provisioning.');

            return self::FAILURE;
        }

        if (! $role->is_system_role
            || $role->default_scope !== User::SCOPE_SYSTEM
            || (int) $role->section_id !== (int) $section->id
            || ! $role->is_active) {
            $this->error('System Administrator role configuration conflicts with the expected system role.');

            return self::FAILURE;
        }

        if (User::where('role_id', $role->id)->exists()) {
            $this->error('A System Administrator already exists; provisioning is one-time only.');

            return self::FAILURE;
        }

        $password = $this->secret('Temporary password (minimum 12 characters)');
        $confirmation = $this->secret('Confirm temporary password');

        if (! is_string($password) || strlen($password) < 12) {
            $this->error('The temporary password must be at least 12 characters.');

            return self::FAILURE;
        }

        if (! is_string($confirmation) || ! hash_equals($password, $confirmation)) {
            $this->error('The password confirmation does not match.');

            return self::FAILURE;
        }

        if (! $this->confirm('Create the one-time System Superadmin account now?', false)) {
            $this->info('Provisioning cancelled; no account was created.');

            return self::FAILURE;
        }

        $result = DB::transaction(function () use ($identity, $password): ?string {
            $role = Role::where('name', Role::SYSTEM_ADMIN)->lockForUpdate()->first();
            $section = Section::where('code', 'SYS')->first();

            if (! $role || ! $section
                || ! $role->is_system_role
                || $role->default_scope !== User::SCOPE_SYSTEM
                || (int) $role->section_id !== (int) $section->id
                || ! $role->is_active) {
                return 'System Administrator role configuration changed; no account was created.';
            }

            if (User::where('role_id', $role->id)->exists()) {
                return 'A System Administrator already exists; provisioning is one-time only.';
            }

            if (User::where('email', $identity['email'])->orWhere('staff_id', $identity['staff_id'])->exists()) {
                return 'The email address or staff identifier is already in use; no account was changed.';
            }

            User::create([
                'full_name' => $identity['full_name'],
                'email' => $identity['email'],
                'staff_id' => $identity['staff_id'],
                'password' => Hash::make($password),
                'role_id' => $role->id,
                'section_id' => $section->id,
                'is_active' => true,
                'must_reset_password' => true,
            ]);

            return null;
        });

        if ($result !== null) {
            $this->error($result);

            return self::FAILURE;
        }

        $this->info('System Superadmin provisioned. A password change is required at first login.');

        return self::SUCCESS;
    }
}
