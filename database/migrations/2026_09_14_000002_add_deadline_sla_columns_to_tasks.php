<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Overs Due-Date & SLA Consistency Standard (§13, §14, §18, §20).
     *
     * Every task now carries an explicit deadline model:
     *   - deadline_type = DATE | DATETIME (defaults to DATE for legacy rows)
     *   - due_date      canonical date-only deadline (DATE tasks)
     *   - due_at        canonical exact-time deadline (DATETIME tasks)
     * Each validates one of:
     *   - DATE:     due_date NOT NULL AND due_at IS NULL
     *   - DATETIME: due_at NOT NULL (due_date is the display date)
     *
     * The SLA columns are a point-in-time snapshot of the configuration that
     * produced the deadline (§13-§14). Changing the SLA configuration later
     * must NOT move an existing task's persisted deadline (§14, §47 T14).
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('deadline_type')->default('DATE')->after('due_date');
            $table->timestamp('due_at')->nullable()->after('deadline_type');
            $table->index('due_at');

            $table->string('sla_code')->nullable()->after('due_at');
            $table->string('sla_version')->nullable()->after('sla_code');
            $table->string('sla_unit')->nullable()->after('sla_version');       // day | hour
            $table->unsignedInteger('sla_value')->nullable()->after('sla_unit');
            $table->string('sla_calendar_type')->nullable()->after('sla_value'); // CALENDAR | BUSINESS
            $table->timestamp('sla_started_at')->nullable()->after('sla_calendar_type');
            $table->timestamp('sla_due_at')->nullable()->after('sla_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['due_at']);
            $table->dropColumn([
                'deadline_type',
                'due_at',
                'sla_code',
                'sla_version',
                'sla_unit',
                'sla_value',
                'sla_calendar_type',
                'sla_started_at',
                'sla_due_at',
            ]);
        });
    }
};
