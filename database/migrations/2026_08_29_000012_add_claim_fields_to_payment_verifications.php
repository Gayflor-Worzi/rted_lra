<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment-claim metadata captured with the receipt photo:
 * Property ID, Tax Identification Number (TIN) and the Tax Due Date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_verifications', function (Blueprint $table) {
            $table->string('tin', 40)->nullable()->after('receipt_bill_number');
            $table->date('tax_due_date')->nullable()->after('tin');
        });
    }

    public function down(): void
    {
        Schema::table('payment_verifications', function (Blueprint $table) {
            $table->dropColumn(['tin', 'tax_due_date']);
        });
    }
};