<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Actions;

use App\Models\User;
use App\Modules\Jobs\Models\JobCloseout;
use App\Modules\Jobs\Models\JobCloseoutAssessment;
use App\Modules\Jobs\Models\JobEvent;
use App\Modules\Jobs\Support\Execution\JobExecutionContext;
use App\Modules\Jobs\Support\Lifecycle\JobCloseoutBlockers;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleIntent;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleOperation;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleOutcome;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleRefusal;
use App\Modules\Jobs\Support\Lifecycle\JobSnapshot;
use Illuminate\Support\Carbon;

/**
 * Operationally closes a COMPLETED Job on a READY closeout assessment of exactly the generation it closes. Under the Job lock it re-runs the blocker rules (completion, holds,
 * the source's proof of its obligations and pinned conditions), so an assessment is never trusted past the moment it was made. It appends the CLOSE event (COMPLETED to CLOSED),
 * the immutable closeout row, the head advance and the actor's receipt atomically; a replay returns the original, there is no second closeout. Closing settles no invoice, balance
 * or accepted capacity: it is an operational fact only. Needs `job.close`, SPECIFIED today, so every caller is refused until it is activated.
 */
final class CloseJob
{
    public function __construct(private readonly JobLifecycleOperation $operation, private readonly JobCloseoutBlockers $blockers) {}

    /** @param  array<string, mixed>  $expected  declared expected versions beyond the required generation */
    public function execute(
        User $actor,
        int $jobId,
        int $expectedGeneration,
        int $assessmentId,
        string $requestKey,
        string $commandIdentity,
        ?string $statement = null,
        ?Carbon $occurredAt = null,
        string $occurrencePrecision = 'NONE',
        array $expected = [],
    ): JobLifecycleOutcome {
        $statement = $statement === null ? null : trim($statement);
        $intent = new JobLifecycleIntent(JobLifecycleOperation::CLOSE, $jobId, $commandIdentity, $statement === '' ? null : $statement, ['assessment_id' => $assessmentId], ['generation' => $expectedGeneration] + $expected, $occurredAt, $occurrencePrecision);
        $statement = $statement === '' ? null : $statement;

        return $this->operation->run(
            $actor, ['job.close'], $intent, $requestKey, 'CLOSED',
            function (JobSnapshot $snapshot, JobExecutionContext $context) use ($assessmentId): void {
                if ($snapshot->job->state !== 'COMPLETED') {
                    throw JobLifecycleRefusal::make(JobLifecycleRefusal::JOB_STATE_NOT_ALLOWED, 'Only a completed Job can be closed.', 'job_id');
                }
                $assessment = JobCloseoutAssessment::query()->where(['id' => $assessmentId, 'job_id' => $snapshot->job->id])->first();
                if ($assessment === null || $assessment->status !== 'READY' || $assessment->at_generation !== $snapshot->job->generation) {
                    throw JobLifecycleRefusal::make(JobLifecycleRefusal::CLOSEOUT_ASSESSMENT_REQUIRED, 'A READY closeout assessment of the Job as it is now is required; assess it again.', 'assessment_id');
                }
                if ($this->blockers->for($snapshot, $context) !== []) {
                    throw JobLifecycleRefusal::make(JobLifecycleRefusal::CLOSEOUT_BLOCKED, 'The Job can no longer be closed; assess it again.', 'job_id');
                }
            },
            function (JobEvent $event, JobSnapshot $snapshot) use ($assessmentId, $statement, $actor, $occurredAt, $occurrencePrecision): void {
                JobCloseout::query()->create([
                    'job_id' => $snapshot->job->id, 'event_id' => $event->id, 'assessment_id' => $assessmentId, 'statement' => $statement, 'closed_by' => $actor->id,
                    'occurred_at' => $occurredAt, 'occurrence_precision' => $occurrencePrecision,
                ]);
            },
        );
    }
}
