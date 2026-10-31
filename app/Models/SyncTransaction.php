<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncTransaction extends Model
{
    protected $fillable = [
        'user_id',
        'local_transaction_id',
        'entity_type',
        'entity_id',
        'status',
        'response_payload',
        'synced_at',
    ];

    protected $casts = [
        'synced_at' => 'datetime',
    ];
}
