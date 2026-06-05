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
        Schema::table('agents', function (Blueprint $table) {
            $table->text('mode')->default('single')->after('status');
            $table->text('supervisor_prompt')->nullable()->after('fallback_message');
            $table->foreignId('supervisor_llm_key_id')
                ->nullable()
                ->after('supervisor_prompt')
                ->constrained('agent_llm_keys')
                ->nullOnDelete();
            $table->text('supervisor_llm_model')->nullable()->after('supervisor_llm_key_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE agents ADD CONSTRAINT agents_mode_check CHECK (mode IN ('single', 'supervisor'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE agents DROP CONSTRAINT IF EXISTS agents_mode_check');
        }

        Schema::table('agents', function (Blueprint $table) {
            $table->dropForeign(['supervisor_llm_key_id']);
            $table->dropColumn([
                'mode',
                'supervisor_prompt',
                'supervisor_llm_key_id',
                'supervisor_llm_model',
            ]);
        });
    }
};
