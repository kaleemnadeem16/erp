<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Queries;

/** Materialised safe profile only; no source vector, model or deferred query escapes the fenced read. */
final readonly class JobOperationContextProjection
{
    /** @param array<string, mixed> $fields */
    public function __construct(public array $fields) {}
}
