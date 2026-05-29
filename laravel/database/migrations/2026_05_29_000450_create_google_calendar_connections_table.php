<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_calendar_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->uuid('connection_uuid')->unique();
            $table->text('label');
            $table->string('google_email');
            $table->string('google_user_id')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->string('token_type')->default('Bearer');
            $table->timestamp('expires_at')->nullable();
            $table->jsonb('scopes')->default('[]');
            $table->string('default_calendar_id')->nullable();
            $table->boolean('default_notify_attendees')->default(true);
            $table->boolean('is_active')->default(true);
            $table->text('last_error')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['workspace_id', 'google_email']);
            $table->index(['workspace_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_calendar_connections');
    }
};
