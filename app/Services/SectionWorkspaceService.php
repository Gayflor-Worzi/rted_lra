<?php

namespace App\Services;

use App\Http\Controllers\Api\AccountTaskController;
use App\Models\Notification;
use App\Models\Task;
use App\Models\User;

/**
 * Section workspace registry — the single source of truth for which task types,
 * roles, notification types and destinations form each working section's own
 * workspace (Assistant Commissioner / Account & Record / Valuation / Enforcement).
 *
 * A desk task is stamped with its owning section and assigned to its section
 * user. Backend list endpoints, assignment notifications and the web/mobile
 * navigations all consult this registry, so a task always surfaces inside its
 * OWN section workspace — it is never routed through Enforcement, and one
 * cancellation of the enforcement workspace never hides another section's work.
 */
class SectionWorkspaceService
{
    public const AC = 'ac';

    public const ACCOUNT = 'account';

    public const VALUATION = 'valuation';

    public const ENFORCEMENT = 'enforcement';

    /**
     * Workspace registry. Order matters: first definition wins when resolving a
     * task type back to its workspace (sets are disjoint today).
     */
    private const WORKSPACES = [
        self::AC => [
            'label' => 'Assistant Commissioner',
            'section_code' => 'MGT',
            'route' => '/ac/tasks',
            'types' => [Task::TYPE_AC_APPROVAL],
            'notification' => Notification::TYPE_AC_TASKS,
            'roles' => ['Assistant Commissioner'],
        ],
        self::ACCOUNT => [
            'label' => 'Account & Record',
            'section_code' => 'ACCT',
            'route' => '/account/my-tasks',
            'types' => AccountTaskController::ACCOUNT_TASK_TYPES,
            'notification' => Notification::TYPE_ACCOUNT_TASKS,
            'roles' => ['Account & Records Officer', 'Account Supervisor', 'Account Manager'],
        ],
        self::VALUATION => [
            'label' => 'Valuation',
            'section_code' => 'VAL',
            'route' => '/valuation/tasks',
            'types' => [
                Task::TYPE_VALUATION,
                Task::TYPE_VALUATION_REVIEW,
                Task::TYPE_VALUATION_MANAGER_REVIEW,
            ],
            'notification' => Notification::TYPE_VALUATION_TASKS,
            'roles' => ['Valuation Officer', 'Valuation Supervisor', 'Valuation Manager'],
        ],
        self::ENFORCEMENT => [
            'label' => 'Enforcement',
            'section_code' => 'ENF',
            'route' => '/enforcement?tab=tasks',
            'types' => [
                Task::TYPE_BILL_DELIVERY,
                Task::TYPE_PAYMENT_FOLLOWUP,
                Task::TYPE_ENFORCEMENT_VISIT,
            ],
            'notification' => Notification::TYPE_TASKS,
            'roles' => ['Enforcement Officer', 'Enforcement Supervisor', 'Enforcement Manager', 'M&E Officer'],
            // Read-level register right: holders may browse the workspace without
            // gaining assignment rights. Enforcement Supervisors have the distinct
            // operational assignment permission for the unassigned bill queue.
            'view_permissions' => ['enforcement.view_assignments'],
            'view_roles' => ['Account Manager'],
        ],
    ];

    /** @return string[] all workspace keys, stable order. */
    public static function all(): array
    {
        return array_keys(self::WORKSPACES);
    }

    public static function label(string $workspace): string
    {
        return self::WORKSPACES[$workspace]['label'] ?? 'Section';
    }

    public static function sectionCode(string $workspace): string
    {
        return self::WORKSPACES[$workspace]['section_code'] ?? '';
    }

    /** Task types that constitute "work" inside the workspace. */
    public static function types(string $workspace): array
    {
        return self::WORKSPACES[$workspace]['types'] ?? [];
    }

    /** Web/mobile destination that mirrors this workspace. */
    public static function route(string $workspace): string
    {
        return self::WORKSPACES[$workspace]['route'] ?? '/dashboard';
    }

