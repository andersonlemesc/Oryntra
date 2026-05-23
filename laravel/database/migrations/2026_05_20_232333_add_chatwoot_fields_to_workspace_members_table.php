<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_members', function (Blueprint $table) {
            $table->unsignedBigInteger('chatwoot_user_id')->nullable()->after('role');
            $table->string('chatwoot_availability')->nullable()->after('chatwoot_user_id');
            $table->boolean('chatwoot_confirmed')->default(false)->after('chatwoot_availability');
            $table->string('chatwoot_role')->nullable()->after('chatwoot_confirmed');
            $table->index(['workspace_id', 'chatwoot_user_id'], 'workspace_members_workspace_chatwoot_user_idx');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_members', function (Blueprint $table) {
            $table->dropIndex('workspace_members_workspace_chatwoot_user_idx');
            $table->dropColumn([
                'chatwoot_user_id',
                'chatwoot_availability',
                'chatwoot_confirmed',
                'chatwoot_role',
            ]);
        });
    }
};
