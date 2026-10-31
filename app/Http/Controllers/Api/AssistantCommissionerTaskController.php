<?php

namespace App\Http\Controllers\Api;

use App\Services\SectionWorkspaceService;

/**
 * Assistant Commissioner — own section workspace (My Tasks / Decision Queue).
 *
 * The AC processes their approvals directly here: nothing routes through the
 * Enforcement workspace, and no enforcement permission is required to reach it
 * (spec §6 / §24). Task types owned by this workspace are the AC desk types.
 */
class AssistantCommissionerTaskController extends SectionTaskController
{
    protected function workspace(): string
    {
        return SectionWorkspaceService::AC;
    }
}
