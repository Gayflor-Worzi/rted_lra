<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec v4 — controlled-routing workflow.
 *
 * 1. Extend `property_discoveries` with the fields the new workflow needs
 *    (declared value, submission stamp, automatic-classification source,
 *    current task owner, Account & Record Manager review, referral audit).
 * 2. Extend `valuations` with the Account & Record Manager final-review fields.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Discovery workflow fields ────────────────────────────────────────
        Schema::table('property_discoveries', function (Blueprint $table) {
            $table->decimal('declared_value', 14, 2)->nullable()->after('description');
            $table->timestamp('submitted_at')->nullable()->after('discovered_by');
            $table->string('classification_source', 50)->nullable()->after('classified_at');
            $table->unsignedBigInteger('current_owner_id')->nullable()->after('valuation_id');

            // Account & Record Manager desk review (Path A + final Path B/D review)
            $table->unsignedBigInteger('aarm_reviewed_by')->nullable()->after('manager_remarks');
            $table->timestamp('aarm_reviewed_at')->nullable()->after('aarm_reviewed_by');
            $table->string('aarm_decision', 20)->nullable()->after('aarm_reviewed_at'); // approved|returned|referred|rejected
            $table->string('aarm_remarks', 2000)->nullable()->after('aarm_decision');

            // Referral audit trail (Path C — A&R desk -> Valuation)
            $table->unsignedBigInteger('referred_by')->nullable()->after('aarm_remarks');
            $table->timestamp('referred_at')->nullable()->after('referred_by');
            $table->string('referral_reason', 2000)->nullable()->after('referred_at');
            $table->string('referral_from_stage', 60)->nullable()->after('referral_reason');

            $table->index(['status', 'decision_path']);
        });

        Schema::table('property_discoveries', function (Blueprint $table) {
            $table->foreign('current_owner_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('aarm_reviewed_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('referred_by')->references('id')->on('users')->nullOnDelete();
        });

        // ── Valuation account-review fields ─────────────────────────────────────
        Schema::table('valuations', function (Blueprint $table) {
            $table->unsignedBigInteger('account_reviewed_by')->nullable()->after('ac_reviewed_at');
            $table->timestamp('account_reviewed_at')->nullable()->after('account_reviewed_by');
            $table->text('account_remarks')->nullable()->after('account_reviewed_at');
        });

        Schema::table('valuations', function (Blueprint $table) {
            $table->foreign('account_reviewed_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('valuations', function (Blueprint $table) {
            $table->dropForeign(['account_reviewed_by']);
            $table->dropColumn(['account_reviewed_by', 'account_reviewed_at', 'account_remarks']);
        });

        Schema::table('property_discoveries', function (Blueprint $table) {
            $table->dropForeign(['current_owner_id']);
            $table->dropForeign(['aarm_reviewed_by']);
            $table->dropForeign(['referred_by']);
            $table->dropIndex(['status', 'decision_path']);
            $table->dropColumn([
                'declared_value', 'submitted_at', 'classification_source', 'current_owner_id',
                'aarm_reviewed_by', 'aarm_reviewed_at', 'aarm_decision', 'aarm_remarks',
                'referred_by', 'referred_at', 'referral_reason', 'referral_from_stage',
            ]);
        });
    }
};
