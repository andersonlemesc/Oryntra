<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_specialists', function (Blueprint $table): void {
            $table->jsonb('resolution_config')->default('{}')->after('memory_config');
        });
    }

    public function down(): void
    {
        Schema::table('agent_specialists', function (Blueprint $table): void {
            $table->dropColumn('resolution_config');
        });
    }
};
