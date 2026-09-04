<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Intentional domain-audit helper. Each record chains to the previous one via
 * the AuditLog SHA-256 computeHash chain — mirroring the AuditableObserver so
 * the whole trail shares one canonical hashing scheme.
 */
class AuditService
{
    public function record(Model $auditable, string $action, ?int $actorId = null, array $old = [], array $new = [], ?string $ip = null): AuditLog
    {
        $previous = AuditLog::query()->orderByDesc('id')->value('hash');

        $entry = AuditLog::create([
            'actor_id' => $actorId,
            'action' => $action,
            'auditable_type' => $auditable->getMorphClass(),
            'auditable_id' => $auditable->getKey(),
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'ip_address' => $ip ?? request()->ip(),
            'previous_hash' => $previous,
        ]);

        $entry->update(['hash' => $entry->computeHash()]);

        return $entry;
    }
}
