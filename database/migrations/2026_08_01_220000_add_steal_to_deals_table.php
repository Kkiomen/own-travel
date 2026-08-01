<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a route normally costs, and whether this offer is far enough below it
 * to be a real steal rather than merely cheap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->unsignedInteger('typical_price_minor_units')->nullable()->after('weekend_getaway');
            $table->boolean('steal')->default(false)->after('typical_price_minor_units');

            $table->index('steal');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->dropIndex(['steal']);
            $table->dropColumn(['typical_price_minor_units', 'steal']);
        });
    }
};
