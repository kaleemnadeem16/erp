<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Actions;

use App\Models\User;
use App\Modules\Jobs\Models\JobEvent;
use App\Modules\Jobs\Models\JobHold;
use App\Modules\Jobs\Models\JobHoldResolution;
use App\Modules\Jobs\Support\Execution\JobExecutionContext;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleIntent;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleOperation;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleOutcome;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleRefusal;
use App\Modules\Jobs\Support\Lifecycle\JobSnapshot;
use Illuminate\Support\Carbon;

/**
 * Resolves exactly one named, unresolved hold of the Job, with a required reason; any other open hold stays in force and nothing starts implicitly. The resolution, its
 * RESOLVE_HOLD event, the head advance and the actor's receipt are written atomically, effective at the event's database time. Needs `job.hold.resolve` — neither holding
 * `job.hold` nor having placed the hold is enough — which is SPECIFIED today, so every caller is refused until it is activated.
 */
final class ResolveJobHold
{
    public function __construct(private readonly JobLifecycleOperation $operation) {}

    /** @param  array<string, mixed>  $expected  declared expected versions beyond the required generation */
    public function execute(
        User $actor,
        int $jobId,
        int $holdId,
        string $reason,
        int $expectedGeneration,
        string $requestKey,
        string $commandIdentity,
        ?Carbon $occurredAt = null,
        string $occurrencePrecision = 'NONE',
        array $expected = [],
    ): JobLifecycleOutcome {
        if (trim($reason) === '') {
            throw JobLifecycleRefusal::make(JobLifecycleRefusal::INVALID_INPUT, 'A resolution needs a reason.', 'reason');
        }
        $intent = new JobLifecycleIntent(JobLifecycleOperation::RESOLVE_HOLD, $jobId, $commandIdentity, $reason, ['hold_id' => $holdId], ['generation' => $expectedGeneration] + $expected, $occurredAt, $occurrencePrecision);

        return $this->operation->run(
            $actor, ['job.hold.resolve'], $intent, $requestKey, null,
            function (JobSnapshot $snapshot, JobExecutionContext $context) use ($holdId): void {
                if (! in_array($snapshot->job->state, ['RELEASED', 'ACTIVE'], true)) {
                    throw JobLifecycleRefusal::make(JobLifecycleRefusal::JOB_STATE_NOT_ALLOWED, 'A hold can only be resolved on a released or active Job.', 'job_id');
                }
                $hold = JobHold::query()->where(['job_id' => $snapshot->job->id, 'id' => $holdId])->first()
                    ?? throw JobLifecycleRefusal::make(JobLifecycleRefusal::HOLD_NOT_FOUND, 'That hold does not belong to this Job.', 'hold_id');
                if (! in_array($hold->id, $snapshot->openHoldIds, true)) {
                    throw JobLifecycleRefusal::make(JobLifecycleRefusal::HOLD_ALREADY_RESOLVED, 'That hold is already resolved.', 'hold_id');
                }
            },
            function (JobEvent $event, JobSnapshot $snapshot) use ($holdId, $actor): void {
                JobHoldResolution::query()->create(['job_id' => $event->job_id, 'event_id' => $event->id, 'hold_id' => $holdId, 'actor_id' => $actor->id, 'reason' => $event->reason]);
            },
        );
    }
}
