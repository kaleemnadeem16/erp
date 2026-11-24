<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Actions;

use App\Models\User;
use App\Modules\Jobs\Models\JobAssignmentEvent;
use App\Modules\Jobs\Models\JobEvent;
use App\Modules\Jobs\Support\Execution\JobExecutionContext;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleIntent;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleOperation;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleOutcome;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleRefusal;
use App\Modules\Jobs\Support\Lifecycle\JobSnapshot;
use Illuminate\Support\Carbon;

/**
 * Fresh START requires the current assignee, a released Job with no open holds and a ready source.
 * The lifecycle operation writes the event, head and receipt atomically. Only the original actor
 * can replay it, subject to current duty and source eligibility.
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
            function (JobSnapshot $snapshot, JobExecutionContext $context) use ($actor): void {
                if ($snapshot->job->state !== 'RELEASED') {
                    throw JobLifecycleRefusal::make(JobLifecycleRefusal::JOB_STATE_NOT_ALLOWED, 'Only a released Job can be started.', 'job_id');
                }
                if ($snapshot->assignmentSequence < 1) {
                    throw JobLifecycleRefusal::make(JobLifecycleRefusal::RESPONSIBILITY_REQUIRED, 'Someone must be made responsible before the Job can start.', 'job_id');
                }
                $assignment = JobAssignmentEvent::query()->where('job_id', $snapshot->job->id)->find($snapshot->latestAssignmentId);
                if ($assignment?->responsible_user_id !== $actor->id) {
                    throw JobLifecycleRefusal::make('START_ASSIGNEE_REQUIRED', 'Only the currently assigned employee can start this Job.', 'job_id');
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
