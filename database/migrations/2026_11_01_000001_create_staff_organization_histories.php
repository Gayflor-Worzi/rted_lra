<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_organization_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('role_id')->nullable();
            $table->string('role_name')->nullable();
            $table->string('role_scope', 20)->nullable();
            $table->unsignedBigInteger('role_section_id')->nullable();
            $table->unsignedBigInteger('section_id')->nullable();
            $table->unsignedBigInteger('supervisor_id')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'effective_from']);
            // Explicit short names: MySQL rejects identifiers over 64 characters,
            // and the auto-generated composite index names exceeded that limit.
            $table->index(['supervisor_id', 'effective_from', 'effective_to'], 'staff_org_history_supervisor_range_index');
            $table->index(['section_id', 'effective_from', 'effective_to'], 'staff_org_history_section_range_index');
        });

        DB::table('users')
            ->leftJoin('roles', 'roles.id', '=', 'users.role_id')
            ->select([
                'users.id as user_id',
                'users.role_id',
                'roles.name as role_name',
                'roles.default_scope as role_scope',
                'roles.section_id as role_section_id',
                'users.section_id',
                'users.supervisor_id',
            ])
            ->orderBy('users.id')
            ->chunk(500, function ($users): void {
                // Start historical attribution at rollout; do not invent older relationship dates.
                $today = today()->toDateString();
                $now = now();
                $rows = $users->map(fn ($user) => [
                    'user_id' => $user->user_id,
                    'role_id' => $user->role_id,
                    'role_name' => $user->role_name,
                    'role_scope' => $user->role_scope,
                    'role_section_id' => $user->role_section_id,
                    'section_id' => $user->section_id,
                    'supervisor_id' => $user->supervisor_id,
                    'effective_from' => $today,
                    'effective_to' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                DB::table('staff_organization_histories')->insert($rows);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_organization_histories');
    }
};
