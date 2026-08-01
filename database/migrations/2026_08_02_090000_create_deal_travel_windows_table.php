<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The dates a blog offer can be taken on, lifted out of the trip_details JSON
 * so a holiday search can filter on them.
 *
 * A row per window rather than a first/last pair on the deal, because an
 * article that names several terms is naming *alternatives*: "4 lipca" or
 * "12-15 września" is two separate chances to go, and collapsing them to
 * "4 July until 15 September" would invent a three-month trip that was never
 * on offer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_travel_windows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('deal_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            // Some articles name a single day rather than a span.
            $table->date('ends_on')->nullable();
            // What the article called it, so the offer reads the way it was written.
            $table->string('label');

            $table->index(['starts_on', 'ends_on']);
        });

        $this->liftExistingWindowsOutOfJson();
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_travel_windows');
    }

    /**
     * Deals already stored keep their dates only in JSON, and dedup means a
     * re-scan will never rewrite them - the rows would stay unsearchable until
     * they expired.
     */
    private function liftExistingWindowsOutOfJson(): void
    {
        DB::table('deals')
            ->select(['id', 'trip_details'])
            ->whereNotNull('trip_details')
            ->orderBy('id')
            ->chunk(200, function ($deals): void {
                $rows = [];

                foreach ($deals as $deal) {
                    /** @var object{id: int, trip_details: string|null} $deal */
                    $details = json_decode((string) $deal->trip_details, true);

                    if (! is_array($details) || ! is_array($details['dates'] ?? null)) {
                        continue;
                    }

                    foreach ($details['dates'] as $window) {
                        if (! is_array($window) || ! is_string($window['from'] ?? null)) {
                            continue;
                        }

                        $rows[] = [
                            'deal_id' => $deal->id,
                            'starts_on' => $window['from'],
                            'ends_on' => is_string($window['to'] ?? null) ? $window['to'] : null,
                            'label' => is_string($window['label'] ?? null) ? $window['label'] : $window['from'],
                        ];
                    }
                }

                if ($rows !== []) {
                    DB::table('deal_travel_windows')->insert($rows);
                }
            });
    }
};
