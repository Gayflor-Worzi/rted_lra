<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-user permission overrides on top of the role permission checklist.
        // A row tri-states each permission for a user:
        //   - no row        => inherit from role
        //   - allow = true  => explicitly granted (even if the role does not grant)
        //   - allow = false => explicitly denied (even if the role does grant)
        Schema::create('user_permissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('permission_id')->index();
            $table->boolean('allow');
            $table->timestamps();

            $table->unique(['user_id', 'permission_id']);

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permissions');
    }
};
