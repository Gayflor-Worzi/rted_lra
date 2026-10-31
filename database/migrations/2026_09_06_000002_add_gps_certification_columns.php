<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Surface GPS certification state on valuations and property discoveries,
 * mirroring what enforcement_visits carry — so the field app knows whether an
 * engagement's captured GPS has been certified as the actual property location.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['valuations', 'property_discoveries'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('gps_verified', 10)->nullable(); // yes | no | null (pending)
                $t->unsignedBigInteger('gps_certified_by')->nullable();
                $t->timestamp('gps_certified_at')->nullable();
                $t->text('gps_cert_remarks')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['valuations', 'property_discoveries'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn(['gps_verified', 'gps_certified_by', 'gps_certified_at', 'gps_cert_remarks']);
            });
        }
    }
};
