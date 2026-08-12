<?php

use App\Http\Middleware\SetPermissionsTeam;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Cookie-authenticates requests from the SPA's origin (SANCTUM_STATEFUL_DOMAINS)
        // instead of requiring a bearer token — see docs/decisions/0005-sanctum-authentication.md.
        $middleware->statefulApi();
        // Rate limiter 'api' (120/min, docs/architecture/api-conventions.md) registered in
        // AppServiceProvider — login's own 5/min limit is set directly on that route.
        $middleware->throttleApi();
        $middleware->api(append: [
            SetPermissionsTeam::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
