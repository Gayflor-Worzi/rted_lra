<?php

use App\Models\PropertyDiscovery;
use App\Models\Task;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Account & Record is split by rank — the Manager reviews, the Officer only
 * processes. This migration renames the persisted vocabulary that used to imply
 * the Account & Record desk reviewed an already-approved record:
 *
 *   property_discoveries.status / referral_from_stage
 *     'Referred to Account & Record' → 'Assigned to Account & Record'
 *
 *   tasks.task_type
 *     'Account & Record Review'     → 'Account & Record Manager Review'  (Manager review)
 *     'Property Registration'       → 'Account & Record Processing'       (Officer task)
 *
 * No rows are created, deleted or otherwise altered beyond the label rename.
 */
return new class extends Migration
{
    private const DISCOVERY_STATUS_FROM = 'Referred to Account & Record';

    private const ACCOUNT_REVIEW_FROM = 'Account & Record Review';

    private const REGISTRATION_FROM = 'Property Registration';

    public function up(): void
    {
        DB::table('property_discoveries')
            ->where('status', self::DISCOVERY_STATUS_FROM)
            ->update(['status' => PropertyDiscovery::STATUS_ASSIGNED_ACCT]);

        DB::table('property_discoveries')
            ->where('referral_from_stage', self::DISCOVERY_STATUS_FROM)
            ->update(['referral_from_stage' => PropertyDiscovery::STATUS_ASSIGNED_ACCT]);

        DB::table('tasks')
            ->where('task_type', self::ACCOUNT_REVIEW_FROM)
            ->update(['task_type' => Task::TYPE_ACCOUNT_MANAGER_REVIEW]);

        DB::table('tasks')
            ->where('task_type', self::REGISTRATION_FROM)
            ->update(['task_type' => Task::TYPE_ACCOUNT_PROCESSING]);
    }

    public function down(): void
    {
        DB::table('property_discoveries')
            ->where('status', PropertyDiscovery::STATUS_ASSIGNED_ACCT)
            ->update(['status' => self::DISCOVERY_STATUS_FROM]);

        DB::table('property_discoveries')
            ->where('referral_from_stage', PropertyDiscovery::STATUS_ASSIGNED_ACCT)
            ->update(['referral_from_stage' => self::DISCOVERY_STATUS_FROM]);

        DB::table('tasks')
            ->where('task_type', Task::TYPE_ACCOUNT_MANAGER_REVIEW)
            ->update(['task_type' => self::ACCOUNT_REVIEW_FROM]);

        DB::table('tasks')
            ->where('task_type', Task::TYPE_ACCOUNT_PROCESSING)
            ->update(['task_type' => self::REGISTRATION_FROM]);
    }
};
