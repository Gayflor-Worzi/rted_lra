<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attachment extends Model
{
    protected $fillable = [
        'uploaded_by',
        'attachable_type',
        'attachable_id',
        'doc_type',
        'label',
        'path',
        'description',
    ];

    public function attachable(): \Illuminate\Database\Eloquent\Relations\MorphTo
    {
        return $this->morphTo();
    }
}