<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Keep existing task and notification rows; the later one-open-task
        // migration closes duplicate tasks without deleting their history.
    }

    public function down(): void {}
};
