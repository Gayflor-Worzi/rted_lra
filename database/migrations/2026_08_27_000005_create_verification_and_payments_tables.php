<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payment Verification — distinct from verified payments.
     * Officers submit CLAIMS; Account & Records VERIFY against LITAS info.
     */
    public function up(): void
    {
        Schema::create('payment_verifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('task_id')->nullable()->index();
            $table->unsignedBigInteger('bill_id')->index();
            $table->string('property_id')->nullable();
            $table->string('document_number')->nullable();
            $table->string('receipt_number');
            $table->string('receipt_bill_number');
            $table->decimal('amount_claimed', 14, 2);
            $table->string('payment_period', 50)->nullable();
            $table->date('receipt_date')->nullable();
            $table->string('receipt_attachment')->nullable();
            $table->string('match_status')->default('Pending'); // Pending | Match | Mismatch
            $table->string('litas_verification_status')->nullable(); // litas_reference | verified_amount | verified_date | verified_period
            $table->decimal('verified_amount', 14, 2)->nullable();
            $table->string('litas_reference')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('verification_status')->default('Pending'); // Pending | Confirmed | Rejected | Exception
            $table->text('rejection_reason')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        // Verified payments only (NOT taxpayer claims)
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bill_id')->index();
            $table->string('document_number')->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('payment_period', 50)->nullable();
            $table->string('receipt_number')->nullable();
            $table->string('litas_reference')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('payment_verifications');
    }
};