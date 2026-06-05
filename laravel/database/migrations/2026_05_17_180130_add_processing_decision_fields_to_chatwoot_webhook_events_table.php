<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('chatwoot_webhook_events', function (Blueprint $table) {
            $table->text('ignored_reason')->nullable()->after('failure_reason');
            $table->text('failed_reason')->nullable()->after('ignored_reason');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE chatwoot_webhook_events DROP CONSTRAINT IF EXISTS chatwoot_webhook_events_status_check');
            DB::statement("ALTER TABLE chatwoot_webhook_events ADD CONSTRAINT chatwoot_webhook_events_status_check CHECK (status IN ('queued', 'processing', 'processed', 'ignored', 'failed'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE chatwoot_webhook_events DROP CONSTRAINT IF EXISTS chatwoot_webhook_events_status_check');
            DB::statement("ALTER TABLE chatwoot_webhook_events ADD CONSTRAINT chatwoot_webhook_events_status_check CHECK (status IN ('queued', 'processing', 'processed', 'failed'))");
        }

        Schema::table('chatwoot_webhook_events', function (Blueprint $table) {
            $table->dropColumn([
                'ignored_reason',
                'failed_reason',
            ]);
        });
    }
};
