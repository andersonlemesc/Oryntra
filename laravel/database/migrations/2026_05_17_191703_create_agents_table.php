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
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->text('name');
            $table->text('description')->nullable();
            $table->text('status')->default('inactive');
            $table->text('locale')->default('en');
            $table->text('timezone')->default('UTC');
            $table->text('response_mode')->default('automatic');
            $table->text('llm_provider')->nullable();
            $table->text('llm_model')->nullable();
            $table->decimal('llm_temperature', 4, 2)->nullable();
            $table->unsignedInteger('llm_max_tokens')->nullable();
            $table->text('system_prompt')->nullable();
            $table->text('behavior_prompt')->nullable();
            $table->text('fallback_message')->nullable();
            $table->jsonb('debounce_config')->default('{}');
            $table->jsonb('media_policy')->default('{}');
            $table->jsonb('guard_config')->default('{}');
            $table->jsonb('rag_config')->default('{}');
            $table->jsonb('runtime_config')->default('{}');
            $table->timestamps();

            $table->unique(['workspace_id', 'name']);
            $table->index(['workspace_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE agents ADD CONSTRAINT agents_status_check CHECK (status IN ('active', 'inactive'))");
            DB::statement("ALTER TABLE agents ADD CONSTRAINT agents_response_mode_check CHECK (response_mode IN ('automatic', 'suggestion_only', 'human_approval'))");
            DB::statement("ALTER TABLE agents ADD CONSTRAINT agents_llm_provider_check CHECK (llm_provider IS NULL OR llm_provider IN ('openai', 'anthropic', 'gemini', 'local'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};
