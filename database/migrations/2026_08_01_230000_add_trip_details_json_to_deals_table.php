<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Everything an article gave up beyond the few fields worth querying on:
 * destination, hotel, departure airports, dates and the offer's own bullets.
 * Nothing filters or sorts by these, so one column beats six.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->json('trip_details')->nullable()->after('hotel_stars');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->dropColumn('trip_details');
        });
    }
};
