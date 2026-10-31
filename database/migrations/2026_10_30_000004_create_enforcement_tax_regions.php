<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enforcement_tax_regions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('code', 40)->unique();
            $table->string('classification', 12);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('supervisor_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamps();

            $table->unique('name');
            $table->foreign('supervisor_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('enforcement_districts', function (Blueprint $table) {
            $table->unsignedBigInteger('region_id')->nullable()->index();
            $table->foreign('region_id')->references('id')->on('enforcement_tax_regions')->restrictOnDelete();
        });

        Schema::create('enforcement_tax_region_supervisor_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('region_id')->index();
            $table->unsignedBigInteger('supervisor_id')->index();
            $table->unsignedBigInteger('assigned_by')->index();
            $table->unsignedBigInteger('ended_by')->nullable()->index();
            $table->text('reason')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable()->index();
            $table->timestamps();

            $table->foreign('region_id')->references('id')->on('enforcement_tax_regions')->restrictOnDelete();
            $table->foreign('supervisor_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('assigned_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('ended_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('enforcement_district_region_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('district_id')->index();
            $table->unsignedBigInteger('region_id')->index();
            $table->unsignedBigInteger('assigned_by')->index();
            $table->unsignedBigInteger('ended_by')->nullable()->index();
            $table->text('reason')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable()->index();
            $table->timestamps();

            $table->foreign('district_id')->references('id')->on('enforcement_districts')->restrictOnDelete();
            $table->foreign('region_id')->references('id')->on('enforcement_tax_regions')->restrictOnDelete();
            $table->foreign('assigned_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('ended_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enforcement_district_region_history');
        Schema::dropIfExists('enforcement_tax_region_supervisor_history');

        Schema::table('enforcement_districts', function (Blueprint $table) {
            $table->dropForeign(['region_id']);
            $table->dropColumn('region_id');
        });

        Schema::dropIfExists('enforcement_tax_regions');
    }
};
