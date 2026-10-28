<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Actions;

use App\Models\User;
use App\Modules\Jobs\Models\JobEvent;
use App\Modules\Jobs\Support\Execution\JobExecutionContext;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleIntent;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleOperation;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleOutcome;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleRefusal;
use App\Modules\Jobs\Support\Lifecycle\JobSnapshot;
use Illuminate\Support\Carbon;

/**
 * Moves a RELEASED Job to ACTIVE: recorded responsibility, no unresolved hold, and a verified source/readiness context (fresh work only — a replay needs none of it) are
 * required; it appends the START event (no child), the head advance and the actor's receipt atomically, and starts nothing else. Needs `job.start`, SPECIFIED today, so every
 * caller is refused until it is activated; the delivered source verifier proves nothing, so a new start is refused even then until a reviewed integration exists.
 */
final class StartJob
{
    public function __construct(private readonly JobLifecycleOperation $operation) {}

    /** @param  array<string, mixed>  $expected  declared expected versions beyond the required generation */
    public function execute(
        User $actor,
        int $jobId,
        int $expectedGeneration,
        string $requestKey,
        string $commandIdentity,
        ?string $reason = null,
        ?Carbon $occurredAt = null,
        string $occurrencePrecision = 'NONE',
        array $expected = [],
    ): JobLifecycleOutcome {
        $intent = new JobLifecycleIntent(JobLifecycleOperation::START, $jobId, $commandIdentity, $reason, [], ['generation' => $expectedGeneration] + $expected, $occurredAt, $occurrencePrecision);

        return $this->operation->run(
            $actor, ['job.start'], $intent, $requestKey, 'ACTIVE',
            function (JobSnapshot $snapshot, JobExecutionContext $context): void {
                if ($snapshot->job->state !== 'RELEASED') {
                    throw JobLifecycleRefusal::make(JobLifecycleRefusal::JOB_STATE_NOT_ALLOWED, 'Only a released Job can be started.', 'job_id');
                }
                if ($snapshot->assignmentSequence < 1) {
                    throw JobLifecycleRefusal::make(JobLifecycleRefusal::RESPONSIBILITY_REQUIRED, 'Someone must be made responsible before the Job can start.', 'job_id');
                }
                if ($snapshot->openHoldIds !== []) {
                    throw JobLifecycleRefusal::make(JobLifecycleRefusal::JOB_HELD, 'The Job has an unresolved hold; resolve it before starting.', 'job_id');
                }
                if (! $context->commandReady) {
                    throw JobLifecycleRefusal::make(JobLifecycleRefusal::NOT_READY_TO_START, 'The Job is not ready to start.', 'job_id');
                }
            },
            function (JobEvent $event, JobSnapshot $snapshot): void {
                // START has no child row: the event, the head and the receipt are the whole change.
            },
        );
    }
}
