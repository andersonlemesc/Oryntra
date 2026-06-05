<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chatwoot contacts are account-level: the same person has one contact id across
 * every inbox and agent bot of a Chatwoot account. We previously scoped contacts
 * by (workspace, chatwoot_connection_id, chatwoot_contact_id), so a second bot
 * (a second connection) on the same account produced a duplicate contact row.
 *
 * This re-scopes contacts to (workspace, chatwoot_account_id, chatwoot_contact_id),
 * merging existing duplicates and keeping chatwoot_connection_id only as the
 * first-seen connection (nullable on Postgres, surviving a connection deletion).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->unsignedBigInteger('chatwoot_account_id')->nullable()->after('chatwoot_connection_id');
        });

        $this->backfillAccountIds();
        $this->mergeDuplicateContacts();

        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropUnique('contacts_workspace_connection_chatwoot_unique');
            $table->unsignedBigInteger('chatwoot_account_id')->nullable(false)->change();
            $table->unique(['workspace_id', 'chatwoot_account_id', 'chatwoot_contact_id'], 'contacts_workspace_account_chatwoot_unique');
        });

        // A contact now outlives any single bot/connection, so it must not be
        // cascade-deleted when its first-seen connection is removed.
        if (DB::getDriverName() === 'pgsql') {
            Schema::table('contacts', function (Blueprint $table): void {
                $table->dropForeign(['chatwoot_connection_id']);
                $table->unsignedBigInteger('chatwoot_connection_id')->nullable()->change();
                $table->foreign('chatwoot_connection_id')->references('id')->on('chatwoot_connections')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            Schema::table('contacts', function (Blueprint $table): void {
                $table->dropForeign(['chatwoot_connection_id']);
                $table->unsignedBigInteger('chatwoot_connection_id')->nullable(false)->change();
                $table->foreign('chatwoot_connection_id')->references('id')->on('chatwoot_connections')->cascadeOnDelete();
            });
        }

        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropUnique('contacts_workspace_account_chatwoot_unique');
            $table->unique(['workspace_id', 'chatwoot_connection_id', 'chatwoot_contact_id'], 'contacts_workspace_connection_chatwoot_unique');
            $table->dropColumn('chatwoot_account_id');
        });
    }

    private function backfillAccountIds(): void
    {
        $accountByConnection = DB::table('chatwoot_connections')->pluck('account_id', 'id');

        foreach ($accountByConnection as $connectionId => $accountId) {
            DB::table('contacts')
                ->where('chatwoot_connection_id', $connectionId)
                ->update(['chatwoot_account_id' => $accountId]);
        }
    }

    /**
     * Keep the oldest contact per (workspace, account, chatwoot_contact_id), repoint
     * children to it, and remove the duplicates. contact_memories, agent_runs and
     * playground_conversations have no unique key on contact_id, so repointing is safe.
     */
    private function mergeDuplicateContacts(): void
    {
        $survivorByGroup = [];
        $duplicateToSurvivor = [];

        DB::table('contacts')
            ->orderBy('id')
            ->select(['id', 'workspace_id', 'chatwoot_account_id', 'chatwoot_contact_id'])
            ->each(function (object $contact) use (&$survivorByGroup, &$duplicateToSurvivor): void {
                $group = $contact->workspace_id . '|' . $contact->chatwoot_account_id . '|' . $contact->chatwoot_contact_id;

                if (! isset($survivorByGroup[$group])) {
                    $survivorByGroup[$group] = $contact->id;

                    return;
                }

                $duplicateToSurvivor[$contact->id] = $survivorByGroup[$group];
            });

        foreach ($duplicateToSurvivor as $duplicateId => $survivorId) {
            DB::table('agent_runs')->where('contact_id', $duplicateId)->update(['contact_id' => $survivorId]);
            DB::table('contact_memories')->where('contact_id', $duplicateId)->update(['contact_id' => $survivorId]);
            DB::table('playground_conversations')->where('contact_id', $duplicateId)->update(['contact_id' => $survivorId]);
            DB::table('contacts')->where('id', $duplicateId)->delete();
        }
    }
};
