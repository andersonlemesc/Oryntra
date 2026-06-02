<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allow multiple Chatwoot connections (agent bots) per account. Each bot is
     * still unique per workspace by name and by agent_bot_id; only the
     * one-connection-per-account restriction is dropped.
     */
    public function up(): void
    {
        Schema::table('chatwoot_connections', function (Blueprint $table): void {
            $table->dropUnique(['workspace_id', 'base_url', 'account_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chatwoot_connections', function (Blueprint $table): void {
            $table->unique(['workspace_id', 'base_url', 'account_id']);
        });
    }
};
