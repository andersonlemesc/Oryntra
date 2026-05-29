<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_calendar_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('google_calendar_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_run_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('specialist_id')->nullable();
            $table->string('action');
            $table->string('calendar_id')->nullable();
            $table->string('google_event_id')->nullable();
            $table->jsonb('request_args')->default('{}');
            $table->boolean('success')->default(false);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('latency_ms')->default(0);
            $table->text('response_excerpt')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['workspace_id', 'created_at']);
            $table->index(['google_calendar_connection_id']);
            $table->index(['agent_run_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_calendar_audit_logs');
    }
};
