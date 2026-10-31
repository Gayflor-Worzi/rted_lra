<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Intentional domain-audit helper. Each record chains to the previous one via
 * the AuditLog SHA-256 computeHash chain. Every append is serialized through
 * the audit_chain_locks row so two concurrent writers can never read the same
 * tail hash and fork the chain.
 */
class AuditService
{
    /**
     * Append a chained audit entry atomically. The previous_hash tail is read
     * and the row written inside one transaction, serialized by a single-row
     * lock so concurrent writers cannot produce sibling entries.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function append(array $attributes): AuditLog
    {
        return DB::transaction(function () use ($attributes) {
            // Serialize every chain append on a single lock row (row-level FOR
            // UPDATE on MySQL/Postgres; SQLite serializes writes naturally).
            DB::table('audit_chain_locks')->upsert(
                ['id' => 1, 'locked_at' => now()],
                'id',
                ['locked_at'],
            );
            DB::table('audit_chain_locks')->where('id', 1)->lockForUpdate()->first();

            $previous = AuditLog::query()->orderByDesc('id')->value('hash');

            $entry = AuditLog::create($attributes + ['previous_hash' => $previous]);

            // Hash the PERSISTED row so the chain is byte-stable even if Eloquent
            // normalizes a value between the in-memory snapshot and the DB write.
            $entry->refresh();

            $entry->update(['hash' => $entry->computeHash()]);

            return $entry;
        });
    }

    public function record(Model $auditable, string $action, ?int $actorId = null, array $old = [], array $new = [], ?string $ip = null): AuditLog
    {
        return $this->append([
            'actor_id' => $actorId,
            'action' => $action,
            'auditable_type' => $auditable->getMorphClass(),
            'auditable_id' => $auditable->getKey(),
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'ip_address' => $ip ?? (request()?->ip()),
        ]);
    }
}
