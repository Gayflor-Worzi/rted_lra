<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
}