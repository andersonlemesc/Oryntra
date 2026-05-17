<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedBigInteger('chatwoot_account_id')->nullable()->unique();
            $table->string('timezone')->default('UTC');
            $table->string('locale')->default('en');
            $table->timestamps();

            $table->index('chatwoot_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspaces');
    }
};
