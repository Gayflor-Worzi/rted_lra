<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Terminology correction (spec): payment / verification records do not name
 * the source system — they carry a source-system reference and its
 * verification status. Columns are renamed to match RETD's own vocabulary.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_verifications', function (Blueprint $table) {
            $table->renameColumn('litas_verification_status', 'source_verification_status');
            $table->renameColumn('litas_reference', 'source_reference');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->renameColumn('litas_reference', 'source_reference');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->renameColumn('source_reference', 'litas_reference');
        });

        Schema::table('payment_verifications', function (Blueprint $table) {
            $table->renameColumn('source_reference', 'litas_reference');
            $table->renameColumn('source_verification_status', 'litas_verification_status');
        });
    }
};
