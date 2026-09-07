<?php

namespace App\Services;

use App\Models\SyncTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Server-side idempotency ledger for offline-first field submissions.
 *
 * Every field transaction carries a device-generated local_transaction_id. The
 * first commit is stored with the exact response payload; any later resubmission
 * of the same (user, local_transaction_id) is short-circuited to the stored
 * payload so retries and auto-reflushes can never duplicate server records.
 */
class SyncService
{
    public const ENTITY_TABLES = [
        'visit' => 'enforcement_visits',
        'discovery' => 'property_discoveries',
        'action' => 'tasks',
    ];

    /**
     * Extract the device transaction id from a request payload (nullable).
     */
    public function localId(Request $request): ?string
    {
        $id = $request->input('local_transaction_id') ?? $request->input('client_ref');

        if ($id === null || $id === '') {
            return null;
        }

        return (string) $id;
    }

    /**
     * If a (user, local id) transaction was already committed, return the stored
     * response payload so the caller can replay it; otherwise null.
     */
    public function findForReplay(int $userId, string $localId): ?array
    {
        $row = SyncTransaction::where('user_id', $userId)
            ->where('local_transaction_id', $localId)
            ->first();

        if (! $row || $row->entity_id === null) {
            return null;
        }

        $payload = json_decode((string) $row->response_payload, true);

        return is_array($payload) ? $payload : null;
    }

    /**
     * Persist a successful offline commit and stamp sync provenance on its entity.
     */
    public function record(int $userId, string $localId, string $entityType, int $entityId, array $responseData): void
    {
        DB::transaction(function () use ($userId, $localId, $entityType, $entityId, $responseData) {
            SyncTransaction::updateOrCreate(
                ['user_id' => $userId, 'local_transaction_id' => $localId],
                [
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'status' => 'completed',
                    'response_payload' => json_encode($responseData),
                    'synced_at' => now(),
                ],
            );

            $this->stampSynced($entityType, $entityId);
        });
    }

    /**
     * Replay response for an already-committed transaction.
     */
    public function replayResponse(array $data, string $message = 'Already received — no duplicate recorded.'): JsonResponse
    {
        return response()->json([
            'data' => ['replayed' => true] + $data,
            'message' => $message,
        ], 200);
    }

    /**
     * Stamp the sync provenance columns on an entity row (mobile-created, synced).
     */
    private function stampSynced(string $entityType, int $entityId): void
    {
        $table = self::ENTITY_TABLES[$entityType] ?? null;

        if (! $table) {
            return;
        }

        try {
            DB::table($table)->where('id', $entityId)->update([
                'sync_status' => 'synced',
                'sync_attempts' => 1,
                'sync_source' => 'mobile',
                'last_sync_at' => now(),
                'last_sync_error' => null,
            ]);
        } catch (\Throwable) {
            // Non-blocking — the sync ledger row is authoritative.
        }
    }
}