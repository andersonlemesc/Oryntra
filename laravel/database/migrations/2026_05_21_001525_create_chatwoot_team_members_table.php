<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatwoot_team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chatwoot_connection_id')->constrained('chatwoot_connections')->cascadeOnDelete();
            $table->unsignedBigInteger('chatwoot_team_id');
            $table->unsignedBigInteger('chatwoot_user_id');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['chatwoot_connection_id', 'chatwoot_team_id', 'chatwoot_user_id'],
                'chatwoot_team_members_conn_team_user_uniq',
            );
            $table->index(['workspace_id', 'chatwoot_team_id']);
            $table->index(['workspace_id', 'chatwoot_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatwoot_team_members');
    }
};
