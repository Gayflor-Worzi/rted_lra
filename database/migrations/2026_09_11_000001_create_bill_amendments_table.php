<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->constrained('property_bills')->cascadeOnDelete();
            $table->string('document_number', 80)->comment('Current document number on the bill after amendment');
            $table->string('property_id', 60);
            $table->string('original_document_number', 80)->comment('Document number from the original logged bill');
            $table->string('previous_document_number', 80)->nullable()->comment('Document number before this amendment');
            $table->string('new_document_number', 80)->nullable()->comment('New document number if changed');
            $table->decimal('previous_penalty_charge', 15, 2);
            $table->decimal('new_penalty_charge', 15, 2);
            $table->decimal('previous_interest_charge', 15, 2);
            $table->decimal('new_interest_charge', 15, 2);
            $table->decimal('previous_total_tax_due', 15, 2);
            $table->decimal('new_total_tax_due', 15, 2);
            $table->foreignId('amended_by')->constrained('users');
            $table->string('reason', 2000)->nullable();
            $table->timestamp('amended_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_amendments');
    }
};
