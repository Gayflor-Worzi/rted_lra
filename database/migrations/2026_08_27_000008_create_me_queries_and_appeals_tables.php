<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // M&E queries (cross-sectional monitoring)
        Schema::create('me_queries', function (Blueprint $table) {
            $table->id();
            $table->string('query_reference')->unique(); // MEQ-YYYY-#####
            $table->string('title');
            $table->text('description');
            $table->string('priority')->default('Normal');
            $table->string('status')->default('Open'); // Open | Answered | Closed
            $table->unsignedBigInteger('raised_by')->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('response')->nullable();
            $table->unsignedBigInteger('responded_by')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        // Appeals (taxpayer challenge against a LITAS bill)
        Schema::create('appeals', function (Blueprint $table) {
            $table->id();
            $table->string('appeal_reference')->unique(); // APP-YYYY-#####
            $table->unsignedBigInteger('bill_id')->nullable()->index();
            $table->string('document_number')->nullable();
            $table->string('property_id')->nullable();
            $table->string('taxpayer_name')->nullable();
            $table->string('reason');
            $table->text('description')->nullable();
            $table->string('status')->default('Submitted'); // Submitted | Under Review | Upheld | Adjusted | Dismissed | Withdrawn
            $table->string('decision')->nullable();
            $table->text('decision_notes')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appeals');
        Schema::dropIfExists('me_queries');
    }
};