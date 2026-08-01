<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->unsignedSmallInteger('trip_days')->nullable()->after('departs_at');
            $table->string('board', 32)->nullable()->after('trip_days');
            $table->unsignedTinyInteger('hotel_stars')->nullable()->after('board');
            $table->unsignedTinyInteger('score')->nullable()->after('hotel_stars');
            $table->unsignedInteger('rated_price_minor_units')->nullable()->after('score');
            $table->string('score_basis', 16)->nullable()->after('rated_price_minor_units');

            $table->index('score');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->dropIndex(['score']);
            $table->dropColumn([
                'trip_days',
                'board',
                'hotel_stars',
                'score',
                'rated_price_minor_units',
                'score_basis',
            ]);
        });
    }
};
