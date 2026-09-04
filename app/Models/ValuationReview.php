<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ValuationReview extends Model
{
    protected $fillable = [
        'valuation_id',
        'stage',
        'decision',
        'reviewer_id',
        'remarks',
    ];
}