<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A guard, not a feature.
 *
 * The suite once ran against the live Postgres database because the container
 * exports DB_CONNECTION through $_SERVER, which outranks anything PHPUnit
 * sets - and RefreshDatabase duly emptied it. If this test ever fails, stop
 * and fix tests/bootstrap.php before running anything else.
 */
final class TestEnvironmentTest extends TestCase
{
    public function test_the_suite_runs_on_a_throwaway_database(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertSame('testing', $this->app->environment());
    }

    public function test_it_never_points_at_the_application_database(): void
    {
        $this->assertNotSame('pgsql', config('database.default'));
        $this->assertNotSame('travel', DB::connection()->getDatabaseName());
    }
}
