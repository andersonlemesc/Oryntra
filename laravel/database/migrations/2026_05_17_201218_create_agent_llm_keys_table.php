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
        Schema::create('agent_llm_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->text('name');
            $table->text('provider');
            $table->text('api_key');
            $table->text('status')->default('active');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'name']);
            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'provider', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE agent_llm_keys ADD CONSTRAINT agent_llm_keys_status_check CHECK (status IN ('active', 'inactive'))");
            DB::statement("ALTER TABLE agent_llm_keys ADD CONSTRAINT agent_llm_keys_provider_check CHECK (provider IN ('openai', 'anthropic', 'gemini', 'local'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_llm_keys');
    }
};