    /** Notification type that powers this workspace's assignment badge. */
    public static function notificationType(string $workspace): string
    {
        return self::WORKSPACES[$workspace]['notification'] ?? 'info';
    }

    /** @return string[] role names chartered to operate the workspace. */
    public static function roles(string $workspace): array
    {
        return self::WORKSPACES[$workspace]['roles'] ?? [];
    }

    /** @return string[] permissions granting read-level access to the workspace. */
    public static function viewPermissions(string $workspace): array
    {
        return self::WORKSPACES[$workspace]['view_permissions'] ?? [];
    }

    /**
     * @return string[] role names allowed to exercise the workspace's view
     *                  permissions (empty = any holder may use them).
     */
    public static function viewerRoles(string $workspace): array
    {
        return self::WORKSPACES[$workspace]['view_roles'] ?? [];
    }

    /** May a non-operator holder of the view permissions actually browse $workspace? */
    public static function isWorkspaceViewer(User $user, string $workspace): bool
    {
        $viewRoles = self::viewerRoles($workspace);

        return $viewRoles === [] || in_array($user->role?->name, $viewRoles, true);
    }

    /**
     * Resolve a task type to the workspace that owns it (null = a type with no
     * dedicated section workspace — e.g. M&E Query, Other).
     */
    public static function workspaceForType(string $taskType): ?string
    {
        foreach (array_keys(self::WORKSPACES) as $key) {
            if (in_array($taskType, self::types($key), true)) {
                return $key;
            }
        }

        return null;
    }

    /** Destination for a routed assignment notification. */
    public static function actionUrlForType(string $taskType): string
    {
        $workspace = self::workspaceForType($taskType);

        return $workspace ? self::route($workspace) : '/dashboard';
    }

    /** Notification type for a routed assignment alert. */
    public static function notificationTypeForType(string $taskType): string
    {
        $workspace = self::workspaceForType($taskType);

        return $workspace ? self::notificationType($workspace) : 'info';
    }

    /**
     * May this user operate $workspace? System Administrators are unrestricted;
     * otherwise the user must hold a workspace role, or be chartered inside the
     * workspace's section with full type visibility (custom-role fallback).
     */
    public static function canAccess(User $user, string $workspace): bool
    {
        if (! isset(self::WORKSPACES[$workspace])) {
            return false;
        }

        if ($user->isSystemAdministrator()) {
            return true;
        }

        if (in_array($user->role?->name, self::roles($workspace), true)) {
            return true;
        }

        if ($user->section?->code === self::sectionCode($workspace)) {
            $types = self::types($workspace);
            if ($types === []) {
                return true;
            }

            return collect($types)->every(fn (string $type) => AssignmentEligibilityService::canViewTaskType($user, $type));
        }

        return false;
    }

    /**
     * Read-level workspace access: operators (canAccess) plus holders of the
     * workspace's view permissions whose role is chartered to browse the
     * surface (viewerRoles). Listing a workspace surfaces only task types the
     * caller is type-view-eligible for — a view permission never grants
     * assignment or any other mutating right.
     */
    public static function canViewWorkspace(User $user, string $workspace): bool
    {
        if (self::canAccess($user, $workspace)) {
            return true;
        }

        return self::viewPermissions($workspace) !== []
            && self::isWorkspaceViewer($user, $workspace)
            && $user->hasAnyPermission(self::viewPermissions($workspace));
    }

    /**
     * May this user assign / reassign a task of $taskType? Tasks owned by a
     * dedicated section workspace require operating access to that workspace
     * (workspace role or in-section chartered staffing); view-only holders
     * (e.g. enforcement.view_assignments) are refused.
     */
    public static function mayAssignTask(User $user, string $taskType): bool
    {
        $workspace = self::workspaceForType($taskType);

        return $workspace === null || self::canAccess($user, $workspace);
    }
}
