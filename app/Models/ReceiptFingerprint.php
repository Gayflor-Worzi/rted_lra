<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Write-time uniqueness backstop for receipt claims. One active claim per
 * receipt fingerprint — enforced by the unique `fingerprint` constraint — so a
 * receipt cannot be claimed for two properties even if two submissions arrive
 * at the same instant. The row is removed when its claim is rejected (allowing
 * a corrected resubmission) and kept once the claim is confirmed.
 */
class ReceiptFingerprint extends Model
{
    protected $fillable = [
        'fingerprint',
        'claim_id',
        'bill_id',
        'property_id',
        'receipt_number',
        'amount',
        'payment_period',
        'verification_status',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(PaymentVerification::class, 'claim_id');
    }
}
