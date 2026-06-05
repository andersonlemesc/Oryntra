<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatwoot_labels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chatwoot_connection_id')->constrained('chatwoot_connections')->cascadeOnDelete();
            $table->unsignedBigInteger('chatwoot_label_id')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('color', 32)->nullable();
            $table->boolean('show_on_sidebar')->default(true);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['chatwoot_connection_id', 'title']);
            $table->index('workspace_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatwoot_labels');
    }
};
