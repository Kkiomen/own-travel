<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Blog offers used to keep their publication date in departs_at, which made
 * every one of them look like a flight that had already left.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->timestamp('published_at')->nullable()->after('departs_at');
        });

        // Anything without a route never had a departure - that date is a
        // publication date, so move it where it belongs.
        DB::table('deals')
            ->whereNull('origin_iata')
            ->whereNotNull('departs_at')
            ->update([
                'published_at' => DB::raw('departs_at'),
                'departs_at' => null,
            ]);
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->dropColumn('published_at');
        });
    }
};
