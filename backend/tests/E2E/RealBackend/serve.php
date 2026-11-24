<?php

declare(strict_types=1);

/**
 * Router script of the isolated real-backend browser harness (docs/architecture/real-backend-browser-tests.md):
 *
 *   php -S 0.0.0.0:8099 -t public tests/E2E/RealBackend/serve.php
 *
 * TEST ONLY. It is not referenced by any production file, route, config or image entry point. It refuses to serve unless the process was started as the dedicated harness
 * (see RealBackendAuthority::refusal()), then serves the REAL application — real routes, middleware, Actions, policies and database — with the duty manifests of THIS process
 * activated for the test world. The authority is process-local and is never persisted or exposed over HTTP.
 */

use Illuminate\Http\Request;
use Tests\E2E\RealBackend\RealBackendAuthority;

require __DIR__.'/../../../bootstrap/testing-environment.php';
require __DIR__.'/../../../vendor/autoload.php';

$refusal = RealBackendAuthority::refusal();
if ($refusal !== null) {
    http_response_code(503);
    header('Content-Type: text/plain');
    echo $refusal;
    error_log($refusal);

    return true;
}

define('LARAVEL_START', microtime(true));
$app = require_once __DIR__.'/../../../bootstrap/app.php';
$app->booted(static fn ($app) => RealBackendAuthority::apply($app));
$app->handleRequest(Request::capture());

return true;
