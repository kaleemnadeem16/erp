<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Support\Lifecycle;

/** What a closeout assessment reports: a safe reference to the immutable assessment, its READY/BLOCKED status and the stable blocker codes. No private source context or hash. */
final readonly class JobCloseoutOutcome
{
    /** @param list<string> $blockers */
    public function __construct(public int $jobId, public int $assessmentId, public string $status, public array $blockers, public int $generation, public bool $replayed) {}
}
