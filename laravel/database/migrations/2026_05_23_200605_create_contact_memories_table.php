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
        Schema::create('contact_memories', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->text('content');
            $table->string('source', 32);
            $table->float('confidence')->nullable();
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->foreignId('agent_run_id')->nullable()->constrained('agent_runs')->nullOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();

            $table->index(['contact_id', 'created_at']);
            $table->index(['workspace_id', 'type']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE contact_memories ADD CONSTRAINT contact_memories_type_check CHECK (type IN ('preference', 'fact', 'constraint', 'history', 'custom'))");
            DB::statement("ALTER TABLE contact_memories ADD CONSTRAINT contact_memories_source_check CHECK (source IN ('agent_extracted', 'manual', 'chatwoot_attribute', 'tool'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_memories');
    }
};
