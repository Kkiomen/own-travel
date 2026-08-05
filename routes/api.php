<?php

declare(strict_types=1);

use App\Http\Controllers\Api\DealApiController;
use App\Http\Controllers\Api\OpenApiController;
use Illuminate\Support\Facades\Route;

/*
 * The same deals the dashboard shows, for other apps to read.
 *
 * Read-only and unauthenticated, like the rest of this app - the protection is
 * not exposing the port, so keep it behind the same boundary as the dashboard.
 */

Route::get('openapi.json', [OpenApiController::class, 'specification'])->name('api.openapi');
Route::get('docs', [OpenApiController::class, 'docs'])->name('api.docs');

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    // The whole board in one request, for rebuilding the view elsewhere.
    Route::get('dashboard', [DealApiController::class, 'dashboard'])->name('dashboard');

    Route::get('deals', [DealApiController::class, 'index'])->name('deals.index');
    // Ahead of the wildcard, or "airports" would be read as a fingerprint.
    Route::get('deals/airports', [DealApiController::class, 'airports'])->name('deals.airports');
    Route::get('deals/summary', [DealApiController::class, 'summary'])->name('deals.summary');
    Route::get('deals/{deal}', [DealApiController::class, 'show'])->name('deals.show');

    Route::get('meta', [DealApiController::class, 'meta'])->name('meta');
});
