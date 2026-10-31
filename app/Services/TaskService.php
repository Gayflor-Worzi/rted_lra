<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\PropertyBill;
use App\Models\PropertyDiscovery;
use App\Models\Role;
use App\Models\Section;
use App\Models\Task;
use App\Models\User;
use App\Models\Valuation;
use App\Support\BusinessCalendar;
use App\Support\BusinessClock;
use Illuminate\Database\QueryException;

class TaskService
{
    public function __construct(private readonly SlaDeadlineService $sla) {}

    /**
     * One place computes + persists every default task deadline (§14, §17):
     * the SLA snapshot written at creation is authoritative — later SLA
     * configuration changes never move an existing task's deadline (§14).
     */
    private function deadlineFields(
        int $days,
        ?string $slaCode = null,
        string $slaVersion = '1'
    ): array {
        $start = BusinessClock::now();
        $deadline = $this->sla->calculateDeadline(
            $start,
            $days,
            'day',
            BusinessCalendar::CALENDAR_DAYS
        );

        return [
            'due_date' => $deadline->toDateString(),
            'deadline_type' => BusinessClock::DEADLINE_DATE,
            'sla_code' => $slaCode,
            'sla_version' => $slaVersion,
            'sla_unit' => 'day',
            'sla_value' => $days,
            'sla_calendar_type' => BusinessCalendar::CALENDAR_DAYS,
            'sla_started_at' => $start,
            'sla_due_at' => $deadline,
        ];
    }

    public const SLA_BILL_DELIVERY = 'ENF_DELIVERY_14_DAY';

    public const SLA_PROCESSING = 'RETD_PROCESS_7_DAY';

    /** 3 calendar days (manager review, §34 example). */
    public const SLA_MANAGER_REVIEW = 'RETD_MANAGER_REVIEW_3_DAY';

