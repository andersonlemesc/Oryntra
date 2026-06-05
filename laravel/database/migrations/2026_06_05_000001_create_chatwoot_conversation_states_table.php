<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatwoot_conversation_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chatwoot_connection_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('conversation_id');
            $table->timestamp('human_takeover_at')->nullable();
            $table->timestamps();

            $table->unique(['chatwoot_connection_id', 'conversation_id']);
            $table->index(['workspace_id', 'human_takeover_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatwoot_conversation_states');
    }
};
