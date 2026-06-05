<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chatwoot_connection_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('chatwoot_contact_id');
            $table->text('identifier')->nullable();
            $table->text('name')->nullable();
            $table->text('email')->nullable();
            $table->text('phone_number')->nullable();
            $table->text('thumbnail')->nullable();
            $table->jsonb('additional_attributes')->default('{}');
            $table->jsonb('chatwoot_custom_attributes')->default('{}');
            $table->string('lead_status', 32)->default('new');
            $table->integer('lead_score')->nullable();
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->timestampTz('last_message_at')->nullable();
            $table->timestampTz('synced_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['workspace_id', 'chatwoot_connection_id', 'chatwoot_contact_id'], 'contacts_workspace_connection_chatwoot_unique');
            $table->index(['workspace_id', 'lead_status']);
            $table->index(['workspace_id', 'last_message_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
