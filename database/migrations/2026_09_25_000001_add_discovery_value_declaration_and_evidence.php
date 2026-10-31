<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * System-wide Property-Discovery value model:
 *
 * 1. `property_discoveries` gains the controlled Value Declaration Type and the
 *    kept-separate declared / documentary / official assessed value columns.
 * 2. A dedicated `property_discovery_evidence` table holds ownership/deed
 *    documents (OWNERSHIP_DOCUMENT) and up to three sequenced property
 *    photographs (PROPERTY_PHOTO, sequence_no 1-3) with full capture metadata.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_discoveries', function (Blueprint $table) {
            $table->string('value_declaration_type', 24)->nullable()->after('declared_value');
            $table->decimal('declared_assessed_value', 14, 2)->nullable()->after('value_declaration_type');
            $table->decimal('documentary_value', 14, 2)->nullable()->after('declared_assessed_value');
            $table->decimal('official_assessed_value', 14, 2)->nullable()->after('documentary_value');
        });

        Schema::create('property_discovery_evidence', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('discovery_id');
            $table->string('property_id', 60)->nullable();
            $table->string('evidence_type', 24);
            $table->unsignedTinyInteger('sequence_no')->nullable();
            $table->string('file_path', 255);
            $table->string('original_name', 255)->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('capture_source', 12)->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->unsignedBigInteger('captured_by')->nullable();
            $table->decimal('gps_latitude', 10, 7)->nullable();
            $table->decimal('gps_longitude', 10, 7)->nullable();
            $table->string('sync_status', 16)->default('SYNCED');
            $table->timestamps();

            $table->index(['discovery_id', 'evidence_type']);
            $table->foreign('discovery_id')->references('id')->on('property_discoveries')->cascadeOnDelete();
            $table->foreign('captured_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_discovery_evidence');

        Schema::table('property_discoveries', function (Blueprint $table) {
            $table->dropColumn([
                'value_declaration_type',
                'declared_assessed_value',
                'documentary_value',
                'official_assessed_value',
            ]);
        });
    }
};
