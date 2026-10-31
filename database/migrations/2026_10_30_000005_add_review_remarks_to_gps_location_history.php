<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gps_location_history', function (Blueprint $table) {
            $table->text('review_remarks')->nullable()->after('remarks');
        });
    }

    public function down(): void
    {
        Schema::table('gps_location_history', function (Blueprint $table) {
            $table->dropColumn('review_remarks');
        });
    }
};
