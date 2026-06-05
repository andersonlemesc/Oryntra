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
        Schema::table('agents', function (Blueprint $table): void {
            $table->unsignedBigInteger('fallback_specialist_id')->nullable()->after('mode');
            $table->index('fallback_specialist_id', 'agents_fallback_specialist_id_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE agents ADD CONSTRAINT agents_fallback_specialist_id_foreign ' .
                'FOREIGN KEY (fallback_specialist_id) REFERENCES agent_specialists(id) ON DELETE SET NULL'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE agents DROP CONSTRAINT IF EXISTS agents_fallback_specialist_id_foreign');
        }

        Schema::table('agents', function (Blueprint $table): void {
            $table->dropIndex('agents_fallback_specialist_id_idx');
            $table->dropColumn('fallback_specialist_id');
        });
    }
};
