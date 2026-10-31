<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    /** Assignment alerts for the Account & Record "My Tasks" module. */
    public const TYPE_ACCOUNT_TASKS = 'account_tasks';

    /** Assignment alerts for the Assistant Commissioner workspace. */
    public const TYPE_AC_TASKS = 'ac_tasks';

    /** Assignment alerts for the Valuation workspace. */
    public const TYPE_VALUATION_TASKS = 'valuation_tasks';

    /** Generic assignment alerts (enforcement field queue). */
    public const TYPE_TASKS = 'tasks';

    /** Enforcement organization recommendation and decision notices. */
    public const TYPE_ORGANIZATION = 'organization';

    protected $fillable = [
        'user_id',
        'title',
        'message',
        'type',
        'action_url',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function markRead(): void
    {
        if (! $this->read_at) {
            $this->update(['read_at' => now()]);
        }
    }
}
