<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Team-to-district assignments are operational history: moving a team to
        // another district must not erase where it previously operated.
        Schema::create('enforcement_team_district_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('district_id')->index();
            $table->unsignedBigInteger('assigned_by')->index();
            $table->unsignedBigInteger('ended_by')->nullable()->index();
            $table->text('reason')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable()->index();
            $table->timestamps();

            $table->foreign('team_id')->references('id')->on('enforcement_teams')->restrictOnDelete();
            $table->foreign('district_id')->references('id')->on('enforcement_districts')->restrictOnDelete();
            $table->foreign('assigned_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('ended_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enforcement_team_district_history');
    }
};
