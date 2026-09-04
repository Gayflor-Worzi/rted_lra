<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Role;
use App\Models\User;

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
        $role = Role::where('name', $roleName)->first();

        if (! $role) {
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
