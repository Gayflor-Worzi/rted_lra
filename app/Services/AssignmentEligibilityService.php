<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;

/**
 * Single source of truth for "who may be assigned / may act on a task type".
 *
 * The separation enforced here is:
 *   Role -> Permission -> Section -> Assignment -> Workflow
 *
 * A task type maps to an explicit permission (e.g. enforcement.bill_delivery)
 * and an operational section (ENF). Being a RETD staff member, or merely
 * existing in the staff table, is NOT enough to be assigned or to act.
 * Managers/supervisors/AC retain broader *visibility* via their data scope,
 * but ordinary officers may only ever be assigned tasks of a type they are
 * truly eligible to execute.
 */
class AssignmentEligibilityService
{
    /**
     * Map of task type => ['permissions' => [...], 'sections' => [...]]
     * (permissions must be interchangeable with 'AND' within a section).
     */
    private const TYPE_MAP = [
        Task::TYPE_BILL_DELIVERY => [
            'permissions' => ['enforcement.bill_delivery'],
            'sections'    => ['ENF'],
        ],
        Task::TYPE_ENFORCEMENT_VISIT => [
            'permissions' => ['enforcement.visit'],
            'sections'    => ['ENF'],
        ],
        Task::TYPE_PAYMENT_FOLLOWUP => [
            'permissions' => ['enforcement.payment_followup'],
            'sections'    => ['ENF'],
        ],
        Task::TYPE_PAYMENT_VERIFICATION => [
            'permissions' => ['payments.verify'],
            'sections'    => ['ACCT'],
        ],
        Task::TYPE_VALUATION => [
            'permissions' => ['valuation.prepare'],
            'sections'    => ['VAL'],
        ],
        Task::TYPE_VALUATION_REVIEW => [
            'permissions' => ['valuation.review'],
            'sections'    => ['VAL'],
        ],
        Task::TYPE_AC_APPROVAL => [
            'permissions' => ['valuation.approve'],
            'sections'    => ['VAL', 'MGT'],
        ],
        Task::TYPE_ME_QUERY => [
            'permissions' => ['me.respond'],
            'sections'    => ['ENF', 'MGT'],
        ],
        Task::TYPE_LITAS_PROCESSING => [
            'permissions' => ['valuation.litas_processing', 'discovery.litas_processing'],
            'sections'    => ['ACCT', 'VAL'],
        ],
        Task::TYPE_OTHER => null,
    ];

    /**
     * Task type requirements (permissions + sections) or null when unrestricted.
     *
     * @return array{permissions: string[], sections: string[]}|null
     */
    public static function requirementsFor(string $taskType): ?array
    {
        return self::TYPE_MAP[$taskType] ?? null;
    }

    /**
     * May this user VIEW / open a task of $taskType?
     * Broader than execution: managers/supervisors/AC keep oversight via scope.
     */
    public static function canViewTaskType(User $user, string $taskType): bool
    {
        if ($user->isSystemAdministrator()) {
            return true;
        }

        $reqs = self::requirementsFor($taskType);
        if ($reqs === null) {
            return true;
        }

        $scope = $user->scopeLevel();
        if ($scope === User::SCOPE_SYSTEM || $scope === User::SCOPE_DIVISION) {
            return true;
        }

        $sectionCode = $user->section?->code;

        if ($sectionCode && in_array($sectionCode, $reqs['sections'], true)) {
            return true;
        }

        return $user->hasAnyPermission($reqs['permissions']);
    }

    /**
     * May this user be ASSIGNED to execute / otherwise ACT ON a task of
     * $taskType? Strict: must be active, in the right section and hold the
     * required permission. System Administrators are NOT auto-eligible to be
     * assigned operational field tasks (their role manages config/RBAC); they
     * remain fully view-capable via canViewTaskType.
     */
    public static function canExecuteTaskType(User $user, string $taskType): bool
    {
        $reqs = self::requirementsFor($taskType);
        if ($reqs === null) {
            return true;
        }

        if (! $user->is_active) {
            return false;
        }

        if (! $user->hasAnyPermission($reqs['permissions'])) {
            return false;
        }

        $sectionCode = $user->section?->code;

        if (! $sectionCode || ! in_array($sectionCode, $reqs['sections'], true)) {
            return false;
        }

        return true;
    }

    /**
     * Human-readable eligibility list for a task type (for error messages).
     */
    public static function requirementsLabel(string $taskType): string
    {
        $reqs = self::requirementsFor($taskType);

        if ($reqs === null) {
            return 'no specific restriction';
        }

        return implode(' / ', array_merge($reqs['permissions'], array_map(fn ($s) => "section {$s}", $reqs['sections'])));
    }

    /**
     * All task types the user is VIEW-eligible to see (used for list filters).
     * Division/system scopes see everything; other scopes are restricted.
     *
     * @return string[]|null  null = unrestricted (view all)
     */
    public static function viewableTypes(User $user): ?array
    {
        if ($user->isSystemAdministrator()) {
            return null;
        }

        $scope = $user->scopeLevel();
        if ($scope === User::SCOPE_SYSTEM || $scope === User::SCOPE_DIVISION) {
            return null;
        }

        $sectionCode = $user->section?->code;
        $types = [];

        foreach (array_keys(self::TYPE_MAP) as $type) {
            if (self::canViewTaskType($user, $type)) {
                $types[] = $type;
            }
        }

        // Unrestricted types (e.g. Other) are always viewable.
        if (self::requirementsFor(Task::TYPE_OTHER) === null) {
            $types[] = Task::TYPE_OTHER;
        }

        // Remove the placeholder key used only to keep the map stable.
        $types = array_values(array_unique($types));

        return $types;
    }
}
