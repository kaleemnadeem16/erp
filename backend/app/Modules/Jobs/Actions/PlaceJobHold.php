<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Actions;

use App\Models\User;
use App\Modules\Jobs\Models\JobEvent;
use App\Modules\Jobs\Models\JobHold;
use App\Modules\Jobs\Support\Execution\JobExecutionContext;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleIntent;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleOperation;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleOutcome;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleRefusal;
use App\Modules\Jobs\Support\Lifecycle\JobSnapshot;
use Illuminate\Support\Carbon;

/**
 * Places one independent JOB-scope hold, with a required reason, on a RELEASED or ACTIVE Job: the hold, its HOLD event, the head advance and the actor's receipt are written
 * atomically. Another open hold does not prevent a further one; a deliberate repeat is a new command identity. The hold takes effect at the event's database time. Needs
 * `job.hold`, SPECIFIED today, so every caller is refused until it is activated.
 */
final class PlaceJobHold
{
    public function __construct(private readonly JobLifecycleOperation $operation) {}

    /** @param  array<string, mixed>  $expected  declared expected versions beyond the required generation */
    public function execute(
        User $actor,
        int $jobId,
        string $reason,
        int $expectedGeneration,
        string $requestKey,
        string $commandIdentity,
        ?Carbon $occurredAt = null,
        string $occurrencePrecision = 'NONE',
        array $expected = [],
    ): JobLifecycleOutcome {
        if (trim($reason) === '') {
            throw JobLifecycleRefusal::make(JobLifecycleRefusal::INVALID_INPUT, 'A hold needs a reason.', 'reason');
        }
        $intent = new JobLifecycleIntent(JobLifecycleOperation::HOLD, $jobId, $commandIdentity, $reason, [], ['generation' => $expectedGeneration] + $expected, $occurredAt, $occurrencePrecision);

        return $this->operation->run(
            $actor, ['job.hold'], $intent, $requestKey, null,
            function (JobSnapshot $snapshot, JobExecutionContext $context): void {
                if (! in_array($snapshot->job->state, ['RELEASED', 'ACTIVE'], true)) {
                    throw JobLifecycleRefusal::make(JobLifecycleRefusal::JOB_STATE_NOT_ALLOWED, 'A hold can only be placed on a released or active Job.', 'job_id');
                }
            },
            function (JobEvent $event, JobSnapshot $snapshot) use ($actor): void {
                JobHold::query()->create(['job_id' => $event->job_id, 'event_id' => $event->id, 'scope_kind' => 'JOB', 'reason' => $event->reason, 'actor_id' => $actor->id]);
            },
        );
    }
}
