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
        Schema::create('agent_specialists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->text('name');
            $table->text('description')->nullable();
            $table->text('role_prompt');
            $table->jsonb('intent_keywords')->default('[]');
            $table->foreignId('llm_key_id')->nullable()->constrained('agent_llm_keys')->nullOnDelete();
            $table->text('llm_model')->nullable();
            $table->decimal('llm_temperature', 4, 2)->default(0.20);
            $table->jsonb('tools_allowlist')->default('[]');
            $table->unsignedInteger('priority')->default(100);
            $table->decimal('confidence_threshold', 4, 2)->default(0.60);
            $table->foreignId('fallback_specialist_id')->nullable()->constrained('agent_specialists')->nullOnDelete();
            $table->text('status')->default('active');
            $table->timestamps();

            $table->unique(['agent_id', 'name']);
            $table->index(['workspace_id', 'status']);
            $table->index(['agent_id', 'status', 'priority']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE agent_specialists ADD CONSTRAINT agent_specialists_status_check CHECK (status IN ('active', 'inactive'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_specialists');
    }
};
