<?php

/**
 * PHPUnit's <env force="true"> (phpunit.xml) sets putenv()/$_ENV, but Laravel's
 * env() resolution reads $_SERVER first — and this container sets DB_CONNECTION,
 * APP_ENV, CACHE_STORE, SESSION_DRIVER etc. as real process environment variables
 * (docker-compose), which PHP auto-populates into $_SERVER at startup. That left
 * force="true" unable to win, and the whole suite silently ran against the dev
 * database and dev cache/session/queue drivers. Setting $_SERVER directly here,
 * before vendor/autoload.php (and therefore before Laravel ever loads env), is
 * what actually makes the override take effect. Discovered 2026-08-13.
 */
$testEnvironment = [
    'APP_ENV' => 'testing',
    'APP_MAINTENANCE_DRIVER' => 'file',
    'BCRYPT_ROUNDS' => '4',
    'BROADCAST_CONNECTION' => 'null',
    'CACHE_STORE' => 'array',
    // config/database.php hardcodes the erp_testing database for the 'testing'
    // connection regardless of DB_DATABASE — see docs/architecture/database.md.
    'DB_CONNECTION' => 'testing',
    'DB_URL' => '',
    'MAIL_MAILER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array',
    'PULSE_ENABLED' => 'false',
    'TELESCOPE_ENABLED' => 'false',
    'NIGHTWATCH_ENABLED' => 'false',
];

foreach ($testEnvironment as $name => $value) {
    putenv("{$name}={$value}");
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

require __DIR__.'/../vendor/autoload.php';
