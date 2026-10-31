<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillAmendment extends Model
{
    public const STATUS_APPLIED = 'Applied';

    protected $fillable = [
        'bill_id',
        'document_number',
        'property_id',
        'original_document_number',
        'previous_document_number',
        'new_document_number',
        'previous_penalty_charge',
        'new_penalty_charge',
        'previous_interest_charge',
        'new_interest_charge',
        'previous_total_tax_due',
        'new_total_tax_due',
        'amended_by',
        'reason',
        'amended_at',
    ];

    protected function casts(): array
    {
        return [
            'previous_penalty_charge' => 'decimal:2',
            'new_penalty_charge' => 'decimal:2',
            'previous_interest_charge' => 'decimal:2',
            'new_interest_charge' => 'decimal:2',
            'previous_total_tax_due' => 'decimal:2',
            'new_total_tax_due' => 'decimal:2',
            'amended_at' => 'datetime',
        ];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(PropertyBill::class, 'bill_id');
    }

    public function amender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'amended_by');
    }
}
