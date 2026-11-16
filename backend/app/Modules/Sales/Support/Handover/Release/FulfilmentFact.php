<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Handover\Release;

/** What a Job's completion tells the Sales source: which Job, which COMPLETE event, who completed it, and the source pin the completion relied on. Never an authority. */
final readonly class FulfilmentFact
{
    public function __construct(public int $jobId, public int $completionEventId, public int $actorId, public ?string $sourcePin) {}
}
