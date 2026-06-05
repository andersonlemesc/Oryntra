<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatwoot_connections', function (Blueprint $table) {
            $table->text('admin_api_token')->nullable()->after('api_access_token');
        });
    }

    public function down(): void
    {
        Schema::table('chatwoot_connections', function (Blueprint $table) {
            $table->dropColumn('admin_api_token');
        });
    }
};
