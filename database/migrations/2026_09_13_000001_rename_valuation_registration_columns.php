<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Terminology / system-boundary correction (spec): RETD records the completed
 * registration event on its own record — it does not perform the registration
 * itself. The registration outcome columns are renamed to match.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('valuations', function (Blueprint $table) {
            $table->renameColumn('litas_processing_status', 'registration_status');
            $table->renameColumn('litas_processed_by', 'registration_completed_by');
            $table->renameColumn('litas_processed_at', 'registration_completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('valuations', function (Blueprint $table) {
            $table->renameColumn('registration_status', 'litas_processing_status');
            $table->renameColumn('registration_completed_by', 'litas_processed_by');
            $table->renameColumn('registration_completed_at', 'litas_processed_at');
        });
    }
};
