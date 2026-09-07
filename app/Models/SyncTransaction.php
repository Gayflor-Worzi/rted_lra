<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncTransaction extends Model
{
    protected $guarded = [];

    protected $casts = [
        'synced_at' => 'datetime',
    ];
}