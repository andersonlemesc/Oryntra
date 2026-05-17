<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('chatwoot_webhook_events') && DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE chatwoot_webhook_events ALTER COLUMN payload TYPE jsonb USING payload::jsonb');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('chatwoot_webhook_events') && DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE chatwoot_webhook_events ALTER COLUMN payload TYPE json USING payload::json');
        }
    }
};
