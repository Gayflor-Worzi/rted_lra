<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPermission extends Model
{
    protected $table = 'user_permissions';

    protected $fillable = ['user_id', 'permission_id', 'allow'];

    protected $casts = [
        'allow' => 'boolean',
    ];

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class, 'permission_id');
    }
}
