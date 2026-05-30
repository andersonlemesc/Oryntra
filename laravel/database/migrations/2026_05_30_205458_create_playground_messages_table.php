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
        Schema::create('playground_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playground_conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_run_id')->nullable()->constrained()->nullOnDelete();
            $table->text('role');
            $table->text('content')->nullable();
            $table->text('status')->nullable();
            $table->unsignedBigInteger('specialist_id')->nullable();
            $table->jsonb('trace')->nullable();
            $table->jsonb('usage')->nullable();
            $table->jsonb('response')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['playground_conversation_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE playground_messages ADD CONSTRAINT playground_messages_role_check CHECK (role IN ('user', 'assistant'))");
            DB::statement("ALTER TABLE playground_messages ADD CONSTRAINT playground_messages_status_check CHECK (status IS NULL OR status IN ('pending', 'streaming', 'completed', 'waiting_human', 'failed'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('playground_messages');
    }
};
