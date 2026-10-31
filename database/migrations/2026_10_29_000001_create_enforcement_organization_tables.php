<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enforcement_districts', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('enforcement_teams', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedBigInteger('manager_id')->index();
            $table->unsignedBigInteger('supervisor_id')->index();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('enforcement_team_district', function (Blueprint $table) {
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('district_id');
            $table->primary(['team_id', 'district_id']);
            $table->index('district_id');
        });

        Schema::create('enforcement_org_recommendations', function (Blueprint $table) {
            $table->id();
            $table->string('change_type', 40);
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->json('payload');
            $table->json('previous_values')->nullable();
            $table->unsignedBigInteger('applied_record_id')->nullable()->index();
            $table->unsignedBigInteger('submitted_by')->index();
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedBigInteger('decided_by')->nullable()->index();
            $table->text('decision_notes')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        Schema::create('enforcement_team_memberships', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('officer_id')->index();
            $table->unsignedBigInteger('supervisor_id')->index();
            $table->unsignedBigInteger('primary_district_id')->index();
            $table->unsignedBigInteger('prior_supervisor_id')->nullable()->index();
            $table->unsignedBigInteger('recommendation_id')->nullable()->index();
            $table->boolean('is_team_lead')->default(false);
            $table->unsignedBigInteger('assigned_by')->index();
            $table->unsignedBigInteger('ended_by')->nullable()->index();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable()->index();
            $table->timestamps();

            $table->foreign('recommendation_id')
                ->references('id')->on('enforcement_org_recommendations')
                ->nullOnDelete();
        });

        Schema::table('property_bills', function (Blueprint $table) {
            $table->unsignedBigInteger('primary_tax_district_id')->nullable()->index();
            $table->foreign('primary_tax_district_id')
                ->references('id')->on('enforcement_districts')
                ->restrictOnDelete();
        });

        Schema::table('enforcement_teams', function (Blueprint $table) {
            $table->foreign('manager_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('supervisor_id')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('enforcement_team_district', function (Blueprint $table) {
            $table->foreign('team_id')->references('id')->on('enforcement_teams')->cascadeOnDelete();
            $table->foreign('district_id')->references('id')->on('enforcement_districts')->restrictOnDelete();
        });

        Schema::table('enforcement_org_recommendations', function (Blueprint $table) {
            $table->foreign('team_id')->references('id')->on('enforcement_teams')->nullOnDelete();
            $table->foreign('submitted_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('decided_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('enforcement_team_memberships', function (Blueprint $table) {
            $table->foreign('team_id')->references('id')->on('enforcement_teams')->restrictOnDelete();
            $table->foreign('officer_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('supervisor_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('primary_district_id')->references('id')->on('enforcement_districts')->restrictOnDelete();
            $table->foreign('prior_supervisor_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('assigned_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('ended_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('property_bills', function (Blueprint $table) {
            $table->dropForeign(['primary_tax_district_id']);
            $table->dropColumn('primary_tax_district_id');
        });

        Schema::dropIfExists('enforcement_team_memberships');
        Schema::dropIfExists('enforcement_org_recommendations');
        Schema::dropIfExists('enforcement_team_district');
        Schema::dropIfExists('enforcement_teams');
        Schema::dropIfExists('enforcement_districts');
    }
};
