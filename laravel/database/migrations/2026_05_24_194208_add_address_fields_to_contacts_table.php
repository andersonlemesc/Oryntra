<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->text('address_postal_code')->nullable()->after('phone_number');
            $table->text('address_street')->nullable()->after('address_postal_code');
            $table->text('address_number')->nullable()->after('address_street');
            $table->text('address_complement')->nullable()->after('address_number');
            $table->text('address_neighborhood')->nullable()->after('address_complement');
            $table->text('address_city')->nullable()->after('address_neighborhood');
            $table->text('address_state')->nullable()->after('address_city');
            $table->text('address_country')->nullable()->after('address_state');
            $table->text('address_reference')->nullable()->after('address_country');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn([
                'address_postal_code',
                'address_street',
                'address_number',
                'address_complement',
                'address_neighborhood',
                'address_city',
                'address_state',
                'address_country',
                'address_reference',
            ]);
        });
    }
};
