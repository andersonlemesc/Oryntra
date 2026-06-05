<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->text('source')->default('chatwoot')->after('agent_id');
            $table->index(['workspace_id', 'source']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE agent_runs ADD CONSTRAINT agent_runs_source_check CHECK (source IN ('chatwoot', 'playground'))");
            DB::statement('ALTER TABLE agent_runs ALTER COLUMN chatwoot_connection_id DROP NOT NULL');
        } else {
            // SQLite rebuilds the table on a column change, which drops the partial
            // WHERE clause of the in-flight uniqueness index. Recreate it afterwards.
            Schema::table('agent_runs', function (Blueprint $table) {
                $table->unsignedBigInteger('chatwoot_connection_id')->nullable()->change();
            });

            DB::statement('DROP INDEX IF EXISTS agent_runs_one_inflight_per_conversation');
            DB::statement("CREATE UNIQUE INDEX agent_runs_one_inflight_per_conversation ON agent_runs (chatwoot_connection_id, conversation_id) WHERE status IN ('debouncing', 'queued', 'running')");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE agent_runs DROP CONSTRAINT IF EXISTS agent_runs_source_check');
        }

        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'source']);
            $table->dropColumn('source');
        });
    }
};
