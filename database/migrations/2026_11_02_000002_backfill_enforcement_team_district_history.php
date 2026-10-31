<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const REASON = 'Backfilled from the existing team district assignment at rollout.';

    public function up(): void
    {
        if (! Schema::hasTable('enforcement_team_district')
            || ! Schema::hasTable('enforcement_team_district_history')
            || ! Schema::hasTable('enforcement_teams')) {
            return;
        }

        // Teams created before the history table existed still have an
        // authoritative district link in the pivot. Record that link once so
        // district attribution is complete; never overwrite an existing row.
        DB::table('enforcement_team_district')
            ->orderBy('team_id')
            ->orderBy('district_id')
            ->chunk(500, function ($links): void {
                $teamIds = collect($links)->pluck('team_id')->unique()->values();
                $teams = DB::table('enforcement_teams')->whereIn('id', $teamIds)->get()->keyBy('id');
                $existing = DB::table('enforcement_team_district_history')
                    ->whereIn('team_id', $teamIds)
                    ->whereNull('ended_at')
                    ->get(['team_id', 'district_id'])
                    ->map(fn ($row) => $row->team_id.'|'.$row->district_id)
                    ->all();

                $now = now();
                $rows = [];
                foreach ($links as $link) {
                    if (in_array($link->team_id.'|'.$link->district_id, $existing, true)) {
                        continue;
                    }

                    $team = $teams->get($link->team_id);
                    if (! $team) {
                        continue;
                    }

                    $rows[] = [
                        'team_id' => $link->team_id,
                        'district_id' => $link->district_id,
                        'assigned_by' => $team->manager_id,
                        'ended_by' => null,
                        'reason' => self::REASON,
                        'started_at' => $team->created_at ?? $now,
                        'ended_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('enforcement_team_district_history')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('enforcement_team_district_history')) {
            return;
        }

        DB::table('enforcement_team_district_history')->where('reason', self::REASON)->delete();
    }
};
