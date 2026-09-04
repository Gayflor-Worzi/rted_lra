<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskEngagement extends Model
{
    public const TYPE_DELIVERY_ATTEMPT   = 'delivery_attempt';
    public const TYPE_BILL_DELIVERED     = 'bill_delivered';
    public const TYPE_FOLLOW_UP          = 'follow_up';
    public const TYPE_REMINDER_30_DAY    = 'reminder_30_day';
    public const TYPE_DEMAND_72_HOUR     = 'demand_72_hour';
    public const TYPE_FINAL_ENFORCEMENT  = 'final_enforcement';
    public const TYPE_CLOSURE            = 'closure';
    public const TYPE_PAYMENT_CLAIM      = 'payment_claim';
    public const TYPE_VERIFICATION       = 'verification';
    public const TYPE_PAYMENT_CONFIRMED  = 'payment_confirmed';
    public const TYPE_ASSIGNMENT         = 'assignment';
    public const TYPE_NOTE               = 'note';

    public const TYPES = [
        self::TYPE_DELIVERY_ATTEMPT,
        self::TYPE_BILL_DELIVERED,
        self::TYPE_FOLLOW_UP,
        self::TYPE_REMINDER_30_DAY,
        self::TYPE_DEMAND_72_HOUR,
        self::TYPE_FINAL_ENFORCEMENT,
        self::TYPE_CLOSURE,
        self::TYPE_PAYMENT_CLAIM,
        self::TYPE_VERIFICATION,
        self::TYPE_PAYMENT_CONFIRMED,
        self::TYPE_ASSIGNMENT,
        self::TYPE_NOTE,
    ];

    public const OUTCOMES_DELIVERY = ['handed_over', 'delivered', 'no_answer', 'refused', 'promised_payment'];
    public const OUTCOMES_FOLLOW_UP = ['contact_made', 'promised_payment', 'paid', 'no_contact'];
    public const OUTCOMES_NOTICE = ['notice_issued', 'notice_served'];

    protected $table = 'task_engagements';

    protected $fillable = [
        'task_id',
        'bill_id',
        'engagement_type',
        'outcome',
        'notes',
        'officer_id',
        'occurred_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (TaskEngagement $engagement) {
            if (empty($engagement->occurred_at)) {
                $engagement->occurred_at = now();
            }
        });
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function officer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'officer_id');
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(PropertyBill::class, 'bill_id');
    }
}