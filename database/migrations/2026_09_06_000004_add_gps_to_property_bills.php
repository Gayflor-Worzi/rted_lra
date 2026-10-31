<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_bills', function (Blueprint $table) {
            $table->string('gps_coordinate', 100)->nullable()->after('property_address');
            $table->decimal('gps_accuracy', 10, 2)->nullable()->after('gps_coordinate');
            $table->timestamp('gps_captured_at')->nullable()->after('gps_accuracy');
        });
    }

    public function down(): void
    {
        Schema::table('property_bills', function (Blueprint $table) {
            $table->dropColumn(['gps_coordinate', 'gps_accuracy', 'gps_captured_at']);
        });
    }
};
