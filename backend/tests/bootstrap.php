<?php

// The shared `erp_testing` database is not a place to run tests: two runs on it drop each other's tables (47 deadlocks seen on the
// shared test server). Outside CI a run must name its own database, which docker/backend/run-tests-tmpfs.sh does for every agent.
// ERP_ALLOW_SHARED_TEST_DB=1 is a deliberate, human-only override (for example a one-off local `pest` on a disposable machine).
$privateDatabase = getenv('TEST_DB_DATABASE');
if (! getenv('CI') && ! getenv('ERP_ALLOW_SHARED_TEST_DB') && ($privateDatabase === false || $privateDatabase === '' || $privateDatabase === 'erp_testing')) {
    fwrite(STDERR, "\nRefusing to run the test suite against the shared 'erp_testing' database.\n"
        ."Run it through the wrapper, which gives you a private database and coordinates full-suite runs:\n"
        ."  ERP_AGENT=<you> docker/backend/run-tests-tmpfs.sh [--filter=...]\n"
        ."See docs/architecture/environments.md (Test database on tmpfs).\n\n");
    exit(78);
}
unset($privateDatabase);

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
