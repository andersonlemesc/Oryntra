<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_runs', function (Blueprint $table): void {
            $table->unsignedBigInteger('contact_id')->nullable()->after('chatwoot_connection_id');
            $table->index('contact_id', 'agent_runs_contact_id_idx');
        });

        // SQLite recreates the table when adding a foreign key via Blueprint,
        // which drops the partial unique index created via raw SQL in the
        // initial agent_runs migration. Add the FK separately on Postgres only.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE agent_runs ADD CONSTRAINT agent_runs_contact_id_foreign ' .
                'FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE agent_runs DROP CONSTRAINT IF EXISTS agent_runs_contact_id_foreign');
        }

        Schema::table('agent_runs', function (Blueprint $table): void {
            $table->dropIndex('agent_runs_contact_id_idx');
            $table->dropColumn('contact_id');
        });
    }
};
