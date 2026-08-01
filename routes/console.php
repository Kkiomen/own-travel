<?php

use App\Console\Commands\PruneDealsCommand;
use App\Console\Commands\ScanDealsCommand;
use Illuminate\Support\Facades\Schedule;

// Error fares disappear within hours, so the sources are polled often. The
// overlap guard keeps a slow scan from stacking on top of the next one.
Schedule::command(ScanDealsCommand::class)
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command(PruneDealsCommand::class)
    ->dailyAt('04:00')
    ->runInBackground();
