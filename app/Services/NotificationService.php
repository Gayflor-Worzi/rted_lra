<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    /**
     * Send a notification to a single user.
     */
    public static function send(int $userId, string $title, string $message, string $type = 'system', ?string $relatedType = null, ?int $relatedId = null, string $priority = 'normal'): void
    {
        Notification::create([
            'user_id' => $userId,
            'title' => $title,
            'message' => $message,
            'type' => $type,
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'priority' => $priority,
        ]);
    }

    /**
     * Send a notification to every active user holding a given role.
     */
    public static function sendToRole(string $roleName, string $title, string $message, string $type = 'system', ?string $relatedType = null, ?int $relatedId = null, string $priority = 'normal'): void
    {
        // Case- and whitespace-insensitive role match so a label shipped from a
        // workbook or hand-typed in a command never silently no-ops.
        $role = Role::whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($roleName))])->first();

        if (! $role) {
            Log::warning('sendToRole: unknown role — notification not delivered.', [
                'role' => $roleName,
                'caller' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? 'unknown',
            ]);

            return;
        }

        User::where('role_id', $role->id)->where('is_active', true)
            ->pluck('id')
            ->each(fn ($id) => self::send($id, $title, $message, $type, $relatedType, $relatedId, $priority));
    }

    /**
     * Send to multiple users at once.
     */
    public static function sendToMany(iterable $userIds, string $title, string $message, string $type = 'system', ?string $relatedType = null, ?int $relatedId = null, string $priority = 'normal'): void
    {
        foreach ($userIds as $id) {
            self::send((int) $id, $title, $message, $type, $relatedType, $relatedId, $priority);
        }
    }
}
