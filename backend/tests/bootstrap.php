<?php

/**
 * PHPUnit's <env force="true"> (phpunit.xml) sets putenv()/$_ENV, but Laravel's
 * env() resolution reads $_SERVER first — and this container sets DB_CONNECTION,
 * APP_ENV, CACHE_STORE, SESSION_DRIVER etc. as real process environment variables
 * (docker-compose), which PHP auto-populates into $_SERVER at startup. That left
 * force="true" unable to win, and the whole suite silently ran against the dev
 * database and dev cache/session/queue drivers. The shared bootstrap required below
 * sets $_SERVER before vendor/autoload.php (and therefore before Laravel ever loads
 * env), which makes the override take effect for PHPUnit and direct testing Artisan
 * commands alike. Discovered 2026-08-13; shared with Artisan 2026-09-02.
 */
require __DIR__.'/../bootstrap/testing-environment.php';

require __DIR__.'/../vendor/autoload.php';
