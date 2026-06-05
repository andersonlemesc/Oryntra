<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_llm_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_llm_key_id')->constrained()->cascadeOnDelete();
            $table->text('model_id');
            $table->text('label')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['agent_llm_key_id', 'model_id']);
            $table->index('agent_llm_key_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_llm_models');
    }
};
