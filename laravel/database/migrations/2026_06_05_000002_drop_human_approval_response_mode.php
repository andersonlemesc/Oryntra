<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('agents')
            ->where('response_mode', 'human_approval')
            ->update(['response_mode' => 'automatic']);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE agents DROP CONSTRAINT IF EXISTS agents_response_mode_check');
            DB::statement("ALTER TABLE agents ADD CONSTRAINT agents_response_mode_check CHECK (response_mode IN ('automatic', 'suggestion_only'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE agents DROP CONSTRAINT IF EXISTS agents_response_mode_check');
            DB::statement("ALTER TABLE agents ADD CONSTRAINT agents_response_mode_check CHECK (response_mode IN ('automatic', 'suggestion_only', 'human_approval'))");
        }
    }
};
