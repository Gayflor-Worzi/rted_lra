<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * RETD Bill Register — a log of LITAS-generated bills, NOT a master SSOT.
     * Document # and Property ID are LITAS identifiers; this system never generates them.
     */
    public function up(): void
    {
        Schema::create('property_bills', function (Blueprint $table) {
            $table->id();
            $table->string('document_number')->unique()->index(); // LITAS Document #
            $table->string('property_id')->index();               // LITAS Property ID
            $table->string('taxpayer_name');
            $table->string('tin', 40)->nullable()->index();
            $table->string('property_classification')->nullable();
            $table->string('property_address');
            $table->decimal('assessed_value', 14, 2)->nullable();
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('interest_charged', 14, 2)->default(0);
            $table->decimal('penalty_charged', 14, 2)->default(0);
            $table->decimal('total_tax_due', 14, 2)->default(0);
            $table->decimal('outstanding_balance', 14, 2)->default(0);
            $table->string('tax_period', 50)->nullable();
            $table->string('property_type')->nullable();
            $table->unsignedBigInteger('account_staff_id')->nullable()->index();
            $table->string('recipient_type')->default('Walk-in Taxpayer'); // Enforcement Officer | Walk-in Taxpayer | Email | Overseas
            $table->string('recipient_name')->nullable();
            $table->string('recipient_contact')->nullable();
            $table->date('date_logged');
            $table->string('delivery_status')->default('Logged');
            $table->string('payment_status')->default('Unpaid');
            $table->string('case_status')->default('Logged');
            $table->string('approval_status')->nullable();
            $table->string('property_photo')->nullable();
            $table->unsignedBigInteger('assigned_enforcement_officer_id')->nullable()->index();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_bills');
    }
};