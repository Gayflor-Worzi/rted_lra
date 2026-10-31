<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payment-verification integrity hardening (receipt anti-duplication) plus the
 * serialization seam for the hash-chained audit trail.
 *
 * 1. payment_verifications gains a deterministic `receipt_fingerprint` so an
 *    already-claimed receipt can be matched before a second claim is created.
 * 2. `receipt_fingerprints` is a write-time uniqueness backstop: one active
 *    claim per fingerprint, enforced by a unique constraint so two officers
 *    submitting the same receipt at the same instant cannot both win.
 * 3. `audit_chain_locks` serializes chain appends (see AuditService) so the
 *    previous-hash tail cannot fork under concurrent writers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_verifications', function (Blueprint $table) {
            $table->string('receipt_fingerprint', 64)->nullable()->index()->after('receipt_bill_number');
            $table->json('duplicate_warnings')->nullable()->after('receipt_fingerprint');
        });

        Schema::table('payment_verifications', function (Blueprint $table) {
            $table->index(['receipt_number', 'verification_status'], 'pv_receipt_status_idx');
        });

        Schema::create('receipt_fingerprints', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 64)->unique();
            $table->unsignedBigInteger('claim_id')->index();
            $table->unsignedBigInteger('bill_id')->index();
            $table->string('property_id', 60)->nullable();
            $table->string('receipt_number');
            $table->decimal('amount', 14, 2);
            $table->string('payment_period', 50)->nullable();
            $table->string('verification_status');
            $table->timestamps();
        });

        Schema::create('audit_chain_locks', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->timestamp('locked_at')->nullable();
        });

        DB::table('audit_chain_locks')->insertOrIgnore(['id' => 1, 'locked_at' => null]);

        $this->backfillFingerprints();
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_fingerprints');
        Schema::dropIfExists('audit_chain_locks');

        Schema::table('payment_verifications', function (Blueprint $table) {
            $table->dropIndex('pv_receipt_status_idx');
            $table->dropColumn(['receipt_fingerprint', 'duplicate_warnings']);
        });
    }

    /**
     * Stamp existing claims with their fingerprint and reserve the active ones
     * in the uniqueness table (earliest claim wins per fingerprint). Rejected
     * claims are intentionally not reserved so a corrected resubmission is
     * possible.
     */
    private function backfillFingerprints(): void
    {
        $normalize = static fn ($value) => (string) preg_replace('/\s+/', ' ', mb_strtoupper((string) ($value ?? '')));
        $amount = static fn ($value) => number_format((float) $value, 2, '.', '');
        $fingerprint = static fn ($receipt, $amountValue, $period) => hash('sha256', implode('|', [
            $normalize($receipt), $amount($amountValue), $normalize($period),
        ]));

        DB::table('payment_verifications')->orderBy('id')->chunkById(500, function ($claims) use ($normalize, $fingerprint) {
            foreach ($claims as $claim) {
                $hash = $fingerprint($claim->receipt_number, $claim->amount_claimed, $claim->payment_period);

                DB::table('payment_verifications')->where('id', $claim->id)->update(['receipt_fingerprint' => $hash]);

                if ($claim->verification_status === 'Rejected') {
                    continue;
                }

                DB::table('receipt_fingerprints')->insertOrIgnore([
                    'fingerprint' => $hash,
                    'claim_id' => $claim->id,
                    'bill_id' => $claim->bill_id,
                    'property_id' => $claim->property_id,
                    'receipt_number' => $normalize($claim->receipt_number),
                    'amount' => $claim->amount_claimed,
                    'payment_period' => $claim->payment_period === null || $claim->payment_period === ''
                        ? null
                        : $normalize($claim->payment_period),
                    'verification_status' => $claim->verification_status,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }
};
