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
            $table->jsonb('media_config')->default('{}');
            $table->foreignId('media_llm_key_id')->nullable()->constrained('agent_llm_keys')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropForeign(['media_llm_key_id']);
            $table->dropColumn(['media_config', 'media_llm_key_id']);
        });
    }
};
