<?php

/**
 * Force the isolated test environment before Laravel reads configuration.
 *
 * Docker supplies the development .env values as real process variables, which PHP mirrors
 * into $_SERVER. Dotenv and PHPUnit's <env> entries cannot override those reliably. Both the
 * PHPUnit bootstrap and backend/artisan load this file before Laravel starts, so test commands
 * use the dedicated `testing` connection and non-persistent service drivers consistently.
 */
$testEnvironment = [
    'APP_ENV' => 'testing',
    'APP_MAINTENANCE_DRIVER' => 'file',
    'BCRYPT_ROUNDS' => '4',
    'BROADCAST_CONNECTION' => 'null',
    'CACHE_STORE' => 'array',
    // config/database.php fixes this connection to erp_testing independently of DB_DATABASE.
    'DB_CONNECTION' => 'testing',
    // Defence in depth for code that reads DB_DATABASE directly or a misconfigured fallback.
    'DB_DATABASE' => 'erp_testing',
    'DB_URL' => '',
    'MAIL_MAILER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'SANCTUM_STATEFUL_DOMAINS' => 'localhost:5176,localhost:8090,127.0.0.1:5176,127.0.0.1:8090',
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

unset($testEnvironment, $name, $value);
