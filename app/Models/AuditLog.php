<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Immutable, hash-chained audit trail. Every entry links to the previous one
 * through a SHA-256 chain (computeHash) so the history cannot be silently
 * rewritten. The AuditableObserver writes rows automatically for observed
 * models; the AuditService logs intentional domain actions.
 */
class AuditLog extends Model
{
    protected $table = 'audit_logs';

    public $timestamps = false;

    protected $fillable = [
        'actor_id',
        'action',
        'auditable_type',
        'auditable_id',
        'old_values',
        'new_values',
        'ip_address',
        'previous_hash',
        'hash',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected static function booted(): void
    {
        static::creating(function (AuditLog $log) {
            if (empty($log->created_at)) {
                $log->created_at = now();
            }
        });
    }

    /**
     * Deterministic SHA-256 chain member: previous hash + canonical payload.
     */
    public function computeHash(): string
    {
        $payload = json_encode([
            (int) $this->actor_id,
            $this->action,
            $this->auditable_type,
            $this->auditable_id,
            $this->old_values,
            $this->new_values,
            $this->ip_address,
            $this->created_at?->toDateTimeString(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', ($this->previous_hash ?? '').'|'.$payload);
    }
}
