<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // New Property Discovery — first-class workflow. Property ID / Document #
        // are LITAS identifiers: blank until the source system creates them.
        Schema::create('property_discoveries', function (Blueprint $table) {
            $table->id();
            $table->string('discovery_reference')->unique();
            // Workflow: DISCOVERED → SUBMITTED → UNDER_MANAGER_REVIEW → CLASSIFIED
            // → SENT_TO_ACCOUNT | VALUATION_REQUIRED → ... → PROCESSED_IN_LITAS → COMPLETED
            $table->string('status')->default('DISCOVERED')->index();

            // Property information (LITAS IDs stay blank — never generated)
            $table->string('owner_name')->nullable();
            $table->string('owner_contact')->nullable();
            $table->string('tin', 40)->nullable();
            $table->string('property_address', 255)->nullable();
            $table->string('county', 100)->nullable();
            $table->string('district', 100)->nullable();
            $table->string('city_town', 100)->nullable();
            $table->string('community', 100)->nullable();
            $table->string('street', 150)->nullable();
            $table->string('house_number', 50)->nullable();
            $table->string('property_classification', 100)->nullable();
            $table->string('property_type', 100)->nullable();
            $table->string('occupancy_use', 100)->nullable();
            $table->text('description')->nullable();

            // LITAS identifiers — filled only when the source output exists
            $table->string('property_id', 60)->nullable()->index();
            $table->string('document_number', 80)->nullable()->index();

            // GPS + camera evidence
            $table->string('gps_coordinate', 100)->nullable();
            $table->decimal('gps_accuracy', 10, 2)->nullable();
            $table->timestamp('gps_captured_at')->nullable();

            $table->date('discovery_date')->nullable();
            $table->unsignedBigInteger('discovered_by')->nullable()->index();

            // Classification / routing (Valuation Manager step)
            $table->string('decision_path', 20)->nullable(); // account | valuation
            $table->string('classification_decision', 150)->nullable();
            $table->unsignedBigInteger('classified_by')->nullable();
            $table->timestamp('classified_at')->nullable();
            $table->string('manager_remarks', 2000)->nullable();

            // Valuation routing (Path B)
            $table->unsignedBigInteger('valuation_id')->nullable()->index();

            // AC approval (Path B)
            $table->unsignedBigInteger('ac_decided_by')->nullable();
            $table->timestamp('ac_decided_at')->nullable();
            $table->string('ac_decision', 20)->nullable(); // approved | rejected
            $table->string('ac_remarks', 2000)->nullable();

            // Downstream processing
            $table->unsignedBigInteger('processed_by')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('remarks', 2000)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['discovered_by', 'status']);
            $table->foreign('discovered_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('classified_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('ac_decided_by')->references('id')->on('users')->nullOnDelete();
        });

        // Link valuations to their source discovery (Path B routing)
        Schema::table('valuations', function (Blueprint $table) {
            $table->foreignId('discovery_id')->nullable()->after('bill_id')->constrained('property_discoveries')->nullOnDelete();
        });

        // Link photos to discoveries in the existing evidence registry
        Schema::table('evidence_photos', function (Blueprint $table) {
            $table->foreignId('discovery_id')->nullable()->after('valuation_id')->constrained('property_discoveries')->nullOnDelete();
        });

        // Extend staff targets with the target-management fields
        Schema::table('staff_targets', function (Blueprint $table) {
            $table->string('section')->nullable()->after('metric');
            $table->string('measurement_unit', 50)->nullable()->after('achieved_value');
            $table->date('start_date')->nullable()->after('measurement_unit');
            $table->date('end_date')->nullable()->after('start_date');
            $table->string('frequency', 20)->nullable()->after('end_date'); // Daily|Weekly|Monthly|Quarterly|Annual
            $table->unsignedBigInteger('created_by')->nullable()->after('frequency');
            $table->unsignedBigInteger('approved_by')->nullable()->after('created_by');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->index(['user_id', 'metric', 'period']);
        });

        // M&E data-quality flags (raised against bills)
        Schema::create('data_quality_flags', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bill_id')->nullable()->index();
            $table->string('issue', 255);
            $table->string('severity', 20)->default('Moderate'); // Low|Moderate|High
            $table->string('status', 20)->default('Open'); // Open|In Progress|Resolved
            $table->unsignedBigInteger('flagged_by')->nullable();
            $table->timestamp('flagged_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_remarks', 2000)->nullable();
            $table->timestamps();

            $table->foreign('bill_id')->references('id')->on('property_bills')->nullOnDelete();
            $table->foreign('flagged_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('resolved_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_quality_flags');
        Schema::table('staff_targets', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'metric', 'period']);
            $table->dropColumn(['section', 'measurement_unit', 'start_date', 'end_date', 'frequency', 'created_by', 'approved_by', 'approved_at']);
        });
        Schema::table('evidence_photos', function (Blueprint $table) {
            $table->dropForeign(['discovery_id']);
            $table->dropColumn('discovery_id');
        });
        Schema::table('valuations', function (Blueprint $table) {
            $table->dropForeign(['discovery_id']);
            $table->dropColumn('discovery_id');
        });
        Schema::dropIfExists('property_discoveries');
    }
};