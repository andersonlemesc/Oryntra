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
            $table->jsonb('memory_config')->nullable()->after('contact_tools_config');
        });
    }

    public function down(): void
    {
        Schema::table('agent_specialists', function (Blueprint $table): void {
            $table->dropColumn('memory_config');
        });
    }
};
