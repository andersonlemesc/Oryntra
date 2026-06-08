<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE agent_runs ALTER COLUMN conversation_id DROP NOT NULL');
        } else {
            Schema::table('agent_runs', function ($table) {
                $table->unsignedBigInteger('conversation_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE agent_runs ALTER COLUMN conversation_id SET NOT NULL');
        } else {
            Schema::table('agent_runs', function ($table) {
                $table->unsignedBigInteger('conversation_id')->nullable(false)->change();
            });
        }
    }
};
