<?php

namespace App\Services;

use App\Models\PropertyBill;
use App\Models\User;

/**
 * Single source of truth for which property bills a user may see based on
 * their role data scope (own | team | section | division | system). Consumed
 * by the bill register, the officer dashboard, and evidence/photo scoping so
 * every surface agrees on exactly the same record set.
 *
 * Returns null to mean "unrestricted" (System/Division scopes and the
 * Account Manager who spans the whole register); otherwise an array of
 * allowed PropertyBill ids.
 */
class BillScopeService
{
    /** Returns array of allowed ids, or null for unrestricted. */
    public static function visibleBillIds(User $user): ?array
    {
        if (self::userSeesAllBills($user)) {
            return null;
        }

        $scope = $user->scopeLevel();

        if (in_array($scope, ['system', 'division'], true)) {
            return null;
        }

        $q = PropertyBill::query()->select('id');

        if ($scope === 'own') {
            $q->where(function ($b) use ($user) {
                $b->where('assigned_enforcement_officer_id', $user->id)
                    ->orWhere('account_staff_id', $user->id)
                    ->orWhereIn('id', self::taskAssignedBillSub(function ($sub) use ($user) {
                        $sub->where('assigned_to', $user->id);
                    }));
            });
        } elseif ($scope === 'team') {
            $q->where(function ($b) use ($user) {
                $b->whereIn('assigned_enforcement_officer_id', function ($sub) use ($user) {
                    $sub->select('id')->from('users')->where('supervisor_id', $user->id);
                })->orWhere('account_staff_id', $user->id)
                    ->orWhereIn('id', self::taskAssignedBillSub(function ($sub) use ($user) {
                        $sub->whereIn('assigned_to', function ($sub2) use ($user) {
                            $sub2->select('id')->from('users')->where('supervisor_id', $user->id);
                        });
                    }));
            });
        } else { // section
            $q->where(function ($b) use ($user) {
                $b->whereIn('assigned_enforcement_officer_id', function ($sub) use ($user) {
                    $sub->select('id')->from('users')->where('section_id', $user->section_id)->where('is_active', true);
                })->orWhere('account_staff_id', $user->id)
                    ->orWhereIn('id', self::taskAssignedBillSub(function ($sub) use ($user) {
                        $sub->whereIn('assigned_to', function ($sub2) use ($user) {
                            $sub2->select('id')->from('users')->where('section_id', $user->section_id)->where('is_active', true);
                        });
                    }));
            });
        }

        return $q->pluck('id')->all();
    }

    /**
     * Bills referenced by tasks (the operative assignment record). The task
     * register is the source of truth for who works a bill — a task can be
     * assigned even when the bill's assigned_enforcement_officer_id column is
     * stale or null — so every surface (bill register, officer dashboard,
     * evidence scoping) must count the same bills the task register shows.
     */
    private static function taskAssignedBillSub(callable $constrain): \Closure
    {
        return function ($sub) use ($constrain) {
            $sub->select('tasks.reference_id')->from('tasks')
                ->where('reference_type', 'property_bill');

            $constrain($sub);
        };
    }

    /** @return int[] */
    public static function unassignedEnforcementBillIds(): array
    {
        return PropertyBill::query()
            ->where('case_status', 'Awaiting Assignment')
            ->whereNull('assigned_enforcement_officer_id')
            ->whereHas('tasks', fn ($task) => $task
                ->where('task_type', 'Bill Delivery')
                ->where('reference_type', 'property_bill')
                ->whereNull('assigned_to')
                ->where('status', 'Awaiting Assignment'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** Custodians of the full bill register see every bill & record. */
    public static function userSeesAllBills(User $user): bool
    {
        if (in_array($user->scopeLevel(), ['system', 'division'], true)) {
            return true;
        }

        // Account Manager spans the whole register as the records custodian.
        // Account & Records Officers / Supervisors remain scoped to the bills
        // they logged; a bill logged by another staff member is only reached
        // through the targeted amendment lookup (Property ID + Tax Period).
        return $user->hasRole('Account Manager');
    }
}
