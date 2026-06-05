<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE chatwoot_webhook_events DROP CONSTRAINT IF EXISTS chatwoot_webhook_events_status_check');
        DB::statement("ALTER TABLE chatwoot_webhook_events ADD CONSTRAINT chatwoot_webhook_events_status_check CHECK (status IN ('queued', 'processing', 'debouncing', 'processed', 'ignored', 'failed'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE chatwoot_webhook_events DROP CONSTRAINT IF EXISTS chatwoot_webhook_events_status_check');
        DB::statement("ALTER TABLE chatwoot_webhook_events ADD CONSTRAINT chatwoot_webhook_events_status_check CHECK (status IN ('queued', 'processing', 'processed', 'ignored', 'failed'))");
    }
};
