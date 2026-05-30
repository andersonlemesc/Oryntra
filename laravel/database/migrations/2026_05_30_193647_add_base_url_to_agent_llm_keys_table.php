<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_llm_keys', function (Blueprint $table) {
            $table->text('base_url')->nullable()->after('provider');
        });
    }

    public function down(): void
    {
        Schema::table('agent_llm_keys', function (Blueprint $table) {
            $table->dropColumn('base_url');
        });
    }
};
