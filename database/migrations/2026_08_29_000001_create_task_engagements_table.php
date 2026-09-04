<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Unified engagement log — every delivery attempt, follow-up, notice,
        // payment claim and verification step against a task is one record,
        // giving the task card a chronological timeline.
        Schema::create('task_engagements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('task_id')->index();
            $table->unsignedBigInteger('bill_id')->nullable()->index();
            $table->string('engagement_type');            // delivery_attempt | bill_delivered | follow_up | reminder_30_day | demand_72_hour | final_enforcement | closure | payment_claim | verification | payment_confirmed | assignment | note
            $table->string('outcome')->nullable();        // handed_over | no_answer | refused | promised_payment | notice_issued | claim_submitted | confirmed | rejected | assigned | ...
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('officer_id')->nullable()->index();
            $table->timestamp('occurred_at');
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_engagements');
    }
};