<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskHistory extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'task_id',
        'from_status',
        'to_status',
        'action',
        'performed_by',
        'remarks',
        'created_at',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }
}
