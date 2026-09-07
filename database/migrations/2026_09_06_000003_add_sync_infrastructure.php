<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Server-side synchronization layer (offline-first field app).
 *
 * sync_transactions — idempotency ledger. Every offline field transaction carries
 * a locally-generated local_transaction_id (unique per user). The first time the
 * server receives one it commits the entity and stores the original response; a
 * repeated submission is short-circuited to the stored response so retries and
 * automatic re-flushes can never create duplicate visits/discoveries/receipts.
 *
 * Entity sync_state columns record WHERE an entity came from (web vs. mobile
 * sync) and when the server accepted it. Server-resident records are by
 * definition 'synced'; the "Awaiting Sync" lane belongs to the device outbox.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('local_transaction_id', 80);
            $table->string('entity_type', 40); // visit | discovery | receipt | action
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('status', 20)->default('completed'); // completed | failed
            $table->text('response_payload')->nullable(); // exact reply to replay
            $table->string('last_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'local_transaction_id']);
        });

        $entityTables = [
            'tasks' => ['sync_status', 'sync_attempts', 'sync_source', 'last_sync_at', 'last_sync_error'],
            'enforcement_visits' => ['sync_status', 'sync_attempts', 'sync_source', 'last_sync_at', 'last_sync_error'],
            'property_discoveries' => ['sync_status', 'sync_attempts', 'sync_source', 'last_sync_at', 'last_sync_error'],
        ];

        foreach ($entityTables as $table => $cols) {
            Schema::table($table, function (Blueprint $t) use ($cols) {
                if (in_array('sync_status', $cols, true)) {
                    $t->string('sync_status', 20)->default('synced'); // synced | awaiting_sync | syncing | sync_failed
                }
                if (in_array('sync_attempts', $cols, true)) {
                    $t->unsignedSmallInteger('sync_attempts')->default(0);
                }
                if (in_array('sync_source', $cols, true)) {
                    $t->string('sync_source', 20)->nullable(); // web | mobile
                }
                if (in_array('last_sync_at', $cols, true)) {
                    $t->timestamp('last_sync_at')->nullable();
                }
                if (in_array('last_sync_error', $cols, true)) {
                    $t->text('last_sync_error')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['tasks', 'enforcement_visits', 'property_discoveries'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn(['sync_status', 'sync_attempts', 'sync_source', 'last_sync_at', 'last_sync_error']);
            });
        }

        Schema::dropIfExists('sync_transactions');
    }
};