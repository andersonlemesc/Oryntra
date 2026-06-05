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
        Schema::create('agent_chatwoot_bindings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chatwoot_connection_id')->constrained()->cascadeOnDelete();
            $table->text('status')->default('active');
            $table->jsonb('inbox_ids')->nullable();
            $table->boolean('ignore_assigned_conversations')->default(false);
            $table->jsonb('ignore_label_names')->default('[]');
            $table->text('handoff_label_name')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
            $table->index(['chatwoot_connection_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE agent_chatwoot_bindings ADD CONSTRAINT agent_chatwoot_bindings_status_check CHECK (status IN ('active', 'inactive'))");
        }

        DB::statement('CREATE UNIQUE INDEX agent_chatwoot_bindings_active_per_connection_unique ON agent_chatwoot_bindings (chatwoot_connection_id) WHERE status = \'active\'');
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_chatwoot_bindings');
    }
};
