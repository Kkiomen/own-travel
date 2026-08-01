<?php

declare(strict_types=1);

/**
 * The application runs inside a container whose .env points at the live
 * Postgres, and those variables reach PHP through $_SERVER - which Laravel's
 * env repository reads *before* anything PHPUnit sets with putenv(). Left
 * alone, the suite connects to the real database and RefreshDatabase wipes it.
 *
 * Pinning the test environment here, before the framework is even autoloaded,
 * is the only place that reliably wins. TestEnvironmentTest guards it.
 */
$testEnvironment = [
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DB_URL' => '',
    'DB_HOST' => '',
    'DB_PORT' => '',
    'DB_USERNAME' => '',
    'DB_PASSWORD' => '',
    'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'array',
    'BROADCAST_CONNECTION' => 'null',
    'BCRYPT_ROUNDS' => '4',
];

foreach ($testEnvironment as $key => $value) {
    $_SERVER[$key] = $value;
    $_ENV[$key] = $value;
    putenv($key.'='.$value);
}

require __DIR__.'/../vendor/autoload.php';
