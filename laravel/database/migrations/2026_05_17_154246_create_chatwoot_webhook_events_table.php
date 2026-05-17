<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('chatwoot_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chatwoot_connection_id')->constrained()->cascadeOnDelete();
            $table->text('event_name');
            $table->unsignedBigInteger('chatwoot_account_id');
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->text('chatwoot_message_id')->nullable();
            $table->jsonb('payload');
            $table->text('signature')->nullable();
            $table->text('status')->default('queued');
            $table->timestamp('received_at');
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->unique(['chatwoot_connection_id', 'chatwoot_message_id']);
            $table->index(['workspace_id', 'received_at']);
            $table->index(['chatwoot_connection_id', 'conversation_id']);
            $table->index(['status', 'received_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE chatwoot_webhook_events ADD CONSTRAINT chatwoot_webhook_events_status_check CHECK (status IN ('queued', 'processing', 'processed', 'failed'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chatwoot_webhook_events');
    }
};
