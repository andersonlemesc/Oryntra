<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops dead agent-level columns: single-mode LLM config and free-floating
 * prompts were never sent to the runtime (execution config lives on the
 * specialist). See "Modo Único = 1 Especialista" refactor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'llm_key_id']);
            $table->dropConstrainedForeignId('llm_key_id');
        });

        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn([
                'llm_provider',
                'llm_model',
                'llm_temperature',
                'llm_max_tokens',
                'system_prompt',
                'behavior_prompt',
                'fallback_message',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->string('llm_provider')->nullable()->after('response_mode');
            $table->foreignId('llm_key_id')
                ->nullable()
                ->after('llm_provider')
                ->constrained('agent_llm_keys')
                ->nullOnDelete();
            $table->index(['workspace_id', 'llm_key_id']);
            $table->string('llm_model')->nullable()->after('llm_key_id');
            $table->float('llm_temperature')->nullable()->after('llm_model');
            $table->integer('llm_max_tokens')->nullable()->after('llm_temperature');
            $table->text('system_prompt')->nullable();
            $table->text('behavior_prompt')->nullable();
            $table->text('fallback_message')->nullable();
        });
    }
};
