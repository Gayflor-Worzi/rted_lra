<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Field visit against a bill/task. Snapshot columns (outstanding/payment/case)
        // are written once at creation from the live bill and are read-only afterwards.
        Schema::create('enforcement_visits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('task_id')->nullable()->index();
            $table->unsignedBigInteger('bill_id')->nullable()->index();
            $table->string('document_number')->nullable();
            $table->string('property_id')->nullable();
            $table->unsignedBigInteger('officer_id')->index();
            $table->date('visit_date');
            $table->string('visit_status');
            $table->string('delivery_status')->nullable();
            $table->string('recipient_name')->nullable();
            $table->string('recipient_contact')->nullable();
            $table->string('gps_coordinate', 100)->nullable();
            $table->string('visit_photo')->nullable();
            $table->text('remarks')->nullable();
            $table->string('next_action')->nullable();
            $table->date('next_followup_date')->nullable();
            // read-only snapshot captured at visit time
            $table->decimal('snapshot_outstanding', 14, 2)->nullable();
            $table->string('snapshot_payment_status')->nullable();
            $table->string('snapshot_case_status')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enforcement_visits');
    }
};