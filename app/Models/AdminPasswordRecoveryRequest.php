<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminPasswordRecoveryRequest extends Model
{
    protected $fillable = [
        'user_id',
        'request_token_hash',
        'otp_hash',
        'expires_at',
        'failed_attempts',
        'verified_at',
        'reset_token_hash',
        'reset_expires_at',
        'consumed_at',
        'invalidated_at',
        'request_ip',
        'request_user_agent',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'reset_expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'invalidated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
