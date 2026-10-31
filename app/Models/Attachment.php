<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

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

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }
}
