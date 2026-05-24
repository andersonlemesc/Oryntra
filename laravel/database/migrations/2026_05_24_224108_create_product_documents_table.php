<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('original_filename');
            $table->string('filename')->unique();
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('path');
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_documents');
    }
};