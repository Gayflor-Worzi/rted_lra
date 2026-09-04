<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Staff performance targets (role/metric based)
        Schema::create('staff_targets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('metric'); // bills_delivered | visits | payment_followups | bills_logged | payment_verifications | valuations | reassessments | collections_amount | custom
            $table->decimal('target_value', 14, 2)->default(0);
            $table->decimal('achieved_value', 14, 2)->default(0);
            $table->string('period', 20); // e.g. 2026 or 2026-Q1
            $table->string('status')->default('Active');
            $table->timestamps();
        });

        // In-app workflow notifications
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('title')->nullable();
            $table->text('message');
            $table->string('type')->default('info');
            $table->string('action_url')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        // Immutable SHA-256 chained audit trail
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action');
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('previous_hash', 64)->nullable();
            $table->string('hash', 64)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['auditable_type', 'auditable_id']);
            $table->index('actor_id');
        });

        // Polymorphic attachments (receipts, LITAS bill, property photos, evidence)
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->string('attachable_type')->nullable();
            $table->unsignedBigInteger('attachable_id')->nullable();
            $table->string('doc_type');
            $table->string('label')->nullable();
            $table->string('path');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['attachable_type', 'attachable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('staff_targets');
    }
};