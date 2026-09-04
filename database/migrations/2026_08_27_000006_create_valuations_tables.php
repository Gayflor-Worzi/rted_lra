<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Valuation workflow: valuer prepares -> Supervisor -> Valuation Manager review
     * -> AC approval -> Account Manager marks "Processed in LITAS" (confirmation,
     * not integration).
     */
    public function up(): void
    {
        Schema::create('valuations', function (Blueprint $table) {
            $table->id();
            $table->string('valuation_reference')->unique(); // VAL-YYYY-##### (internal)
            $table->string('valuation_type')->default('new_property'); // new_property | reassessment
            $table->unsignedBigInteger('bill_id')->nullable()->index();
            $table->string('property_id')->nullable();
            $table->string('document_number')->nullable();
            $table->string('owner_name')->nullable();
            $table->string('tin', 40)->nullable();
            $table->string('property_classification')->nullable();
            $table->string('property_address')->nullable();
            $table->string('land_dimensions')->nullable();
            $table->string('building_specs')->nullable();
            $table->string('construction_year', 10)->nullable();
            $table->string('condition')->nullable();
            $table->decimal('assessed_value', 14, 2)->nullable();
            $table->decimal('annual_tax', 14, 2)->nullable();
            $table->string('photos')->nullable(); // comma-separated paths
            $table->string('gps_coordinate', 100)->nullable();
            $table->unsignedBigInteger('valuation_officer_id')->nullable()->index();
            $table->string('status')->default('Draft'); // Draft|Submitted|Manager Review|Returned|AC Approval|Approved|Rejected
            $table->string('manager_decision')->nullable();
            $table->text('manager_remarks')->nullable();
            $table->unsignedBigInteger('manager_reviewed_by')->nullable();
            $table->timestamp('manager_reviewed_at')->nullable();
            $table->string('ac_decision')->nullable();
            $table->text('ac_remarks')->nullable();
            $table->unsignedBigInteger('ac_reviewed_by')->nullable();
            $table->timestamp('ac_reviewed_at')->nullable();
            $table->string('litas_processing_status')->default('Pending'); // Pending | Processed in LITAS
            $table->unsignedBigInteger('litas_processed_by')->nullable();
            $table->timestamp('litas_processed_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('valuation_reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('valuation_id')->index();
            $table->string('stage'); // supervisor | manager | ac
            $table->string('decision'); // forward | return | request_clarification | approve | reject
            $table->unsignedBigInteger('reviewer_id')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('valuation_reviews');
        Schema::dropIfExists('valuations');
    }
};