<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add the GPS accuracy field to valuations — it is captured by field officers,
 * surfaced by the mobile form, and previously validated but silently dropped
 * because the column did not exist. Matches property_discoveries.gps_accuracy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('valuations', function (Blueprint $table) {
            $table->decimal('gps_accuracy', 10, 2)->nullable()->after('gps_coordinate');
        });
    }

    public function down(): void
    {
        Schema::table('valuations', function (Blueprint $table) {
            $table->dropColumn('gps_accuracy');
        });
    }
};
