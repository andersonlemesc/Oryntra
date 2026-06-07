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
        Schema::table('products', function (Blueprint $table): void {
            $table->jsonb('tags')->nullable()->after('metadata');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX products_tags_gin ON products USING gin (tags)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS products_tags_gin');
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('tags');
        });
    }
};
