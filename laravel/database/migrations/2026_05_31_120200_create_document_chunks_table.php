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
        Schema::create('document_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_document_id')->constrained('agent_documents')->cascadeOnDelete();
            $table->integer('chunk_index');
            $table->text('content');
            $table->integer('tokens')->nullable();
            $table->string('embedding_model');
            $table->unsignedInteger('embedding_dim');
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['agent_document_id', 'chunk_index']);
            $table->index(['workspace_id', 'embedding_model']);
        });

        // pgvector stores the embedding in a native `vector` column (unsized: each
        // workspace pins one model/dim, so rows in a workspace share a dimension).
        // On non-pgsql drivers (sqlite test suite) fall back to a text column so the
        // Embedding cast can round-trip the `[1,2,3]` representation.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE document_chunks ADD COLUMN embedding vector');
        } else {
            Schema::table('document_chunks', function (Blueprint $table) {
                $table->text('embedding')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_chunks');
    }
};
