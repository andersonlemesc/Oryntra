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
            if (Schema::hasColumn('agents', 'media_llm_key_id')) {
                $table->dropForeign(['media_llm_key_id']);
                $table->dropColumn('media_llm_key_id');
            }

            if (Schema::hasColumn('agents', 'media_config')) {
                $table->dropColumn('media_config');
            }

            $table->foreignId('audio_llm_key_id')
                ->nullable()
                ->constrained('agent_llm_keys')
                ->nullOnDelete();
            $table->text('audio_llm_model')->nullable();

            $table->foreignId('vision_llm_key_id')
                ->nullable()
                ->constrained('agent_llm_keys')
                ->nullOnDelete();
            $table->text('vision_llm_model')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropForeign(['audio_llm_key_id']);
            $table->dropForeign(['vision_llm_key_id']);
            $table->dropColumn([
                'audio_llm_key_id',
                'audio_llm_model',
                'vision_llm_key_id',
                'vision_llm_model',
            ]);

            $table->jsonb('media_config')->default('{}');
            $table->foreignId('media_llm_key_id')
                ->nullable()
                ->constrained('agent_llm_keys')
                ->nullOnDelete();
        });
    }
};
