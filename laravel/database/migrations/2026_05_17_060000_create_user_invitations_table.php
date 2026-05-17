<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 80)->unique();
            $table->string('email_sent_to');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 32)->default('manual');
            $table->timestamps();

            $table->index(['user_id', 'accepted_at']);
            $table->index('expires_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_invitation_sent_at')->nullable()->after('is_super_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_invitation_sent_at');
        });

        Schema::dropIfExists('user_invitations');
    }
};
