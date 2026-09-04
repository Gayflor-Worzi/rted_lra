<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\PropertyBill;
use App\Models\Role;
use App\Models\Section;
use App\Models\Task;
use App\Models\User;
use App\Models\Valuation;

class TaskService
{
    /**
     * Create the enforcement task for a logged bill.
     * Direct recipient -> immediate assignment to the officer.
     * Walk-in / other -> Awaiting Assignment (Enforcement queue).
     */
    public function createTaskFromBill(PropertyBill $bill, ?User $officer = null, ?User $actor = null): Task
    {
        $actor ??= auth()->user();

        if ($officer) {
            abort_unless(
                AssignmentEligibilityService::canExecuteTaskType($officer, Task::TYPE_BILL_DELIVERY),
                422,
                'Assignment rejected. '.$officer->full_name.' does not have permission to perform Bill Delivery.'
            );
        }

        $status = $officer ? 'Assigned' : 'Awaiting Assignment';

        $task = Task::create([
            'task_type' => 'Bill Delivery',
            'section' => 'Enforcement',
            'reference_type' => 'property_bill',
            'reference_id' => $bill->id,
            'assigned_to' => $officer?->id,
            'assigned_by' => $actor?->id,
            'priority' => 'Normal',
            'status' => $status,
            'due_date' => now()->addDays(14)->toDateString(),
            'remarks' => $officer
                ? 'Bill logged and assigned for delivery.'
                : 'Walk-in bill awaiting enforcement assignment.',
        ]);

        Task::where('id', $task->id)->update(['task_reference' => $task->task_reference]);

        if ($officer) {
            $this->notify($officer, 'New bill assigned', "Bill {$bill->document_number} assigned to you for delivery.", 'enforcement');
            $task->recordEngagement('assignment', 'assigned', "Bill {$bill->document_number} assigned to {$officer->full_name} for delivery.", $actor);
            $bill->update(['case_status' => 'Assigned']);
        } else {
            $this->notifyEnforcementManagers("Bill {$bill->document_number} is awaiting enforcement assignment.", 'enforcement');
            $task->recordEngagement('assignment', 'queued', "Walk-in bill {$bill->document_number} logged; awaiting enforcement assignment.", $actor);
            $bill->update(['case_status' => 'Awaiting Assignment']);
        }

        return $task;
    }

    /** Assign/reassign an existing bill's task to an officer. */
    public function assignBillTask(PropertyBill $bill, User $officer, ?User $actor = null): Task
    {
        $actor ??= auth()->user();

        abort_unless(
            AssignmentEligibilityService::canExecuteTaskType($officer, Task::TYPE_BILL_DELIVERY),
            422,
            'Assignment rejected. '.$officer->full_name.' does not have permission to perform Bill Delivery.'
        );

        $initial = $bill->tasks()->count() === 0;

        if ($initial) {
            $task = $this->createTaskFromBill($bill, $officer, $actor);

            return $task;
        }

        $task = $bill->tasks()->orderByDesc('id')->first();

        $fromOfficer = $task->assigned_to;
        $fromStatus = $task->status;
        $task->update([
            'assigned_to' => $officer->id,
            'assigned_by' => $actor?->id,
            'status' => 'Assigned',
            'due_date' => $task->due_date ?? now()->addDays(14)->toDateString(),
        ]);

        $task->history()->create([
            'from_status' => $fromStatus,
            'to_status' => 'Assigned',
            'action' => 'reassign',
            'performed_by' => $actor?->id,
            'remarks' => 'Reassigned from user #'.($fromOfficer ?? 'none')." to {$officer->full_name}.",
        ]);

        $this->notify($officer, 'Bill reassigned', "Bill {$bill->document_number} reassigned to you.", 'enforcement');

        $task->recordEngagement('assignment', 'reassigned', "Reassigned to {$officer->full_name}.".($fromOfficer ? " (from user #{$fromOfficer})." : ''), $actor);

        $bill->update([
            'assigned_enforcement_officer_id' => $officer->id,
            'case_status' => 'Assigned',
        ]);

        return $task;
    }

    /** Create the valuation task for a discovery-routed or scheduled valuation. */
    public function createValuationTask(Valuation $valuation, ?User $officer = null, ?User $actor = null): Task
    {
        $actor ??= auth()->user();

        if ($officer) {
            abort_unless(
                AssignmentEligibilityService::canExecuteTaskType($officer, Task::TYPE_VALUATION),
                422,
                'Assignment rejected. '.$officer->full_name.' does not have permission to perform Valuation.'
            );
        }

        return Task::create([
            'task_type' => 'Valuation',
            'section' => 'Valuation',
            'reference_type' => 'valuation',
            'reference_id' => $valuation->id,
            'assigned_to' => $officer?->id,
            'assigned_by' => $actor?->id,
            'priority' => 'Normal',
            'status' => $officer ? 'Assigned' : 'Awaiting Assignment',
            'due_date' => now()->addDays(7)->toDateString(),
            'remarks' => $officer
                ? "Valuation {$valuation->valuation_reference} assigned for completion."
                : "Valuation {$valuation->valuation_reference} awaiting assignment.",
        ]);
    }

    public function notify(?User $user, string $title, string $message, string $type = 'info', ?string $actionUrl = null): void
    {
        if (! $user) {
            return;
        }

        Notification::create([
            'user_id' => $user->id,
            'title' => $title,
            'message' => $message,
            'type' => $type,
            'action_url' => $actionUrl,
        ]);
    }

    public function notifyEnforcementManagers(string $message, string $type = 'info', ?string $actionUrl = null): void
    {
        $roleIds = Role::whereIn('name', ['Enforcement Manager', 'Enforcement Supervisor', 'M&E Officer'])
            ->pluck('id')
            ->all();

        User::whereIn('role_id', $roleIds)->where('is_active', true)->get()->each(function ($user) use ($message, $type, $actionUrl) {
            $this->notify($user, 'Awaiting assignment', $message, $type, $actionUrl);
        });
    }

    public function notifySection(string $sectionCode, string $title, string $message, string $type = 'info', ?string $actionUrl = null): void
    {
        $sectionId = Section::where('code', $sectionCode)->value('id');

        if (! $sectionId) {
            return;
        }

        User::where('section_id', $sectionId)->where('is_active', true)->get()->each(function ($user) use ($title, $message, $type, $actionUrl) {
            $this->notify($user, $title, $message, $type, $actionUrl);
        });
    }
}