<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Verified payment records ONLY — never taxpayer claims.
 * Created when Account & Records confirms a payment against LITAS information.
 */
class Payment extends Model
{
    protected $fillable = [
        'bill_id',
        'document_number',
        'amount',
        'payment_period',
        'receipt_number',
        'litas_reference',
        'verified_by',
        'verified_at',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'amount' => 'decimal:2',
        ];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(PropertyBill::class, 'bill_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}