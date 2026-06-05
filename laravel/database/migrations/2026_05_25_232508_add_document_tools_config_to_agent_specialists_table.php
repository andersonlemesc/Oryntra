<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_specialists', function (Blueprint $table) {
            $table->jsonb('document_tools_config')->default('{}')->after('product_tools_config');
        });
    }

    public function down(): void
    {
        Schema::table('agent_specialists', function (Blueprint $table) {
            $table->dropColumn('document_tools_config');
        });
    }
};
