<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether both legs fit into a weekend is worked out once, when the deal is
 * found, so the dashboard can filter on it without the database having to know
 * what counts as a weekend.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->boolean('weekend_getaway')->default(false)->after('score_basis');

            $table->index(['type', 'weekend_getaway']);
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->dropIndex(['type', 'weekend_getaway']);
            $table->dropColumn('weekend_getaway');
        });
    }
};
