<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentVerification extends Model
{
    public const MATCH_PENDING = 'Pending';
    public const MATCH_MATCH = 'Match';
    public const MATCH_MISMATCH = 'Mismatch';

    public const STATUS_PENDING = 'Pending';
    public const STATUS_CONFIRMED = 'Confirmed';
    public const STATUS_REJECTED = 'Rejected';
    public const STATUS_EXCEPTION = 'Exception';

    protected $fillable = [
        'task_id',
        'bill_id',
        'claimed_by',
        'property_id',
        'document_number',
        'receipt_number',
        'receipt_bill_number',
        'property_id',
        'tin',
        'tax_due_date',
        'amount_claimed',
        'payment_period',
        'receipt_date',
        'receipt_attachment',
        'match_status',
        'litas_verification_status',
        'verified_amount',
        'litas_reference',
        'verified_by',
        'verified_at',
        'verification_status',
        'rejection_reason',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'receipt_date' => 'date',
            'tax_due_date' => 'date',
            'verified_at' => 'datetime',
            'amount_claimed' => 'decimal:2',
            'verified_amount' => 'decimal:2',
        ];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(PropertyBill::class, 'bill_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}