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
        Schema::table('agent_specialists', function (Blueprint $table) {
            $table->jsonb('google_calendar_config')->default('{}');
        });

        DB::statement("UPDATE agent_specialists SET google_calendar_config = '{}' WHERE google_calendar_config IS NULL");
    }

    public function down(): void
    {
        Schema::table('agent_specialists', function (Blueprint $table) {
            $table->dropColumn('google_calendar_config');
        });
    }
};
