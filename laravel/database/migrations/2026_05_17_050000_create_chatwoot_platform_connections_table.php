<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatwoot_platform_connections', function (Blueprint $table) {
            $table->id();
            $table->text('base_url');
            $table->text('platform_token');
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_sync_status')->nullable();
            $table->text('last_sync_error')->nullable();
            $table->json('last_sync_summary')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatwoot_platform_connections');
    }
};
