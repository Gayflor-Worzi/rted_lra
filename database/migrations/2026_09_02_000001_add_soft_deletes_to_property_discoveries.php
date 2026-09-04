<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Property Discovery now uses soft deletes — matching the audit-preservation
 * guarantees already in place for valuations and bills (spec #8 / #15). A
 * discovery record must never be hard-deleted or overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_discoveries', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('property_discoveries', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
