<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_bills', function (Blueprint $table) {
            $table->string('delivery_status')->default('Logged')->change();
        });

        DB::table('property_bills')
            ->whereIn('delivery_status', ['Pending Print', 'Printed'])
            ->update(['delivery_status' => 'Logged']);
    }

    public function down(): void
    {
        Schema::table('property_bills', function (Blueprint $table) {
            $table->string('delivery_status')->default('Pending Print')->change();
        });
    }
};