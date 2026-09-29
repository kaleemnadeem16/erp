<?php

use App\Exceptions\DomainInvariantException;
use App\Http\Middleware\SetPermissionsTeam;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Production runs behind Traefik (docs/architecture/deployment.md). Without
        // this every request appears to come from the proxy's own IP, which would
        // collapse login's throttle:5,1 into five attempts per minute for the whole
        // application, and would leave isSecure() false so X-Forwarded-Proto is
        // ignored and secure cookies are never marked secure. TRUSTED_PROXIES is a
        // comma-separated list; '*' is correct here because the container is only
        // reachable through Traefik on the internal network.
        $middleware->trustProxies(
            at: explode(',', (string) env('TRUSTED_PROXIES', '*')),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );
        // Cookie-authenticates requests from the SPA's origin (SANCTUM_STATEFUL_DOMAINS)
        // instead of requiring a bearer token — see docs/decisions/0005-sanctum-authentication.md.
        $middleware->statefulApi();
        // Rate limiter 'api' (120/min, docs/architecture/api-conventions.md) registered in
        // AppServiceProvider — login's own 5/min limit is set directly on that route.
        $middleware->throttleApi();
        $middleware->api(append: [
            SetPermissionsTeam::class,
            EnsureUserIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Order matters here: renderers run in registration order and the first to
        // return a non-null response wins (Handler::renderViaCallbacks()), and
        // UniqueConstraintViolationException extends QueryException — so the more
        // specific handler must be registered first, or every unique-violation would
        // be caught by the generic 23503 handler below instead.

        // A model guard (tenant scoping, hierarchy cycle, a required default nothing
        // resolved) rejected client-supplied input — see App\Exceptions\
        // DomainInvariantException. Without this, these are uncaught 500s instead of
        // api-conventions.md's 422 shape for a business-rule violation.
        $exceptions->render(function (DomainInvariantException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $e->getMessage(),
                'errors' => [($e->field ?? 'id') => [$e->getMessage()]],
            ], 422);
        });

        // Generic backstop for a concurrent-insert race on a unique constraint that a
        // controller's own Rule::unique() check can't close by itself (validated at
        // request time, violated by another request before the insert). A specific
        // field-level check like the exchange-rate endpoint's own catch, where one
        // exists, still wins — this only fires when nothing more specific caught it.
        $exceptions->render(function (UniqueConstraintViolationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => 'This record conflicts with one that already exists.',
                'errors' => ['conflict' => ['This record conflicts with one that already exists.']],
            ], 422);
        });

        // A foreign-key RESTRICT violation (e.g. deleting a category that items still
        // reference) is always a 409-shaped situation from the API's perspective,
        // regardless of which code path triggered it — no per-endpoint pre-check
        // needed, the database is the authoritative source of truth here.
        $exceptions->render(function (QueryException $e, Request $request) {
            if ((string) $e->getCode() !== '23503' || ! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => 'This record is still referenced by other records and cannot be changed this way.',
            ], 409);
        });

        // A CHECK constraint reached by the database without a matching PHP-level
        // DomainInvariantException — e.g. a genuine concurrent race two requests'
        // own guards couldn't see coming. Every table pairs its checks/triggers with
        // an Action-level guard where the violation is reachable in the ordinary
        // flow, so this is a backstop for the race case, not the primary path — see
        // docs/architecture/invariants.md. Without it, this shape of failure leaks
        // as an uncaught 500 instead of api-conventions.md's 422 for a business-rule
        // violation.
        $exceptions->render(function (QueryException $e, Request $request) {
            if ((string) $e->getCode() !== '23514' || ! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => 'This action violates a data integrity rule and cannot be completed.',
            ], 422);
        });
    })->create();
