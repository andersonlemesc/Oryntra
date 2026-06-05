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
        Schema::create('chatwoot_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->uuid('connection_uuid')->unique();
            $table->text('name');
            $table->text('base_url');
            $table->unsignedBigInteger('account_id');
            $table->text('api_access_token')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->text('status')->default('active');
            $table->timestamps();

            $table->unique(['workspace_id', 'name']);
            $table->unique(['workspace_id', 'base_url', 'account_id']);
            $table->index(['workspace_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE chatwoot_connections ADD CONSTRAINT chatwoot_connections_status_check CHECK (status IN ('active', 'inactive'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chatwoot_connections');
    }
};
