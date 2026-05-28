<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_tool_call_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('external_tool_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_run_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('specialist_id')->nullable();
            $table->string('tool_slug');
            $table->jsonb('request_args')->default('{}');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->boolean('success')->default(false);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->text('response_excerpt')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['workspace_id', 'created_at']);
            $table->index(['agent_run_id']);
            $table->index(['external_tool_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_tool_call_logs');
    }
};
