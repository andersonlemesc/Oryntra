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
            $table->foreignId('resolved_agent_id')
                ->nullable()
                ->after('chatwoot_message_id')
                ->constrained('agents')
                ->nullOnDelete();

            $table->index(['resolved_agent_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('chatwoot_webhook_events', function (Blueprint $table) {
            $table->dropIndex(['resolved_agent_id', 'status']);
            $table->dropConstrainedForeignId('resolved_agent_id');
        });
    }
};
