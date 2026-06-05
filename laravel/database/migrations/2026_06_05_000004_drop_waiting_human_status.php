<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The waiting_human run/message status backed the human-approval (HITL) flow,
 * which was never emitted by the runtime and has been removed. Drop the value
 * from the status CHECK constraints. No rows ever used it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE agent_runs DROP CONSTRAINT IF EXISTS agent_runs_status_check');
        DB::statement("ALTER TABLE agent_runs ADD CONSTRAINT agent_runs_status_check CHECK (status IN ('debouncing', 'queued', 'running', 'completed', 'failed', 'ignored'))");

        DB::statement('ALTER TABLE playground_messages DROP CONSTRAINT IF EXISTS playground_messages_status_check');
        DB::statement("ALTER TABLE playground_messages ADD CONSTRAINT playground_messages_status_check CHECK (status IS NULL OR status IN ('pending', 'streaming', 'completed', 'failed'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE agent_runs DROP CONSTRAINT IF EXISTS agent_runs_status_check');
        DB::statement("ALTER TABLE agent_runs ADD CONSTRAINT agent_runs_status_check CHECK (status IN ('debouncing', 'queued', 'running', 'completed', 'failed', 'ignored', 'waiting_human'))");

        DB::statement('ALTER TABLE playground_messages DROP CONSTRAINT IF EXISTS playground_messages_status_check');
        DB::statement("ALTER TABLE playground_messages ADD CONSTRAINT playground_messages_status_check CHECK (status IS NULL OR status IN ('pending', 'streaming', 'completed', 'waiting_human', 'failed'))");
    }
};
