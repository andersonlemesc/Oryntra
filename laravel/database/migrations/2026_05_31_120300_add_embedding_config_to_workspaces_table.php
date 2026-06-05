<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            // Workspace-wide embedding model. Changing it requires re-indexing every
            // knowledge document (vectors of different models are not comparable).
            $table->foreignId('embedding_llm_key_id')->nullable()->after('locale')
                ->constrained('agent_llm_keys')->nullOnDelete();
            $table->string('embedding_model')->nullable()->after('embedding_llm_key_id');
            $table->unsignedInteger('embedding_dim')->nullable()->after('embedding_model');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropConstrainedForeignId('embedding_llm_key_id');
            $table->dropColumn(['embedding_model', 'embedding_dim']);
        });
    }
};
