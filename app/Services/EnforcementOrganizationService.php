<?php

namespace App\Services;

use App\Models\EnforcementDistrict;
use App\Models\EnforcementDistrictRegionHistory;
use App\Models\EnforcementOrgRecommendation;
use App\Models\EnforcementTaxRegion;
use App\Models\EnforcementTaxRegionSupervisorHistory;
use App\Models\EnforcementTeam;
use App\Models\EnforcementTeamDistrictHistory;
use App\Models\EnforcementTeamMembership;
use App\Models\Notification;
use App\Models\PropertyBill;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EnforcementOrganizationService
{
    public function index(User $actor): array
    {
        $role = $actor->role?->name;
        abort_unless(in_array($role, ['Enforcement Manager', 'Enforcement Supervisor', 'Assistant Commissioner', 'System Administrator'], true), 403);

        $teams = EnforcementTeam::query()
            ->with([
                'manager:id,full_name',
                'supervisor:id,full_name',
                'districts:id,name,is_active,region_id',
                'districts.region:id,name,code,classification,supervisor_id',
                'districts.region.supervisor:id,full_name,is_active',
                'currentMemberships.officer:id,full_name,staff_id,is_active',
                'currentMemberships.primaryDistrict:id,name',
            ])
            ->when($role === 'Enforcement Manager', fn ($query) => $query->where('manager_id', $actor->id))
            ->when($role === 'Enforcement Supervisor', fn ($query) => $query->where(function ($scope) use ($actor) {
                $scope->where('supervisor_id', $actor->id)
                    ->orWhereHas('districts.region', fn ($region) => $region->where('supervisor_id', $actor->id));
            }))
            ->orderBy('name')
            ->get()
            ->map(function (EnforcementTeam $team) {
                $regionIds = $team->districts->pluck('region_id')->unique();
                $region = $regionIds->count() === 1 ? $team->districts->first()?->region : null;

                return [
                    'id' => $team->id,
                    'name' => $team->name,
                    'manager_id' => $team->manager_id,
                    'manager' => $team->manager?->full_name,
                    'supervisor_id' => $region?->supervisor_id ?? $team->supervisor_id,
                    'supervisor' => $region?->supervisor?->full_name ?? $team->supervisor?->full_name,
                    'region_id' => $region?->id,
                    'region' => $region?->name,
                    'classification' => $region?->classification,
                    'is_configured' => (bool) (
                        $region?->is_active
                        && $region?->supervisor?->is_active
                        && (int) $team->supervisor_id === (int) $region->supervisor_id
                    ),
                    'is_active' => $team->is_active,
                    'districts' => $team->districts->map(fn (EnforcementDistrict $district) => [
                        'id' => $district->id,
                        'name' => $district->name,
                        'is_active' => $district->is_active,
                        'region_id' => $district->region_id,
                        'region' => $district->region?->name,
                        'classification' => $district->region?->classification,
                    ])->values(),
                    'officers' => $team->currentMemberships->map(fn (EnforcementTeamMembership $membership) => [
                        'membership_id' => $membership->id,
                        'officer_id' => $membership->officer_id,
                        'officer' => $membership->officer?->full_name,
                        'staff_id' => $membership->officer?->staff_id,
                        'is_team_lead' => $membership->is_team_lead,
                        'primary_district_id' => $membership->primary_district_id,
                        'primary_district' => $membership->primaryDistrict?->name,
                        'started_at' => $membership->started_at?->toISOString(),
                    ])->values(),
                ];
            })->values()->all();

        $teamIds = collect($teams)->pluck('id')->all();
        $districtQuery = EnforcementDistrict::query()->with('region:id,name,code,classification,supervisor_id')->orderBy('name');
        if ($role === 'Enforcement Supervisor') {
            $districtQuery->where(function ($scope) use ($actor, $teamIds) {
                $scope->whereHas('region', fn ($region) => $region->where('supervisor_id', $actor->id))
                    ->orWhereHas('teams', fn ($team) => $team->whereIn('enforcement_teams.id', $teamIds));
            });
        }

        $regionQuery = EnforcementTaxRegion::query()
            ->with(['supervisor:id,full_name,is_active', 'districts:id,name,is_active,region_id'])
            ->orderBy('name');
        if ($role === 'Enforcement Supervisor') {
            $regionQuery->where('supervisor_id', $actor->id);
        }

        $recommendations = EnforcementOrgRecommendation::query()
            ->with(['submitter:id,full_name', 'decider:id,full_name'])
            ->when($role === 'Enforcement Manager', fn ($query) => $query
                ->where(function ($sub) use ($actor, $teamIds) {
                    $sub->where('submitted_by', $actor->id)
                        ->orWhereIn('team_id', $teamIds);
                }))
            ->when($role === 'Enforcement Supervisor', fn ($query) => $query
                ->where(function ($sub) use ($actor, $teamIds) {
                    $sub->where('submitted_by', $actor->id)
                        ->orWhereIn('team_id', $teamIds)
                        ->orWhere(function ($teamRecommendation) use ($actor) {
                            $teamRecommendation->where('change_type', 'create_team')
                                ->where('payload->supervisor_id', $actor->id);
                        });
                }))
            ->orderByDesc('submitted_at')
            ->limit(100)
            ->get()
            ->map(fn (EnforcementOrgRecommendation $recommendation) => [
                ...$this->presentRecommendation($recommendation),
                'can_review' => $this->canReviewRecommendation($actor, $recommendation),
            ])
            ->values()
            ->all();

        $officerQuery = User::query()
            ->with(['role:id,name', 'section:id,code'])
            ->where('is_active', true)
            ->whereHas('role', fn ($query) => $query->where('name', 'Enforcement Officer'))
            ->whereHas('section', fn ($query) => $query->where('code', 'ENF'));

        if ($role === 'Enforcement Supervisor') {
            $officerQuery->where(function ($query) use ($actor) {
                $query->where('supervisor_id', $actor->id)
                    ->orWhereDoesntHave('enforcementTeamMemberships', fn ($membership) => $membership->whereNull('ended_at'));
            });
        } elseif ($role === 'Enforcement Manager') {
            $officerQuery->where(function ($query) use ($teamIds) {
                $query->whereDoesntHave('enforcementTeamMemberships', fn ($membership) => $membership->whereNull('ended_at'))
                    ->orWhereHas('enforcementTeamMemberships', fn ($membership) => $membership
                        ->whereNull('ended_at')
                        ->whereIn('team_id', $teamIds));
            });
        }

        $officers = $officerQuery->orderBy('full_name')->get(['id', 'full_name', 'staff_id', 'supervisor_id'])
            ->map(function (User $officer) {
                $membership = EnforcementTeamMembership::query()
                    ->with('team:id,name')
                    ->where('officer_id', $officer->id)
                    ->whereNull('ended_at')
                    ->first();

                return [
                    'id' => $officer->id,
                    'full_name' => $officer->full_name,
                    'staff_id' => $officer->staff_id,
                    'supervisor_id' => $officer->supervisor_id,
                    'current_team_id' => $membership?->team_id,
                    'current_team' => $membership?->team?->name,
                ];
            })->values()->all();

        $supervisors = User::query()
            ->where('is_active', true)
            ->whereHas('role', fn ($query) => $query->where('name', 'Enforcement Supervisor'))
            ->whereHas('section', fn ($query) => $query->where('code', 'ENF'))
            ->orderBy('full_name')
            ->get(['id', 'full_name'])
            ->map(fn (User $supervisor) => ['id' => $supervisor->id, 'full_name' => $supervisor->full_name])
            ->values()
            ->all();

        return [
            'regions' => $regionQuery->get()->map(fn (EnforcementTaxRegion $region) => [
                'id' => $region->id,
                'name' => $region->name,
                'code' => $region->code,
                'classification' => $region->classification,
                'description' => $region->description,
                'is_active' => $region->is_active,
                'supervisor_id' => $region->supervisor_id,
                'supervisor' => $region->supervisor?->full_name,
                'supervisor_active' => (bool) $region->supervisor?->is_active,
                'districts' => $region->districts->map(fn (EnforcementDistrict $district) => [
                    'id' => $district->id,
                    'name' => $district->name,
                    'is_active' => $district->is_active,
                ])->values(),
            ])->values()->all(),
            'districts' => $districtQuery->get(['id', 'name', 'is_active', 'region_id', 'created_at'])
                ->map(fn (EnforcementDistrict $district) => [
                    'id' => $district->id,
                    'name' => $district->name,
                    'is_active' => $district->is_active,
                    'region_id' => $district->region_id,
                    'region' => $district->region?->name,
                    'classification' => $district->region?->classification,
                    'created_at' => $district->created_at?->toISOString(),
                ])->values()->all(),
            'teams' => $teams,
            'officers' => $officers,
            'supervisors' => $supervisors,
            'recommendations' => $recommendations,
            'can_manage' => $role === 'Enforcement Manager' && $actor->canPermission('enforcement.org.manage'),
            'can_administer_geography' => $actor->isSystemAdministrator()
                || ($role === 'Enforcement Manager' && $actor->canPermission('enforcement.org.manage')),
            'can_assign_supervisor' => $role === 'Enforcement Manager' && $actor->canPermission('enforcement.org.manage'),
            'can_recommend' => $role === 'Enforcement Supervisor' && $actor->canPermission('enforcement.org.recommend'),
            'can_review' => $role === 'Assistant Commissioner' && $actor->canPermission('enforcement.org.review'),
            'can_review_membership' => $role === 'Enforcement Manager' && $actor->canPermission('enforcement.org.manage'),
        ];
    }

    public function submit(User $actor, array $input): EnforcementOrgRecommendation
    {
        $payload = $this->validatePayload($input);
        $type = $input['change_type'];
        $team = isset($payload['team_id']) ? EnforcementTeam::findOrFail($payload['team_id']) : null;

        $this->authorizeSubmission($actor, $type, $team, $payload);
        if (in_array($type, ['create_team', 'update_team'], true)) {
            $districtIds = $payload['district_ids'] ?? $team?->districts()->pluck('enforcement_districts.id')->all() ?? [];
            $region = $this->regionForDistricts($districtIds);
            $payload['region_id'] = $region->id;
            $payload['supervisor_id'] = $region->supervisor_id;

            // Validate initial membership before review so an approved team is
            // operationally complete from creation.
            if ($type === 'create_team') {
                $this->validateTeamMembers($payload);
            }
        }

        $this->authorizeSubmission($actor, $type, $team, $payload);

        $applyImmediately = $actor->role?->name === 'Enforcement Manager'
            && in_array($type, ['assign_officer', 'remove_officer', 'assign_region_supervisor'], true);

        if ($applyImmediately) {
            return DB::transaction(function () use ($actor, $type, $team, $payload) {
                $recommendation = EnforcementOrgRecommendation::create([
                    'change_type' => $type,
                    'team_id' => $team?->id,
                    'payload' => $payload,
                    'submitted_by' => $actor->id,
                    'status' => 'pending',
                    'submitted_at' => now(),
                ]);
                $previous = $this->apply($recommendation, $actor);
                $recommendation->update([
                    'status' => 'approved',
                    'decided_by' => $actor->id,
                    'decided_at' => now(),
                    'previous_values' => $previous['previous_values'] ?? null,
                    'applied_record_id' => $previous['applied_record_id'] ?? null,
                ]);

                app(AuditService::class)->record($recommendation, 'enforcement.organization.approved', $actor->id, [
                    'status' => 'pending',
                ], [
                    'status' => 'approved',
                    'change_type' => $recommendation->change_type,
                    'previous_values' => $recommendation->previous_values,
                    'payload' => $recommendation->payload,
                ]);

                return $recommendation->fresh(['submitter:id,full_name', 'decider:id,full_name']);
            });
        }

        $recommendation = EnforcementOrgRecommendation::create([
            'change_type' => $type,
            'team_id' => $team?->id,
            'payload' => $payload,
            'submitted_by' => $actor->id,
            'status' => 'pending',
            'submitted_at' => now(),
        ]);
        $this->notifySubmission($actor, $recommendation);

        return $recommendation;
    }

    private function notifySubmission(User $submitter, EnforcementOrgRecommendation $recommendation): void
    {
        $recipientIds = match (true) {
            in_array($recommendation->change_type, ['assign_officer', 'remove_officer'], true),
            $recommendation->change_type === 'update_team' && $submitter->role?->name === 'Enforcement Supervisor' => array_filter([EnforcementTeam::whereKey($recommendation->team_id)->value('manager_id')]),
            default => User::query()
                ->where('is_active', true)
                ->whereHas('role', fn ($query) => $query->where('name', 'Assistant Commissioner'))
                ->pluck('id')
                ->all(),
        };

        $change = $this->changeTypeLabel($recommendation->change_type);

        foreach (array_unique(array_filter($recipientIds)) as $recipientId) {
            if ((int) $recipientId === (int) $submitter->id) {
                continue;
            }

            Notification::create([
                'user_id' => $recipientId,
                'title' => 'Enforcement organization change awaiting review',
                'message' => "{$submitter->full_name} submitted a {$change} request for review.",
                'type' => Notification::TYPE_ORGANIZATION,
                'action_url' => '/enforcement?tab=organization',
            ]);
        }
    }

    private function notifyDecision(EnforcementOrgRecommendation $recommendation, string $decision, User $reviewer): void
    {
        if ((int) $recommendation->submitted_by === (int) $reviewer->id) {
            return;
        }

        Notification::create([
            'user_id' => $recommendation->submitted_by,
            'title' => 'Enforcement organization request '.$decision,
            'message' => 'Your '.$this->changeTypeLabel($recommendation->change_type).' request was '.$decision.'.',
            'type' => Notification::TYPE_ORGANIZATION,
            'action_url' => '/enforcement?tab=organization',
        ]);
    }

    private function changeTypeLabel(string $type): string
    {
        return strtolower(str_replace('_', ' ', $type));
    }

    public function approve(User $reviewer, EnforcementOrgRecommendation $recommendation): EnforcementOrgRecommendation
    {
        $this->authorizeReview($reviewer, $recommendation);

        return DB::transaction(function () use ($reviewer, $recommendation) {
            $locked = EnforcementOrgRecommendation::query()->whereKey($recommendation->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'pending', 422, 'Only pending recommendations can be reviewed.');
            $this->authorizeReview($reviewer, $locked);

            $previous = $this->apply($locked, $reviewer);
            $locked->update([
                'status' => 'approved',
                'decided_by' => $reviewer->id,
                'decided_at' => now(),
                'previous_values' => $previous['previous_values'] ?? null,
                'applied_record_id' => $previous['applied_record_id'] ?? null,
            ]);

            app(AuditService::class)->record($locked, 'enforcement.organization.approved', $reviewer->id, [
                'status' => 'pending',
            ], [
                'status' => 'approved',
                'change_type' => $locked->change_type,
                'previous_values' => $locked->previous_values,
                'payload' => $locked->payload,
            ]);
            $this->notifyDecision($locked, 'approved', $reviewer);

            return $locked->fresh(['submitter:id,full_name', 'decider:id,full_name']);
        });
    }

    public function reject(User $reviewer, EnforcementOrgRecommendation $recommendation, ?string $notes): EnforcementOrgRecommendation
    {
        $this->authorizeReview($reviewer, $recommendation);
        abort_if(trim((string) $notes) === '', 422, 'A reason is required when rejecting an organization recommendation.');

        return DB::transaction(function () use ($reviewer, $recommendation, $notes) {
            $locked = EnforcementOrgRecommendation::query()->whereKey($recommendation->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'pending', 422, 'Only pending recommendations can be reviewed.');
            $this->authorizeReview($reviewer, $locked);

            $locked->update([
                'status' => 'rejected',
                'decided_by' => $reviewer->id,
                'decision_notes' => $notes,
                'decided_at' => now(),
            ]);

            app(AuditService::class)->record($locked, 'enforcement.organization.rejected', $reviewer->id, [
                'status' => 'pending',
            ], [
                'status' => 'rejected',
                'change_type' => $locked->change_type,
                'decision_notes' => $notes,
            ]);
            $this->notifyDecision($locked, 'rejected', $reviewer);

            return $locked->fresh(['submitter:id,full_name', 'decider:id,full_name']);
        });
    }

    public function activeMembershipFor(User $officer): ?EnforcementTeamMembership
    {
        return EnforcementTeamMembership::query()
            ->where('officer_id', $officer->id)
            ->whereNull('ended_at')
            ->first();
    }

    public function requireActiveMembership(User $officer): EnforcementTeamMembership
    {
        $membership = EnforcementTeamMembership::query()
            ->with(['team.districts.region.supervisor', 'primaryDistrict.region'])
            ->where('officer_id', $officer->id)
            ->whereNull('ended_at')
            ->first();

        abort_if(! $membership || ! $membership->team?->is_active, 422, 'The Enforcement Officer must have an approved active team assignment before receiving a bill.');
        abort_if(! $membership->primaryDistrict?->is_active, 422, 'The Enforcement Officer must have an active primary tax district before receiving a bill.');
        $region = $membership->primaryDistrict?->region;
        abort_unless(
            $region?->is_active
                && (int) $membership->team->supervisor_id === (int) $region->supervisor_id
                && (int) $membership->supervisor_id === (int) $region->supervisor_id,
            422,
            'The Enforcement team must be configured under an active region with its assigned Supervisor before receiving a bill.'
        );
        abort_if(
            ! $membership->team->currentMemberships()->where('is_team_lead', true)->exists(),
            422,
            'Assign a Team Lead before assigning property bills to this team.'
        );
        abort_unless(
            $membership->team->districts()->whereKey($membership->primary_district_id)->exists(),
            422,
            'The Enforcement Officer primary district is no longer assigned to the active team.'
        );

        return $membership;
    }

    public function authorizeBillAssignment(User $actor, User $officer, ?Task $task = null): EnforcementTeamMembership
    {
        abort_unless(
            $actor->is_active && in_array($actor->role?->name, ['Enforcement Supervisor', 'System Administrator'], true),
            403,
            'Only an Enforcement Supervisor may assign unassigned bill-delivery work.'
        );

        $membership = $this->requireActiveMembership($officer);
        if ($actor->role?->name === 'System Administrator') {
            return $membership;
        }

        abort_unless(
            $actor->section?->code === 'ENF'
                && (int) $membership->supervisor_id === (int) $actor->id
                && EnforcementTeam::whereKey($membership->team_id)->where('supervisor_id', $actor->id)->exists(),
            403,
            'The officer is outside your Enforcement team scope.'
        );

        abort_unless($task !== null, 422, 'Supervisors may assign only bills in the unassigned enforcement queue.');
        abort_unless(
            $task->task_type === Task::TYPE_BILL_DELIVERY
                && $task->reference_type === 'property_bill'
                && $task->assigned_to === null
                && $task->status === 'Awaiting Assignment',
            422,
            'Supervisors may assign only unassigned bill-delivery tasks awaiting assignment.'
        );
        $bill = PropertyBill::query()->find($task->reference_id);
        abort_unless(
            $bill
                && $bill->case_status === 'Awaiting Assignment'
                && $bill->assigned_enforcement_officer_id === null,
            422,
            'The bill is no longer awaiting enforcement assignment.'
        );

        return $membership;
    }

    private function validatePayload(array $input): array
    {
        foreach (['name', 'code'] as $field) {
            if (isset($input[$field]) && is_string($input[$field])) {
                $input[$field] = trim($input[$field]);
            }
        }
        if (isset($input['code'])) {
            $input['code'] = strtoupper($input['code']);
        }

        $type = $input['change_type'] ?? '';
        $rules = match ($type) {
            'create_region' => [
                'name' => ['required', 'string', 'max:120', Rule::unique('enforcement_tax_regions', 'name')],
                'code' => ['required', 'string', 'max:40', 'alpha_dash', Rule::unique('enforcement_tax_regions', 'code')],
                'classification' => ['required', Rule::in(['Rural', 'Urban'])],
                'description' => ['nullable', 'string', 'max:2000'],
                'reason' => ['nullable', 'string', 'max:2000'],
            ],
            'update_region' => [
                'region_id' => ['required', 'integer', Rule::exists('enforcement_tax_regions', 'id')],
                'name' => ['sometimes', 'required', 'string', 'max:120', Rule::unique('enforcement_tax_regions', 'name')->ignore($input['region_id'] ?? null)],
                'code' => ['sometimes', 'required', 'string', 'max:40', 'alpha_dash', Rule::unique('enforcement_tax_regions', 'code')->ignore($input['region_id'] ?? null)],
                'classification' => ['sometimes', 'required', Rule::in(['Rural', 'Urban'])],
                'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
                'is_active' => ['sometimes', 'required', 'boolean'],
                'reason' => ['nullable', 'string', 'max:2000'],
            ],
            'assign_region_supervisor' => [
                'region_id' => ['required', 'integer', Rule::exists('enforcement_tax_regions', 'id')],
                'supervisor_id' => ['required', 'integer', Rule::exists('users', 'id')],
                'reason' => ['nullable', 'string', 'max:2000'],
            ],
            'create_district' => [
                'name' => ['required', 'string', 'max:120', Rule::unique('enforcement_districts', 'name')],
                'reason' => ['nullable', 'string', 'max:2000'],
            ],
            'update_district' => [
                'district_id' => ['required', 'integer', Rule::exists('enforcement_districts', 'id')],
                'name' => ['sometimes', 'required', 'string', 'max:120', Rule::unique('enforcement_districts', 'name')->ignore($input['district_id'] ?? null)],
                'is_active' => ['sometimes', 'required', 'boolean'],
                'reason' => ['nullable', 'string', 'max:2000'],
            ],
            'assign_districts_to_region' => [
                'region_id' => ['required', 'integer', Rule::exists('enforcement_tax_regions', 'id')],
                'district_ids' => ['required', 'array', 'min:1'],
                'district_ids.*' => ['required', 'integer', 'distinct', Rule::exists('enforcement_districts', 'id')],
                'reason' => ['nullable', 'string', 'max:2000'],
            ],
            'create_team' => [
                'name' => ['required', 'string', 'max:120', Rule::unique('enforcement_teams', 'name')],
                'district_ids' => ['required', 'array', 'min:1'],
                'district_ids.*' => ['required', 'integer', 'distinct', Rule::exists('enforcement_districts', 'id')],
                'reason' => ['nullable', 'string', 'max:2000'],
                'members' => ['nullable', 'array'],
                'members.*.officer_id' => ['required', 'integer', 'distinct', Rule::exists('users', 'id')],
                'members.*.primary_district_id' => ['required', 'integer', Rule::exists('enforcement_districts', 'id')],
                'members.*.is_team_lead' => ['required', 'boolean'],
            ],
            'update_team' => [
                'team_id' => ['required', 'integer', Rule::exists('enforcement_teams', 'id')],
                'name' => ['sometimes', 'required', 'string', 'max:120', Rule::unique('enforcement_teams', 'name')->ignore($input['team_id'] ?? null)],
                'district_ids' => ['sometimes', 'required', 'array', 'min:1'],
                'district_ids.*' => ['required', 'integer', 'distinct', Rule::exists('enforcement_districts', 'id')],
                'is_active' => ['sometimes', 'required', 'boolean'],
                'reason' => ['nullable', 'string', 'max:2000'],
            ],
            'assign_officer' => [
                'team_id' => ['required', 'integer', Rule::exists('enforcement_teams', 'id')],
                'officer_id' => ['required', 'integer', Rule::exists('users', 'id')],
                'primary_district_id' => ['required', 'integer', Rule::exists('enforcement_districts', 'id')],
                'is_team_lead' => ['required', 'boolean'],
            ],
            'remove_officer' => [
                'team_id' => ['required', 'integer', Rule::exists('enforcement_teams', 'id')],
                'officer_id' => ['required', 'integer', Rule::exists('users', 'id')],
            ],
            default => throw ValidationException::withMessages(['change_type' => 'Unsupported organization change type.']),
        };

        $validator = Validator::make($input, $rules);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $payload = $validator->validated();
        unset($payload['change_type']);

        return $payload;
    }

    private function authorizeSubmission(User $actor, string $type, ?EnforcementTeam $team, array $payload): void
    {
        $role = $actor->role?->name;
        $geographyChanges = [
            'create_region', 'update_region', 'create_district', 'update_district', 'assign_districts_to_region',
        ];
        $isManager = $role === 'Enforcement Manager';
        $isSupervisor = $role === 'Enforcement Supervisor';
        $isAdministrator = $actor->isSystemAdministrator();

        abort_unless($actor->is_active, 403, 'An active account is required.');
        abort_unless(
            ($isManager || $isSupervisor) && $actor->section?->code === 'ENF' || $isAdministrator,
            403,
            'An authorized Enforcement Manager, Supervisor, or System Administrator is required.'
        );

        if (in_array($type, $geographyChanges, true)) {
            abort_unless($isManager || $isAdministrator, 403, 'Only an Enforcement Manager or System Administrator may administer tax geography.');
        }

        if (in_array($type, ['create_team', 'assign_region_supervisor'], true)) {
            abort_unless($isManager, 403, 'Only an Enforcement Manager may administer teams or assign regional supervisors.');
        }

        if ($type === 'update_team') {
            abort_unless(
                $isManager || $isSupervisor,
                403,
                'Only an Enforcement Manager or the regional Supervisor may update a team.'
            );
        }

        if (in_array($type, ['assign_officer', 'remove_officer'], true)) {
            abort_unless($team && $team->is_active, 422, 'An active team is required.');
            $hasAuthority = $isManager
                ? (int) $team->manager_id === (int) $actor->id
                : (int) $team->supervisor_id === (int) $actor->id;
            abort_unless($hasAuthority, 403, 'The team is outside your organizational scope.');
        } elseif ($team) {
            $hasAuthority = $isManager
                ? (int) $team->manager_id === (int) $actor->id
                : ($type === 'update_team' && $this->teamIsInSupervisorScope($team, $actor));
            abort_unless($hasAuthority, 403, 'The team is outside your organizational scope.');
        }

        // Supervisors may recommend team updates, but only inside their own tax
        // region: neither the existing team nor the destination districts may
        // belong to another region's supervisor.
        if ($type === 'update_team' && $isSupervisor && isset($payload['district_ids'])) {
            $regionIds = EnforcementDistrict::query()
                ->whereIn('id', $payload['district_ids'])
                ->pluck('region_id')
                ->unique();
            abort_unless(
                $regionIds->count() === 1
                    && EnforcementTaxRegion::query()
                        ->whereKey($regionIds->first())
                        ->where('supervisor_id', $actor->id)
                        ->exists(),
                403,
                'A team may only be updated within your own tax region.'
            );
        }

        if ($type === 'assign_region_supervisor') {
            $region = EnforcementTaxRegion::query()->whereKey($payload['region_id'])->firstOrFail();
            abort_unless($region->is_active, 422, 'An active tax region is required.');
            $this->assertSupervisor(User::with(['role', 'section'])->findOrFail($payload['supervisor_id']));
        }

        if ($type === 'create_team' || $type === 'update_team') {
            if ($type === 'update_team' && isset($payload['district_ids'])) {
                $currentDistricts = $team->currentMemberships()->pluck('primary_district_id')->unique();
                abort_if(
                    $currentDistricts->diff($payload['district_ids'])->isNotEmpty(),
                    422,
                    'A district assigned as an officer primary district cannot be removed from its team.'
                );
            }

            if ($type === 'update_team' && ($payload['is_active'] ?? true) === false) {
                abort_if($team->currentMemberships()->exists(), 422, 'Remove all officers before deactivating a team.');
            }
        }

        if ($type === 'update_region' && ($payload['is_active'] ?? true) === false) {
            $region = EnforcementTaxRegion::findOrFail($payload['region_id']);
            abort_if(
                $region->districts()->whereHas('teams', fn ($query) => $query->where('is_active', true))->exists(),
                422,
                'Remove or deactivate the region teams before deactivating this tax region.'
            );
        }

        if ($type === 'update_district' && ($payload['is_active'] ?? true) === false) {
            $district = EnforcementDistrict::findOrFail($payload['district_id']);
            abort_if(
                $district->teams()->where('is_active', true)->exists(),
                422,
                'Remove or deactivate the district teams before deactivating this tax district.'
            );
        }

        if ($type === 'assign_districts_to_region') {
            $region = EnforcementTaxRegion::query()->findOrFail($payload['region_id']);
            abort_unless($region->is_active, 422, 'Districts can only be assigned to an active tax region.');
            $selectedIds = $payload['district_ids'];
            $districts = EnforcementDistrict::query()->whereIn('id', $selectedIds)->get();
            abort_unless($districts->count() === count($selectedIds), 422, 'All selected districts must exist.');

            $affectedTeams = EnforcementTeam::query()
                ->whereHas('districts', fn ($query) => $query->whereIn('enforcement_districts.id', $selectedIds))
                ->with('districts:id,region_id')
                ->get();
            foreach ($affectedTeams as $affectedTeam) {
                $unselectedDistricts = $affectedTeam->districts
                    ->filter(fn (EnforcementDistrict $district) => ! in_array($district->id, $selectedIds)
                        && (int) $district->region_id !== (int) $region->id);
                abort_if(
                    $unselectedDistricts->isNotEmpty(),
                    422,
                    'Move all districts of each affected team together so the team remains within one tax region.'
                );
                if ($affectedTeam->is_active) {
                    $this->assertSupervisor($region->supervisor()->with(['role', 'section'])->firstOrFail());
                }
            }
        }

        if ($type === 'assign_officer') {
            $officer = User::with(['role', 'section'])->findOrFail($payload['officer_id']);
            $this->assertOfficer($officer);
            $this->regionForDistricts($team->districts()->pluck('enforcement_districts.id')->all());
            abort_unless(EnforcementDistrict::whereKey($payload['primary_district_id'])
                ->where('is_active', true)
                ->whereHas('teams', fn ($query) => $query->whereKey($team->id))
                ->exists(), 422, 'The officer primary district must be active and assigned to this team.');

            $current = $this->activeMembershipFor($officer);
            if ($current && $current->team_id !== $team->id) {
                $previousTeam = $current->team;
                $withinScope = $isManager
                    ? (int) $previousTeam?->manager_id === (int) $actor->id
                    : (int) $previousTeam?->supervisor_id === (int) $actor->id;
                abort_unless($withinScope, 403, 'An officer cannot be moved from another management scope.');
            }
        }

        if ($type === 'remove_officer') {
            $membership = EnforcementTeamMembership::query()
                ->where('team_id', $team->id)
                ->where('officer_id', $payload['officer_id'])
                ->whereNull('ended_at')
                ->first();
            abort_unless($membership, 422, 'The officer is not currently assigned to this team.');
        }
    }

    /**
     * A Supervisor's team scope is the tax region they supervise: every one of
     * the team's districts must sit in that single region.
     */
    private function teamIsInSupervisorScope(EnforcementTeam $team, User $actor): bool
    {
        $regionIds = $team->districts()->pluck('region_id')->unique()->filter();

        return $regionIds->count() === 1
            && EnforcementTaxRegion::query()
                ->whereKey($regionIds->first())
                ->where('supervisor_id', $actor->id)
                ->exists();
    }

    private function authorizeReview(User $reviewer, EnforcementOrgRecommendation $recommendation): void
    {
        abort_unless($reviewer->is_active, 403);

        if ($recommendation->change_type === 'update_team'
            && $recommendation->submitter()->whereHas('role', fn ($query) => $query->where('name', 'Enforcement Supervisor'))->exists()) {
            $team = EnforcementTeam::findOrFail($recommendation->team_id);
            $submitter = $recommendation->submitter()->with(['role', 'section'])->firstOrFail();
            abort_unless(
                $reviewer->role?->name === 'Enforcement Manager'
                    && $reviewer->section?->code === 'ENF'
                    && (int) $team->manager_id === (int) $reviewer->id
                    && $submitter->section?->code === 'ENF'
                    && $this->teamIsInSupervisorScope($team, $submitter),
                403,
                'Only the responsible Enforcement Manager may review a Supervisor team recommendation.'
            );

            return;
        }

        if (in_array($recommendation->change_type, ['assign_officer', 'remove_officer'], true)) {
            $team = EnforcementTeam::findOrFail($recommendation->team_id);
            $submitter = $recommendation->submitter()->with(['role', 'section'])->firstOrFail();
            abort_unless(
                $reviewer->role?->name === 'Enforcement Manager'
                    && $reviewer->section?->code === 'ENF'
                    && (int) $team->manager_id === (int) $reviewer->id
                    && $submitter->role?->name === 'Enforcement Supervisor'
                    && (int) $team->supervisor_id === (int) $submitter->id,
                403,
                'Only the responsible Enforcement Manager may review a Supervisor membership recommendation.'
            );

            return;
        }

        abort_unless($reviewer->role?->name === 'Assistant Commissioner', 403);
    }

    private function apply(EnforcementOrgRecommendation $recommendation, User $reviewer): array
    {
        $payload = $recommendation->payload;

        return match ($recommendation->change_type) {
            'create_region' => $this->applyCreateRegion($recommendation, $payload),
            'update_region' => $this->applyUpdateRegion($recommendation, $payload),
            'assign_region_supervisor' => $this->applyAssignRegionSupervisor($recommendation, $payload, $reviewer),
            'create_district' => $this->applyCreateDistrict($recommendation, $payload),
            'update_district' => $this->applyUpdateDistrict($recommendation, $payload),
            'assign_districts_to_region' => $this->applyAssignDistrictsToRegion($recommendation, $payload, $reviewer),
            'create_team' => $this->applyCreateTeam($recommendation, $payload, $reviewer),
            'update_team' => $this->applyUpdateTeam($recommendation, $payload, $reviewer),
            'assign_officer' => $this->applyAssignOfficer($recommendation, $payload, $reviewer),
            'remove_officer' => $this->applyRemoveOfficer($recommendation, $payload, $reviewer),
            default => throw ValidationException::withMessages(['change_type' => 'Unsupported organization change type.']),
        };
    }

    private function regionForDistricts(array $districtIds): EnforcementTaxRegion
    {
        $districts = EnforcementDistrict::query()->with(['region', 'region.supervisor.role', 'region.supervisor.section'])
            ->whereIn('id', $districtIds)
            ->get();
        abort_unless($districts->count() === count($districtIds), 422, 'All selected districts must exist.');
        abort_if($districts->contains(fn (EnforcementDistrict $district) => ! $district->is_active), 422, 'Teams can only use active districts.');

        $regionIds = $districts->pluck('region_id')->unique();
        abort_if($regionIds->count() !== 1 || $regionIds->first() === null, 422, 'All team districts must belong to one configured tax region.');

        $region = $districts->first()->region;
        abort_unless($region?->is_active
            && $region->supervisor?->is_active, 422, 'The team tax region must be active.');
        abort_unless($region?->supervisor, 422, 'Assign an active Enforcement Supervisor to the tax region before creating or operating its teams.');
        $this->assertSupervisor($region->supervisor);

        return $region;
    }

    private function applyCreateRegion(EnforcementOrgRecommendation $recommendation, array $payload): array
    {
        $this->authorizeSubmission($recommendation->submitter, 'create_region', null, $payload);
        $region = EnforcementTaxRegion::create([
            'name' => trim($payload['name']),
            'code' => strtoupper(trim($payload['code'])),
            'classification' => $payload['classification'],
            'description' => $payload['description'] ?? null,
            'created_by' => $recommendation->submitted_by,
        ]);

        return ['previous_values' => null, 'applied_record_id' => $region->id];
    }

    private function applyUpdateRegion(EnforcementOrgRecommendation $recommendation, array $payload): array
    {
        $region = EnforcementTaxRegion::query()->whereKey($payload['region_id'])->lockForUpdate()->firstOrFail();
        $this->authorizeSubmission($recommendation->submitter, 'update_region', null, $payload);
        $previous = $region->only(['name', 'code', 'classification', 'description', 'is_active']);
        $updates = array_intersect_key($payload, array_flip(['name', 'code', 'classification', 'description', 'is_active']));
        if (isset($updates['name'])) {
            $updates['name'] = trim($updates['name']);
        }
        if (isset($updates['code'])) {
            $updates['code'] = strtoupper(trim($updates['code']));
        }
        $region->update($updates);

        return ['previous_values' => $previous, 'applied_record_id' => $region->id];
    }

    private function applyAssignRegionSupervisor(
        EnforcementOrgRecommendation $recommendation,
        array $payload,
        User $reviewer
    ): array {
        $region = EnforcementTaxRegion::query()->whereKey($payload['region_id'])->lockForUpdate()->firstOrFail();
        $supervisor = User::query()->with(['role', 'section'])->whereKey($payload['supervisor_id'])->lockForUpdate()->firstOrFail();
        $this->authorizeSubmission($recommendation->submitter, 'assign_region_supervisor', null, $payload);
        $this->assertSupervisor($supervisor);

        $previousSupervisorId = $region->supervisor_id;
        if ((int) $previousSupervisorId === (int) $supervisor->id) {
            return ['previous_values' => ['supervisor_id' => $previousSupervisorId], 'applied_record_id' => $region->id];
        }

        EnforcementTaxRegionSupervisorHistory::query()
            ->where('region_id', $region->id)
            ->whereNull('ended_at')
            ->update(['ended_at' => now(), 'ended_by' => $reviewer->id, 'updated_at' => now()]);
        EnforcementTaxRegionSupervisorHistory::create([
            'region_id' => $region->id,
            'supervisor_id' => $supervisor->id,
            'assigned_by' => $recommendation->submitted_by,
            'reason' => $payload['reason'] ?? null,
            'started_at' => now(),
        ]);
        $region->update(['supervisor_id' => $supervisor->id]);

        $teams = EnforcementTeam::query()
            ->whereHas('districts', fn ($query) => $query->where('region_id', $region->id))
            ->with('districts:id,region_id')
            ->lockForUpdate()
            ->get();
        foreach ($teams as $team) {
            if ($team->districts->isEmpty() || $team->districts->contains(fn (EnforcementDistrict $district) => (int) $district->region_id !== (int) $region->id)) {
                continue;
            }
            if ((int) $team->supervisor_id !== (int) $supervisor->id) {
                $team->update(['supervisor_id' => $supervisor->id]);
                $this->moveTeamReportsToSupervisor($team, $reviewer, $recommendation);
            }
        }

        app(AuditService::class)->record($region, 'enforcement.region.supervisor_changed', $reviewer->id, [
            'supervisor_id' => $previousSupervisorId,
        ], [
            'supervisor_id' => $supervisor->id,
            'recommendation_id' => $recommendation->id,
            'reason' => $payload['reason'] ?? null,
        ]);

        return [
            'previous_values' => ['supervisor_id' => $previousSupervisorId],
            'applied_record_id' => $region->id,
        ];
    }

    private function applyCreateDistrict(EnforcementOrgRecommendation $recommendation, array $payload): array
    {
        $this->authorizeSubmission($recommendation->submitter, 'create_district', null, $payload);
        abort_if(
            EnforcementDistrict::where('name', trim($payload['name']))->exists(),
            422,
            'A district with this name has already been created.'
        );

        $district = EnforcementDistrict::create([
            'name' => trim($payload['name']),
            'created_by' => $recommendation->submitted_by,
        ]);

        return ['previous_values' => null, 'applied_record_id' => $district->id];
    }

    private function applyUpdateDistrict(EnforcementOrgRecommendation $recommendation, array $payload): array
    {
        $district = EnforcementDistrict::query()->whereKey($payload['district_id'])->lockForUpdate()->firstOrFail();
        $this->authorizeSubmission($recommendation->submitter, 'update_district', null, $payload);
        $previous = $district->only(['name', 'is_active']);
        $updates = array_intersect_key($payload, array_flip(['name', 'is_active']));
        if (isset($updates['name'])) {
            $updates['name'] = trim($updates['name']);
        }
        $district->update($updates);

        return ['previous_values' => $previous, 'applied_record_id' => $district->id];
    }

    private function applyAssignDistrictsToRegion(
        EnforcementOrgRecommendation $recommendation,
        array $payload,
        User $reviewer
    ): array {
        $region = EnforcementTaxRegion::query()->whereKey($payload['region_id'])->lockForUpdate()->firstOrFail();
        $this->authorizeSubmission($recommendation->submitter, 'assign_districts_to_region', null, $payload);
        $districts = EnforcementDistrict::query()->whereIn('id', $payload['district_ids'])->lockForUpdate()->get();
        abort_unless($districts->count() === count($payload['district_ids']), 422, 'All selected districts must exist.');

        $previous = [];
        foreach ($districts as $district) {
            $previous[$district->id] = $district->region_id;
            if ((int) $district->region_id === (int) $region->id) {
                continue;
            }
            EnforcementDistrictRegionHistory::query()
                ->where('district_id', $district->id)
                ->whereNull('ended_at')
                ->update(['ended_at' => now(), 'ended_by' => $reviewer->id, 'updated_at' => now()]);
            $district->update(['region_id' => $region->id]);
            EnforcementDistrictRegionHistory::create([
                'district_id' => $district->id,
                'region_id' => $region->id,
                'assigned_by' => $recommendation->submitted_by,
                'reason' => $payload['reason'] ?? null,
                'started_at' => now(),
            ]);
        }

        $teams = EnforcementTeam::query()
            ->whereHas('districts', fn ($query) => $query->whereIn('enforcement_districts.id', $payload['district_ids']))
            ->with('districts:id,region_id')
            ->lockForUpdate()
            ->get();
        foreach ($teams as $team) {
            $teamRegionIds = $team->districts->pluck('region_id')->unique();
            if ($teamRegionIds->count() !== 1 || $teamRegionIds->first() === null) {
                continue;
            }
            $teamRegion = EnforcementTaxRegion::query()->with(['supervisor.role', 'supervisor.section'])->findOrFail($teamRegionIds->first());
            if (! $teamRegion->is_active || ! $teamRegion->supervisor || ! $teamRegion->supervisor->is_active) {
                continue;
            }
            $this->assertSupervisor($teamRegion->supervisor);
            if ((int) $team->supervisor_id !== (int) $teamRegion->supervisor_id) {
                $team->update(['supervisor_id' => $teamRegion->supervisor_id]);
                $this->moveTeamReportsToSupervisor($team, $reviewer, $recommendation);
            }
        }

        return ['previous_values' => ['district_region_ids' => $previous], 'applied_record_id' => $region->id];
    }

    private function applyCreateTeam(EnforcementOrgRecommendation $recommendation, array $payload, User $reviewer): array
    {
        $region = $this->regionForDistricts($payload['district_ids']);
        abort_unless((int) $region->id === (int) ($payload['region_id'] ?? 0)
            && (int) $region->supervisor_id === (int) ($payload['supervisor_id'] ?? 0), 422,
            'The district region or regional supervisor changed after the team request was submitted. Resubmit the request.');
        $this->authorizeSubmission($recommendation->submitter, 'create_team', null, $payload);
        // Revalidate at approval time: membership/team state may have changed
        // while the recommendation was waiting for the Commissioner.
        $this->validateTeamMembers($payload);

        $team = EnforcementTeam::create([
            'name' => trim($payload['name']),
            'manager_id' => $recommendation->submitted_by,
            'supervisor_id' => $region->supervisor_id,
        ]);
        $team->districts()->sync($payload['district_ids']);

        $districtIds = array_map('intval', $payload['district_ids']);
        foreach ($districtIds as $districtId) {
            EnforcementTeamDistrictHistory::create([
                'team_id' => $team->id,
                'district_id' => $districtId,
                'assigned_by' => $recommendation->submitted_by,
                'reason' => $payload['reason'] ?? null,
                'started_at' => now(),
            ]);
        }

        foreach ($payload['members'] ?? [] as $member) {
            $this->assignTeamMember($team, $member, $recommendation, $reviewer);
        }

        app(AuditService::class)->record($team, 'enforcement.team.created', $reviewer->id, [], [
            'name' => $team->name,
            'manager_id' => $team->manager_id,
            'supervisor_id' => $team->supervisor_id,
            'district_ids' => $districtIds,
            'members' => collect($payload['members'] ?? [])->map(fn (array $member) => [
                'officer_id' => (int) $member['officer_id'],
                'primary_district_id' => (int) $member['primary_district_id'],
                'is_team_lead' => (bool) $member['is_team_lead'],
            ])->all(),
            'reason' => $payload['reason'] ?? null,
            'recommendation_id' => $recommendation->id,
        ]);

        return ['previous_values' => null, 'applied_record_id' => $team->id];
    }

    private function applyUpdateTeam(EnforcementOrgRecommendation $recommendation, array $payload, User $reviewer): array
    {
        $team = EnforcementTeam::query()->whereKey($payload['team_id'])->lockForUpdate()->firstOrFail();
        $districtIds = $payload['district_ids'] ?? $team->districts()->pluck('enforcement_districts.id')->all();
        $region = $this->regionForDistricts($districtIds);
        abort_unless((int) $region->id === (int) ($payload['region_id'] ?? 0)
            && (int) $region->supervisor_id === (int) ($payload['supervisor_id'] ?? 0), 422,
            'The district region or regional supervisor changed after the team request was submitted. Resubmit the request.');
        $this->authorizeSubmission($recommendation->submitter, 'update_team', $team, $payload);
        $previous = [
            'name' => $team->name,
            'manager_id' => $team->manager_id,
            'supervisor_id' => $team->supervisor_id,
            'is_active' => $team->is_active,
            'district_ids' => $team->districts()->pluck('enforcement_districts.id')->all(),
        ];
        $oldSupervisor = $team->supervisor_id;
        $previousDistrictIds = array_map('intval', $previous['district_ids']);

        $team->update(array_intersect_key($payload, array_flip(['name', 'supervisor_id', 'is_active'])));
        if (array_key_exists('district_ids', $payload)) {
            $newDistrictIds = array_map('intval', $payload['district_ids']);
            $team->districts()->sync($newDistrictIds);

            $removed = array_values(array_diff($previousDistrictIds, $newDistrictIds));
            $added = array_values(array_diff($newDistrictIds, $previousDistrictIds));

            if ($removed !== []) {
                EnforcementTeamDistrictHistory::query()
                    ->where('team_id', $team->id)
                    ->whereIn('district_id', $removed)
                    ->whereNull('ended_at')
                    ->update([
                        'ended_at' => now(),
                        'ended_by' => $reviewer->id,
                        'updated_at' => now(),
                    ]);
            }

            foreach ($added as $districtId) {
                EnforcementTeamDistrictHistory::create([
                    'team_id' => $team->id,
                    'district_id' => $districtId,
                    'assigned_by' => $recommendation->submitted_by,
                    'reason' => $payload['reason'] ?? null,
                    'started_at' => now(),
                ]);
            }
        }

        if (isset($payload['supervisor_id']) && (int) $payload['supervisor_id'] !== (int) $oldSupervisor) {
            $this->moveTeamReportsToSupervisor($team, $reviewer, $recommendation);
        }

        app(AuditService::class)->record($team, 'enforcement.team.updated', $reviewer->id, $previous, [
            'name' => $team->name,
            'manager_id' => $team->manager_id,
            'supervisor_id' => $team->supervisor_id,
            'is_active' => $team->is_active,
            'district_ids' => $team->districts()->pluck('enforcement_districts.id')->map(fn ($id) => (int) $id)->all(),
            'reason' => $payload['reason'] ?? null,
            'recommendation_id' => $recommendation->id,
        ]);

        return ['previous_values' => $previous, 'applied_record_id' => $team->id];
    }

    private function applyAssignOfficer(EnforcementOrgRecommendation $recommendation, array $payload, User $reviewer): array
    {
        $officer = User::query()->whereKey($payload['officer_id'])->lockForUpdate()->firstOrFail();
        $team = EnforcementTeam::query()->whereKey($payload['team_id'])->lockForUpdate()->firstOrFail();
        $this->authorizeSubmission($recommendation->submitter, 'assign_officer', $team, $payload);

        $current = $this->activeMembershipFor($officer);
        $previous = $current ? $this->membershipSnapshot($current) : null;
        $oldSupervisor = $officer->supervisor_id;
        $priorSupervisorId = $current
            ? $current->prior_supervisor_id
            : $officer->supervisor_id;
        if ($current) {
            $current->update(['ended_at' => now(), 'ended_by' => $reviewer->id]);
        }

        if ($payload['is_team_lead']) {
            $currentLeadMemberships = EnforcementTeamMembership::query()
                ->where('team_id', $team->id)
                ->whereNull('ended_at')
                ->where('is_team_lead', true)
                ->lockForUpdate()
                ->get();
            foreach ($currentLeadMemberships as $currentLead) {
                $currentLead->update(['ended_at' => now(), 'ended_by' => $reviewer->id]);
                EnforcementTeamMembership::create([
                    'team_id' => $currentLead->team_id,
                    'officer_id' => $currentLead->officer_id,
                    'supervisor_id' => $currentLead->supervisor_id,
                    'primary_district_id' => $currentLead->primary_district_id,
                    'prior_supervisor_id' => $currentLead->prior_supervisor_id,
                    'recommendation_id' => $recommendation->id,
                    'is_team_lead' => false,
                    'assigned_by' => $recommendation->submitted_by,
                    'started_at' => now(),
                ]);
            }
        }

        $membership = EnforcementTeamMembership::create([
            'team_id' => $team->id,
            'officer_id' => $officer->id,
            'supervisor_id' => $team->supervisor_id,
            'primary_district_id' => $payload['primary_district_id'],
            'prior_supervisor_id' => $priorSupervisorId,
            'recommendation_id' => $recommendation->id,
            'is_team_lead' => $payload['is_team_lead'],
            'assigned_by' => $reviewer->id,
            'started_at' => now(),
        ]);
        $officer->update(['supervisor_id' => $team->supervisor_id]);

        return [
            'previous_values' => [
                'membership' => $previous,
                'supervisor_id' => $oldSupervisor,
            ],
            'applied_record_id' => $membership->id,
        ];
    }

    private function applyRemoveOfficer(EnforcementOrgRecommendation $recommendation, array $payload, User $reviewer): array
    {
        $team = EnforcementTeam::query()->whereKey($payload['team_id'])->lockForUpdate()->firstOrFail();
        $this->authorizeSubmission($recommendation->submitter, 'remove_officer', $team, $payload);
        $membership = EnforcementTeamMembership::query()
            ->where('team_id', $team->id)
            ->where('officer_id', $payload['officer_id'])
            ->whereNull('ended_at')
            ->lockForUpdate()
            ->firstOrFail();
        $previous = $this->membershipSnapshot($membership);
        $membership->update(['ended_at' => now(), 'ended_by' => $reviewer->id]);

        $officer = User::query()->whereKey($membership->officer_id)->lockForUpdate()->firstOrFail();
        $oldSupervisor = $officer->supervisor_id;
        if ((int) $officer->supervisor_id === (int) $membership->supervisor_id) {
            $officer->update(['supervisor_id' => $membership->prior_supervisor_id]);
        }

        return [
            'previous_values' => ['membership' => $previous, 'supervisor_id' => $oldSupervisor],
            'applied_record_id' => $membership->id,
        ];
    }

    private function moveTeamReportsToSupervisor(
        EnforcementTeam $team,
        User $reviewer,
        EnforcementOrgRecommendation $recommendation
    ): void {
        $memberships = $team->currentMemberships()->lockForUpdate()->get();
        foreach ($memberships as $membership) {
            $officer = User::query()->whereKey($membership->officer_id)->lockForUpdate()->firstOrFail();
            $oldSupervisor = $officer->supervisor_id;
            $membership->update(['ended_at' => now(), 'ended_by' => $reviewer->id]);
            EnforcementTeamMembership::create([
                'team_id' => $team->id,
                'officer_id' => $officer->id,
                'supervisor_id' => $team->supervisor_id,
                'primary_district_id' => $membership->primary_district_id,
                'prior_supervisor_id' => $membership->prior_supervisor_id,
                'recommendation_id' => $recommendation->id,
                'is_team_lead' => $membership->is_team_lead,
                'assigned_by' => $reviewer->id,
                'started_at' => now(),
            ]);
            $officer->update(['supervisor_id' => $team->supervisor_id]);
            app(AuditService::class)->record($officer, 'enforcement.officer_supervisor_changed', $reviewer->id, [
                'supervisor_id' => $oldSupervisor,
                'team_id' => $team->id,
            ], [
                'supervisor_id' => $team->supervisor_id,
                'team_id' => $team->id,
                'recommendation_id' => $recommendation->id,
            ]);
        }
    }

    /**
     * A team must be submitted with exactly one Team Lead. Every initial member
     * must be an eligible, unassigned Enforcement Officer whose primary district
     * is one of the team's districts.
     *
     * @param  array<string, mixed>  $payload
     */
    private function validateTeamMembers(array $payload): void
    {
        $members = $payload['members'] ?? [];
        $districtIds = array_map('intval', $payload['district_ids'] ?? []);
        $teamLeads = array_filter($members, fn (array $member) => (bool) ($member['is_team_lead'] ?? false));
        abort_unless($members !== [] && count($teamLeads) === 1, 422, 'Select exactly one Team Lead for the new team.');

        foreach ($members as $member) {
            $officer = User::query()->with(['role', 'section'])->findOrFail($member['officer_id']);
            $this->assertOfficer($officer);
            abort_unless(
                in_array((int) $member['primary_district_id'], $districtIds, true),
                422,
                'Each team member primary district must be one of the team districts.'
            );
            abort_if(
                EnforcementTeamMembership::query()
                    ->where('officer_id', $officer->id)
                    ->whereNull('ended_at')
                    ->exists(),
                422,
                'Remove the officer from their current team before adding them to a new team.'
            );
        }
    }

    /** @param  array<string, mixed>  $member */
    private function assignTeamMember(
        EnforcementTeam $team,
        array $member,
        EnforcementOrgRecommendation $recommendation,
        User $reviewer
    ): EnforcementTeamMembership {
        $officer = User::query()->whereKey($member['officer_id'])->lockForUpdate()->firstOrFail();
        $priorSupervisorId = $officer->supervisor_id;

        $membership = EnforcementTeamMembership::create([
            'team_id' => $team->id,
            'officer_id' => $officer->id,
            'supervisor_id' => $team->supervisor_id,
            'primary_district_id' => $member['primary_district_id'],
            'prior_supervisor_id' => $priorSupervisorId,
            'recommendation_id' => $recommendation->id,
            'is_team_lead' => (bool) $member['is_team_lead'],
            'assigned_by' => $reviewer->id,
            'started_at' => now(),
        ]);
        $officer->update(['supervisor_id' => $team->supervisor_id]);

        return $membership;
    }

    private function assertSupervisor(User $user): void
    {
        abort_unless(
            $user->is_active && $user->role?->name === 'Enforcement Supervisor' && $user->section?->code === 'ENF',
            422,
            'An active Enforcement Supervisor in the Enforcement section is required.'
        );
    }

    private function assertOfficer(User $user): void
    {
        abort_unless(
            $user->is_active && $user->role?->name === 'Enforcement Officer' && $user->section?->code === 'ENF',
            422,
            'An active Enforcement Officer in the Enforcement section is required.'
        );
    }

    private function membershipSnapshot(EnforcementTeamMembership $membership): array
    {
        return [
            'team_id' => $membership->team_id,
            'officer_id' => $membership->officer_id,
            'supervisor_id' => $membership->supervisor_id,
            'primary_district_id' => $membership->primary_district_id,
            'prior_supervisor_id' => $membership->prior_supervisor_id,
            'is_team_lead' => $membership->is_team_lead,
            'started_at' => $membership->started_at?->toISOString(),
            'ended_at' => $membership->ended_at?->toISOString(),
        ];
    }

    private function presentRecommendation(EnforcementOrgRecommendation $recommendation): array
    {
        return [
            'id' => $recommendation->id,
            'change_type' => $recommendation->change_type,
            'team_id' => $recommendation->team_id,
            'payload' => $recommendation->payload,
            'previous_values' => $recommendation->previous_values,
            'status' => $recommendation->status,
            'submitted_by' => $recommendation->submitter?->full_name,
            'submitted_at' => $recommendation->submitted_at?->toISOString(),
            'decided_by' => $recommendation->decider?->full_name,
            'decision_notes' => $recommendation->decision_notes,
            'decided_at' => $recommendation->decided_at?->toISOString(),
        ];
    }

    private function canReviewRecommendation(User $reviewer, EnforcementOrgRecommendation $recommendation): bool
    {
        if (! $reviewer->is_active || $recommendation->status !== 'pending') {
            return false;
        }

        $role = $reviewer->role?->name;
        if ($role === 'Assistant Commissioner') {
            return $reviewer->canPermission('enforcement.org.review')
                && ! in_array($recommendation->change_type, ['assign_officer', 'remove_officer'], true);
        }

        if ($role !== 'Enforcement Manager' || $reviewer->section?->code !== 'ENF'
            || ! $reviewer->canPermission('enforcement.org.manage')) {
            return false;
        }

        if (! in_array($recommendation->change_type, ['assign_officer', 'remove_officer', 'update_team'], true)) {
            return false;
        }

        $team = EnforcementTeam::find($recommendation->team_id);
        $submitter = $recommendation->submitter()->with(['role', 'section'])->first();
        if (! $team || ! $submitter || (int) $team->manager_id !== (int) $reviewer->id
            || $submitter->role?->name !== 'Enforcement Supervisor'
            || $submitter->section?->code !== 'ENF') {
            return false;
        }

        if (in_array($recommendation->change_type, ['assign_officer', 'remove_officer'], true)) {
            return (int) $team->supervisor_id === (int) $submitter->id;
        }

        return $this->teamIsInSupervisorScope($team, $submitter);
    }
}
