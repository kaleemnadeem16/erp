<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Performance\QueryTimings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * One structured line per request: route, status, duration, query count and database time, memory.
 * Logged in terminate(), after the response has been sent, so it adds no latency to the caller.
 * docs/architecture/observability.md.
 */
final class LogRequestPerformance
{
    public function __construct(private readonly QueryTimings $timings) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldLog($request)) {
            // The Cloudflare ray id lets one slow page load be followed from the edge into this log.
            $this->timings->reset((string) ($request->header('cf-ray') ?: Str::uuid()), $request->method().' '.$request->path());
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! $this->shouldLog($request)) {
            return;
        }

        $started = defined('LARAVEL_START') ? (float) LARAVEL_START : (float) $request->server('REQUEST_TIME_FLOAT', microtime(true));
        $durationMs = round((microtime(true) - $started) * 1000, 1);
        $route = $request->route();

        Log::channel('perf')->info('http_request', [
            'method' => $request->method(),
            // The route pattern (api/v1/items/{item}), not the URL: no record ids, and it groups by endpoint.
            'route' => $route ? $route->uri() : 'unmatched',
            'name' => $route?->getName(),
            'status' => $response->getStatusCode(),
            'duration_ms' => $durationMs,
            'slow' => $durationMs >= (float) config('performance.slow_request_ms'),
            ...$this->timings->summary(),
            'memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
            'user_id' => $request->user()?->getAuthIdentifier(),
            'cf_ray' => $request->header('cf-ray'),
        ]);
    }

    private function shouldLog(Request $request): bool
    {
        return (bool) config('performance.enabled') && ! $request->is(...(array) config('performance.ignore'));
    }
}
