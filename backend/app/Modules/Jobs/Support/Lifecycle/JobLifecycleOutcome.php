<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Support\Lifecycle;

/** What a lifecycle command reports: safe references to the event it produced and where the Job is now. No private source context, hash or payload. */
final readonly class JobLifecycleOutcome
{
    public function __construct(
        public int $jobId,
        public int $eventId,
        public int $sequence,
        public string $operation,
        public string $state,
        public int $generation,
        public bool $replayed,
    ) {}
}
