<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separation of duties: record which officer CLAIMED a payment so that the
 * same person cannot verify/reject their own submission. The claimant is an
 * Enforcement officer (payments.claim); the verifier is an Account & Records
 * officer (payments.verify) — structurally distinct roles, and this column
 * provides the hard record-level guard on top.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_verifications', function (Blueprint $table) {
            $table->unsignedBigInteger('claimed_by')->nullable()->after('bill_id')->index();
            $table->foreign('claimed_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_verifications', function (Blueprint $table) {
            $table->dropForeign(['claimed_by']);
            $table->dropIndex(['claimed_by']);
            $table->dropColumn('claimed_by');
        });
    }
};