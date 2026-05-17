<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->foreignId('llm_key_id')
                ->nullable()
                ->after('llm_provider')
                ->constrained('agent_llm_keys')
                ->nullOnDelete();

            $table->index(['workspace_id', 'llm_key_id']);
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'llm_key_id']);
            $table->dropConstrainedForeignId('llm_key_id');
        });
    }
};
