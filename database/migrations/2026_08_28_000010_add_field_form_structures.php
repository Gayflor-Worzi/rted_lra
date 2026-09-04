<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Field-form structures for the Valuation and Enforcement workflows:
 *  - repeatable property-description sub-table with RETD value calculation
 *  - evidence photos (searchable, auditable photo metadata)
 *  - enforcement escalation dates / stage on bills
 *  - visit reference + GPS accuracy / capture time
 * Also wires the enforcement.escalation_override permission for manager overrides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('valuations', function (Blueprint $table) {
            $table->string('owner_contact', 100)->nullable()->after('owner_name');
            $table->date('assessment_date')->nullable()->after('gps_coordinate');
            $table->decimal('declared_value', 14, 2)->nullable()->after('assessment_date');
            $table->decimal('reassessed_value', 14, 2)->nullable()->after('declared_value');
            $table->decimal('applicable_tax_rate', 8, 4)->nullable()->after('reassessed_value');
            $table->decimal('other_amounts', 14, 2)->nullable()->after('applicable_tax_rate');
            $table->decimal('total_property_value', 14, 2)->nullable()->after('other_amounts');
            $table->decimal('total_tax_payable', 14, 2)->nullable()->after('total_property_value');
            $table->timestamp('submitted_at')->nullable()->after('status');
            $table->string('prepared_by_designation', 100)->nullable()->after('submitted_at');
        });

        Schema::create('valuation_property_descriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('valuation_id')->index();
            $table->unsignedInteger('seq')->default(1);
            $table->string('description')->default('');
            $table->string('level', 50)->nullable();
            $table->decimal('area_sqft', 12, 2)->nullable();
            $table->decimal('tar', 8, 2)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('amount', 14, 2)->nullable();
            $table->unsignedInteger('building_age')->nullable();
            $table->decimal('depreciation_pct', 6, 2)->default(0);
            $table->decimal('value', 14, 2)->nullable();
            $table->timestamps();

            $table->foreign('valuation_id')->references('id')->on('valuations')->cascadeOnDelete();
        });

        Schema::create('evidence_photos', function (Blueprint $table) {
            $table->id();
            $table->string('photo_reference')->unique(); // PHOTO-YYYY-##### (system-generated)
            $table->string('photo_type'); // PROPERTY_FULL_VIEW | BILL_DELIVERY | WARNING_NOTICE | PREMISES | SEIZURE | CLOSURE | OTHER
            $table->unsignedBigInteger('bill_id')->nullable()->index();
            $table->unsignedBigInteger('task_id')->nullable()->index();
            $table->unsignedBigInteger('visit_id')->nullable()->index();
            $table->unsignedBigInteger('valuation_id')->nullable()->index();
            $table->string('property_id')->nullable(); // LITAS Property ID (never generated)
            $table->unsignedBigInteger('officer_id')->nullable()->index();
            $table->string('file_path');
            $table->string('original_name')->nullable();
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('gps_coordinate', 100)->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        Schema::table('property_bills', function (Blueprint $table) {
            $table->date('delivery_date')->nullable()->after('delivery_status');
            $table->date('thirty_day_notice_date')->nullable()->after('delivery_date');
            $table->date('final_notice_date')->nullable()->after('thirty_day_notice_date');
            $table->string('escalation_stage')->nullable()->after('final_notice_date');
            $table->string('escalation_override_reason')->nullable()->after('escalation_stage');
        });

        Schema::table('enforcement_visits', function (Blueprint $table) {
            $table->string('visit_reference')->nullable()->unique()->after('id');
            $table->decimal('gps_accuracy', 10, 2)->nullable()->after('gps_coordinate');
            $table->timestamp('gps_captured_at')->nullable()->after('gps_accuracy');
        });

        // Escalation override permission + grants (managers may override the 30-day lock).
        $permId = DB::table('permissions')->where('name', 'enforcement.escalation_override')->value('id');
        if (! $permId) {
            $permId = DB::table('permissions')->insertGetId([
                'module' => 'enforcement',
                'action' => 'escalation_override',
                'name' => 'enforcement.escalation_override',
                'description' => 'Override the 30-day escalation lock (restricted).',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $roles = DB::table('roles')->whereIn('name', [
            'Enforcement Manager', 'Enforcement Supervisor', 'Account Manager',
            'Account Supervisor', 'Assistant Commissioner', 'M&E Officer',
        ])->pluck('id');

        foreach ($roles as $roleId) {
            $exists = DB::table('role_permissions')
                ->where('role_id', $roleId)
                ->where('permission_id', $permId)
                ->exists();

            if (! $exists) {
                DB::table('role_permissions')->insert([
                    'role_id' => $roleId,
                    'permission_id' => $permId,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('valuation_property_descriptions');
        Schema::dropIfExists('evidence_photos');

        Schema::table('valuations', function (Blueprint $table) {
            $table->dropColumn([
                'owner_contact', 'assessment_date', 'declared_value', 'reassessed_value',
                'applicable_tax_rate', 'other_amounts', 'total_property_value',
                'total_tax_payable', 'submitted_at', 'prepared_by_designation',
            ]);
        });

        Schema::table('property_bills', function (Blueprint $table) {
            $table->dropColumn([
                'delivery_date', 'thirty_day_notice_date', 'final_notice_date',
                'escalation_stage', 'escalation_override_reason',
            ]);
        });

        Schema::table('enforcement_visits', function (Blueprint $table) {
            $table->dropUnique(['visit_reference']);
            $table->dropColumn(['visit_reference', 'gps_accuracy', 'gps_captured_at']);
        });
    }
};