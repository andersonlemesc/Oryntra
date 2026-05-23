<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatwoot_teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chatwoot_connection_id')->constrained('chatwoot_connections')->cascadeOnDelete();
            $table->unsignedBigInteger('chatwoot_team_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('allow_auto_assign')->default(false);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['chatwoot_connection_id', 'chatwoot_team_id']);
            $table->index('workspace_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatwoot_teams');
    }
};
