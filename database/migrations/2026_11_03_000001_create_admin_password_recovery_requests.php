<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'password_changed_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('password_changed_at')->nullable();
            });
        }

        if (! Schema::hasTable('admin_password_recovery_requests')) {
            Schema::create('admin_password_recovery_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('request_token_hash', 64)->unique();
                $table->string('otp_hash')->nullable();
                $table->timestamp('expires_at');
                $table->unsignedTinyInteger('failed_attempts')->default(0);
                $table->timestamp('verified_at')->nullable();
                $table->string('reset_token_hash', 64)->nullable()->unique();
                $table->timestamp('reset_expires_at')->nullable();
                $table->timestamp('consumed_at')->nullable();
                $table->timestamp('invalidated_at')->nullable();
                $table->string('request_ip', 45)->nullable();
                $table->string('request_user_agent', 500)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasIndex('admin_password_recovery_requests', 'apr_user_state_index')) {
            Schema::table('admin_password_recovery_requests', function (Blueprint $table) {
                $table->index(['user_id', 'consumed_at', 'invalidated_at'], 'apr_user_state_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_password_recovery_requests');

        if (Schema::hasColumn('users', 'password_changed_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('password_changed_at');
            });
        }
    }
};
