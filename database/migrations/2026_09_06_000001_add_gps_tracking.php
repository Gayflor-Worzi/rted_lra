<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GPS property-location tracking.
 *
 * gps_locations         — the CURRENT official (verified) property location,
 *                         one row per LITAS Property ID. Never auto-populated
 *                         from a visit capture; only written through explicit
 *                         certification with the gps.update permission, or by
 *                         an approved gps.approve decision.
 * gps_location_history  — append-only log of every certification event
 *                         (proposed, approved, rejected, "not the property"),
 *                         preserving previous coordinates on every update.
 *
 * The captured visit/valuation/discovery coordinates continue to live on the
 * enforcement_visits / valuations / property_discoveries records themselves.
 * LITAS identifiers (Property ID / Document #) are referenced, never generated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gps_locations', function (Blueprint $table) {
            $table->id();
            $table->string('property_id')->index();
            $table->string('tin', 50)->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('accuracy', 10, 2)->nullable();
            $table->string('source', 40); // enforcement_visit | valuation | discovery | correction | other
            $table->string('verification_status', 20)->default('verified');
            $table->unsignedBigInteger('captured_by')->nullable();
            $table->unsignedBigInteger('certified_by')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->unsignedBigInteger('task_id')->nullable();
            $table->unsignedBigInteger('visit_id')->nullable();
            $table->unsignedBigInteger('valuation_id')->nullable();
            $table->unsignedBigInteger('discovery_id')->nullable();
            $table->text('device_metadata')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();

            $table->unique('property_id');
        });

        Schema::create('gps_location_history', function (Blueprint $table) {
            $table->id();
            $table->string('property_id')->index();
            $table->string('tin', 50)->nullable();
            $table->decimal('previous_latitude', 10, 7)->nullable();
            $table->decimal('previous_longitude', 10, 7)->nullable();
            $table->decimal('new_latitude', 10, 7);
            $table->decimal('new_longitude', 10, 7);
            $table->decimal('accuracy', 10, 2)->nullable();
            $table->string('source', 40);
            $table->string('verification_status', 20)->default('pending'); // pending_review | approved | rejected | not_property
            $table->string('certification_decision', 10)->nullable(); // yes | no
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('captured_by')->nullable();
            $table->unsignedBigInteger('certified_by')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->unsignedBigInteger('task_id')->nullable();
            $table->unsignedBigInteger('visit_id')->nullable();
            $table->unsignedBigInteger('valuation_id')->nullable();
            $table->unsignedBigInteger('discovery_id')->nullable();
            $table->text('device_metadata')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamp('certified_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['property_id', 'verification_status']);
        });

        Schema::table('enforcement_visits', function (Blueprint $table) {
            $table->string('gps_verified', 10)->nullable()->after('gps_captured_at'); // yes | no | null (pending)
            $table->unsignedBigInteger('gps_certified_by')->nullable()->after('gps_verified');
            $table->timestamp('gps_certified_at')->nullable()->after('gps_certified_by');
            $table->text('gps_cert_remarks')->nullable()->after('gps_certified_at');
        });

        $this->seedGpsPermissions();
    }

    public function down(): void
    {
        Schema::table('enforcement_visits', function (Blueprint $table) {
            $table->dropColumn(['gps_verified', 'gps_certified_by', 'gps_certified_at', 'gps_cert_remarks']);
        });

        Schema::dropIfExists('gps_location_history');
        Schema::dropIfExists('gps_locations');

        DB::table('permissions')->where('module', 'gps')->delete();
    }

    private function seedGpsPermissions(): void
    {
        $defs = [
            'capture' => 'Capture GPS during an authorized field engagement',
            'view' => 'View verified property GPS',
            'certify' => 'Certify whether a captured location is the actual property location',
            'update' => 'Update the official property GPS directly',
            'review' => 'Review pending GPS certifications',
            'approve' => 'Approve or reject GPS certification',
            'view_history' => 'View property GPS history',
        ];

        $ids = [];
        foreach ($defs as $action => $desc) {
            $existing = DB::table('permissions')->where('name', 'gps.'.$action)->value('id');
            if (! $existing) {
                $existing = DB::table('permissions')->insertGetId([
                    'module' => 'gps',
                    'action' => $action,
                    'name' => 'gps.'.$action,
                    'description' => $desc,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $ids[$action] = $existing;
        }

        $grants = [
            'Enforcement Officer' => ['capture', 'view', 'certify'],
            'Enforcement Supervisor' => ['capture', 'view', 'certify', 'update', 'review', 'approve', 'view_history'],
            'Enforcement Manager' => ['view', 'update', 'review', 'approve', 'view_history'],
            'Valuation Officer' => ['capture', 'view', 'certify'],
            'Valuation Supervisor' => ['capture', 'view', 'certify', 'review', 'update', 'view_history'],
            'Valuation Manager' => ['view', 'review', 'approve', 'update', 'view_history'],
            'Assistant Commissioner' => ['view', 'review', 'approve', 'update', 'view_history'],
            'Account Manager' => ['view', 'view_history'],
            'Account Supervisor' => ['view', 'view_history'],
            'Account & Records Officer' => ['view'],
            'M&E Officer' => ['view', 'view_history', 'review'],
        ];

        foreach ($grants as $roleName => $actions) {
            $roleId = DB::table('roles')->where('name', $roleName)->value('id');
            if (! $roleId) {
                continue;
            }
            foreach ($actions as $action) {
                $exists = DB::table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $ids[$action])
                    ->exists();

                if (! $exists) {
                    DB::table('role_permissions')->insert([
                        'role_id' => $roleId,
                        'permission_id' => $ids[$action],
                    ]);
                }
            }
        }
    }
};