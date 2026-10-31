<?php

namespace App\Http\Controllers\Api;

use App\Services\SectionWorkspaceService;

/**
 * Valuation section — own workspace (My Tasks / Manager Review Queue).
 *
 * Valuation Officers, Supervisors and Managers process their reassessments and
 * desk reviews in the Valuation workspace — never inside Enforcement (spec §21
 * / §24). Discovery Review tasks that need a valuation arrive here as Valuation
 * Manager Review tasks via the shared engine.
 */
class ValuationManagerTaskController extends SectionTaskController
{
    protected function workspace(): string
    {
        return SectionWorkspaceService::VALUATION;
    }
}
