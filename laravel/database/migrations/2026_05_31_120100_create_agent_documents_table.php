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
        Schema::create('agent_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('storage_disk')->default('s3');
            $table->string('storage_path');
            $table->string('checksum')->nullable();
            $table->jsonb('tags')->nullable();
            // PDF-only: which BYOK key/model extracts text when the lib path is
            // insufficient (scanned/complex). Null for markdown/text uploads.
            $table->foreignId('extractor_llm_key_id')->nullable()->constrained('agent_llm_keys')->nullOnDelete();
            $table->string('extractor_model')->nullable();
            $table->string('index_status')->default('pending');
            $table->text('index_error')->nullable();
            $table->timestamp('indexed_at')->nullable();
            $table->unsignedInteger('chunks_count')->default(0);
            $table->string('embedding_provider')->nullable();
            $table->string('embedding_model')->nullable();
            $table->unsignedInteger('embedding_dim')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'index_status']);
            $table->index(['workspace_id', 'name']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE agent_documents ADD CONSTRAINT agent_documents_index_status_check '
                . "CHECK (index_status IN ('pending','indexing','indexed','failed'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_documents');
    }
};
