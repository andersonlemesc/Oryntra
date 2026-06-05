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
        Schema::table('agent_chatwoot_bindings', function (Blueprint $table) {
            $table->unsignedBigInteger('handoff_team_id')->nullable();
            $table->text('handoff_team_name')->nullable();
            $table->unsignedBigInteger('handoff_agent_id')->nullable();
            $table->text('handoff_agent_name')->nullable();
            $table->text('handoff_private_note_template')->nullable();
            $table->text('handoff_assign_strategy')->default('none');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE agent_chatwoot_bindings ADD CONSTRAINT agent_chatwoot_bindings_handoff_assign_strategy_check CHECK (handoff_assign_strategy IN ('none', 'team', 'agent', 'team_then_agent'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE agent_chatwoot_bindings DROP CONSTRAINT IF EXISTS agent_chatwoot_bindings_handoff_assign_strategy_check');
        }

        Schema::table('agent_chatwoot_bindings', function (Blueprint $table) {
            $table->dropColumn([
                'handoff_team_id',
                'handoff_team_name',
                'handoff_agent_id',
                'handoff_agent_name',
                'handoff_private_note_template',
                'handoff_assign_strategy',
            ]);
        });
    }
};
