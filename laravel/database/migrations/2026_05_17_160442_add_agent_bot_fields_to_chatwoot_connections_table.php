<?php

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
        Schema::table('chatwoot_connections', function (Blueprint $table) {
            $table->unsignedBigInteger('agent_bot_id')->nullable()->after('account_id');
            $table->text('agent_bot_outgoing_url')->nullable()->after('agent_bot_id');
            $table->timestampTz('provisioned_at')->nullable()->after('status');
            $table->timestampTz('provisioning_started_at')->nullable()->after('provisioned_at');
            $table->text('provisioning_error')->nullable()->after('provisioning_started_at');

            $table->unique(['workspace_id', 'agent_bot_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE chatwoot_connections ALTER COLUMN api_access_token DROP NOT NULL');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE chatwoot_connections ALTER COLUMN api_access_token SET NOT NULL');
        }

        Schema::table('chatwoot_connections', function (Blueprint $table) {
            $table->dropUnique(['workspace_id', 'agent_bot_id']);
            $table->dropColumn([
                'agent_bot_id',
                'agent_bot_outgoing_url',
                'provisioned_at',
                'provisioning_started_at',
                'provisioning_error',
            ]);
        });
    }
};
