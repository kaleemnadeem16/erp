<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Support\Execution;

/**
 * What a verified source/readiness check proved about one Job for one operation: the current canonical source versions (an opaque vector the caller may declare as
 * expected), whether the record is safely eligible for this actor to touch, and whether FRESH work (a start) is ready. The two are distinct: a valid replay needs
 * eligibility, not readiness. Nothing here is private source detail.
 */
final readonly class JobExecutionContext
{
    /** @param array<string, string> $sourceVector */
    public function __construct(public array $sourceVector, public bool $recordEligible, public bool $commandReady) {}
}
