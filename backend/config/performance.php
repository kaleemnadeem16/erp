<?php

/*
| Request and query timing, written as one JSON line per request to the `perf` log channel
| (stderr -> Docker -> Promtail -> Loki). See docs/architecture/observability.md.
| Nothing here logs request bodies, query bindings or personal data.
*/
return [
    // Off under the test suite (set PERF_LOG_ENABLED=true in a test that checks it).
    'enabled' => (bool) env('PERF_LOG_ENABLED', env('APP_ENV') !== 'testing'),

    // A query at or above this many milliseconds is also logged on its own line.
    'slow_query_ms' => (float) env('PERF_SLOW_QUERY_MS', 100),

    // A request at or above this many milliseconds is flagged `slow` on its line.
    'slow_request_ms' => (float) env('PERF_SLOW_REQUEST_MS', 1000),

    // Paths that are never logged (health checks run every few seconds).
    'ignore' => ['up'],
];
