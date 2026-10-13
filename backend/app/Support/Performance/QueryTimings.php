<?php

declare(strict_types=1);

namespace App\Support\Performance;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Log;

/**
 * Per-request database timing. One instance per container lifetime (php-fpm handles one request per
 * worker at a time), reset by LogRequestPerformance at the start of each request.
 *
 * Only the SQL with its placeholders is ever recorded — never the bindings — so no row data or
 * personal data reaches the logs.
 */
final class QueryTimings
{
    private int $count = 0;

    private float $totalMs = 0.0;

    private int $slow = 0;

    private string $requestId = '';

    private string $route = '';

    public function reset(string $requestId, string $route): void
    {
        $this->count = 0;
        $this->totalMs = 0.0;
        $this->slow = 0;
        $this->requestId = $requestId;
        $this->route = $route;
    }

    public function record(QueryExecuted $query): void
    {
        $this->count++;
        $this->totalMs += $query->time;

        if ($this->requestId === '' || $query->time < (float) config('performance.slow_query_ms')) {
            return;
        }

        $this->slow++;
        Log::channel('perf')->warning('slow_query', [
            'request_id' => $this->requestId,
            'route' => $this->route,
            'duration_ms' => round($query->time, 2),
            'connection' => $query->connectionName,
            'sql' => mb_substr($query->sql, 0, 1000),
        ]);
    }

    /** @return array{queries:int, db_ms:float, slow_queries:int} */
    public function summary(): array
    {
        return ['queries' => $this->count, 'db_ms' => round($this->totalMs, 2), 'slow_queries' => $this->slow];
    }
}
