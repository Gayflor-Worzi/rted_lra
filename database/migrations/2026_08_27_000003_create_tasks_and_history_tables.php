<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Unified internal task engine. Every operational follow-up (delivery, visit,
     * payment follow-up, verification, valuation step, LITAS processing, M&E query)
     * is a Task referencing the domain record it is about (reference_type/reference_id).
     */
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('task_reference')->unique();        // TASK-YYYY-##### (internal only)
            $table->string('task_type');                        // Bill Delivery | Payment Follow-up | Payment Verification | Enforcement Visit | Valuation | Valuation Review | AC Approval | LITAS Processing | M&E Query | Other
            $table->string('section')->nullable();
            $table->string('reference_type')->nullable();       // property_bill | valuation | verification | ...
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->string('priority')->default('Normal');      // Low | Normal | High | Urgent
            $table->string('status');
            $table->date('due_date')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['reference_type', 'reference_id']);
            $table->index(['assigned_to', 'status']);
        });

        Schema::create('task_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('task_id')->index();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('action');
            $table->unsignedBigInteger('performed_by')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_histories');
        Schema::dropIfExists('tasks');
    }
};