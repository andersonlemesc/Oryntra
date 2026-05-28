<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_tools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('kind')->default('http_connector');
            $table->string('slug');
            $table->string('label');
            $table->text('description');
            $table->boolean('enabled')->default(true);
            $table->jsonb('config')->default('{}');
            $table->text('credentials')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'slug']);
            $table->index(['workspace_id', 'kind', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_tools');
    }
};
