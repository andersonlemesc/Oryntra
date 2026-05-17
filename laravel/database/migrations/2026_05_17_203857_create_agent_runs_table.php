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
        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chatwoot_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chatwoot_webhook_event_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('chatwoot_account_id');
            $table->unsignedBigInteger('conversation_id');
            $table->text('chatwoot_message_id')->nullable();
            $table->text('thread_id');
            $table->text('status')->default('debouncing');
            $table->jsonb('input')->default('{}');
            $table->jsonb('output')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('debounce_started_at')->nullable();
            $table->timestamp('debounce_until')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
            $table->index(['agent_id', 'status']);
            $table->index(['chatwoot_connection_id', 'conversation_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE agent_runs ADD CONSTRAINT agent_runs_status_check CHECK (status IN ('debouncing', 'queued', 'running', 'completed', 'failed', 'ignored', 'waiting_human'))");
        }

        DB::statement("CREATE UNIQUE INDEX agent_runs_one_inflight_per_conversation ON agent_runs (chatwoot_connection_id, conversation_id) WHERE status IN ('debouncing', 'queued', 'running')");
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_runs');
    }
};
