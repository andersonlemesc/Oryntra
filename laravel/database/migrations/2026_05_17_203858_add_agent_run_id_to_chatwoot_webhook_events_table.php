<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatwoot_webhook_events', function (Blueprint $table) {
            $table->foreignId('agent_run_id')
                ->nullable()
                ->after('resolved_agent_id')
                ->constrained('agent_runs')
                ->nullOnDelete();

            $table->index(['agent_run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('chatwoot_webhook_events', function (Blueprint $table) {
            $table->dropIndex(['agent_run_id', 'status']);
            $table->dropConstrainedForeignId('agent_run_id');
        });
    }
};