    /**
     * Create the enforcement task for a logged bill.
     * Direct recipient -> immediate assignment to the officer.
     * Walk-in / other -> Awaiting Assignment (Enforcement queue).
     */
    public function createTaskFromBill(PropertyBill $bill, ?User $officer = null, ?User $actor = null): Task
    {
        $actor ??= auth()->user();
        $membership = null;

        if ($officer) {
            abort_unless(
                AssignmentEligibilityService::canExecuteTaskType($officer, Task::TYPE_BILL_DELIVERY),
                422,
                'Assignment rejected. '.$officer->full_name.' does not have permission to perform Bill Delivery.'
            );
            $membership = app(EnforcementOrganizationService::class)->requireActiveMembership($officer);
        }

        $status = $officer ? 'Assigned' : 'Awaiting Assignment';

        // Re-logging / re-processing the same bill must continue the delivery
        // task it already has, not stack a second one on the same bill.
        if ($existing = $this->reuseOpenTask('Bill Delivery', 'property_bill', $bill->id, $officer)) {
            if ($officer) {
                $bill->update([
                    'case_status' => 'Assigned',
                    'assigned_enforcement_officer_id' => $officer->id,
                    'primary_tax_district_id' => $membership->primary_district_id,
                ]);
            }

            return $existing;
        }

        $task = $this->createOpenTask(array_merge([
            'task_type' => 'Bill Delivery',
            'section' => 'Enforcement',
            'reference_type' => 'property_bill',
            'reference_id' => $bill->id,
            'assigned_to' => $officer?->id,
            'assigned_by' => $actor?->id,
            'priority' => 'Normal',
            'status' => $status,
            'remarks' => $officer
                ? 'Bill logged and assigned for delivery.'
                : 'Walk-in bill awaiting enforcement assignment.',
        ], $this->deadlineFields(14, self::SLA_BILL_DELIVERY)));

        if ($officer) {
            $this->notify($officer, 'New bill assigned', "Bill {$bill->document_number} assigned to you for delivery.", 'enforcement');
            $task->recordEngagement('assignment', 'assigned', "Bill {$bill->document_number} assigned to {$officer->full_name} for delivery.", $actor);
            $bill->update([
                'case_status' => 'Assigned',
                'assigned_enforcement_officer_id' => $officer->id,
                'primary_tax_district_id' => $membership->primary_district_id,
            ]);
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
        $membership = app(EnforcementOrganizationService::class)->requireActiveMembership($officer);

        $initial = $bill->tasks()->count() === 0;

        if ($initial) {
            $task = $this->createTaskFromBill($bill, $officer, $actor);

            return $task;
        }

        $task = $bill->tasks()->orderByDesc('id')->first();

        $fromOfficer = $task->assigned_to;
        $fromStatus = $task->status;
        $task->update(array_merge([
            'assigned_to' => $officer->id,
            'assigned_by' => $actor?->id,
            'status' => 'Assigned',
        ], $task->due_date ? [] : $this->deadlineFields(14, self::SLA_BILL_DELIVERY)));

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
            'primary_tax_district_id' => $membership->primary_district_id,
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

        if ($existing = $this->reuseOpenTask('Valuation', 'valuation', $valuation->id, $officer)) {
            return $existing;
        }

        return $this->createOpenTask(array_merge([
            'task_type' => 'Valuation',
            'section' => 'Valuation',
            'reference_type' => 'valuation',
            'reference_id' => $valuation->id,
            'assigned_to' => $officer?->id,
            'assigned_by' => $actor?->id,
            'priority' => 'Normal',
            'status' => $officer ? 'Assigned' : 'Awaiting Assignment',
            'remarks' => $officer
                ? "Valuation {$valuation->valuation_reference} assigned for completion."
                : "Valuation {$valuation->valuation_reference} awaiting assignment.",
        ], $this->deadlineFields(7, self::SLA_PROCESSING)));
    }

    /**
     * Re-running an action must not stack a second OPEN task for the same
     * reference + task type (this is how repeated script runs accumulated
     * 45 duplicate open tasks in the dev DB). When an open task already
     * exists it is reused instead; if the caller supplies a concrete
     * assignee it is re-assigned to match the latest intent.
     */
    private function reuseOpenTask(string $taskType, string $referenceType, int $referenceId, ?User $assignee): ?Task
    {
        $open = $this->openTask($taskType, $referenceType, $referenceId);

        if (! $open) {
            return null;
        }

        // Never hand an existing open task to a user who is not eligible for
        // its task type — reassignment must respect the same eligibility
        // contract as every other assignment path.
        if ($assignee !== null && $open->assigned_to !== $assignee->id) {
            if (! AssignmentEligibilityService::canExecuteTaskType($assignee, $taskType)) {
                return $open;
            }

            $fromStatus = $open->status;
            $fromOfficer = $open->assigned_to;

            $open->update(array_merge([
                'assigned_to' => $assignee->id,
                'status' => 'Assigned',
            ], $open->due_date ? [] : $this->deadlineFields(7, self::SLA_PROCESSING)));

            // Same task, same identifier — the continuation is still an event in
            // its history, so the audit trail shows who it moved to and from.
            $open->history()->create([
                'from_status' => $fromStatus,
                'to_status' => 'Assigned',
                'action' => 'reassign',
                'performed_by' => auth()->id(),
                'remarks' => 'Continued on the same task; reassigned from user #'.($fromOfficer ?? 'none')." to {$assignee->full_name}.",
                'created_at' => BusinessClock::now(),
            ]);
        }

        return $open;
    }

    /** The open task for one workflow step, if any. */
    private function openTask(string $taskType, string $referenceType, int $referenceId): ?Task
    {
        return Task::query()
            ->where('task_type', $taskType)
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->whereNull('completed_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Create the task, or adopt the one an identical concurrent request created.
     * The unique index on open_task_key is the arbiter, so two requests racing
     * on the same workflow step can never leave the property with two open
     * tasks — the loser continues the winner's task instead of failing.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createOpenTask(array $attributes): Task
    {
        try {
            return Task::create($attributes);
        } catch (QueryException $e) {
            $existing = $this->openTask(
                (string) $attributes['task_type'],
                (string) $attributes['reference_type'],
                (int) $attributes['reference_id'],
            );

            if ($existing) {
                return $existing;
            }

            throw $e;
        }
    }

    /**
     * The actionable correction task for a returned discovery.
     *
     * A return is work, not just a notification: the officer who recorded the
     * record has to correct and resubmit it, so the work must appear in their
     * task list (with a due date, so it can age) and carry the reason. Returning
     * the same record again continues the same open task — identical Task ID,
     * with the new reason appended to its history — instead of stacking another.
     *
     * TYPE_OTHER is the existing unrestricted task type: correction work is not a
     * review, a valuation or a processing assignment, and the recording officer
     * may sit in any section.
     */
    public function createDiscoveryCorrectionTask(
        PropertyDiscovery $discovery,
        ?User $officer = null,
        ?User $actor = null,
        ?string $reason = null,
    ): ?Task {
        $actor ??= auth()->user();
        $officer ??= $discovery->discoverer;

        // A record with no identifiable recorder still gets the notification sent
        // by the caller; there is simply nobody to assign the task to.
        if (! $officer) {
            return null;
        }

        $reason ??= 'The record was returned for correction.';

        if ($existing = $this->reuseOpenTask(Task::TYPE_OTHER, 'property_discovery', $discovery->id, $officer)) {
            $this->recordReturnEvent($existing, $actor, $reason);

            return $existing;
        }

        $task = $this->createOpenTask(array_merge([
            'task_type' => Task::TYPE_OTHER,
            'section' => $officer->section?->name ?? 'Enforcement',
            'reference_type' => 'property_discovery',
            'reference_id' => $discovery->id,
            'assigned_to' => $officer->id,
            'assigned_by' => $actor?->id,
            'priority' => 'Normal',
            'status' => 'Assigned',
            'remarks' => $reason,
        ], $this->deadlineFields(7, self::SLA_PROCESSING)));

        // The task is created *by* the return, so the return is its first event:
        // the reason is in the trail from the start, and every later return adds
        // another entry to the same task rather than rewriting this one.
        $this->recordReturnEvent($task, $actor, $reason);

        return $task;
    }

    /** Log a return on the correction task without changing its status. */
    private function recordReturnEvent(Task $task, ?User $actor, string $reason): void
    {
        $task->history()->create([
            'from_status' => $task->status,
            'to_status' => $task->status,
            'action' => 'returned_for_correction',
            'performed_by' => $actor?->id,
            'remarks' => $reason,
            'created_at' => BusinessClock::now(),
        ]);
    }

    /** Retire the open correction task once the corrected record is resubmitted. */
    public function closeDiscoveryCorrectionTask(PropertyDiscovery $discovery, User $actor, ?string $remarks = null): void
    {
        $discovery->tasks()
            ->where('task_type', Task::TYPE_OTHER)
            ->whereNotIn('status', Task::COMPLETED_STATUSES)
            ->get()
            ->each(function (Task $task) use ($actor, $remarks) {
                $task->forceTransition('Closed', 'resubmitted_for_review', $actor, $remarks);
                $task->recordEngagement('completed', 'resubmitted_for_review', $remarks, $actor);
            });
    }

    // ── Spec v4 desk-queue task helpers ──────────────────────────────────────

    /**
     * Retire the open tasks for a workflow stage that has just been completed.
     *
     * A stage's task has to close when the work it asked for is done, otherwise the
     * same property carries two open tasks at once — the finished stage and the next
     * one — and a single property lifecycle presents as several separate pieces of
     * work. This closes tasks; it never deletes them: the row stays, `forceTransition`
     * writes the task history, and an engagement records the outcome, so the stage
     * remains auditable and the task engine's own rules still own the status change.
     *
     * Returns how many tasks were closed.
     */
    public function closeStageTasks(
        string $taskType,
        string $referenceType,
        int $referenceId,
        string $action,
        ?User $actor = null,
        ?string $remarks = null
    ): int {
        $actor ??= auth()->user();

        return Task::query()
            ->where('task_type', $taskType)
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->whereNotIn('status', Task::COMPLETED_STATUSES)
            ->get()
            ->each(function (Task $task) use ($action, $actor, $remarks) {
                $task->forceTransition('Closed', $action, $actor, $remarks);
                $task->recordEngagement('completed', $action, $remarks, $actor);
            })
            ->count();
    }

    /**
     * First active user holding the given role name (for management desks that
     * should receive a direct, individually-visible task - e.g. Valuation
     * Manager, Account & Record Manager, Assistant Commissioner).
     */
    public function deskUser(string $roleName): ?User
    {
        $roleId = Role::where('name', $roleName)->value('id');

        return $roleId ? User::where('role_id', $roleId)->where('is_active', true)->first() : null;
    }

    /**
     * Create a management-desk task. With a resolvable desk user the task is
     * assigned directly; otherwise it sits in the desk queue as
     * 'Awaiting Assignment' (spec §15 task ownership).
     */
    public function createDeskTask(
        string $taskType,
        string $section,
        string $referenceType,
        int $referenceId,
        string $refLabel,
        ?User $deskUser = null,
        ?User $actor = null,
        array $extras = [],
    ): Task {
        $actor ??= auth()->user();

        // A desk user that does not meet the eligibility contract for this task
        // type (permission + section) must not be assigned directly — fall back
        // to the desk queue so a supervisor can route the case correctly.
        if ($deskUser && ! AssignmentEligibilityService::canExecuteTaskType($deskUser, $taskType)) {
            $deskUser = null;
        }

        if ($existing = $this->reuseOpenTask($taskType, $referenceType, $referenceId, $deskUser)) {
            return $existing;
        }

        $task = $this->createOpenTask(array_merge([
            'task_type' => $taskType,
            'section' => $section,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'assigned_to' => $deskUser?->id,
            'assigned_by' => $actor?->id,
            'priority' => 'Normal',
            'status' => $deskUser ? 'Assigned' : 'Awaiting Assignment',
            'remarks' => $deskUser
                ? "{$refLabel} requires action on your desk."
                : "{$refLabel} requires action on the {$section} desk queue.",
        ], $this->deadlineFields(7, self::SLA_PROCESSING), $extras));

        if ($deskUser) {
            $this->notifyWorkspaceAssignment($deskUser, $task);
        }

        return $task;
    }

    /**
     * Routed assignment alert — each desk task lands inside the assignee's OWN
     * section workspace (Assistant Commissioner / Account & Record / Valuation),
     * not in the enforcement inbox. The badge + action_url come from the section
     * workspace registry, so the sections can never cross wires.
     */
    public function notifyWorkspaceAssignment(User $user, Task $task): void
    {
        $this->notify(
            $user,
            'New task on your desk',
            "Task {$task->task_reference} — {$task->task_type} requires your action in RETD.",
            SectionWorkspaceService::notificationTypeForType($task->task_type),
            SectionWorkspaceService::actionUrlForType($task->task_type),
        );
    }

    /** Account & Record Manager desk task for a self-declared discovery (Path A) or approved valuation. */
    public function createDiscoveryReviewTask(PropertyDiscovery $discovery, ?User $deskUser = null, ?User $actor = null): Task
    {
        return $this->createDeskTask(
            Task::TYPE_DISCOVERY_REVIEW,
            'ACCT',
            'property_discovery',
            $discovery->id,
            "Discovery {$discovery->discovery_reference}",
            $deskUser,
            $actor,
        );
    }

    /** Valuation Manager desk task for a valuation-required discovery (Path B / Path C). */
    public function createValuationManagerReviewTask(PropertyDiscovery $discovery, ?User $deskUser = null, ?User $actor = null): Task
    {
        return $this->createDeskTask(
            Task::TYPE_VALUATION_MANAGER_REVIEW,
            'VAL',
            'property_discovery',
            $discovery->id,
            "Discovery {$discovery->discovery_reference}",
            $deskUser,
            $actor,
        );
    }

    /** Valuation Manager review task when an officer submits a completed assessment. */
    public function createValuationReviewTask(Valuation $valuation, ?User $deskUser = null, ?User $actor = null): Task
    {
        return $this->createDeskTask(
            Task::TYPE_VALUATION_REVIEW,
            'VAL',
            'valuation',
            $valuation->id,
            "Valuation {$valuation->valuation_reference}",
            $deskUser,
            $actor,
        );
    }

    /** Assistant Commissioner approval task for a manager-submitted valuation. */
    public function createAcApprovalTask(Valuation $valuation, ?User $ac = null, ?User $actor = null): Task
    {
        return $this->createDeskTask(
            Task::TYPE_AC_APPROVAL,
            'MGT',
            'valuation',
            $valuation->id,
            "Valuation {$valuation->valuation_reference}",
            $ac,
            $actor,
        );
    }

    /**
     * Account & Record Manager final-review task (Path B/D after AC approval).
     * This is a MANAGER decision — Account & Record Officers never receive it.
     */
    public function createAccountManagerReviewTask(string $referenceType, int $referenceId, string $refLabel, ?User $deskUser = null, ?User $actor = null): Task
    {
        return $this->createDeskTask(
            Task::TYPE_ACCOUNT_MANAGER_REVIEW,
            'ACCT',
            $referenceType,
            $referenceId,
            $refLabel,
            $deskUser,
            $actor,
        );
    }

    /**
     * The assigned processing task an Account & Record Officer executes: process
     * the property registration in the source system, then update the property
     * bill record in RETD. Never a review — the Manager reviewed before assigning.
     */
    public function createAccountProcessingTask(string $referenceType, int $referenceId, string $refLabel, User $officer, ?User $actor = null): Task
    {
        $actor ??= auth()->user();

        abort_unless(
            AssignmentEligibilityService::canExecuteTaskType($officer, Task::TYPE_ACCOUNT_PROCESSING),
            422,
            'Assignment rejected. '.$officer->full_name.' does not have permission to process property registration.'
        );

        if ($existing = $this->reuseOpenTask(Task::TYPE_ACCOUNT_PROCESSING, $referenceType, $referenceId, $officer)) {
            $this->notifyAccountAssignment($officer, $existing, $refLabel);

            return $existing;
        }

        $task = $this->createOpenTask(array_merge([
            'task_type' => Task::TYPE_ACCOUNT_PROCESSING,
            'section' => 'ACCT',
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'assigned_to' => $officer->id,
            'assigned_by' => $actor?->id,
            'priority' => 'Normal',
            'status' => 'Assigned',
            'remarks' => "{$refLabel} approved by the Account & Record Manager — process the property registration in the source system, then update the property bill record in RETD.",
        ], $this->deadlineFields(7, self::SLA_PROCESSING)));

        $this->notifyAccountAssignment($officer, $task, $refLabel);

        return $task;
    }

    /** Assignment alert for the Account & Record "My Tasks" badge. */
    private function notifyAccountAssignment(User $officer, Task $task, string $refLabel): void
    {
        $this->notify(
            $officer,
            'New task assigned',
            "Task {$task->task_reference} — {$refLabel} requires your action in RETD.",
            Notification::TYPE_ACCOUNT_TASKS,
            '/account/my-tasks',
        );
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
