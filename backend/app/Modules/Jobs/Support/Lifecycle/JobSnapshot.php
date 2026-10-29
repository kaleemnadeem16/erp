<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Support\Lifecycle;

use App\Modules\Jobs\Models\JobEvent;
use App\Modules\Jobs\Models\ServiceJob;

/** The locked Job head and the versions a command's preconditions and declared expectations are judged against. */
final readonly class JobSnapshot
{
    /** @param list<int> $openHoldIds */
    public function __construct(
        public ServiceJob $job,
        public JobEvent $latestEvent,
        public int $baselineVersion,
        public int $assignmentSequence,
        public ?int $latestAssignmentId,
        public array $openHoldIds,
    ) {}
}
